<?php
/**
 * Content creation write operation.
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
 * REQ-0013: content creation. An agency operator drafts new client content
 * through an AI client without touching wp-admin.
 *
 * The capability checks are split, because a definition cannot express a
 * conditional or per-post-type capability: the declared `edit_posts` on the
 * definition is a floor the policy engine enforces regardless of the
 * requested type, and this operation checks the requested post type's own
 * capabilities on top of it — `create_posts` always, and `publish_posts`
 * whenever the requested status is not draft-like. A custom post type
 * registered with its own `capability_type` maps those to distinct
 * capability names (for example `edit_products`), so the generic
 * `edit_posts` / `publish_posts` capabilities are never substituted for
 * them. The contract permits exactly this — the policy engine may add
 * restrictions on top of the declared capabilities, never fewer.
 *
 * @package SiteHelm
 */
final class ContentCreate implements WriteOperation {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-create',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Create one new content item with a title, body, excerpt, initial status, and optionally its place in a hand-ordered content type.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'      => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A public content type this site registers, for example post or page.',
					],
					'title'     => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Title of the new content item.',
					],
					'content'   => [
						'type'        => 'string',
						'maxLength'   => 500000,
						'description' => 'Body of the new content item.',
					],
					'excerpt'   => [
						'type'        => 'string',
						'maxLength'   => 5000,
						'description' => 'Excerpt of the new content item.',
					],
					'status'    => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
						'description' => 'Initial status. Requesting publish additionally requires the publish capability.',
					],
					'menuOrder' => [
						'type'        => 'integer',
						'description' => 'Where this item sits when its content type is ordered by hand rather than by date. Lower numbers come first, and 0 is the default every item starts at. Only content types registered with page-attribute support, and archives a theme sorts by it, take any notice.',
					],
					'slug'      => [
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'The address this item is reached at. Left out, WordPress derives one from the title. A slug already in use is silently suffixed, so the preview reports the slug that will actually be stored rather than the one asked for.',
					],
					'parent'    => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The item this one sits under, or 0 for none. Only hierarchical content types take any notice.',
					],
					'template'  => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'The page template the active theme renders this item through, given as its filename. Send "default" for the theme\'s ordinary rendering. A filename the theme does not offer is refused in the preview, naming the ones it does.',
					],
				],
				'required'             => [ 'type', 'title', 'status' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-create',
				'arguments' => [
					'type'   => 'post',
					'title'  => 'Launch announcement',
					'status' => 'draft',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields    $fields    The normalized field map.
	 * @param ContentTarget    $targets   Shared target resolution.
	 * @param ContentPlacement $placement Shared placement checks.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
		private readonly ContentPlacement $placement,
	) {
	}

	/**
	 * A creation's target does not exist yet, so it resolves to the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	/**
	 * Builds the promised new content item.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an
	 *                           unavailable content type, or
	 *                           ErrorCode::Forbidden for an unpermitted create
	 *                           or an unpermitted publish.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$type        = (string) ( $input['type'] ?? '' );
		$type_object = $this->resolve_creatable_type( $type );
		if ( null === $type_object ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested content type is not available for creation on this site.',
				'Choose a public content type this site registers.'
			);
		}

		if ( ! user_can( $context->userId, $type_object->cap->create_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not create this content type.',
				'Ask a site administrator to grant the capability this content type requires.'
			);
		}

		$status = (string) $input['status'];
		if ( ! in_array( $status, ContentFields::DRAFT_LIKE_STATUSES, true )
			&& ! user_can( $context->userId, $type_object->cap->publish_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish content.',
				'Create the item as a draft, or ask a site administrator to grant the publish capability.'
			);
		}

		$parent = (int) ( $input['parent'] ?? 0 );
		$this->placement->requireParent( $parent, $type );

		$promised = [
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => $this->fields->sanitizeForSave( 'post_title', (string) ( $input['title'] ?? '' ), $context->userId ),
			'post_content' => $this->fields->sanitizeForSave( 'post_content', (string) ( $input['content'] ?? '' ), $context->userId ),
			'post_excerpt' => $this->fields->sanitizeForSave( 'post_excerpt', (string) ( $input['excerpt'] ?? '' ), $context->userId ),
			'menu_order'   => (int) ( $input['menuOrder'] ?? 0 ),
			'post_parent'  => $parent,
		];

		$detail = [];

		// Only when it was asked for. Left out, WordPress derives the slug from
		// the title, and there is no honest way to promise a value this operation
		// did not choose.
		if ( array_key_exists( 'slug', $input ) ) {
			$requested             = (string) $input['slug'];
			$resolved              = $this->placement->requireSlug( $requested, 0, $status, $type, $parent );
			$promised['post_name'] = $resolved;
			$detail                = $this->placement->slugDetail( $requested, $resolved );
		}

		if ( array_key_exists( 'template', $input ) ) {
			$promised['page_template'] = $this->placement->requireTemplate( (string) $input['template'], $type );
		}

		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER, [], $detail );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * The contract's `supported` snapshot policy covers exactly this: creation
	 * style writes proceed without a snapshot, and the result then omits the
	 * rollback reference.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	/**
	 * Inserts the promised content item.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$created = wp_insert_post( wp_slash( $planned->payload ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the content item.',
				'Generate a fresh preview and retry; no content item was created.',
				[ 'plan approved' ]
			);
		}

		return $this->fields->targetKey( (int) $created );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the created item for verification.
	 *
	 * @param string           $targetKey The created target key.
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

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	/**
	 * A creation cannot be reversed by restoring prior state, because there was
	 * none. Removal is a separate requirement in a later phase.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly created content item has no prior state to restore.',
			'Move the item to the trash in WordPress if it should not exist.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Resolves the post type object this creation targets, refusing rather
	 * than guessing when it cannot be resolved with the capabilities this
	 * operation needs to check.
	 *
	 * Falling back to a generic capability when a type's own `cap` object
	 * cannot be read would let a caller create content of a type they hold
	 * no capability for at all, so an unresolvable type is refused instead of
	 * silently treated as creatable.
	 *
	 * The capability guard holds SIX conditions, and they are not equally load
	 * bearing. Five change the answer and each has a `unresolvableTypeObjects`
	 * case that fails when it is deleted — including `publish_posts is not a
	 * string`, which was added after a reviewer deleted that condition and the
	 * whole file stayed green: every other case refused at an earlier condition,
	 * so the last one was never the one doing the work.
	 *
	 * `! is_object( $object->cap )` is DEFENCE IN DEPTH and cannot currently
	 * change the answer, because isset() on a property of a scalar is already
	 * false and the next condition catches that shape anyway. It is kept
	 * deliberately: it is free, it states the intended shape, and an edit that
	 * reordered or narrowed the isset() conditions would make it reachable again.
	 * Do not delete it on the strength of a surviving mutation.
	 *
	 * @param string $type The requested content type.
	 *
	 * @return object|null The post type object, or null when it is not
	 *                     creatable through this operation.
	 */
	private function resolve_creatable_type( string $type ): ?object {
		if ( '' === $type || ! post_type_exists( $type ) ) {
			return null;
		}
		$object = get_post_type_object( $type );
		if ( ! is_object( $object ) || ! isset( $object->public ) || true !== $object->public ) {
			return null;
		}
		if ( ! isset( $object->cap ) || ! is_object( $object->cap )
			|| ! isset( $object->cap->create_posts ) || ! is_string( $object->cap->create_posts )
			|| ! isset( $object->cap->publish_posts ) || ! is_string( $object->cap->publish_posts ) ) {
			return null;
		}

		return $object;
	}
}
