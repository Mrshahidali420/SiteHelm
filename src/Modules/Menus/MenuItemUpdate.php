<?php
/**
 * Navigation menu item update write operation.
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
 * REQ-0029: change SOME fields of one existing navigation menu item, leaving
 * every other field exactly as it was. An agency operator renames a link, points
 * it somewhere else, or moves it beneath another item.
 *
 * THE MERGE IS THE WHOLE OPERATION. `wp_update_nav_menu_item()` REPLACES the item
 * with the field array it is handed and DEFAULTS every field that array omits, so
 * handing it only the changed field does not update a title — it blanks the
 * address, the tooltip, the classes, the description, the XFN value, and the
 * window target on the way past. applyChange() therefore reads the item's whole
 * current field set first and overlays the planned changes onto it. The merge base
 * is MenuTarget::snapshotItem(),
 * which already produces exactly the `menu-item-*` set the restore path replays;
 * one list, so the merge and the rollback cannot drift apart.
 *
 * EVERY SUPPLIED FIELD IS GATED ON array_key_exists(), never on `??`. A supplied
 * '' means "make this empty" and an ABSENT key means "do not touch this"; `??`
 * collapses the two, and the collapsed form here writes an empty string over live
 * content. The same rule governs the replay in MenuTarget::restoreItem().
 *
 * THE SNAPSHOT IS REQUIRED rather than merely supported, unlike the sibling
 * create: reversing this write means putting the prior values back, and there is
 * nowhere else to read them from once the write has run.
 *
 * EVERY VALIDATION LIVES IN planChange(), which the engine runs in both phases,
 * so an item re-parented by someone else between preview and apply is refused at
 * apply rather than half-applied.
 *
 * Two checks here have no equivalent in a naive update: the cycle test below, and
 * the refusal of an address on an item whose address WordPress derives.
 *
 * @package SiteHelm
 */
final class MenuItemUpdate implements WriteOperation {

