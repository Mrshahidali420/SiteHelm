<?php
/**
 * Tests for MenuGet (REQ-0027).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuGet;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0027: menu tree retrieval.
 *
 * The operation answers one menu named by identifier, slug, or name with its
 * full nested item tree, so that an operator can see the hierarchy a later
 * reorder or move will act on before proposing one.
 */
final class MenuGetTest extends TestCase {

	private MenuGet $handler;

	/**
	 * The menu wp_get_nav_menu_object() resolves, or null for a site with none.
	 */
	private ?stdClass $menu = null;

	/**
	 * The item rows wp_get_nav_menu_items() serves for menu 5.
	 *
	 * @var mixed
	 */
	private mixed $items = [];

	/**
	 * Whether the resolved WordPress user holds `edit_theme_options`.
	 */
	private bool $permitted = true;

	protected function setUp(): void {
		parent::setUp();

		$this->handler   = new MenuGet( new MenuFields() );
		$this->permitted = true;
		$this->menu      = $this->makeMenu( 5, 'Primary Navigation', 'primary-navigation' );
		$this->items     = [
			$this->makeItem( 11, 'Home', 0, 1 ),
			$this->makeItem( 12, 'Services', 0, 2 ),
			$this->makeItem( 13, 'Design', 12, 3 ),
			$this->makeItem( 14, 'Build', 12, 4 ),
			$this->makeItem( 15, 'Contact', 0, 5 ),
		];

		$this->stubWordPress();
	}

	private function makeMenu( int $id, string $name, string $slug ): stdClass {
		$menu          = new stdClass();
		$menu->term_id = $id;
		$menu->name    = $name;
		$menu->slug    = $slug;

		return $menu;
	}

	private function makeItem( int $id, string $title, int $parent, int $order ): stdClass {
		$item                   = new stdClass();
		$item->ID               = $id;
		$item->title            = $title;
		$item->url              = 'https://example.com/' . strtolower( $title );
		$item->type             = 'post_type';
		$item->object           = 'page';
		$item->object_id        = 900 + $id;
		$item->menu_item_parent = $parent;
		$item->menu_order       = $order;
		$item->target           = '';
		$item->classes          = [ '' ];
		$item->description      = '';
		$item->xfn              = '';

		return $item;
	}

