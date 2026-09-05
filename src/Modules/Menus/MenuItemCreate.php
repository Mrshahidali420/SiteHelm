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
 * Validation is split from execution, rather than validating and writing in one
 * pass, so a refusal happens before anything is created.
 *
 * @package SiteHelm
 */
final class MenuItemCreate implements WriteOperation {

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
	 * The identifier this instance created, once applyChange() has created one.
	 *
	 * THE ONLY OWNERSHIP RECORD THIS OPERATION HAS. The restore state is frozen
	 * before the item exists, so nothing durable can name it; the engine
	 * compensates a failed write on THIS instance, which is what lets restore()
	 * delete the item this change created rather than every item the menu has
	 * gained since. Deliberately not readonly and not part of any recorded state.
	 *
	 * @var int|null
	 */
	private ?int $created_item_id = null;

	/**
	 * Resolves what the requested item points at.
	 *
	 * Built here rather than injected because it is an implementation detail of
	 * this operation's validation and has no collaborators of its own; the public
	 * constructor signature MenusModule registers stays exactly as it was.
	 *
	 * @var MenuItemType
	 */
	private readonly MenuItemType $item_types;

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
		$this->item_types = new MenuItemType();
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
						'maxLength'   => MenuFields::MAX_MENU_REFERENCE_LENGTH,
						'description' => 'Identifier, slug, or name of the menu the item is added to.',
					],
					'type'        => [
						'type'        => 'string',
						'maxLength'   => MenuFields::MAX_OBJECT_NAME_LENGTH,
						'description' => 'What the item points at: "custom" for a link typed by hand, a post type such as "page" or "post", or "category", "tag", or "taxonomy" for an archive. Defaults to "custom".',
					],
					'object'      => [
						'type'        => 'string',
						'maxLength'   => MenuFields::MAX_OBJECT_NAME_LENGTH,
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
						'description' => 'The item\'s position among its siblings, counting from 1. Send 0, the default, to add the item at the end of the menu.',
					],
					'target'      => [
						'type'        => 'string',
						'enum'        => [ MenuFields::TARGET_SAME_TAB, MenuFields::TARGET_NEW_TAB ],
						'description' => 'Send "_blank" to open the item in a new browser tab, or "_self", the default, to open it in the same tab. An empty string is still accepted and means "_self", but it is deprecated and will stop being listed.',
					],
					'classes'     => [
						'type'        => 'array',
						'maxItems'    => MenuFields::MAX_ITEM_CLASSES,
						'items'       => [
							'type'      => 'string',
							'maxLength' => MenuFields::MAX_ITEM_CLASS_LENGTH,
						],
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
			moreExamples: [
				// A page item carries no url and no title of its own: it
				// follows the page, so a link typed by hand would go stale the
				// moment the page moved.
				[
					'operation' => 'menu-item-create',
					'arguments' => [
						'menu'     => 'primary',
						'type'     => 'page',
						'objectId' => 42,
					],
				],
				// A term archive names the taxonomy through "object", because
				// "category" and "tag" are the only two that can be named on
				// their own.
				[
					'operation' => 'menu-item-create',
					'arguments' => [
						'menu'     => 'primary',
						'type'     => 'taxonomy',
						'object'   => 'product_cat',
						'objectId' => 17,
					],
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

		$resolved = $this->item_types->resolve( $input );
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
	 * `$completed` NAMES THE SNAPSHOT ONLY WHEN THERE IS ONE. The snapshot policy
	 * is Supported rather than Required, so apply can be reached having captured
	 * nothing, and declaring the step unconditionally makes the refusal envelope
	 * assert a recovery position that does not exist. The test is captureSnapshot()
	 * itself, which the contract guarantees is side-effect free.
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
		$completed = [ 'plan approved' ];

		if ( null !== $this->captureSnapshot( $current, $context ) ) {
			$completed[] = 'snapshot captured';
		}

		$payload = $planned->payload;
		$menu_id = (int) ( $payload['menu'] ?? 0 );

		$data = [ 'menu-item-status' => 'publish' ];

		foreach ( self::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}

			$value = 'target' === $field
				? MenuFields::storedTarget( $payload[ $field ] )
				: $payload[ $field ];

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

		$this->created_item_id = (int) $item_id;

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
	 * THE DIFFERENCE NAMES CANDIDATES, NEVER THE DELETION SET. This operation adds
	 * exactly ONE item, so at most one identifier in it can be this change's doing.
	 * Deleting the whole difference force-deletes — no trash, no recovery — items
	 * another operator created while this change was in flight. owned_addition()
	 * narrows it to the one item this change is known to have created, and REFUSES
	 * the rollback when it cannot tell which that is.
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
	 *                           describes no menu or no item list, or names no item
	 *                           this change can be shown to have added, or
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
		$owned    = $this->owned_addition( $added );

		if ( null === $owned ) {
			return MenuTarget::menuTargetKey( $menu_id );
		}

		wp_delete_post( $owned, true );
		clean_post_cache( $owned );

		// Verified by re-reading rather than by trusting wp_delete_post()'s answer,
		// because a `before_delete_post` handler can veto a deletion the return
		// value still reports as attempted.
		if ( in_array( $owned, $this->item_ids( $menu_id ), true ) ) {
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * The one item of the difference this change is known to have added.
	 *
	 * Three answers, and the third is the point:
	 *
	 * 1. `null` when nothing of ours is there to remove — an empty difference, or
	 *    one that does not hold our identifier, whose every member is somebody
	 *    else's item and must be left alone.
	 * 2. The identifier applyChange() recorded, when this instance created one.
	 * 3. A REFUSAL when there is no such record and the difference names more than
	 *    one item. Nothing durable names our item, so a rollback in a later request
	 *    has only the difference to go on, and force-deleting all of it to be sure
	 *    of catching ours is the failure this method exists to prevent.
	 *
	 * @param int[] $added The identifiers the menu holds and the snapshot does not.
	 *
	 * @return int|null The item to delete, or null when there is none.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	private function owned_addition( array $added ): ?int {
		if ( [] === $added ) {
			return null;
		}

		if ( null !== $this->created_item_id ) {
			return in_array( $this->created_item_id, $added, true ) ? $this->created_item_id : null;
		}

		if ( 1 !== count( $added ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The navigation menu has gained more than one item since the snapshot was taken, so the one this change added cannot be told apart from the others and none of them was removed.',
				'Remove the added item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		return $added[0];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The title and address a custom link carries, or nothing for any other type.
	 *
	 * Both are required for a custom link and for nothing else, which is core's
	 * behaviour: every other item type takes its label and its
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
	 * default. `position` 0 and a same-tab `target` are both meaningful values
	 * that a truthiness test would drop.
	 *
	 * `position` KEEPS ITS `minimum: 0` HERE, unlike the sibling MenuItemUpdate,
	 * and the two are not inconsistent. `wp_update_nav_menu_item()` reads a 0 as
	 * "add this at the end", which is exactly what this operation documents 0 to
	 * mean and what a creation with no position in mind wants. The update refuses
	 * a 0 because there the same substitution MOVES an item that already has a
	 * place, and an operator sending a zero-based "first" would get "last".
	 * Recording the supplied 0 is still meaningful: it is what makes the plan
	 * promise "at the end" rather than promising nothing about the position.
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
			// The PUBLISHED token is recorded, not the value core will store, so
			// the promise reads the way the verification read of the same item
			// will. Anything other than the one window value WordPress understands
			// — a deprecated '', a stray 'popup' — is normalized to "same tab"
			// rather than refused, because core stores the value verbatim into the
			// rendered `target` attribute.
			$fields['target'] = MenuFields::targetToken( $input['target'] );
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
