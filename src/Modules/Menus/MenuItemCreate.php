<?php
/**
 * Navigation menu item creation write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

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
 * REQ-0028: add one item to an existing navigation menu. An agency operator adds
 * a "Contact" link, or a page the client just published, without opening
 * Appearance then Menus.
 *
 * THE TARGET IS THE MENU, NOT THE ITEM, because the item does not exist yet. That
 * is what binds an approved plan to one menu: a plan previewed against the primary
 * menu carries target key `menu:5`, and PlanAdmission refuses to apply it once the
 * caller points it at the footer menu. applyChange() then answers the concrete
 * `menu-item:{id}` key of what it created, exactly as MediaUpload answers
 * `attachment:{id}` after resolving `attachment:new`.
 *
 * THE SNAPSHOT IS THE MENU'S ITEM LIST, not the created item's fields. The change
 * engine freezes the restore state BEFORE the write runs, so the new item's
 * identifier can never appear in it; recording the identifiers that existed
 * beforehand lets restore() name the survivors by difference. That is also why
 * this operation's snapshot policy is Supported rather than Required: the reversal
 * is a deletion, not a field replay.
 *
 * EVERY VALIDATION LIVES IN planChange(), which the engine runs in both phases —
 * once to build the preview and again immediately before applyChange(). A check
 * moved into applyChange() would pass preview and refuse at apply, which is the
 * one outcome the preview contract exists to prevent.
 *
 * Ported from EMCP Tools' `class-nav-menu-abilities.php` (GPL-2.0-or-later):
 * `resolve_item_type()` and `op_add_item()`. The WordPress knowledge transfers;
 * the structure does not. EMCP validates and writes in one pass and returns
 * WP_Error, while this splits validation from execution so a refusal happens
 * before anything is created.
 *
 * @package SiteHelm
 */
final class MenuItemCreate implements WriteOperation {

	/**
	 * The `type` values that name a taxonomy rather than a post type.
	 *
	 * Ported verbatim in meaning from EMCP's `resolve_item_type()`. `tag` is the
	 * name operators use and `post_tag` is the name WordPress registered, so both
	 * are accepted and normalized to the latter; `taxonomy` and `term` are the
	 * generic forms that take the concrete taxonomy from `object`.
	 *
	 * @var string[]
	 */
	private const TAXONOMY_TYPES = [ 'category', 'tag', 'post_tag', 'taxonomy', 'term' ];

	/**
	 * The generic `type` values whose concrete name is carried by `object`.
	 *
	 * @var string[]
	 */
	private const GENERIC_TAXONOMY_TYPES = [ 'taxonomy', 'term' ];

	/**
	 * The planned payload's field order, which is also the promise's.
	 *
	 * Fixed rather than derived from the input, because the payload is stored as
	 * canonical JSON and fingerprinted: two identical requests whose arguments
	 * arrived in different orders must produce the same plan.
	 *
	 * @var string[]
	 */
	private const FIELD_ORDER = [
		'title',
		'url',
		'type',
		'object',
		'objectId',
		'parent',
		'position',
		'target',
		'classes',
		'description',
		'xfn',
	];

	/**
	 * Each planned field's `wp_update_nav_menu_item()` key.
	 *
	 * The two spellings are deliberate and are not interchangeable: SiteHelm's
	 * payload is camelCase like every other module's, and core's argument array
	 * uses its own `menu-item-*` names. This map is the single place they meet, so
	 * a rename on either side breaks here rather than silently dropping a field
	 * core never receives.
	 *
	 * @var array<string, string>
	 */
	private const CORE_KEY_FOR_FIELD = [
		'title'       => 'menu-item-title',
		'url'         => 'menu-item-url',
		'type'        => 'menu-item-type',
		'object'      => 'menu-item-object',
		'objectId'    => 'menu-item-object-id',
		'parent'      => 'menu-item-parent-id',
		'position'    => 'menu-item-position',
		'target'      => 'menu-item-target',
		'classes'     => 'menu-item-classes',
		'description' => 'menu-item-description',
		'xfn'         => 'menu-item-xfn',
	];

	/**
	 * The longest value this operation will accept for one text field.
	 *
	 * A blast-radius limit on an unattended write rather than a storage limit, the
	 * same one MediaMetaUpdate applies. The schema is the ONLY place it is
	 * enforced, so there is no second copy for it to drift against.
	 */
	private const MAX_VALUE_LENGTH = 65535;

