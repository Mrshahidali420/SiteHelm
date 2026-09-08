<?php
/**
 * Save a content item again without changing anything in it.
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
use SiteHelm\Contracts\SideEffect;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * Saves one content item again with every field exactly as it already is, so
 * that everything watching for a save recalculates what it keeps beside the
 * item.
 *
 * WHY A SITE NEEDS THIS. A great deal of what a WordPress site shows is not
 * stored on the post at all. It is worked out once, when the post is saved, and
 * kept somewhere else: an SEO plugin's score and its sitemap entry, a page
 * builder's compiled CSS, a caching plugin's copy of the page, a translation
 * plugin's index. Every one of those is refreshed by the same signal — the post
 * being saved — and none of them is refreshed by anything else. So a change
 * that reached the post any other way leaves them stale: content restored from
 * a backup, a row edited straight in the database, a plugin activated after the
 * post was written, a bulk import. The post is right and everything computed
 * from it is wrong, and the only fix was to open the editor and press Update.
 *
 * NOTHING IN THE ITEM CHANGES, AND THAT IS THE PROMISE. The payload is empty:
 * this operation asks WordPress to store the row it already has. What is
 * promised is that the title, slug, content, excerpt, type and status read back
 * byte for byte the way they went in. That is not a formality. WordPress
 * expects the data handed to wp_update_post() to be escaped, and unescapes it
 * itself before storing; a re-save that passed the post back the way it was
 * read would have that unescaping applied to text which was never escaped, and
 * every backslash in the content would be eaten. The item would be saved,
 * every hook would fire, the operation would report success, and the content
 * would be quietly damaged. So the row is escaped on the way in, and the
 * read-back proves it survived.
 *
 * WHAT IS NOT PROMISED. The item's meta and its terms are deliberately left out
 * of the promise, because the plugins this operation exists to wake up write
 * exactly there — a recalculated SEO score is stored as meta, and storing it is
 * the operation working. `post_modified_gmt` is left out for the same reason:
 * moving it is the point.
 *
 * A SNAPSHOT IS STILL TAKEN. Nothing this operation does needs undoing, but a
 * plugin's save routine belongs to that plugin, and a badly written one can
 * rewrite the content it was handed. The snapshot is the ordinary content
 * snapshot, so a save that damaged the item can be rolled back like any other
 * write. What a rollback cannot do is un-fire the hooks, and the refusal
 * wording for the rest of the module already says so.
 *
 * @package SiteHelm
 */
final class ContentResave implements WriteOperation {

	public const ID = 'content-resave';

	/**
	 * The fields whose values must come back unchanged.
	 *
	 * This is FIELD_ORDER minus the three that are expected to move:
	 * `post_modified_gmt`, which moving is the point of; and `meta` and `terms`,
	 * which are where the plugins being woken up write their recalculated
	 * answers. `post_parent`, `menu_order`, `page_template` and
	 * `featured_media` are absent for a different reason — they are not text,
	 * so the escaping fault this promise exists to catch cannot reach them.
	 *
	 * @var string[]
	 */
	private const UNCHANGED_FIELDS = [
		'post_type',
		'post_status',
		'post_title',
		'post_name',
		'post_content',
		'post_excerpt',
	];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-resave.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Save one content item again with every field exactly as it is, so that anything which recalculates on save — an SEO plugin\'s score and sitemap entry, a page builder\'s compiled CSS, a caching plugin\'s stored copy — works itself out again. Nothing in the item changes.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to save again.',
					],
				],
				'required'             => [ 'id' ],
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
				'operation' => self::ID,
				'arguments' => [ 'id' => 42 ],
			],
			sideEffects: [ SideEffect::RunsInstalledCode ],
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
	 * Promises that the item comes back exactly as it went in.
	 *
	 * The payload is empty because there is nothing to write: what this
	 * operation asks for is the save itself. The promise is therefore about
	 * what must NOT move, and it is built from the state that was just read so
	 * that the read-back is compared against the item's own values rather than
	 * against anything this operation invented.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The empty payload and the unchanged after-state.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $input, $context );

		$unchanged = [];
		foreach ( self::UNCHANGED_FIELDS as $field ) {
			$unchanged[ $field ] = $current->fields[ $field ] ?? '';
		}

		return new PlannedChange(
			[],
			$unchanged,
			ContentFields::FIELD_ORDER,
			[ 'Everything that recalculates when this item is saved runs now, and that includes plugin code this site\'s owner installed rather than anything SiteHelm wrote. On a large item with several such plugins the save is slower than an ordinary write.' ]
		);
	}

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $context );

		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Stores the item's own row again, escaped.
	 *
	 * The whole row is passed rather than the identifier alone. Handing
	 * wp_update_post() nothing but an ID makes it read the stored row itself and
	 * merge the result, and the row it reads is unescaped while the merged data
	 * is unescaped a second time on the way to storage — which is the fault this
	 * operation's promise exists to catch, arrived at through the shortest way of
	 * writing it.
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
		unset( $planned, $context );

		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );
		$row     = get_post( $post_id, ARRAY_A );

		if ( ! is_array( $row ) || ! isset( $row['ID'] ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The content item could not be read back for saving.',
				'Check that the item still exists and request a fresh preview.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$updated = wp_update_post( wp_slash( $row ), true );

		if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to save the content item.',
				'Generate a fresh preview and retry; the item is recorded for rollback and nothing was changed.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( (int) $updated );
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
	 * Writes a recorded snapshot back.
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
		unset( $context );

		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
