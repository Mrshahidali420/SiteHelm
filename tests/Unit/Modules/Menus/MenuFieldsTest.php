<?php
/**
 * Tests for MenuFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The shared menu projection and the validators every menus operation reuses.
 *
 * Every WordPress call these methods make is filtered, so each one is tested
 * against the answer core documents AND against the answer a filter can
 * substitute. A cast where a guard belongs is the failure mode: `(int)` on a
 * WP_Error is a fatal, and `(array)` on one is a one-member list of garbage.
 */
final class MenuFieldsTest extends TestCase {

	private MenuFields $fields;

	/**
	 * The arguments wp_get_nav_menu_object() was called with.
	 *
	 * @var mixed[]
	 */
	private array $menuLookups = [];

	protected function setUp(): void {
		parent::setUp();
		$this->fields      = new MenuFields();
		$this->menuLookups = [];
	}

	private function makeMenu( int $id, string $name = 'Primary', string $slug = 'primary' ): stdClass {
		$menu          = new stdClass();
		$menu->term_id = $id;
		$menu->name    = $name;
		$menu->slug    = $slug;
		$menu->count   = 0;

		return $menu;
	}

	/**
	 * One flat row shaped as wp_get_nav_menu_items() serves it.
	 *
	 * @param int    $id       The menu item post identifier.
	 * @param int    $parent   The parent menu item identifier, 0 at top level.
	 * @param int    $position The menu order.
	 * @param string $title    The item title.
	 *
	 * @return stdClass The row.
	 */
	private function makeItem( int $id, int $parent, int $position, string $title = 'Item' ): stdClass {
		$item                   = new stdClass();
		$item->ID               = $id;
		$item->title            = $title;
		$item->type             = 'post_type';
		$item->object           = 'page';
		$item->object_id        = 100 + $id;
		$item->url              = 'https://example.com/page';
		$item->target           = '';
		$item->classes          = [ 'featured', '' ];
		$item->description      = 'A description';
		$item->xfn              = '';
		$item->menu_item_parent = $parent;
		$item->menu_order       = $position;

		return $item;
	}

	/**
	 * Serves wp_get_nav_menu_object() from a fixed menu, recording its argument.
	 *
	 * @param stdClass|false $answer The object core returns.
	 */
	private function stubMenuObject( stdClass|false $answer ): void {
		Functions\when( 'wp_get_nav_menu_object' )->alias(
			function ( mixed $ref ) use ( $answer ): stdClass|false {
				$this->menuLookups[] = $ref;

				return $answer;
			}
		);
	}

	/**
	 * Core would reach the same menu either way — `get_term()` casts a numeric
	 * string inside `WP_Term::get_instance()` — so this pins the call-site
	 * contract rather than a core requirement: a numeric key is an id, and the
	 * id path does not lean on that internal cast. Deleting the `(int)` in
	 * `menuFromKey()` breaks it.
	 */
	public function test_a_numeric_key_reaches_core_as_an_integer_id(): void {
		$this->stubMenuObject( $this->makeMenu( 5 ) );

		$this->fields->menuFromKey( '5' );

		$this->assertSame( [ 5 ], $this->menuLookups );
	}

	public function test_a_slug_or_name_key_reaches_core_unchanged(): void {
		$this->stubMenuObject( $this->makeMenu( 5 ) );

		$this->fields->menuFromKey( 'Primary Navigation' );

		$this->assertSame( [ 'Primary Navigation' ], $this->menuLookups );
	}

	public function test_a_resolved_menu_is_returned(): void {
		$this->stubMenuObject( $this->makeMenu( 5, 'Primary', 'primary' ) );

		$menu = $this->fields->menuFromKey( 'primary' );

		$this->assertNotNull( $menu );
		$this->assertSame( 5, (int) $menu->term_id );
	}

	public function test_an_unresolvable_key_is_null(): void {
		$this->stubMenuObject( false );

		$this->assertNull( $this->fields->menuFromKey( 'nope' ) );
	}

