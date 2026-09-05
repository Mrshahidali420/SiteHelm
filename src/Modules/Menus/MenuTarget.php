<?php
/**
 * Shared target resolution for the menus write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The things every menus write does identically: name a target, resolve one,
 * re-read it for verification, and record or replay a whole menu item.
 *
 * Extracted for the reason MediaTarget was: four writes land in this module and
 * each would otherwise carry its own copy of the target-key spelling and the
 * restore field list, and the copy that drifts is the one deciding which values
 * a rollback silently fails to restore.
 *
 * WordPress is not loaded in unit tests, so every core answer is guarded on its
 * SHAPE rather than on `instanceof WP_Term` / `instanceof WP_Post`, exactly as
 * MenuFields does. `(int)` on a WP_Error is a fatal, and a filter can substitute
 * one on a site that is otherwise healthy.
 *
 * @package SiteHelm
 */
final class MenuTarget {

	/**
	 * The one capability WordPress gates menu administration on.
	 *
	 * Checked here as well as declared on every menus definition, because a
	 * definition's requiredCapabilities is the gateway's gate and this is the
	 * module's own: a write reached through any other path must still refuse.
	 */
	public const REQUIRED_CAPABILITY = 'edit_theme_options';

	/**
	 * Every `wp_update_nav_menu_item()` field a menu item snapshot records.
	 *
	 * THE WHOLE SET, not the subset a given write touches, because
	 * `wp_update_nav_menu_item()` OVERWRITES every field it is handed and
	 * DEFAULTS every field it is not. An unrecorded field is therefore not an
	 * untouched field; it is a field the restore resets to core's default. That
	 * is the exact difference between this list and MediaTarget's three, where
	 * the write mechanism is wp_update_post() and an omitted column is genuinely
	 * left alone.
	 *
	 * `menu-item-status` is present and carries the item's OWN stored status. A
	 * nav menu item is a post, and restoring one without a status would leave
	 * wp_update_nav_menu_item() to resolve '' — the same collapse that nearly
	 * unpublished a live post in the core block. Recording a hardcoded 'publish'
	 * is the opposite failure: it publishes a draft item as a side effect of an
	 * update or a rollback that never claimed to touch its visibility.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_ITEM_FIELDS = [
		'menu-item-attr-title',
		'menu-item-classes',
		'menu-item-db-id',
		'menu-item-description',
		'menu-item-object',
		'menu-item-object-id',
		'menu-item-parent-id',
		'menu-item-position',
		'menu-item-status',
		'menu-item-target',
		'menu-item-title',
		'menu-item-type',
		'menu-item-url',
		'menu-item-xfn',
	];

	/**
	 * The recorded fields that are integers rather than strings.
	 *
	 * A separate list rather than a flag on each entry above, for the reason
	 * MediaTarget keeps its parent field apart from its text fields: the CAST is
	 * what decides whether a restore is correct. `(int) null` is 0, and 0 is the
	 * recorded value that MEANS "top level" for a parent and "not an object" for
	 * an object id, so these are gated on is_numeric() as well as on presence.
	 *
	 * @var string[]
	 */
	public const INTEGER_ITEM_FIELDS = [
		'menu-item-db-id',
		'menu-item-object-id',
		'menu-item-parent-id',
		'menu-item-position',
	];

	/**
	 * Constructs the resolver.
	 *
	 * @param MenuFields $fields The normalized menu projection and validators.
	 */
	public function __construct( private readonly MenuFields $fields ) {
	}

