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
use SiteHelm\Modules\Diagnostics\OperationSchema;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
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
	 * A non-string `menu` cannot reach handle() through the gateway: the
	 * dispatcher validates arguments against inputSchema first, and the schema
	 * declares `menu` a string. This test calls handle() directly, so it covers
	 * the is_string() guard as the defence in depth it is — the behaviour a
	 * caller that bypasses the dispatcher gets — and claims nothing about the
	 * branch being reachable in production.
	 *
	 * What it pins down is the SHAPE of that defence. A `(string)` cast would be
	 * the obvious alternative and is the wrong one: `(string) [ 'a' ]` is a fatal
	 * on a live site, not a refusal. A key that is not a string names no menu,
	 * which is the truth about it.
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
	 * The claim the comment above rests on, asserted rather than asserted about:
	 * the declared inputSchema is what makes a non-string `menu` unreachable, so
	 * the schema must actually declare it, and SchemaValidator must actually
	 * refuse an array for it.
	 *
	 * Without this, "unreachable in production" is a comment nothing checks, and
	 * a later schema edit could quietly make the guard load bearing again with
	 * no test noticing.
	 *
	 * Mutation that breaks this: widening `menu` to
	 * `[ 'type' => [ 'string', 'array' ] ]` in MenuGet::definition().
	 */
	public function test_the_input_schema_is_what_makes_a_non_string_key_unreachable(): void {
		$schema = MenuGet::definition()->inputSchema;

		try {
			( new SchemaValidator() )->validate( [ 'menu' => [ 'primary' ] ], $schema );
			$this->fail( 'SchemaValidator must refuse a non-string menu argument before handle() runs.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}

		$this->assertSame( 'string', $schema['properties']['menu']['type'] );
	}

	/**
	 * `''` used to be the one argument the schema did NOT stop: the declaration
	 * carried `minLength: 1` and SchemaValidator ignored it, so an empty string
	 * reached handle() and was refused there.
	 *
	 * It is now stopped at the gateway, which changes what the handler's own
	 * empty-key refusal is for: it covers a direct call, not a request. That
	 * refusal is deliberately kept — an operation that only behaves when something
	 * upstream filters for it is one bad edit away from a different answer — but
	 * this test asserts the gateway's answer, because the gateway is what a caller
	 * meets.
	 *
	 * Mutation that breaks this: removing `minLength` from MenuGet::definition(),
	 * or dropping the minLength branch from SchemaValidator.
	 */
	public function test_an_empty_key_is_refused_by_input_validation(): void {
		$this->assertSame( 1, MenuGet::definition()->inputSchema['properties']['menu']['minLength'] );

		try {
			( new SchemaValidator() )->validate( [ 'menu' => '' ], MenuGet::definition()->inputSchema );
			$this->fail( 'SchemaValidator must refuse an empty menu argument before handle() runs.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
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
	 * The reference must resolve where clients ACTUALLY READ SCHEMAS, which is
	 * the on-demand schema lookup rather than the operation definition on its own.
	 * REQ-0075 moved the schemas out of the dispatcher catalog, so that response
	 * is where this schema now reaches a client: nested at `outputSchema` inside a
	 * larger envelope, exactly as it was nested inside the catalog before. A
	 * pointer fragment resolves against the base URI in force where it appears —
	 * the response root, unless an `$id` moved it.
	 *
	 * So the resolver below is the test, not scaffolding. It is handed the WHOLE
	 * lookup response as its starting document and implements the one rule that
	 * decides the outcome: descending into a node that carries `$id` rebases
	 * every reference beneath it on that node. Without the `$id`, every
	 * `#/$defs/menuItem` in the response is resolved against a response root that
	 * has no `$defs` member, and the reference a client is supposed to follow
	 * leads nowhere.
	 *
	 * Mutation that breaks this: deleting `'$id' => self::OUTPUT_SCHEMA_ID` from
	 * MenuGet's outputSchema.
	 */
	public function test_every_schema_reference_resolves_from_the_schema_response(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		// The lookup requires `read`; the menu operation requires
		// `edit_theme_options` to be visible at all. Both must be held here or
		// the response under test is a refusal instead of a schema.
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool =>
				in_array( $capability, [ 'read', 'edit_theme_options' ], true )
		);

		$registry = new CapabilityRegistry();
		( new MenusModule() )->register( $registry );

		$response = ( new OperationSchema( $registry ) )->handle(
			[ 'operation' => 'menu-get' ],
			$this->makeContext()
		);

		$found    = [];
		$dangling = [];
		$this->collectRefs( $response, $response, '', $found, $dangling );

		$this->assertNotSame(
			[],
			$found,
			'The menu-get schema response must carry the menu item reference; a test that finds none proves nothing.'
		);

		$this->assertSame(
			[],
			$dangling,
			'A client resolving these references against the schema response finds nothing at the far end of them.'
		);
	}

	/**
	 * Walks one JSON document, resolving every `$ref` against the innermost
	 * enclosing schema resource.
	 *
	 * @param mixed                $node     The node being walked.
	 * @param array<string, mixed> $base     The schema resource references resolve against.
	 * @param string               $path     The node's location, for the failure message.
	 * @param string[]             $found    Collects every reference seen.
	 * @param string[]             $dangling Collects every reference that resolves to nothing.
	 */
	private function collectRefs( mixed $node, array $base, string $path, array &$found, array &$dangling ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( array_key_exists( '$id', $node ) ) {
			$base = $node;
		}

		$pointer = $node['$ref'] ?? null;

		if ( is_string( $pointer ) ) {
			$found[] = $path;

			if ( null === $this->followPointer( $pointer, $base ) ) {
				$dangling[] = $path . ' -> ' . $pointer;
			}
		}

		foreach ( $node as $key => $child ) {
			$this->collectRefs( $child, $base, $path . '/' . $key, $found, $dangling );
		}
	}

	/**
	 * Follows one local JSON Pointer fragment against one schema resource.
	 *
	 * @param string               $pointer The `$ref` value.
	 * @param array<string, mixed> $base    The resource to resolve against.
	 *
	 * @return array<string, mixed>|null The referenced node, or null.
	 */
	private function followPointer( string $pointer, array $base ): ?array {
		if ( ! str_starts_with( $pointer, '#/' ) ) {
			return null;
		}

		$target = $base;

		foreach ( explode( '/', substr( $pointer, 2 ) ) as $segment ) {
			if ( ! is_array( $target ) || ! array_key_exists( $segment, $target ) ) {
				return null;
			}

			$target = $target[ $segment ];
		}

		return is_array( $target ) ? $target : null;
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
	 * depth. The whole tree is already type-checked by the schema test above,
	 * which resolves the `$ref` and walks into `children`; this test exists so a
	 * violation at depth 2 names the node member that broke rather than
	 * reporting that the top-level `items` member did.
	 *
	 * Each node is asserted against the declared `$defs` entry, carrying the
	 * document's `$defs` along as the root so the `children` pointer inside it
	 * still resolves. The item schema is read from the definition rather than
	 * restated, so the test cannot pass against a schema that has drifted.
	 *
	 * Mutations that break this: making objectId a string in
	 * MenuFields::normalizeItem(); dropping a field from the same method;
	 * repointing the `children` `$ref` at a definition that does not exist.
	 */
	public function test_every_node_at_every_depth_matches_the_declared_item_schema(): void {
		$schema   = MenuGet::definition()->outputSchema;
		$declared = $schema['$defs']['menuItem'] + [ '$defs' => $schema['$defs'] ];

		$walk = static function ( array $nodes ) use ( &$walk ): array {
			$seen = [];

			foreach ( $nodes as $node ) {
				$seen[] = $node;
				$seen   = array_merge( $seen, $walk( $node['children'] ) );
			}

			return $seen;
		};

		$nodes = $walk( $this->get()['items'] );

		$this->assertCount( 5, $nodes );

		foreach ( $nodes as $node ) {
			$this->assertSame( $schema['$defs']['menuItem']['required'], array_keys( $node ) );
			$this->assertConformsToOutputSchema( $node, $declared );
		}
	}
}