	/**
	 * An empty key must not reach core: wp_get_nav_menu_object( '' ) runs a term
	 * query for nothing, and on some sites answers the first menu it finds.
	 */
	public function test_an_empty_key_is_null_without_asking_core(): void {
		$this->stubMenuObject( $this->makeMenu( 5 ) );

		$this->assertNull( $this->fields->menuFromKey( '' ) );
		$this->assertSame( [], $this->menuLookups );
	}

	/**
	 * `wp_get_nav_menu_object` is a filter hook as well as a function. A filter
	 * returning a term-shaped array, or an object with no term_id, must not be
	 * handed on as a menu.
	 */
	public function test_a_filtered_answer_that_is_not_a_term_object_is_null(): void {
		Functions\when( 'wp_get_nav_menu_object' )->justReturn( [ 'term_id' => 5 ] );
		$this->assertNull( $this->fields->menuFromKey( 'primary' ) );

		Functions\when( 'wp_get_nav_menu_object' )->justReturn( new stdClass() );
		$this->assertNull( $this->fields->menuFromKey( 'primary' ) );
	}

	public function test_the_owning_menu_of_an_item_is_its_nav_menu_term(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( [ $this->makeMenu( 9 ) ] );

		$this->assertSame( 9, $this->fields->menuTermIdForItem( 31 ) );
	}

	public function test_an_item_attached_to_no_menu_has_no_owning_menu(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( [] );

		$this->assertNull( $this->fields->menuTermIdForItem( 31 ) );
	}

	/**
	 * wp_get_object_terms() answers a WP_Error when the taxonomy is missing.
	 * `(int) $terms[0]->term_id` on that is a fatal, so the guard is on the
	 * shape rather than on is_wp_error().
	 */
	public function test_a_non_array_answer_from_core_yields_no_owning_menu(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( new stdClass() );

		$this->assertNull( $this->fields->menuTermIdForItem( 31 ) );
	}

	public function test_an_item_identifier_below_one_is_never_asked_about(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( [ $this->makeMenu( 9 ) ] );

		$this->assertNull( $this->fields->menuTermIdForItem( 0 ) );
	}

