<?php
/**
 * Media library deletion write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * An operator removes a media library item, and the files behind it, for good.
 *
 * THE FILES ARE GONE AND NOTHING HERE CAN PUT THEM BACK. WordPress deletes the
 * uploaded file and every resized copy it generated; SiteHelm keeps no copy of
 * either, and a database row restored without them would point at files that no
 * longer exist. So the snapshot and rollback policies are `NotApplicable` and
 * `restore()` refuses, rather than recording something that would tell an
 * operator they had a way back when they did not. The preview IS the way back
 * and it is the only one.
 *
 * The risk tier is `High` rather than `Extreme` even though this is the one
 * media write that cannot be undone. `Risk::Extreme` means the payload is a
 * program, and it gates the Read & edit permission level: borrowing it for an
 * operation that merely feels dangerous would switch this off for every owner
 * on that level. What makes a delete safe here is the required preview and the
 * warnings in it, not an inflated tier.
 *
 * `isDestructive` IS FALSE, AND THAT IS A CONTRACT WORD RATHER THAN A CLAIM
 * ABOUT THE CHANGE. {@see OperationDefinition} makes `isDestructive: true` force
 * preview AND snapshot AND rollback all to `Required`, so in this codebase the
 * flag means "destructive and reversible" — an operation that promises a way
 * back. A delete cannot promise one, so it declares what it can honour and says
 * the rest in the risk tier, in the description, and in warnings the operator
 * has to approve. It is the same reading
 * {@see \SiteHelm\Modules\Core\ContentTrash} relies on one module over, where
 * the trash IS recoverable and the flag is therefore true.
 *
 * THIS IS NOT THE TRASH. WordPress can be configured to move an attachment to
 * the trash instead of deleting it, and this operation deliberately does not
 * branch on that setting: an operation whose declared policies say "no way back"
 * must not leave a recoverable copy on some sites and not on others, because the
 * operator approving the plan cannot see which site they are on. It always
 * deletes.
 *
 * @package SiteHelm
 */
final class MediaDelete implements WriteOperation {

	/**
	 * The one field this operation promises.
	 *
	 * A field the media projection does not otherwise carry, on purpose: every
	 * other field of a deleted attachment is GONE rather than changed, so
	 * promising `title` or `url` would be promising a value read off a row that
	 * will not exist to be read.
	 */
	private const PROMISED_FIELD = 'deleted';

	/**
	 * How many using content items a warning names before it stops counting.
	 */
	private const USAGE_LIMIT = 20;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-delete.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-delete',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Permanently delete one media library item and the files behind it. This cannot be undone.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item to delete.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'delete_post' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-delete',
				'arguments' => [ 'id' => 108 ],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields $fields  The normalized attachment projection.
	 * @param MediaTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * Resolves the media item the input names, and asserts it may be deleted.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or Forbidden.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$attachment_id = (int) ( $input['id'] ?? 0 );
		$current       = $this->targets->resolve( $attachment_id, $context );

		$this->require_deletable( $attachment_id, $context );

