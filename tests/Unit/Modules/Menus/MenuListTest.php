<?php
/**
 * Tests for MenuList (REQ-0026).
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
use SiteHelm\Modules\Menus\MenuList;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0026: menu listing.
 *
 * The operation answers both the menu list and the location assignments in
 * one call, because a client that asks "what menus does
 * this site have" almost always needs the location assignments in the same
 * breath, and two round trips can disagree with each other.
 */
final class MenuListTest extends TestCase {

	private MenuList $handler;

	/**
	 * The menus wp_get_nav_menus() serves.
	 *
	 * @var stdClass[]
	 */
	private array $menus = [];

	/**
	 * The theme-location map get_nav_menu_locations() serves.
	 *
	 * @var array<string, int>
	 */
	private array $assigned = [];

	/**
	 * The locations the active theme registers, slug to label.
	 *
	 * @var array<string, string>
	 */
	private array $registered = [];

	/**
	 * Whether the resolved WordPress user holds `edit_theme_options`.
	 */
	private bool $permitted = true;

	protected function setUp(): void {
		parent::setUp();

		$this->handler    = new MenuList();
		$this->permitted  = true;
		$this->menus      = [
			$this->makeMenu( 5, 'Primary Navigation', 'primary-navigation', 4 ),
			$this->makeMenu( 9, 'Footer Links', 'footer-links', 2 ),
		];
		$this->assigned   = [ 'primary' => 5 ];
		$this->registered = [
			'primary'   => 'Primary Menu',
			'footer'    => 'Footer Menu',
			'social'    => 'Social Links',
		];

		$this->stubWordPress();
	}

	private function makeMenu( int $id, string $name, string $slug, int $count ): stdClass {
		$menu          = new stdClass();
		$menu->term_id = $id;
		$menu->name    = $name;
		$menu->slug    = $slug;
		$menu->count   = $count;

		return $menu;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool =>
				'edit_theme_options' === $capability && $this->permitted
		);
		Functions\when( 'wp_get_nav_menus' )->alias( fn(): array => $this->menus );
		Functions\when( 'get_nav_menu_locations' )->alias( fn(): array => $this->assigned );
		Functions\when( 'get_registered_nav_menus' )->alias( fn(): array => $this->registered );
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
	 * @return array<string, mixed> The operation result.
	 */
	private function list(): array {
		return $this->handler->handle( [], $this->makeContext() );
	}

	/**
	 * One location row, looked up by slug.
	 *
	 * @param string $slug The theme-location slug.
	 *
	 * @return array<string, mixed> The location row.
	 */
	private function location( string $slug ): array {
		foreach ( $this->list()['locations'] as $row ) {
			if ( $slug === $row['location'] ) {
				return $row;
			}
		}

		$this->fail( "No location row for '{$slug}'." );
	}

	public function test_it_returns_menus_with_item_counts(): void {
		$menus = $this->list()['menus'];

		$this->assertSame( [ 'id', 'name', 'slug', 'itemCount' ], array_keys( $menus[0] ) );
		$this->assertSame( 5, $menus[0]['id'] );
		$this->assertSame( 'Primary Navigation', $menus[0]['name'] );
		$this->assertSame( 'primary-navigation', $menus[0]['slug'] );
		$this->assertSame( 4, $menus[0]['itemCount'] );
		$this->assertSame( 2, $menus[1]['itemCount'] );
	}

	public function test_it_returns_locations_with_the_assigned_menu_id(): void {
		$primary = $this->location( 'primary' );

		$this->assertSame( 'primary', $primary['location'] );
		$this->assertSame( 'Primary Menu', $primary['description'] );
		$this->assertSame( 5, $primary['menuId'] );
		$this->assertSame( 'Primary Navigation', $primary['menuName'] );
	}

	public function test_it_returns_a_location_with_null_when_unassigned(): void {
		$footer = $this->location( 'footer' );

		$this->assertNull( $footer['menuId'] );
		$this->assertNull( $footer['menuName'] );
	}

	/**
	 * A theme mod outlives the menu it names: deleting a menu does not rewrite
	 * `nav_menu_locations`. WordPress renders nothing for such a location, so
	 * reporting the dangling identifier would describe a state the site does not
	 * actually have.
	 */
	public function test_a_location_naming_a_deleted_menu_reports_as_unassigned(): void {
		$this->assigned['social'] = 4242;

		$social = $this->location( 'social' );

		$this->assertNull( $social['menuId'] );
		$this->assertNull( $social['menuName'] );
	}

	/**
	 * `nav_menu_locations` passes through a filter on the way out, so a plugin can
	 * put anything in it. `(int) [ 'x' ]` is 1, so a bare cast would turn a
	 * malformed member into an assignment to menu 1 — an identifier plausible
	 * enough that a client would act on it. The listing must agree with
	 * MenuLocationAssign::project(), which guards the same map on `is_numeric`
	 * and `> 0`.
	 */
	public function test_a_location_holding_a_non_numeric_value_reports_as_unassigned(): void {
		$this->menus[]            = $this->makeMenu( 1, 'First Menu', 'first-menu', 1 );
		$this->assigned['social'] = [ 'x' ];

		$social = $this->location( 'social' );

		$this->assertNull( $social['menuId'] );
		$this->assertNull( $social['menuName'] );
	}