	/**
	 * Constructs the operation.
	 *
	 * @param MenuFields $fields  The shared menu projection and validators.
	 * @param MenuTarget $targets The shared menus target resolver.
	 */
	public function __construct(
		private readonly MenuFields $fields,
		private readonly MenuTarget $targets
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for menu-item-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-item-create',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Add one item — a custom link, a page or post, or a category or tag archive — to an existing navigation menu.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'menu'        => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Identifier, slug, or name of the menu the item is added to.',
					],
					'type'        => [
						'type'        => 'string',
						'description' => 'What the item points at: "custom" for a link typed by hand, a post type such as "page" or "post", or "category", "tag", or "taxonomy" for an archive. Defaults to "custom".',
					],
					'object'      => [
						'type'        => 'string',
						'description' => 'The concrete post type or taxonomy name, required only when "type" is the generic "post_type", "taxonomy", or "term".',
					],
					'objectId'    => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the post or term the item points at. Not used by custom links.',
					],
					'title'       => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The label shown in the menu. Required for a custom link; defaults to the content\'s own title otherwise.',
					],
					'url'         => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The web address a custom link points at. Ignored for every other item type, which use the content\'s own address.',
					],
					'parent'      => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the menu item this one sits beneath. Use 0, the default, for a top-level item.',
					],
					'position'    => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Where the item sits among its siblings. Defaults to the end of the menu.',
					],
					'target'      => [
						'type'        => 'string',
						'enum'        => [ '', '_blank' ],
						'description' => 'Send "_blank" to open the item in a new browser tab, or an empty string, the default, to open it in the same tab.',
					],
					'classes'     => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'CSS class names applied to the item.',
					],
					'description' => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The short description some themes show beneath the item label.',
					],
					'xfn'         => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The XFN relationship value recorded on the link.',
					],
				],
				'required'             => [ 'menu' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ MenuTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Not idempotent: repeating the request adds a second item. The plan
			// token is what makes a retried request safe, not this flag.
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-item-create',
				'arguments' => [
					'menu'  => 'primary',
					'title' => 'Contact',
					'url'   => 'https://example.com/contact',
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	/**
	 * Resolves the menu the new item will be added to.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved menu.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolveMenu( (string) ( $input['menu'] ?? '' ), $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Validates the whole request and builds the item that will be created.
	 *
	 * EVERY refusal below happens here, before anything is written, and the engine
	 * runs this method again immediately before applyChange(). A content item that
	 * was deleted between preview and apply is therefore refused at apply too,
	 * rather than creating a menu entry pointing at nothing.
	 *
	 * @param TargetState          $current The resolved menu.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$menu_id = MenuTarget::menuIdFromKey( $current->targetKey );

		if ( null === $menu_id ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The approved plan does not name a navigation menu to add the item to.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$resolved = $this->resolve_item_type( $input );
		$parent   = (int) ( $input['parent'] ?? 0 );

		if ( ! $this->fields->validateParent( $parent, $menu_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested parent is not an item of this menu, so the new item cannot be placed beneath it.',
				'Read the menu, choose the identifier of an item it already lists, and retry.'
			);
		}

		$payload = [
			'menu'     => $menu_id,
			'type'     => $resolved['type'],
			'object'   => $resolved['object'],
			'objectId' => $resolved['objectId'],
			'parent'   => $parent,
		];

		$payload += $this->custom_link_fields( $resolved['type'], $input );
		$payload += $this->optional_fields( $input );

		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $this->promise( $payload ), self::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records which items the menu held before the write.
	 *
	 * NOT the created item's fields, because there is no created item yet: the
	 * engine freezes this state before applyChange() runs. The identifiers that
	 * existed beforehand are what let restore() name the one that did not.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE — the engine calls it once at
	 * preview to decide snapshot eligibility and again at apply — because it only
	 * reads the menu's items.
	 *
	 * @param TargetState      $current The resolved menu.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no menu.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$menu_id = MenuTarget::menuIdFromKey( $current->targetKey );

		if ( null === $menu_id ) {
			return null;
		}

		// Already in sorted key order: 'item_ids' sorts before 'menu_id'. Stated
		// rather than ksort()ed so the literal below cannot drift out of order
		// unnoticed the way a silently re-sorted array would.
		return [
			'item_ids' => $this->item_ids( $menu_id ),
			'menu_id'  => $menu_id,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Creates the planned item.
	 *
	 * The data is slashed on the way in because `wp_update_nav_menu_item()` forwards
	 * to `wp_insert_post()`, which unslashes before storing — core's own caller
	 * hands it the raw `$_POST`. An unslashed title carrying an apostrophe would
	 * otherwise be stored a character short.
	 *
	 * @param TargetState      $current The resolved menu.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created item's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$completed = [ 'plan approved', 'snapshot captured' ];
		$payload   = $planned->payload;
		$menu_id   = (int) ( $payload['menu'] ?? 0 );

		$data = [ 'menu-item-status' => 'publish' ];

		foreach ( self::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}

			$value = $payload[ $field ];

			$data[ self::CORE_KEY_FOR_FIELD[ $field ] ] = is_array( $value )
				? implode( ' ', array_map( 'strval', $value ) )
				: $value;
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $data ) );

		if ( is_wp_error( $item_id ) || (int) $item_id <= 0 ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to add the item to the navigation menu.',
				'Retry the change, and if it is refused again add the item under Appearance then Menus.',
				$completed
			);
		}

		clean_post_cache( (int) $item_id );

		return MenuTarget::itemTargetKey( (int) $item_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Re-reads the created item so the engine can verify it.
	 *
	 * @param string           $targetKey The created item's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Reverses the creation by deleting whatever the recorded state does not name.
	 *
	 * BY DIFFERENCE, not by a recorded identifier, because the restore state was
	 * frozen before the item existed. Every field is read with array_key_exists()
	 * rather than `??`: a recorded empty item list means "this menu held nothing",
	 * while an ABSENT list means the state does not describe the menu's contents at
	 * all, and treating the second as the first would delete every item in the
	 * menu. That is the `?? []` form of the collapse that nearly unpublished a live
	 * post in the core block, with a far larger blast radius.
	 *
	 * The deletion is forced rather than trashed. A trashed `nav_menu_item` keeps
	 * its term relationship, so the menu would still list an item the rollback
	 * reported as removed.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The menu's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the state
	 *                           describes no menu or no item list, or
	 *                           ErrorCode::ExecutionFailed when an item survived.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$menu_id = is_numeric( $restoreState['menu_id'] ?? null ) ? (int) $restoreState['menu_id'] : 0;

		if ( $menu_id <= 0 || ! is_array( $restoreState['item_ids'] ?? null ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not describe a navigation menu and the items it held, so the addition cannot be reversed.',
				'Remove the added item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$recorded = array_map( 'intval', array_filter( $restoreState['item_ids'], 'is_numeric' ) );
		$added    = array_values( array_diff( $this->item_ids( $menu_id ), $recorded ) );

		foreach ( $added as $item_id ) {
			wp_delete_post( $item_id, true );
			clean_post_cache( $item_id );
		}

		// Verified by re-reading rather than by trusting wp_delete_post()'s answer,
		// because a `before_delete_post` handler can veto a deletion the return
		// value still reports as attempted.
		if ( [] !== array_intersect( $this->item_ids( $menu_id ), $added ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The added navigation menu item is still present after the reversal.',
				'Remove the added item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		return MenuTarget::menuTargetKey( $menu_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves what the new item points at, and refuses when it points at nothing.
	 *
	 * Ported from EMCP's `resolve_item_type()`. The existence checks are the point:
	 * `wp_update_nav_menu_item()` happily stores a `post_type` item naming a post
	 * that was never published, and the menu then renders a link to nowhere.
	 *
	 * The post-type MATCH check is the one that is easy to leave out and expensive
	 * to omit. Without it, `type: post` with the identifier of a page creates an
	 * item WordPress resolves against the wrong post type, so the label and the
	 * address disagree with each other on the rendered site.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function resolve_item_type( array $input ): array {
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );

		if ( '' === $type || 'custom' === $type ) {
			return [
				'type'     => 'custom',
				'object'   => 'custom',
				'objectId' => 0,
			];
		}

		$object_id = (int) ( $input['objectId'] ?? 0 );

		if ( in_array( $type, self::TAXONOMY_TYPES, true ) ) {
			return $this->resolve_taxonomy_item( $type, $object_id, $input );
		}

		return $this->resolve_post_type_item( $type, $object_id, $input );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves a taxonomy archive item.
	 *
	 * @param string               $type      The requested type, already sanitized.
	 * @param int                  $object_id The requested term identifier.
	 * @param array<string, mixed> $input     The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function resolve_taxonomy_item( string $type, int $object_id, array $input ): array {
		$taxonomy = match ( true ) {
			'tag' === $type => 'post_tag',
			in_array( $type, self::GENERIC_TAXONOMY_TYPES, true ) => sanitize_key( (string) ( $input['object'] ?? '' ) ),
			default => $type,
		};

		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not have a content grouping of the requested kind, so no menu item can point at one.',
				'Retry with a grouping the site registers, such as "category" or "tag".'
			);
		}

		$term = get_term( $object_id, $taxonomy );

		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No item of that grouping on this site matches the supplied identifier, so the menu item would point at nothing.',
				'List the site\'s categories or tags and retry with an identifier one of them carries.'
			);
		}

		return [
			'type'     => 'taxonomy',
			'object'   => $taxonomy,
			'objectId' => (int) $term->term_id,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves a post-type content item.
	 *
	 * @param string               $type      The requested type, already sanitized.
	 * @param int                  $object_id The requested content identifier.
	 * @param array<string, mixed> $input     The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function resolve_post_type_item( string $type, int $object_id, array $input ): array {
		$post_type = 'post_type' === $type ? sanitize_key( (string) ( $input['object'] ?? '' ) ) : $type;

		if ( ! post_type_exists( $post_type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not have content of the requested kind, so no menu item can point at it.',
				'Retry with a content kind the site registers, such as "page" or "post".'
			);
		}

		$post = get_post( $object_id );

		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No content on this site matches the supplied identifier, so the menu item would point at nothing.',
				'List the site\'s content and retry with an identifier one of the entries carries.'
			);
		}

		if ( (string) ( $post->post_type ?? '' ) !== $post_type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested content kind does not match the kind of the content the identifier names.',
				'Retry with the content kind that entry actually is, or with an identifier of the requested kind.'
			);
		}

		return [
			'type'     => 'post_type',
			'object'   => $post_type,
			'objectId' => (int) $post->ID,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The title and address a custom link carries, or nothing for any other type.
	 *
	 * Both are required for a custom link and for nothing else, which is EMCP's
	 * rule and core's behaviour: every other item type takes its label and its
	 * address from the content it names, so accepting a `url` for one would record
	 * a value WordPress overwrites on the next save.
	 *
	 * @param string               $type  The resolved item type.
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The custom-link fields.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function custom_link_fields( string $type, array $input ): array {
		if ( 'custom' !== $type ) {
			// A title is still honoured, because a post-type item may legitimately
			// be labelled differently from the content it points at.
			return array_key_exists( 'title', $input )
				? [ 'title' => sanitize_text_field( (string) $input['title'] ) ]
				: [];
		}

		$url = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ) );

		if ( '' === $url ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A custom link menu item needs the web address it points at.',
				'Retry with the address the link should open, or name a page, post, or category instead.'
			);
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( '' === $title ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A custom link menu item needs a title, because there is no content for it to borrow one from.',
				'Retry with the label the menu should show.'
			);
		}

		return [
			'title' => $title,
			'url'   => $url,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The optional presentation fields the request actually supplied.
	 *
	 * Gated on array_key_exists() rather than on truthiness, so an explicit empty
	 * description is recorded as one and an absent description is left to core's
	 * default. `position` 0 and `target` '' are both meaningful values that a
	 * truthiness test would drop.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The supplied optional fields.
	 */
	private function optional_fields( array $input ): array {
		$fields = [];

		if ( array_key_exists( 'position', $input ) ) {
			$fields['position'] = (int) $input['position'];
		}

		if ( array_key_exists( 'target', $input ) ) {
			// Anything other than the one window value WordPress understands is
			// normalized to "same tab" rather than refused, because core stores the
			// value verbatim into the rendered `target` attribute.
			$fields['target'] = '_blank' === $input['target'] ? '_blank' : '';
		}

		if ( array_key_exists( 'classes', $input ) && is_array( $input['classes'] ) ) {
			$fields['classes'] = array_values(
				array_filter(
					array_map(
						static fn( mixed $name ): string => sanitize_html_class( (string) $name ),
						$input['classes']
					),
					static fn( string $name ): bool => '' !== $name
				)
			);
		}

		if ( array_key_exists( 'description', $input ) ) {
			$fields['description'] = sanitize_text_field( (string) $input['description'] );
		}

		if ( array_key_exists( 'xfn', $input ) ) {
			$fields['xfn'] = sanitize_text_field( (string) $input['xfn'] );
		}

		return $fields;
	}

	/**
	 * The after-state this operation promises, in the read path's field order.
	 *
	 * EXACTLY the fields the payload determined, and no others. WriteVerifier
	 * compares each promised key against the read-back projection, so promising a
	 * field WordPress derives — a post-type item's `url`, which is its permalink —
	 * would make every such creation report an adjustment it did not make.
	 *
	 * @param array<string, mixed> $payload The normalized payload.
	 *
	 * @return array<string, mixed> The promised after-state.
	 */
	private function promise( array $payload ): array {
		$promise = [];

		foreach ( self::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$promise[ $field ] = $payload[ $field ];
			}
		}

		return $promise;
	}

	/**
	 * The identifiers of every item one menu currently holds, sorted.
	 *
	 * The `> 0` filter is the module's standing hazard guard, not a formality: a
	 * `wp_get_nav_menu_items` filter can append a synthetic row carrying no `ID`,
	 * and 0 is simultaneously "absent" and the root-parent sentinel. An unfiltered
	 * 0 here would enter the recorded item list, survive the difference in
	 * restore(), and be handed to wp_delete_post( 0, true ).
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return int[] The item identifiers, ascending.
	 */
	private function item_ids( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$ids = [];

		foreach ( $items as $item ) {
			$id = is_object( $item ) ? (int) ( $item->ID ?? 0 ) : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
}