	/**
	 * The fields this operation can change, in the read path's own order.
	 *
	 * Fixed rather than derived from the input, because the payload is stored as
	 * canonical JSON and fingerprinted: two identical requests whose arguments
	 * arrived in different orders must produce the same plan. The order matches
	 * MenuFields::normalizeItem(), so a promise reads in the same order as the
	 * projection it will be verified against.
	 *
	 * `type`, `object`, and `objectId` are deliberately absent. Changing what an
	 * item points at is not an edit of that item; it is a different item, and
	 * allowing it here would let one call turn a page link into a custom link
	 * whose address the merge base still carries from the page.
	 *
	 * @var string[]
	 */
	private const FIELD_ORDER = [
		'title',
		'url',
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
	 * uses its own `menu-item-*` names. This map is the single place they meet.
	 *
	 * @var array<string, string>
	 */
	private const CORE_KEY_FOR_FIELD = [
		'title'       => 'menu-item-title',
		'url'         => 'menu-item-url',
		'parent'      => 'menu-item-parent-id',
		'position'    => 'menu-item-position',
		'target'      => 'menu-item-target',
		'classes'     => 'menu-item-classes',
		'description' => 'menu-item-description',
		'xfn'         => 'menu-item-xfn',
	];

	/**
	 * The `type` value whose address an operator owns rather than WordPress.
	 */
	private const CUSTOM_LINK_TYPE = 'custom';

	/**
	 * The longest value this operation will accept for one text field.
	 *
	 * A blast-radius limit on an unattended write rather than a storage limit,
	 * the same one the sibling create applies. The schema is the ONLY place it is
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
	 * `position` carries `minimum: 1` and that bound is LOAD BEARING rather than
	 * decorative, exactly as the sibling MenuItemsReorder documents it: there is no
	 * way to ask `wp_update_nav_menu_item()` for position 0, because core replaces
	 * a 0 with the menu's last position plus one and appends. An operator sending a
	 * zero-based 0 to mean "first" would get "last", so the bound refuses the value
	 * rather than honouring the opposite of it. SchemaValidator enforces `minimum`
	 * at the dispatcher, and presentation_fields() refuses it again for a caller
	 * that reaches the operation without going through one.
	 *
	 * @return OperationDefinition The definition registered for menu-item-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-item-update',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Change the label, address, placement, or presentation of one existing navigation menu item. Fields the request does not name are left exactly as they are.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'item'        => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the menu item to change.',
					],
					'title'       => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The label shown in the menu.',
					],
					'url'         => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The web address the item points at. Accepted only for a custom link, because every other item type takes its address from the content it names.',
					],
					'parent'      => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the menu item this one sits beneath. Use 0 for a top-level item.',
					],
					'position'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The item\'s position among its siblings, counting from 1.',
					],
					'target'      => [
						'type'        => 'string',
						'enum'        => [ MenuFields::TARGET_SAME_TAB, MenuFields::TARGET_NEW_TAB ],
						'description' => 'Send "_blank" to open the item in a new browser tab, or "_self" to open it in the same tab. An empty string is still accepted and means "_self", but it is deprecated and will stop being listed.',
					],
					'classes'     => [
						'type'        => 'array',
						'maxItems'    => MenuFields::MAX_ITEM_CLASSES,
						'items'       => [
							'type'      => 'string',
							'maxLength' => MenuFields::MAX_ITEM_CLASS_LENGTH,
						],
						'description' => 'CSS class names applied to the item. The supplied list replaces the item\'s current one.',
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
				'required'             => [ 'item' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ MenuTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Idempotent: the same request repeated leaves the item in the same
			// state, because every field it writes is an absolute value rather
			// than a relative one.
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-item-update',
				'arguments' => [
					'item'  => 412,
					'title' => 'Contact us',
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	/**
	 * Resolves the item the request names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved item.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolveItem( (int) ( $input['item'] ?? 0 ), $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $current->fields are the TargetState contract's own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Validates the whole request and names the fields that will change.
	 *
	 * @param TargetState          $current The resolved item.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$item_id = MenuTarget::itemIdFromKey( $current->targetKey );

		if ( null === $item_id ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The approved plan does not name a navigation menu item to change.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$menu_id = $this->fields->menuTermIdForItem( $item_id );

		if ( null === $menu_id ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The menu item does not belong to any navigation menu on this site, so it cannot be changed.',
				'Read the site\'s menus and retry with an identifier one of them lists.'
			);
		}

		$changes = $this->changed_fields( $current, $input, $item_id, $menu_id );

		if ( [] === $changes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The request names no field to change on the menu item.',
				'Retry with at least one of the title, address, parent, position, window target, classes, description, or relationship values.'
			);
		}

		$payload = $changes + [
			'item' => $item_id,
			'menu' => $menu_id,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $this->promise( $payload ), self::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the item's whole `wp_update_nav_menu_item()` field set.
	 *
	 * The WHOLE set, not the fields this request changes, because the rollback
	 * hands its recorded state straight back to a call that overwrites what it is
	 * given and defaults what it is not — see MenuTarget::RESTORABLE_ITEM_FIELDS.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, because MenuTarget::snapshotItem()
	 * only reads. The engine calls this once at preview to decide snapshot
	 * eligibility and again at apply.
	 *
	 * @param TargetState      $current The resolved item.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no menu item.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$item_id = MenuTarget::itemIdFromKey( $current->targetKey );

		return null === $item_id ? null : $this->targets->snapshotItem( $item_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Merges the planned changes over the item's current fields and writes them.
	 *
	 * THE MERGE BASE IS READ HERE rather than carried in the payload, and that is
	 * deliberate: a payload frozen at preview would replay whatever the item held
	 * then, so a field somebody else edited in between would be silently reverted
	 * by an operation that never claimed to touch it. Reading at apply means the
	 * write changes exactly the fields the plan named and nothing else.
	 *
	 * The data is slashed on the way in because `wp_update_nav_menu_item()`
	 * forwards to `wp_insert_post()`, which unslashes before storing — core's own
	 * caller hands it the raw `$_POST`. An unslashed title carrying an apostrophe
	 * would otherwise be stored a character short.
	 *
	 * @param TargetState      $current The resolved item.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The item's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$completed = [ 'plan approved', 'snapshot captured' ];
		$payload   = $planned->payload;
		$item_id   = (int) ( $payload['item'] ?? 0 );
		$menu_id   = (int) ( $payload['menu'] ?? 0 );
		$existing  = $this->targets->snapshotItem( $item_id );

		if ( null === $existing ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The menu item could not be read back before the change, so its other values cannot be preserved.',
				'Read the menu to confirm the item still exists, then preview the change again.',
				$completed
			);
		}

		$data = array_intersect_key( $existing, array_flip( MenuTarget::RESTORABLE_ITEM_FIELDS ) );

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

		$written = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $data ) );

		if ( is_wp_error( $written ) || (int) $written <= 0 ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change the navigation menu item.',
				'Retry the change, and if it is refused again edit the item under Appearance then Menus.',
				$completed
			);
		}

		// THE MERGE BASE CAN CARRY A 0, and core reads a 0 as "append". An item
		// stored first in its menu — which is where menu-item-create leaves the
		// first item of an empty menu — would otherwise be moved to LAST by a
		// request that only renamed it, and moved again by the rollback.
		MenuTarget::correctAppendedPosition( $item_id, $data );

		clean_post_cache( $item_id );

		return MenuTarget::itemTargetKey( $item_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Re-reads the changed item so the engine can verify it.
	 *
	 * @param string           $targetKey The item's target key.
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
	/**
	 * Puts the recorded field set back onto the item.
	 *
	 * Delegated whole to MenuTarget::restoreItem(), which gates every field on
	 * array_key_exists(): an ABSENT key is a field the state does not describe and
	 * must be left alone, while a recorded '' is a field that was empty and must
	 * be made empty again.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored item's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreItem( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->fields is the TargetState contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The fields the request actually asked to change, normalized.
	 *
	 * EVERY TEST IS array_key_exists(), never `??` and never truthiness. A
	 * supplied '' title, '' description, '' xfn, or 0 parent are all meaningful
	 * values; an absent key is the instruction to leave that field alone. This
	 * method is the first of the two places where collapsing those destroys data,
	 * the other being the replay in MenuTarget::restoreItem().
	 *
	 * A 0 POSITION IS NOT IN THAT LIST, and the difference matters. An empty title
	 * and a 0 parent are values WordPress stores as sent; a 0 position is one core
	 * REPLACES with "the end of the menu" before storing, so honouring it would put
	 * the item at the opposite end from where an operator asking for "first" meant.
	 * presentation_fields() refuses it, which is still an array_key_exists() gate
	 * rather than a truthiness one: the refusal is only reachable because a
	 * supplied 0 is seen at all.
	 *
	 * @param TargetState          $current The resolved item.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param int                  $item_id The item being changed.
	 * @param int                  $menu_id The menu that owns it.
	 *
	 * @return array<string, mixed> The normalized changes, keyed by payload field.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function changed_fields( TargetState $current, array $input, int $item_id, int $menu_id ): array {
		$changes = [];

		if ( array_key_exists( 'title', $input ) ) {
			$changes['title'] = sanitize_text_field( (string) $input['title'] );
		}

		if ( array_key_exists( 'url', $input ) ) {
			// Refused rather than ignored for anything but a custom link. Core
			// derives a post-type or taxonomy item's address from the content it
			// names and overwrites whatever is stored, so accepting the value
			// would report a change the rendered site never shows.
			if ( self::CUSTOM_LINK_TYPE !== (string) ( $current->fields['type'] ?? '' ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Only a custom link\'s web address can be changed; every other menu item takes its address from the content it points at.',
					'Change the address of the page, post, or category the item names, or replace the item with a custom link.'
				);
			}

			$changes['url'] = esc_url_raw( trim( (string) $input['url'] ) );
		}

		if ( array_key_exists( 'parent', $input ) ) {
			$changes['parent'] = $this->validated_parent( (int) $input['parent'], $item_id, $menu_id );
		}

		return $changes + $this->presentation_fields( $input );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The requested parent, once it is legal for this item in this menu.
	 *
	 * TWO checks, because they refuse different states. validateParent() answers
	 * whether the parent is an item of this menu at all, which is what
	 * stops a footer item from being nested under a header one. The ancestor
	 * walk answers whether the MOVE would close a loop, which validateParent()
	 * cannot see because both items are legitimately in the same menu. A loop is
	 * not a wrong menu; it is an item that is its own ancestor, which every tree
	 * walk over the result then either drops or never terminates on.
	 *
	 * An item made its own parent is the shortest such loop and needs no separate
	 * test: the walk starts at the requested parent and compares before stepping.
	 *
	 * @param int $parent_id The requested parent, 0 for top level.
	 * @param int $item_id   The item being moved.
	 * @param int $menu_id   The menu that owns it.
	 *
	 * @return int The validated parent.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function validated_parent( int $parent_id, int $item_id, int $menu_id ): int {
		if ( ! $this->fields->validateParent( $parent_id, $menu_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested parent is not an item of this menu, so the item cannot be placed beneath it.',
				'Read the menu, choose the identifier of an item it already lists, and retry.'
			);
		}

		if ( $this->is_descendant( $parent_id, $item_id, $menu_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested parent already sits beneath the item being moved, so the move would make the item its own ancestor.',
				'Move the intended parent out from under this item first, or choose a parent that is not one of its descendants.'
			);
		}

		return $parent_id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether one item sits anywhere beneath another in the same menu.
	 *
	 * The `$seen` set bounds the walk. It is not defensive decoration: a menu can
	 * already contain a loop written straight into the database, and this method
	 * runs BEFORE the write that would have to fix it, so it has to terminate on
	 * data it is not allowed to assume is sane. A pre-existing loop that does not
	 * pass through the moved item is reported as "not a descendant", which lets
	 * the operator carry on repairing the menu one item at a time.
	 *
	 * @param int $candidate The proposed parent, 0 for top level.
	 * @param int $item_id   The item being moved.
	 * @param int $menu_id   The menu that owns them.
	 *
	 * @return bool True when the candidate is the item or one of its descendants.
	 */
	private function is_descendant( int $candidate, int $item_id, int $menu_id ): bool {
		$parents = $this->parents_by_id( $menu_id );
		$seen    = [];
		$cursor  = $candidate;

		while ( $cursor > 0 ) {
			if ( $cursor === $item_id ) {
				return true;
			}

			if ( isset( $seen[ $cursor ] ) ) {
				return false;
			}

			$seen[ $cursor ] = true;
			$cursor          = $parents[ $cursor ] ?? 0;
		}

		return false;
	}

	/**
	 * Each item's stored parent within one menu, keyed by item identifier.
	 *
	 * The `> 0` filter is the module's standing hazard guard, applied here because
	 * this is a walk over nav menu item rows outside MenuFields::itemTree() and
	 * every such walk applies it. A `wp_get_nav_menu_items` filter can append a
	 * synthetic row carrying no `ID` — a plugin injecting a "Login" entry is the
	 * common case — and 0 is simultaneously "absent" and the root-parent sentinel,
	 * a conflation that produced an unbounded recursion in this module already. It
	 * is belt-and-braces rather than load-bearing HERE, and saying so is the point:
	 * is_descendant()'s own `while ( $cursor > 0 )` is what makes identifier 0
	 * unreachable as a lookup, so a synthetic row could only ever add a dead entry
	 * to this map. The filter stays so that a future reader who loosens that loop
	 * bound does not also have to rediscover this row shape.
	 *
	 * The stored parent is used rather than MenuFields::itemTree()'s rooted one,
	 * because itemTree() rewrites a looping parent to 0 in order to present the
	 * menu. Reading the rewritten value here would report an existing loop as
	 * top-level and let the move close a second one.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<int, int> Each item identifier's stored parent.
	 */
	private function parents_by_id( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$parents = [];

		foreach ( $items as $item ) {
			$id = is_object( $item ) ? (int) ( $item->ID ?? 0 ) : 0;

			if ( $id > 0 ) {
				$parents[ $id ] = (int) ( $item->menu_item_parent ?? 0 );
			}
		}

		return $parents;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The presentation fields the request supplied, normalized.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The supplied presentation fields.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function presentation_fields( array $input ): array {
		$fields = [];

		if ( array_key_exists( 'position', $input ) ) {
			// The schema's `minimum: 1` refused this already for anyone who came
			// through the dispatcher. It is refused a second time here, and named
			// separately from "the request names no field to change", because the
			// two are different mistakes: a caller sending a zero-based index needs
			// to be told the count starts at 1, not that they sent nothing.
			if ( (int) $input['position'] < 1 ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'A menu item position counts from 1, and 0 is not "first": WordPress reads it as "no position given" and moves the item to the end of the menu.',
					'Retry with the position the item should take among its siblings, counting from 1.'
				);
			}

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
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The after-state this operation promises, in the read path's field order.
	 *
	 * EXACTLY the fields the request changed, and no others. WriteVerifier
	 * compares each promised key against the read-back projection, so promising a
	 * field this write leaves to the merge base would make every partial update
	 * report an adjustment it did not make.
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
}