		return $current;
	}

	/**
	 * Promises that the item will not be in the library any more, and says in
	 * words what goes with it.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised after-state and its warnings.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $input, $context );

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		$filename      = (string) ( $current->fields['filename'] ?? '' );
		$sizes         = is_array( $current->fields['sizes'] ?? null ) ? $current->fields['sizes'] : [];

		$warnings = [
			'' === $filename
				? 'Deleting this media item removes its file from this site for good. SiteHelm keeps no copy of it and cannot put it back; only uploading it again can.'
				: sprintf(
					'Deleting this media item removes %s from this site for good. SiteHelm keeps no copy of it and cannot put it back; only uploading it again can.',
					$filename
				),
		];

		if ( [] !== $sizes ) {
			$warnings[] = sprintf( 'The %d resized copies WordPress generated from it go as well.', count( $sizes ) );
		}

		$warnings[] = 'Anywhere this file\'s address already appears in content stays as it is, and stops loading once the file is gone.';

		$featured = null === $attachment_id ? [] : $this->featured_for( $attachment_id );

		if ( [] !== $featured ) {
			$warnings[] = count( $featured ) > self::USAGE_LIMIT
				? sprintf(
					'More than %d content items use this as their featured image, starting with %s. Each of them loses its featured image.',
					self::USAGE_LIMIT,
					implode( ', ', array_slice( $featured, 0, self::USAGE_LIMIT ) )
				)
				: sprintf(
					'%d content item%s use this as their featured image and lose it: %s.',
					count( $featured ),
					1 === count( $featured ) ? '' : 's',
					implode( ', ', $featured )
				);
		}

		$promised = [ self::PROMISED_FIELD => true ];

		return new PlannedChange(
			$promised,
			$promised,
			[ self::PROMISED_FIELD ],
			$warnings,
			[
				'filename'   => $filename,
				'sizes'      => count( $sizes ),
				'featuredIn' => $featured,
			]
		);
	}

	/**
	 * Records nothing, because nothing here could be restored.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $current, $context );

		return null;
	}

	/**
	 * Asks WordPress to delete the attachment and its files.
	 *
	 * THE CAPABILITY IS ASKED FOR AGAIN HERE, after the plan was approved and
	 * before anything is deleted. The change engine resolves once for the preview
	 * and again for the apply, and between the two an operator's rights can be
	 * taken away. A check made only at plan time was true when it was made and
	 * false when it mattered.
	 *
	 * `true` is passed for the force argument deliberately. Left to WordPress the
	 * call would honour MEDIA_TRASH and leave a recoverable copy on some sites
	 * and not on others, and this operation's declared policies promise one
	 * behaviour to every operator who approves a plan.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed or Forbidden.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $planned );

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );

		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name a media item, so nothing was deleted.',
				'Request a fresh preview for the item you meant to delete.',
				[ 'plan approved' ]
			);
		}

		$this->require_deletable( $attachment_id, $context );

		$deleted = wp_delete_attachment( $attachment_id, true );

		if ( ! is_object( $deleted ) || ! isset( $deleted->ID ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress could not delete that media item, so this site may still hold some of its files.',
				'Open the WordPress media library, which shows what went wrong in full, and delete it from there.',
				[ 'plan approved', 'delete started' ],
				'There is no rollback for a delete. Open the media library to see what this site holds now.'
			);
		}

		clean_post_cache( $attachment_id );

		return $this->fields->targetKey( $attachment_id );
	}

	/**
	 * Confirms the item is actually gone.
	 *
	 * The inverse of every other media write's read-back: there, a target that
	 * cannot be read is the failure; here it is the whole point, and a target
	 * that still reads back is a delete WordPress reported as done and did not
	 * do.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted absence.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		unset( $context );

		$attachment_id = $this->fields->attachmentIdFromKey( $targetKey );

		if ( null !== $attachment_id && null !== $this->fields->read( $attachment_id ) ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'WordPress reported that media item as deleted but this site still holds it, so the result cannot be confirmed.',
				'Open the WordPress media library to see what this site holds now, and delete it from there if it is still listed.'
			);
		}

		return new TargetState( $targetKey, false, [ self::PROMISED_FIELD => true ] );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The method always throws.
	/**
	 * Refuses, because a delete is a one-way door.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException Always, with ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $restoreState, $context );

		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'Deleting a media item cannot be undone: its files are gone from this site and SiteHelm kept no copy of them.',
			'Upload the file again, or restore this site from a backup taken before the delete.'
		);
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Asserts the caller may delete this item, without saying which right is
	 * missing.
	 *
	 * @param int              $attachmentId The attachment identifier.
	 * @param OperationContext $context      The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	private function require_deletable( int $attachmentId, OperationContext $context ): void {
		if ( ! user_can( $context->userId, 'delete_post', $attachmentId ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not delete that media item.',
				'Ask a site administrator to grant your WordPress user the right to delete media.'
			);
		}
	}

	/**
	 * The content items using this attachment as their featured image.
	 *
	 * Asked through one indexed meta lookup rather than a scan of post content:
	 * the featured image is a single meta value, whereas searching every post
	 * body for the file's address is an unbounded table scan that would still
	 * miss a page builder storing the same reference in a row of its own. What
	 * this cannot see is said in a warning of its own instead, so the operator is
	 * never left believing this list is the whole answer.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return int[] The using content identifiers, ascending.
	 */
	private function featured_for( int $attachmentId ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$found = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => self::USAGE_LIMIT + 1,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => '_thumbnail_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => (string) $attachmentId,
			]
		);

		if ( ! is_array( $found ) ) {
			return [];
		}

		$ids = array_values( array_filter( array_map( 'intval', $found ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