	/**
	 * Stubs the three core calls this operation reaches through MenuFields.
	 *
	 * `wp_get_nav_menu_object()` is stubbed to answer the same menu for its
	 * identifier, its slug, and its name — which is exactly what core does — so
	 * that the three-key resolution test exercises the real key handling in
	 * MenuFields::menuFromKey() rather than a stub that accepts anything.
	 */
	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool =>
				'edit_theme_options' === $capability && $this->permitted
		);
		Functions\when( 'wp_get_nav_menu_object' )->alias(
			function ( mixed $key ): mixed {
				if ( null === $this->menu ) {
					return false;
				}

				$known = [ $this->menu->term_id, $this->menu->slug, $this->menu->name ];

				return in_array( $key, $known, true ) ? $this->menu : false;
			}
		);
		Functions\when( 'wp_get_nav_menu_items' )->alias(
			fn( int $menu_id ): mixed => 5 === $menu_id ? $this->items : false
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'menus' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation.
	 *
	 * @param mixed $key The menu argument, as a client would send it.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function get( mixed $key = 'primary-navigation' ): array {
		return $this->handler->handle( [ 'menu' => $key ], $this->makeContext() );
	}

	public function test_it_returns_the_menu_identity_beside_its_tree(): void {
		$result = $this->get();

		$this->assertSame( [ 'id', 'name', 'slug', 'items' ], array_keys( $result ) );
		$this->assertSame( 5, $result['id'] );
		$this->assertSame( 'Primary Navigation', $result['name'] );
		$this->assertSame( 'primary-navigation', $result['slug'] );
	}

	/**
	 * The hierarchy is the answer this operation exists for: a client planning a
	 * reorder needs to know which items are already nested under which parent,
	 * and in what order the site renders them.
	 *
	 * Mutation that breaks this: MenuGet returning the flat
	 * `wp_get_nav_menu_items()` rows instead of `MenuFields::itemTree()`.
	 */
	public function test_it_returns_a_nested_two_level_tree_in_position_order(): void {
		$items = $this->get()['items'];

		$this->assertSame( [ 11, 12, 15 ], array_column( $items, 'id' ) );
		$this->assertSame( [], $items[0]['children'] );
		$this->assertSame( [ 13, 14 ], array_column( $items[1]['children'], 'id' ) );
		$this->assertSame( [ 1, 2, 5 ], array_column( $items, 'position' ) );
		$this->assertSame( [ 3, 4 ], array_column( $items[1]['children'], 'position' ) );
	}

	/**
	 * Every field the requirement names travels on every node, children included,
	 * in one order. A client that walks the tree must not have to test for the
	 * presence of a key at depth 2 that it found at depth 1.
	 */
	public function test_every_node_carries_the_same_full_field_set_at_every_depth(): void {
		$expected = [
			'id',
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
			'children',
		];

		$items = $this->get()['items'];

		$this->assertSame( $expected, array_keys( $items[1] ) );
		$this->assertSame( $expected, array_keys( $items[1]['children'][0] ) );

		$child = $items[1]['children'][0];
		$this->assertSame( 13, $child['id'] );
		$this->assertSame( 'Design', $child['title'] );
		$this->assertSame( 'https://example.com/design', $child['url'] );
		$this->assertSame( 'post_type', $child['type'] );
		$this->assertSame( 'page', $child['object'] );
		$this->assertSame( 913, $child['objectId'] );
		$this->assertSame( 12, $child['parent'] );
		$this->assertSame( [], $child['classes'] );
	}

	public function test_a_menu_with_no_nesting_returns_a_flat_tree(): void {
		$this->items = [
			$this->makeItem( 21, 'Privacy', 0, 1 ),
			$this->makeItem( 22, 'Terms', 0, 2 ),
		];

		$items = $this->get()['items'];

		$this->assertSame( [ 21, 22 ], array_column( $items, 'id' ) );
		$this->assertSame( [ [], [] ], array_column( $items, 'children' ) );
	}

	public function test_an_empty_menu_returns_an_empty_tree_rather_than_refusing(): void {
		$this->items = [];

		$this->assertSame( [], $this->get()['items'] );
	}

	/**
	 * `wp_get_nav_menu_items()` answers `false` for a menu it cannot read, and it
	 * is filtered besides. An existing menu whose items cannot be read is an
	 * empty tree, not a fatal.
	 */
	public function test_a_non_array_item_answer_returns_an_empty_tree(): void {
		$this->items = false;

		$this->assertSame( [], $this->get()['items'] );
	}

	/**
	 * The same menu answers to all three keys because that is what
	 * `wp_get_nav_menu_object()` accepts, and an operator holding a slug from one
	 * report and an identifier from another must not have to know which is which.
	 *
	 * Mutation that breaks the numeric case: dropping the `is_numeric()` cast in
	 * MenuFields::menuFromKey(), after which '5' searches for a menu slugged "5".
	 */
	public function test_the_same_menu_resolves_by_id_by_slug_and_by_name(): void {
		$by_slug = $this->get( 'primary-navigation' );

		$this->assertSame( $by_slug, $this->get( '5' ) );
		$this->assertSame( $by_slug, $this->get( 'Primary Navigation' ) );
	}

	/**
	 * A key surrounded by whitespace is a copy-paste artefact, not a different
	 * menu.
	 */
	public function test_a_padded_key_resolves_the_same_menu(): void {
		$this->assertSame( 5, $this->get( '  primary-navigation  ' )['id'] );
	}

	public function test_it_refuses_a_key_that_names_no_menu(): void {
		try {
			$this->get( 'no-such-menu' );
			$this->fail( 'A key naming no menu must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_it_refuses_an_empty_key(): void {
		try {
			$this->get( '' );
			$this->fail( 'An empty key must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The schema declares `menu` a string, but nothing revalidates input at the
	 * handler and a cast is the wrong defence: `(string) [ 'a' ]` is a fatal on a
	 * live site. A non-string argument names no menu, which is the truth.
	 *
	 * Mutation that breaks this: replacing the is_string() guard in
	 * MenuGet::handle() with a `(string)` cast.
	 */
	public function test_a_non_string_key_is_refused_rather_than_cast(): void {
		try {
			$this->get( [ 'primary-navigation' ] );
			$this->fail( 'A non-string key must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A refusal must not echo the key back or name any menu on the site: the
	 * envelope for "no such menu" is read by a caller who may be probing.
	 */
	public function test_the_not_found_refusal_echoes_neither_the_key_nor_any_menu(): void {
		try {
			$this->get( 'secret-client-menu' );
			$this->fail( 'A key naming no menu must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'secret-client-menu', $e->getMessage() );
			$this->assertStringNotContainsString( 'Primary Navigation', $e->getMessage() );
		}
	}

	/**
	 * `edit_theme_options` is the declared capability and PolicyEngine gates on
	 * it, but the answer describes the site's navigation structure and must be
	 * bound to the WordPress user the context actually resolved.
	 */
	public function test_it_refuses_without_edit_theme_options(): void {
		$this->permitted = false;

		try {
			$this->get();
			$this->fail( 'A caller without edit_theme_options must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * The capability is asked before the menu is resolved, so a caller who may
	 * not manage menus cannot use the difference between the two refusals to
	 * learn which menu keys exist.
	 */
	public function test_an_unpermitted_caller_is_refused_the_same_way_for_a_real_and_an_unknown_key(): void {
		$this->permitted = false;

		$codes = [];

		foreach ( [ 'primary-navigation', 'no-such-menu' ] as $key ) {
			try {
				$this->get( $key );
			} catch ( OperationException $e ) {
				$codes[] = $e->errorCode;
			}
		}

		$this->assertSame( [ ErrorCode::Forbidden, ErrorCode::Forbidden ], $codes );
	}

	public function test_the_refusal_names_no_menu_and_no_key(): void {
		$this->permitted = false;

		try {
			$this->get();
			$this->fail( 'A caller without edit_theme_options must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'Primary Navigation', $e->getMessage() );
			$this->assertStringNotContainsString( 'primary-navigation', $e->getMessage() );
		}
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MenuGet::definition();

		$this->assertSame( 'menu-get', $definition->id );
		$this->assertSame( 'menu-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertSame( [ 'menu' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * The item tree is unbounded in depth, so `children` cannot be expanded
	 * inline; it is declared once under `$defs` and referenced from both the
	 * top-level list and from itself. This asserts the reference resolves to a
	 * definition that actually exists, because a `$ref` naming nothing is a
	 * schema a client silently ignores.
	 */
	public function test_the_recursive_item_schema_is_declared_once_and_referenced(): void {
		$schema = MenuGet::definition()->outputSchema;

		$this->assertArrayHasKey( 'menuItem', $schema['$defs'] );
		$this->assertSame( [ '$ref' => '#/$defs/menuItem' ], $schema['properties']['items']['items'] );
		$this->assertSame(
			[ '$ref' => '#/$defs/menuItem' ],
			$schema['$defs']['menuItem']['properties']['children']['items']
		);
		$this->assertFalse( $schema['$defs']['menuItem']['additionalProperties'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$result   = $this->get();
		$registry = new CapabilityRegistry();
		( new MenusModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'menu-get' )->outputSchema
		);
	}

	/**
	 * Every node the tree carries must satisfy the one item schema, at every
	 * depth. assertConformsToOutputSchema() only walks the top level, so the
	 * per-node check is done here against the declared `$defs` entry rather than
	 * against a restated list.
	 */
	public function test_every_node_at_every_depth_matches_the_declared_item_schema(): void {
		$declared = MenuGet::definition()->outputSchema['$defs']['menuItem'];

		$walk = static function ( array $nodes ) use ( &$walk, $declared ): array {
			$seen = [];

			foreach ( $nodes as $node ) {
				$seen[] = array_keys( $node );
				$seen   = array_merge( $seen, $walk( $node['children'] ) );
			}

			return $seen;
		};

		$key_sets = $walk( $this->get()['items'] );

		$this->assertCount( 5, $key_sets );

		foreach ( $key_sets as $keys ) {
			$this->assertSame( $declared['required'], $keys );
		}
	}
}