	public function test_the_item_tree_nests_children_under_their_parent_in_position_order(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 0, 1, 'Top' ),
				$this->makeItem( 11, 10, 2, 'Child A' ),
				$this->makeItem( 12, 10, 3, 'Child B' ),
				$this->makeItem( 13, 0, 4, 'Second top' ),
			]
		);

		$tree = $this->fields->itemTree( 5 );

		$this->assertSame( [ 10, 13 ], array_column( $tree, 'id' ) );
		$this->assertSame( [ 11, 12 ], array_column( $tree[0]['children'], 'id' ) );
		$this->assertSame( [], $tree[1]['children'] );
		$this->assertSame( [], $tree[0]['children'][0]['children'] );
	}

	public function test_a_flat_menu_is_a_flat_tree(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 0, 1 ),
				$this->makeItem( 11, 0, 2 ),
			]
		);

		$this->assertSame( [ 10, 11 ], array_column( $this->fields->itemTree( 5 ), 'id' ) );
	}

	public function test_an_empty_menu_is_an_empty_tree(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn( [] );

		$this->assertSame( [], $this->fields->itemTree( 5 ) );
	}

	/**
	 * wp_get_nav_menu_items() answers `false` for a menu that does not exist,
	 * which `foreach` over would warn on.
	 */
	public function test_a_menu_core_cannot_read_is_an_empty_tree(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn( false );

		$this->assertSame( [], $this->fields->itemTree( 5 ) );
	}

	/**
	 * An item whose parent was deleted keeps pointing at the dead identifier.
	 * Dropping it would hide live menu entries the site still renders, so it is
	 * emitted at top level instead.
	 */
	public function test_an_item_whose_parent_is_absent_is_emitted_rather_than_lost(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 0, 1 ),
				$this->makeItem( 11, 999, 2 ),
			]
		);

		$this->assertSame( [ 10, 11 ], array_column( $this->fields->itemTree( 5 ), 'id' ) );
	}

	/**
	 * A menu item that names itself as its own parent is a data state a direct
	 * database edit can produce, and a naive recursion on it never terminates.
	 */
	public function test_a_self_parented_item_does_not_recurse_forever(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 10, 1 ),
			]
		);

		$this->assertSame( [ 10 ], array_column( $this->fields->itemTree( 5 ), 'id' ) );
	}

	/**
	 * `wp_get_nav_menu_items` is filtered, and appending a synthetic row is a
	 * common plugin trick — an injected "Login" entry being the usual one. Such a
	 * row carries no `ID`, which takes identifier 0, which is also the root
	 * sentinel: grouped under parent 0 it makes the root branch its own child and
	 * the walk recurses until the process dies. The row cannot be described, so
	 * it is dropped and the real menu still answers.
	 *
	 * A test that hangs is a test that fails, but it fails by taking the suite
	 * down with it, so the identifier list is asserted rather than the timing.
	 */
	public function test_a_row_carrying_no_identifier_is_dropped_rather_than_rooted(): void {
		$ghost        = new stdClass();
		$ghost->title = 'Login';

		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 0, 1 ),
				$ghost,
				$this->makeItem( 11, 10, 2 ),
			]
		);

		$tree = $this->fields->itemTree( 5 );

		$this->assertSame( [ 10 ], array_column( $tree, 'id' ) );
		$this->assertSame( [ 11 ], array_column( $tree[0]['children'], 'id' ) );
	}

	/**
	 * The same filter can put something that is not a row in the list at all.
	 * `$item->ID` on a string is a TypeError one frame down, so the member is
	 * dropped on the same test that drops an identifier-less row.
	 */
	public function test_a_member_that_is_not_a_row_is_dropped_rather_than_read(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				'not a row',
				$this->makeItem( 10, 0, 1 ),
			]
		);

		$this->assertSame( [ 10 ], array_column( $this->fields->itemTree( 5 ), 'id' ) );
	}

	/**
	 * A longer cycle — 10 parents 11 and 11 parents 10 — passes the self-parent
	 * and absent-parent guards untouched, because each identifier does name a
	 * live item in the same menu. Only the chain walk can see it, and without
	 * that walk the recursion never terminates. Both items must still appear.
	 */
	public function test_a_parent_cycle_is_broken_rather_than_recursed_into(): void {
		Functions\when( 'wp_get_nav_menu_items' )->justReturn(
			[
				$this->makeItem( 10, 11, 1 ),
				$this->makeItem( 11, 10, 2 ),
			]
		);

		$tree = $this->fields->itemTree( 5 );

		$this->assertSame( [ 10 ], array_column( $tree, 'id' ) );
		$this->assertSame( [ 11 ], array_column( $tree[0]['children'], 'id' ) );
		$this->assertSame( [], $tree[0]['children'][0]['children'] );
	}

	public function test_a_normalized_item_carries_exactly_the_declared_fields(): void {
		$item = $this->fields->normalizeItem( $this->makeItem( 10, 3, 7, 'About' ) );

		$this->assertSame(
			[ 'id', 'title', 'url', 'type', 'object', 'objectId', 'parent', 'position', 'target', 'classes', 'description', 'xfn' ],
			array_keys( $item )
		);
		$this->assertSame( 10, $item['id'] );
		$this->assertSame( 'About', $item['title'] );
		$this->assertSame( 'post_type', $item['type'] );
		$this->assertSame( 'page', $item['object'] );
		$this->assertSame( 110, $item['objectId'] );
		$this->assertSame( 3, $item['parent'] );
		$this->assertSame( 7, $item['position'] );
	}

	/**
	 * Core stores an empty string in the class list for every item that has no
	 * classes at all, so passing the array through unfiltered would ship a
	 * meaningless '' to every client.
	 */
	public function test_empty_class_entries_are_dropped_and_the_list_stays_a_list(): void {
		$this->assertSame( [ 'featured' ], $this->fields->normalizeItem( $this->makeItem( 10, 0, 1 ) )['classes'] );
	}

	/**
	 * `classes` is documented as an array but is filtered, and a site storing it
	 * as a string would otherwise reach a client as something its schema does not
	 * declare.
	 */
	public function test_a_non_array_class_value_normalizes_to_an_empty_list(): void {
		$item          = $this->makeItem( 10, 0, 1 );
		$item->classes = 'featured';

		$this->assertSame( [], $this->fields->normalizeItem( $item )['classes'] );
	}

	/**
	 * A missing property on a menu item row is a real state — items served from a
	 * partial cache lack the setup fields — and reading one directly is a warning
	 * plus a null that the string casts would hide.
	 */
	public function test_an_item_missing_its_setup_fields_normalizes_to_empty_values(): void {
		$bare     = new stdClass();
		$bare->ID = 10;

		$item = $this->fields->normalizeItem( $bare );

		$this->assertSame( 10, $item['id'] );
		$this->assertSame( '', $item['title'] );
		$this->assertSame( 0, $item['objectId'] );
		$this->assertSame( 0, $item['parent'] );
		$this->assertSame( [], $item['classes'] );
	}

	/**
	 * Zero is how core spells "top level", so it must validate rather than being
	 * treated as a missing item.
	 */
	public function test_a_parent_of_zero_is_valid_top_level_placement(): void {
		$this->assertTrue( $this->fields->validateParent( 0, 5 ) );
	}

	public function test_a_parent_in_the_same_menu_is_valid(): void {
		Functions\when( 'is_nav_menu_item' )->justReturn( true );
		Functions\when( 'wp_get_object_terms' )->justReturn( [ $this->makeMenu( 5 ) ] );

		$this->assertTrue( $this->fields->validateParent( 10, 5 ) );
	}

	public function test_a_parent_in_a_different_menu_is_invalid(): void {
		Functions\when( 'is_nav_menu_item' )->justReturn( true );
		Functions\when( 'wp_get_object_terms' )->justReturn( [ $this->makeMenu( 9 ) ] );

		$this->assertFalse( $this->fields->validateParent( 10, 5 ) );
	}

	public function test_a_parent_that_is_not_a_menu_item_is_invalid(): void {
		Functions\when( 'is_nav_menu_item' )->justReturn( false );

		$this->assertFalse( $this->fields->validateParent( 10, 5 ) );
	}

	/**
	 * A negative parent is refused without a term query even when everything core
	 * is asked says yes. Both assertions flip if the `$item_id <= 0` guard is
	 * deleted from `menuTermIdForItem()`: the query runs, and the stubbed answer
	 * makes the parent validate against menu 5.
	 */
	public function test_a_negative_parent_is_refused_without_a_term_query(): void {
		$queried = [];
		Functions\when( 'is_nav_menu_item' )->justReturn( true );
		Functions\when( 'wp_get_object_terms' )->alias(
			function ( int $id ) use ( &$queried ): array {
				$queried[] = $id;

				return [ $this->makeMenu( 5 ) ];
			}
		);

		$this->assertFalse( $this->fields->validateParent( -1, 5 ) );
		$this->assertSame( [], $queried );
	}

	public function test_a_registered_location_exists_and_an_unregistered_one_does_not(): void {
		Functions\when( 'get_registered_nav_menus' )->justReturn( [ 'primary' => 'Primary Menu' ] );

		$this->assertTrue( $this->fields->locationExists( 'primary' ) );
		$this->assertFalse( $this->fields->locationExists( 'footer' ) );
	}

	/**
	 * get_registered_nav_menus() reads a theme support argument, which is whatever
	 * the theme passed to add_theme_support(). A theme that passed a non-array
	 * must not make every location look registered.
	 */
	public function test_a_non_array_registration_table_registers_no_location(): void {
		Functions\when( 'get_registered_nav_menus' )->justReturn( false );

		$this->assertFalse( $this->fields->locationExists( 'primary' ) );
	}
}
