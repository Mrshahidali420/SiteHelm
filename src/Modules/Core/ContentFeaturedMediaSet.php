<?php
/**
 * Featured media assignment write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0017: featured media assignment. An agency operator sets a post's
 * featured image from an existing library asset during content work.
 *
 * The attachment id is validated while PLANNING, not after the write. That is
 * interpretation I7, and it is not defensive coding: WriteVerifier classifies a
 * value WordPress silently dropped as an ADJUSTMENT, so the write succeeds and
 * the operator is told the platform changed their value rather than that their
 * value was never valid. Its own test says so. An id that names a post which is
 * not an attachment would set a `_thumbnail_id` that renders nothing at all,
 * and the response would report success.
 *
 * Two things this deliberately does NOT do, named here rather than left to be
 * discovered:
 *
 * - It does not remove a featured image. The requirement is to set one from
 *   existing library assets, so `mediaId` declares `minimum: 1` and a request
 *   to clear the thumbnail is rejected by the schema rather than half-handled.
 * - It ships without its discovery counterpart. REQ-0017's matrix dependency
 *   names REQ-0021, media listing, which is Phase 4. `content-get` returns
 *   `featuredMedia`, so an id is discoverable for content that already has one,
 *   and the plan-time validation above makes a guessed id fail cleanly with
 *   invalid_input instead of setting a broken thumbnail. A known asymmetry.
 *
 * @package SiteHelm
 */
final class ContentFeaturedMediaSet implements WriteOperation {

	/**
	 * The post type a featured image must be.
	 */
	private const ATTACHMENT_TYPE = 'attachment';

	/**
	 * The one field this operation promises. Named as a constant because the
	 * promise, the snapshot and the read-back must all use the same key, and it
	 * has to match ContentFields::read()'s projection or verification would
	 * compare the promise against nothing.
	 */
	private const PROMISED_FIELD = 'featured_media';

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-featured-media-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-featured-media-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set the featured image of one existing content item to an existing media library attachment.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose featured image is being set.',
					],
					'mediaId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of an existing media library attachment to use as the featured image.',
					],
				],
				'required'             => [ 'id', 'mediaId' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-featured-media-set',
				'arguments' => [
					'id'      => 42,
					'mediaId' => 108,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised featured-image assignment, validating the reference.
	 *
	 * The refusal message is identical whether the id names nothing at all or
	 * names a post that is not an attachment. Distinguishing them would turn the
	 * response into a probe for which post ids exist on the site.
	 *
	 * No ksort: the promise holds exactly one key, so there is no order for a
	 * sort to make deterministic. ContentUpdate and ContentCreate ksort because
	 * they build up to three and five keys respectively.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the media
	 *                           identifier does not resolve to an attachment.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$media_id = (int) ( $input['mediaId'] ?? 0 );

		if ( ! $this->is_attachment( $media_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested media identifier does not name an attachment in this site\'s media library.',
				'Choose the identifier of an existing media library attachment and request a fresh preview.'
			);
		}

		$promised = [ self::PROMISED_FIELD => $media_id ];

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the featured image the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(). That records the
	 * five restorable post columns, none of which this write touches, and
	 * recording them would make a rollback promise to rewrite title, body,
	 * excerpt, status and slug that the operator never changed.
	 *
	 * A post with no featured image records 0, not null. Returning null would be
	 * read by SnapshotLifecycle as "nothing recoverable", and this operation's
	 * snapshot policy is `required`, so the plan would be refused with
	 * rollback_unavailable for the ordinary case of a post that simply has no
	 * featured image yet. 0 is a legal recorded value and restoring it is a
	 * deletion.
	 *
	 * The key order is sorted, matching ContentTarget::snapshotOf(): the restore
	 * state is stored as canonical JSON, so building it in a stable order keeps
	 * the stored row identical for identical state rather than dependent on the
	 * order this method happens to assign keys.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => (int) ( $current->fields[ self::PROMISED_FIELD ] ?? 0 ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Sets the promised featured image, and judges the result by measurement.
	 *
	 * The return value of set_post_thumbnail() is NOT a success signal, in BOTH
	 * directions, which is why the stored id is re-read instead:
	 *
	 * - It returns update_post_meta()'s false when the requested id is already
	 *   the stored one. Nothing failed; the promised state already held.
	 * - Worse, when the attachment produces no thumbnail markup — a PDF, an
	 *   attachment whose file is gone — core does not decline. It falls into
	 *   `delete_post_meta( $post->ID, '_thumbnail_id' )` and returns THAT, which
	 *   is TRUE whenever the post had a featured image to destroy. Judging by the
	 *   boolean would report a successful assignment for a write that erased the
	 *   operator's existing featured image and set nothing in its place.
	 *
	 * Re-reading the stored id is unambiguous about both. It is the same
	 * verify-by-measurement ContentTarget::restore_featured_media() uses, for the
	 * same reason.
	 *
	 * The already-correct case is answered before the call rather than after it.
	 * Re-issuing set_post_thumbnail() for an id already stored is not a no-op:
	 * core re-tests whether the attachment still renders and deletes the meta
	 * when it does not, so a repeat apply could destroy a featured image nothing
	 * asked to change. Skipping it is also what makes the declared
	 * `isIdempotent: true` true at the level of writes issued, not just outcomes.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id  = $this->fields->postIdFromTargetKey( $current->targetKey );
		$media_id = (int) $planned->payload[ self::PROMISED_FIELD ];

		if ( (int) ( $current->fields[ self::PROMISED_FIELD ] ?? 0 ) !== $media_id ) {
			set_post_thumbnail( $post_id, $media_id );
		}

		if ( (int) get_post_thumbnail_id( $post_id ) !== $media_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to use the requested attachment as a featured image.',
				'Choose an attachment WordPress can render as an image, then request a fresh preview.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded featured image back.
	 *
	 * ContentTarget::restoreFields() carries `featured_media` through
	 * RESTORABLE_MEDIA_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and content-rollback-apply.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Whether an identifier names an existing attachment on this site.
	 *
	 * Duck-typed rather than checked against WP_Post, matching
	 * ContentFields::read(), so the operation stays unit-testable without
	 * loading WordPress. Every member is checked before it is read: a post
	 * object that does not expose post_type is not evidence of an attachment,
	 * and reading it blind would treat a malformed object as one.
	 *
	 * There is no separate `$mediaId <= 0` guard, and its absence is deliberate
	 * rather than an omission. get_post() answers `$GLOBALS['post']` for any
	 * empty argument, so a mediaId of 0 can come back holding a real, unrelated
	 * post — including an attachment, on an attachment page. The identity check
	 * below is what refuses that, because the global post's id can never equal
	 * the 0 that was asked for. A leading `<= 0` return would shadow the identity
	 * check for that input and could then be deleted without any test noticing.
	 *
	 * @param int $mediaId The requested media identifier.
	 *
	 * @return bool True when the identifier names an attachment.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function is_attachment( int $mediaId ): bool {
		$media = get_post( $mediaId );

		return is_object( $media )
			&& isset( $media->ID, $media->post_type )
			&& (int) $media->ID === $mediaId
			&& self::ATTACHMENT_TYPE === (string) $media->post_type;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
