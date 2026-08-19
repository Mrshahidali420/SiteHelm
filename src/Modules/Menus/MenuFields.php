<?php
/**
 * The normalized WordPress nav menu field map and shared validators.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

/**
 * The one place that decides what "a menu" and "a menu item" mean.
 *
 * Every menus consumer shares this definition: the listing projects menu rows
 * from it, the read projects the nested item tree from it, and the four writes
 * validate against it. Keeping it in one class is what makes a value read at
 * preview comparable to one read at apply.
 *
 * These helpers answer a value or null rather than a `WP_Error`. Every refusal
 * message belongs to the operation that owns the envelope, so a helper never
 * decides how a failure is worded.
 *
 * Every core call below is filtered, so each answer is guarded on its shape
 * rather than cast. `(int)` on a WP_Error is a fatal and `(array)` on one is a
 * single-member list of garbage, and both are states a third-party filter can
 * produce on a site that is otherwise healthy.
 *
 * @package SiteHelm
 */
final class MenuFields {

	/**
	 * The taxonomy WordPress stores menus in.
	 */
	public const MENU_TAXONOMY = 'nav_menu';

	/**
	 * The post type WordPress stores menu items in.
	 */
	public const ITEM_POST_TYPE = 'nav_menu_item';

	/**
	 * The theme mod holding the location-to-menu map.
	 */
	public const LOCATIONS_THEME_MOD = 'nav_menu_locations';

	/**
	 * The target-key prefix for a menu-shaped target.
	 */
	/**
	 * The longest string that can name a menu.
	 *
	 * A menu is a `nav_menu` term, and every one of the three ways to name one —
	 * identifier, slug, name — resolves against `wp_terms`, whose `name` and
	 * `slug` columns are both varchar(200). A longer string therefore cannot
	 * match any menu that exists; accepting it only means carrying it as far as
	 * the lookup before finding that out.
	 *
	 * SHARED RATHER THAN COPIED into the four schemas that need it, because the
	 * bound is a fact about the storage and not a policy any one operation gets
	 * to hold a different opinion about.
	 */
	public const MAX_MENU_REFERENCE_LENGTH = 200;

	public const MENU_PREFIX = 'menu:';