	/**
	 * A stored 0 is what a half-finished third-party write leaves behind, and core
	 * itself treats it as empty.
	 */
	public function test_a_location_holding_zero_reports_as_unassigned(): void {
		$this->assigned['footer'] = 0;

		$this->assertNull( $this->location( 'footer' )['menuId'] );
	}

	/**
	 * The guard must not become stricter than the data: the theme mod is an option
	 * value, and an option round-tripped through the database can carry its
	 * identifiers as numeric strings.
	 */
	public function test_a_location_holding_a_numeric_string_still_resolves_its_menu(): void {
		$this->assigned['footer'] = '9';

		$footer = $this->location( 'footer' );

		$this->assertSame( 9, $footer['menuId'] );
		$this->assertSame( 'Footer Links', $footer['menuName'] );
	}

	/**
	 * The theme registers its locations in whatever order its `register_nav_menus()`
	 * call happened to list them, so the rows are sorted for the same reason
	 * MediaFields::registeredSizes() sorts: identical site state must produce an
	 * identical response.
	 */
	public function test_locations_are_sorted_by_slug_so_the_same_site_answers_the_same_way(): void {
		$this->registered = [
			'social'  => 'Social Links',
			'primary' => 'Primary Menu',
			'footer'  => 'Footer Menu',
		];

		$this->assertSame(
			[ 'footer', 'primary', 'social' ],
			array_column( $this->list()['locations'], 'location' )
		);
	}

	/**
	 * An assignment to a location the active theme does not register is invisible
	 * to the site, and a switched theme leaves plenty of them behind. Emitting it
	 * would invent a location row the theme has no slot for.
	 */
	public function test_an_assignment_to_a_location_the_theme_does_not_register_is_not_listed(): void {
		$this->assigned['legacy-header'] = 9;

		$this->assertSame(
			[ 'footer', 'primary', 'social' ],
			array_column( $this->list()['locations'], 'location' )
		);
	}

	public function test_it_returns_empty_arrays_on_a_site_with_no_menus(): void {
		$this->menus      = [];
		$this->assigned   = [];
		$this->registered = [];

		$result = $this->list();

		$this->assertSame( [], $result['menus'] );
		$this->assertSame( [], $result['locations'] );
	}

	/**
	 * `wp_get_nav_menus()` is documented as returning an array, but it is filtered
	 * (`get_terms`), so a plugin can hand back something else. A cast would turn a
	 * WP_Error into a one-member list of garbage.
	 */
	public function test_a_non_array_menu_list_is_reported_as_no_menus_rather_than_cast(): void {
		Functions\when( 'wp_get_nav_menus' )->justReturn( false );

		$this->assertSame( [], $this->list()['menus'] );
	}

	/**
	 * The same filter that can replace the whole list can also put a member in it
	 * that is not a term. `(int) $thing->term_id` on that is a fatal on a live
	 * site, so the member is skipped and the real menus still answer.
	 */
	public function test_a_member_that_is_not_a_menu_term_is_skipped_rather_than_read(): void {
		$this->menus = [ 'not a term', new stdClass(), $this->makeMenu( 9, 'Footer Links', 'footer-links', 2 ) ];

		$this->assertSame( [ 9 ], array_column( $this->list()['menus'], 'id' ) );
	}

	/**
	 * `get_registered_nav_menus()` reads a global the theme populates, and a theme
	 * that never called `register_nav_menus()` can leave it unset. Iterating that
	 * would warn, so it reports no locations — which is the truth about the site.
	 */
	public function test_a_theme_registering_no_locations_reports_no_locations(): void {
		Functions\when( 'get_registered_nav_menus' )->justReturn( null );

		$result = $this->list();

		$this->assertSame( [], $result['locations'] );
		$this->assertSame( [ 5, 9 ], array_column( $result['menus'], 'id' ) );
	}

	/**
	 * `edit_theme_options` is the declared capability, and PolicyEngine gates on
	 * it, but the answer this operation returns describes theme configuration and
	 * must be bound to the WordPress user the context actually resolved — not to
	 * whatever the policy layer evaluated one layer up.
	 */
	public function test_it_refuses_without_edit_theme_options(): void {
		$this->permitted = false;

		try {
			$this->list();
			$this->fail( 'A caller without edit_theme_options must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * A refusal must not become a disclosure: nothing about the site's menus or
	 * its theme locations may travel in the refusal envelope.
	 */
	public function test_the_refusal_names_no_menu_and_no_location(): void {
		$this->permitted = false;

		try {
			$this->list();
			$this->fail( 'A caller without edit_theme_options must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'Primary Navigation', $e->getMessage() );
			$this->assertStringNotContainsString( 'primary', $e->getMessage() );
		}
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MenuList::definition();

		$this->assertSame( 'menu-list', $definition->id );
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
		$this->assertArrayNotHasKey( 'required', $definition->inputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
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

		$result   = $this->list();
		$registry = new CapabilityRegistry();
		( new MenusModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'menu-list' )->outputSchema
		);
	}
}