	/**
	 * The target key a menu that does not exist yet is planned against.
	 *
	 * Deliberately not an identifier-shaped key: menuIdFromKey() answers null
	 * for it, because its suffix is not digits, so nothing downstream can read
	 * it as a menu that exists.
	 */
	public const NEW_MENU_KEY = MenuFields::MENU_PREFIX . 'new';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The stable target key naming one menu.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return string The target key.
	 */
	public static function menuTargetKey( int $menu_id ): string {
		return MenuFields::MENU_PREFIX . $menu_id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The stable target key naming one menu item.
	 *
	 * @param int $item_id The menu item's post identifier.
	 *
	 * @return string The target key.
	 */
	public static function itemTargetKey( int $item_id ): string {
		return MenuFields::ITEM_PREFIX . $item_id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * The menu identifier one target key names, or null when it names none.
	 *
	 * The digit test is what keeps this from answering 0 for a malformed key.
	 * Zero is not a menu; handing it to wp_update_nav_menu_item() as one asks
	 * WordPress to CREATE a menu, so a key that cannot be parsed must answer
	 * null and be refused rather than cast.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int|null The menu identifier, or null.
	 */
	public static function menuIdFromKey( string $targetKey ): ?int {
		return self::id_from_key( $targetKey, MenuFields::MENU_PREFIX );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * The menu item identifier one target key names, or null when it names none.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int|null The item identifier, or null.
	 */
	public static function itemIdFromKey( string $targetKey ): ?int {
		return self::id_from_key( $targetKey, MenuFields::ITEM_PREFIX );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * The positive identifier one prefixed target key carries.
	 *
	 * @param string $targetKey The target key.
	 * @param string $prefix    The expected prefix.
	 *
	 * @return int|null The identifier, or null.
	 */
	private static function id_from_key( string $targetKey, string $prefix ): ?int {
		if ( ! str_starts_with( $targetKey, $prefix ) ) {
			return null;
		}

		$suffix = substr( $targetKey, strlen( $prefix ) );

		return ctype_digit( $suffix ) && (int) $suffix > 0 ? (int) $suffix : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves the menu one caller-supplied key names.
	 *
	 * THE CAPABILITY IS CHECKED FIRST, and that order is the disclosure control.
	 * `edit_theme_options` is a site-wide capability rather than a per-object
	 * one, so testing it before the lookup means a caller who does not hold it
	 * learns nothing about which menus exist — every key answers the same
	 * refusal. Testing it second would turn the operation into a probe.
	 *
	 * @param string           $key     The menu id, slug, or name.
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state, with no fields.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveMenu( string $key, OperationContext $context ): TargetState {
		$this->assert_may_administer( $context );

		$menu = $this->fields->menuFromKey( $key );

		if ( null === $menu ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No navigation menu on this site matches the supplied menu identifier.',
				'List the site\'s menus and retry with an identifier, slug, or name that one of them carries.'
			);
		}

		// NO FIELDS, deliberately. What a create is about to produce is a menu
		// ITEM that does not exist yet, so there is no prior field map for a
		// preview to diff against; the key exists to bind the approved plan to
		// this menu, which is what stops a plan approved for the primary menu
		// from applying to the footer one.
		return new TargetState( self::menuTargetKey( (int) $menu->term_id ), true, [] );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	/**
	 * Resolves the target of a menu that does not exist yet.
	 *
	 * THERE IS NOTHING TO LOOK UP, so the key is a literal rather than an
	 * identifier: a creation has no prior state for a preview to diff against
	 * and no row for a key to name. The literal is stable across preview and
	 * apply, which is what lets an approved plan be admitted at all, and
	 * applyChange() answers the concrete `menu:{id}` key of what it created.
	 *
	 * The capability is still asked here rather than at apply, for the reason
	 * resolveMenu() gives: a caller who may not administer menus is refused
	 * before anything else happens.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The unresolved state, with no fields.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	public function resolveNewMenu( OperationContext $context ): TargetState {
		$this->assert_may_administer( $context );

		return new TargetState( self::NEW_MENU_KEY, false, [] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Resolves one existing menu item.
	 *
	 * @param int              $item_id The menu item post identifier.
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveItem( int $item_id, OperationContext $context ): TargetState {
		$this->assert_may_administer( $context );

		$item = $this->item_object( $item_id );

		if ( null === $item ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No navigation menu item on this site matches the supplied item identifier.',
				'Read the menu that should contain the item and retry with an identifier it lists.'
			);
		}

		return new TargetState(
			self::itemTargetKey( $item_id ),
			true,
			$this->fields->normalizeItem( $item )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey and $correlationId match the WriteOperation and OperationContext contracts.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Re-reads a menu item after a write so the engine can verify it.
	 *
	 * The post cache is invalidated first, which is both correct for
	 * verification and the module's declared cache-cleanup obligation.
	 *
	 * @param string $targetKey     The concrete item target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$item_id = self::itemIdFromKey( $targetKey );
		$item    = null === $item_id ? null : $this->item_object( $item_id, true );

		if ( null === $item ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The menu item could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $this->fields->normalizeItem( $item ) );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	/**
	 * Records one menu item's WHOLE `wp_update_nav_menu_item()` field set.
	 *
	 * Every field, because that call overwrites what it is handed and defaults
	 * what it is not — see RESTORABLE_ITEM_FIELDS. The recorded set is what
	 * restoreItem() replays, so a field missing here is a field a rollback
	 * silently resets rather than restores.
	 *
	 * FOUR VALUES COME FROM THE STORED COLUMNS, NOT FROM THE ROW AS
	 * `wp_setup_nav_menu_item()` HANDS IT OVER, which is the same correction
	 * MenuItemsReorder::item_args() makes and for the same reason. That call
	 * DECORATES the post with display properties that are not the columns:
	 * `description` is `wp_trim_words( post_content, 200 )`, `attr_title` is a
	 * filtered `post_excerpt`, and `title` is the LINKED POST's title for a
	 * post_type item, run through the_title filters that turn `&` into `&#038;`.
	 * This snapshot is replayed into `wp_update_nav_menu_item()` by
	 * restoreItem() and is the merge base MenuItemUpdate writes from, so
	 * recording the derived values would write the RENDERING back over the
	 * source: a long description truncated to 200 words, a tooltip replaced by
	 * its filtered form, and a title texturized a little further on every write
	 * — which also means the same request applied twice never converges.
	 *
	 * `menu-item-url` is the one value that stays derived, because no column
	 * holds it. For a custom link the derived value IS the stored meta, so it
	 * round-trips exactly; for a post_type or taxonomy item WordPress recomputes
	 * the address on every read and never consults the stored meta.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads the item and the term
	 * relationship and writes nothing. The change engine calls captureSnapshot()
	 * once for preview eligibility and again at apply.
	 *
	 * The key order is sorted, matching every other snapshot in the codebase:
	 * the restore state is stored as canonical JSON, so a stable order keeps the
	 * stored row identical for identical state.
	 *
	 * @param int $item_id The menu item post identifier.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the id
	 *                                   names no menu item in any menu.
	 */
	public function snapshotItem( int $item_id ): ?array {
		$item = $this->item_object( $item_id );

		if ( null === $item ) {
			return null;
		}

		$menu_id = $this->fields->menuTermIdForItem( $item_id );

		if ( null === $menu_id ) {
			return null;
		}

		$classes = $item->classes ?? [];

		$snapshot = [
			'item_id'               => $item_id,
			'menu_id'               => $menu_id,
			'menu-item-attr-title'  => (string) ( $item->post_excerpt ?? '' ),
			'menu-item-classes'     => is_array( $classes ) ? implode( ' ', array_map( 'strval', $classes ) ) : '',
			'menu-item-db-id'       => $item_id,
			'menu-item-description' => (string) ( $item->post_content ?? '' ),
			'menu-item-object'      => (string) ( $item->object ?? '' ),
			'menu-item-object-id'   => (int) ( $item->object_id ?? 0 ),
			'menu-item-parent-id'   => (int) ( $item->menu_item_parent ?? 0 ),
			// A stored 0 is recorded as 0, because that is what the item holds —
			// see correctAppendedPosition() for why replaying it needs a second
			// write, and why rewriting it to 1 here would record a lie.
			'menu-item-position'    => (int) ( $item->menu_order ?? 0 ),
			'menu-item-status'      => (string) ( $item->post_status ?? 'publish' ),
			'menu-item-target'      => (string) ( $item->target ?? '' ),
			'menu-item-title'       => (string) ( $item->post_title ?? '' ),
			'menu-item-type'        => (string) ( $item->type ?? '' ),
			'menu-item-url'         => (string) ( $item->url ?? '' ),
			'menu-item-xfn'         => (string) ( $item->xfn ?? '' ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Replays a recorded menu item field set back onto its item.
	 *
	 * EVERY FIELD IS GATED ON array_key_exists(), never on `??`. A recorded ''
	 * means "set this back to empty" and an ABSENT key means "this state does
	 * not describe that field"; `??` collapses those two into one and hands
	 * wp_update_nav_menu_item() a value it will happily store. That collapse is
	 * what nearly shipped in the core block, where an absent post_status became
	 * '' and unpublished a live post while reporting the rollback verified.
	 *
	 * The data is slashed on the way in, because wp_update_nav_menu_item()
	 * forwards to wp_insert_post(), which unslashes before storing — core's own
	 * caller hands it the raw slashed request. An unslashed title holding a
	 * quote is otherwise stored a character short.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored item's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           state names no item, or
	 *                           ErrorCode::ExecutionFailed when WordPress
	 *                           refuses the replay.
	 */
	public function restoreItem( array $restoreState ): string {
		$item_id = is_numeric( $restoreState['item_id'] ?? null ) ? (int) $restoreState['item_id'] : 0;
		$menu_id = is_numeric( $restoreState['menu_id'] ?? null ) ? (int) $restoreState['menu_id'] : 0;

		if ( $item_id <= 0 || $menu_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a menu item and its menu, so it cannot be restored.',
				'Restore the menu item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$data = $this->restorable_fields( $restoreState );

		if ( [] === $data ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot describes none of the menu item\'s fields, so there is nothing to restore.',
				'Restore the menu item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$written = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $data ) );

		if ( is_wp_error( $written ) || 0 === (int) $written ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded menu item.',
				'Restore the menu item under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		self::correctAppendedPosition( $item_id, $data );

		clean_post_cache( $item_id );

		return self::itemTargetKey( $item_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Builds a NEW menu item from a recorded field set.
	 *
	 * The sibling of restoreItem(), for the one case that method cannot serve: the
	 * item's row is gone. `wp_update_nav_menu_item()` hands a dead identifier
	 * straight to `wp_update_post()`, which refuses it, so replaying a deleted
	 * item's state has to be an insert — and an insert answers a NEW identifier.
	 * The caller is told that plainly by the returned key rather than being handed
	 * back the identifier it asked about.
	 *
	 * `menu-item-db-id` is dropped from the replayed set for the same reason. It
	 * records the identifier the item used to hold, and carrying a dead one into a
	 * fresh insert would put a stale number in the row's own arguments.
	 *
	 * The data is slashed on the way in, because wp_update_nav_menu_item()
	 * forwards to wp_insert_post(), which unslashes before storing.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The re-created item's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           state names no item and menu or describes no
	 *                           fields, or ErrorCode::ExecutionFailed when
	 *                           WordPress refuses the insert.
	 */
	public function recreateItem( array $restoreState ): string {
		$item_id = is_numeric( $restoreState['item_id'] ?? null ) ? (int) $restoreState['item_id'] : 0;
		$menu_id = is_numeric( $restoreState['menu_id'] ?? null ) ? (int) $restoreState['menu_id'] : 0;

		if ( $item_id <= 0 || $menu_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a menu item and its menu, so it cannot be put back.',
				'Add the item again under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$data = $this->restorable_fields( $restoreState );
		unset( $data['menu-item-db-id'] );

		if ( [] === $data ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot describes none of the menu item\'s fields, so there is nothing to put back.',
				'Add the item again under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$created = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $data ) );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to put the recorded menu item back.',
				'Add the item again under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		self::correctAppendedPosition( (int) $created, $data );

		clean_post_cache( (int) $created );

		return self::itemTargetKey( (int) $created );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	/**
	 * Puts `menu_order` back to 0 after a write that asked for position 0.
	 *
	 * THERE IS NO WAY TO REQUEST POSITION 0 THROUGH `wp_update_nav_menu_item()`.
	 * Core reads the argument set and substitutes before it stores
	 * (wp-includes/nav-menu.php):
	 *
	 *     } elseif ( 0 === (int) $args['menu-item-position'] ) {
	 *         $menu_items = (array) wp_get_nav_menu_items( $menu_id, ... );
	 *         $last_item  = array_pop( $menu_items );
	 *         ...
	 *         $args['menu-item-position'] = 1 + $last_item->menu_order;
	 *
	 * so a 0 means "append to the end", and omitting the key reaches the same
	 * branch through `wp_parse_args()`'s default. That substitution is right for a
	 * CREATE, whose caller has no position in mind, and wrong for every write that
	 * carries an existing item's own `menu_order` forward.
	 *
	 * AND 0 IS A REACHABLE STORED STATE, not a theoretical one. The `else` arm of
	 * the same block answers `count( $menu_items )` against a list `array_pop()`
	 * has already emptied, so THE FIRST ITEM CREATED IN AN EMPTY MENU IS STORED AT
	 * `menu_order` 0 — including every item menu-item-create makes. An update or a
	 * rollback that hands that 0 straight back therefore moves the menu's FIRST
	 * item to LAST, silently: `position` is not a field a partial update promises,
	 * so WriteVerifier has nothing to compare and reports success.
	 *
	 * The correction is a second write rather than a rewritten argument, because
	 * the alternatives are both wrong. Sending 1 instead would land the item on top
	 * of whatever already sits at 1 — an ambiguous tie, not the prior arrangement.
	 * Leaving it to core's substitution loses the arrangement outright.
	 *
	 * AN ABSENT KEY IS NOT A ZERO. A field set that does not carry
	 * `menu-item-position` is one that does not describe the position, and this
	 * writes nothing for it — the same array_key_exists() rule that governs every
	 * other field on the restore path.
	 *
	 * NOT SAFE TO CALL FROM captureSnapshot(), which the engine runs twice and
	 * which must stay side-effect free. This belongs on the apply and restore paths
	 * only, after the write it corrects has already landed.
	 *
	 * @param int                  $item_id The menu item post identifier.
	 * @param array<string, mixed> $data    The field set that was written.
	 */
	public static function correctAppendedPosition( int $item_id, array $data ): void {
		if ( ! array_key_exists( 'menu-item-position', $data ) || 0 !== (int) $data['menu-item-position'] ) {
			return;
		}

		wp_update_post(
			[
				'ID'         => $item_id,
				'menu_order' => 0,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * The `wp_update_nav_menu_item()` fields one recorded state actually holds.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return array<string, mixed> The fields to replay, cast to their column type.
	 */
	private function restorable_fields( array $restoreState ): array {
		$data = [];

		foreach ( self::RESTORABLE_ITEM_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $restoreState ) ) {
				continue;
			}

			$value = $restoreState[ $field ];

			if ( in_array( $field, self::INTEGER_ITEM_FIELDS, true ) ) {
				if ( is_numeric( $value ) ) {
					$data[ $field ] = (int) $value;
				}

				continue;
			}

			if ( is_scalar( $value ) ) {
				$data[ $field ] = (string) $value;
			}
		}

		return $data;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Refuses a caller who may not administer this site's navigation menus.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	private function assert_may_administer( OperationContext $context ): void {
		if ( ! user_can( $context->userId, self::REQUIRED_CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not administer this site\'s navigation menus.',
				'Ask a site administrator to grant the ability to edit theme options, then retry.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * One menu item row, set up the way WordPress serves it, or null.
	 *
	 * THE `> 0` IDENTIFIER TEST IS NOT REDUNDANT with the is_nav_menu_item()
	 * call above it. A `wp_setup_nav_menu_item` filter can substitute a row
	 * carrying no `ID` — a plugin injecting a synthetic "Login" entry is the
	 * common case — and 0 is simultaneously "absent" and the root-parent
	 * sentinel, a conflation that produced an unbounded recursion in this module
	 * already. Any code walking nav menu item rows outside
	 * MenuFields::itemTree() has to apply the same filter, and this is it.
	 *
	 * @param int  $item_id The menu item post identifier.
	 * @param bool $refresh Whether to invalidate the post cache first.
	 *
	 * @return object|null The set-up item row, or null.
	 */
	private function item_object( int $item_id, bool $refresh = false ): ?object {
		if ( $item_id <= 0 || ! is_nav_menu_item( $item_id ) ) {
			return null;
		}

		if ( $refresh ) {
			clean_post_cache( $item_id );
		}

		$post = get_post( $item_id );

		if ( ! is_object( $post ) ) {
			return null;
		}

		$item = wp_setup_nav_menu_item( $post );

		return is_object( $item ) && (int) ( $item->ID ?? 0 ) > 0 ? $item : null;
	}
}