	/**
	 * The target-key prefix for a menu-item-shaped target.
	 */
	public const ITEM_PREFIX = 'menu-item:';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The menu one caller-supplied key names, or null when it names none.
	 *
	 * The numeric cast is defensive rather
	 * than load-bearing: `wp_get_nav_menu_object()` hands the key to `get_term()`,
	 * which casts a numeric string inside `WP_Term::get_instance()`, so `'5'` and
	 * `5` already find the same menu. Casting at the call site says which of the
	 * three lookups core will try first without depending on that internal detail.
	 *
	 * An empty key is answered before core is asked. `wp_get_nav_menu_object('')`
	 * runs a term query for nothing, and what it answers is filterable.
	 *
	 * @param string $key The menu id, slug, or name.
	 *
	 * @return object|null The menu term, or null when the key names no menu.
	 */
	public function menuFromKey( string $key ): ?object {
		$key = trim( $key );

		if ( '' === $key ) {
			return null;
		}

		$menu = wp_get_nav_menu_object( is_numeric( $key ) ? (int) $key : $key );

		if ( ! is_object( $menu ) || ! isset( $menu->term_id ) ) {
			return null;
		}

		return $menu;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The menu that owns one menu item, or null when nothing owns it.
	 *
	 * `wp_get_object_terms()` answers a
	 * WP_Error when the taxonomy is unregistered, which is why the guard is on
	 * `is_array()` rather than on `is_wp_error()`: the shape test covers the
	 * documented error return and every other non-list a filter can substitute,
	 * with one condition instead of two.
	 *
	 * @param int $item_id The menu item post identifier.
	 *
	 * @return int|null The owning menu's term identifier, or null.
	 */
	public function menuTermIdForItem( int $item_id ): ?int {
		if ( $item_id <= 0 ) {
			return null;
		}

		$terms = wp_get_object_terms( $item_id, self::MENU_TAXONOMY );

		if ( ! is_array( $terms ) || [] === $terms ) {
			return null;
		}

		$term = reset( $terms );

		return is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * One menu's items as a nested tree, each node carrying `children`.
	 *
	 * The obvious implementation — group on the stored `menu_item_parent` and
	 * recurse — loses every item whose parent row was deleted, because the
	 * branch for a dead parent is never visited, and never terminates on an item
	 * that is its own ancestor, a state a direct database edit produces. Both
	 * are silent on a healthy site and fatal on a damaged one.
	 *
	 * So rows without a usable identifier are dropped and parents are rooted
	 * afterwards: a parent that names no item in this menu, or that leads back to
	 * the item itself, becomes 0. Both halves are needed. Rooting alone does not
	 * bound the recursion, because 0 is also how "top level" is spelled: a row
	 * carrying no `ID` — which `wp_get_nav_menu_items` filters can append, a
	 * plugin injecting a synthetic "Login" entry being the common case — takes
	 * identifier 0, is grouped under parent 0, and makes `branch()` descend into
	 * the bucket it is already walking. Dropping those rows first is what makes
	 * every remaining identifier non-zero, so a branch can never be its own child.
	 * Every surviving item then appears exactly once.
	 *
	 * Item order is core's own, which is `menu_order` ascending, and it is
	 * preserved rather than re-sorted so that the tree reports the order the
	 * site actually renders.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<int, array<string, mixed>> The nested item tree.
	 */
	public function itemTree( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$items = array_filter(
			$items,
			fn( mixed $item ): bool => is_object( $item ) && $this->item_id( $item ) > 0
		);

		$parents = $this->rooted_parents( $items );

		$by_parent = [];
		foreach ( $items as $item ) {
			$by_parent[ $parents[ $this->item_id( $item ) ] ][] = $item;
		}

		return $this->branch( $by_parent, 0 );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether one parent identifier is a legal parent within one menu.
	 *
	 * This answers a boolean and leaves the refusal message to the operation
	 * that owns the envelope. Zero is how core spells "top level", so it is valid rather
	 * than missing. Every other non-positive identifier is refused by
	 * `menuTermIdForItem()`, which answers null below 1 without running a term
	 * query, so no separate sign test is needed here.
	 *
	 * @param int $parent_id The proposed parent menu item identifier, 0 for top level.
	 * @param int $menu_id   The menu the item must belong to.
	 *
	 * @return bool True when the parent is legal within this menu.
	 */
	public function validateParent( int $parent_id, int $menu_id ): bool {
		if ( 0 === $parent_id ) {
			return true;
		}

		if ( ! is_nav_menu_item( $parent_id ) ) {
			return false;
		}

		return $this->menuTermIdForItem( $parent_id ) === $menu_id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether the active theme registers one theme location.
	 *
	 * `get_registered_nav_menus()` reads a theme support argument, so its answer
	 * is whatever the theme passed to `add_theme_support()`. A non-array must
	 * register nothing rather than being cast into a table every location misses.
	 *
	 * @param string $location The theme location slug.
	 *
	 * @return bool True when the active theme registers this location.
	 */
	public function locationExists( string $location ): bool {
		$registered = get_registered_nav_menus();

		return is_array( $registered ) && array_key_exists( $location, $registered );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The normalized record for one menu item, without its children.
	 *
	 * Every property is
	 * read through `??` on an isset test rather than directly, because a menu
	 * item row that has not been through `wp_setup_nav_menu_item()` — one served
	 * from a partial object cache, or one a filter assembled — carries only its
	 * post columns, and reading a derived property off it is a warning plus a
	 * null that the casts would then hide as an empty string.
	 *
	 * `classes` is filtered to drop empty members because core stores a single
	 * empty string for every item with no classes at all, and `array_values`
	 * keeps it a JSON array rather than an object with a gap in its keys.
	 *
	 * @param object $item One menu item row, as wp_get_nav_menu_items() serves it.
	 *
	 * @return array<string, mixed> The normalized record.
	 */
	public function normalizeItem( object $item ): array {
		$classes = $item->classes ?? [];

		return [
			'id'          => $this->item_id( $item ),
			'title'       => (string) ( $item->title ?? '' ),
			'url'         => (string) ( $item->url ?? '' ),
			'type'        => (string) ( $item->type ?? '' ),
			'object'      => (string) ( $item->object ?? '' ),
			'objectId'    => (int) ( $item->object_id ?? 0 ),
			'parent'      => (int) ( $item->menu_item_parent ?? 0 ),
			'position'    => (int) ( $item->menu_order ?? 0 ),
			'target'      => (string) ( $item->target ?? '' ),
			'classes'     => is_array( $classes )
				? array_values( array_filter( array_map( 'strval', $classes ), static fn( string $c ): bool => '' !== $c ) )
				: [],
			'description' => (string) ( $item->description ?? '' ),
			'xfn'         => (string) ( $item->xfn ?? '' ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One menu item row's identifier.
	 *
	 * @param object $item One menu item row.
	 *
	 * @return int The post identifier, or 0 when the row carries none.
	 */
	private function item_id( object $item ): int {
		return (int) ( $item->ID ?? 0 );
	}

	/**
	 * Each item's parent, rewritten so that every chain terminates at the root.
	 *
	 * A stored parent survives into two states the tree walk cannot: it names an
	 * item in another menu or no item at all (the child would be dropped), or the
	 * chain it starts leads back to where it began (the walk would not
	 * terminate). Both become 0, which places the item at top level — visible and
	 * reported, which is what an operator needs in order to fix it. An item that
	 * names itself is just the shortest such chain and needs no separate test.
	 *
	 * The first pass must run before the second: it is what guarantees that every
	 * parent value is either 0 or a key of this map, which is why the chain walk
	 * can read `$parents[ $cursor ]` without a fallback and know it terminates.
	 *
	 * @param object[] $items The flat item rows for one menu, each with a positive id.
	 *
	 * @return array<int, int> Each item identifier's effective parent.
	 */
	private function rooted_parents( array $items ): array {
		$parents = [];

		foreach ( $items as $item ) {
			$parents[ $this->item_id( $item ) ] = (int) ( $item->menu_item_parent ?? 0 );
		}

		foreach ( array_keys( $parents ) as $id ) {
			if ( ! array_key_exists( $parents[ $id ], $parents ) ) {
				$parents[ $id ] = 0;
			}
		}

		foreach ( array_keys( $parents ) as $id ) {
			$seen   = [];
			$cursor = $id;

			while ( 0 !== $cursor ) {
				if ( isset( $seen[ $cursor ] ) ) {
					$parents[ $id ] = 0;
					break;
				}

				$seen[ $cursor ] = true;
				$cursor          = $parents[ $cursor ];
			}
		}

		return $parents;
	}

	/**
	 * One branch of the item tree.
	 *
	 * @param array<int, object[]> $by_parent Item rows grouped by effective parent.
	 * @param int                  $parent_id The parent to build the branch for.
	 *
	 * @return array<int, array<string, mixed>> The branch.
	 */
	private function branch( array $by_parent, int $parent_id ): array {
		$branch = [];

		foreach ( $by_parent[ $parent_id ] ?? [] as $item ) {
			$node             = $this->normalizeItem( $item );
			$node['children'] = $this->branch( $by_parent, $this->item_id( $item ) );
			$branch[]         = $node;
		}

		return $branch;
	}
}
