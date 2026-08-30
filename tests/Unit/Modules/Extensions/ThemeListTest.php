<?php
/**
 * Tests for system-theme-list.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Extensions;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Extensions\ExtensionsPresence;
use SiteHelm\Modules\Extensions\ThemeList;
use SiteHelm\Tests\Doubles\ExtensionsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the theme rows it returns.
 *
 * TWO THINGS HERE ARE NOT PLUGINLIST'S. The first is the child-theme pair: a
 * child theme is two directories, and a row naming only one of them loses the
 * relationship — so `stylesheet` and `template` are asserted to differ on a
 * child and to match on a theme that is its own parent. The second is the
 * transient's shape: WordPress writes `update_themes` with ARRAY rows where
 * `update_plugins` has objects, so the two readers cannot share an
 * implementation, and a reader that borrowed the plugin one would report every
 * theme as current. The update test is what stands between those two facts.
 */
final class ThemeListTest extends TestCase {

	use ExtensionsWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installExtensionsStubs();
	}

	private function operation( ?ExtensionsPresence $presence = null ): ThemeList {
		return new ThemeList( $presence ?? new ExtensionsPresence() );
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_is_a_low_risk_read_under_the_extensions_module(): void {
		$definition = ThemeList::definition();

		$this->assertSame( 'system-theme-list', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( ModuleId::Extensions, $definition->module );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
	}

	/**
	 * `manage_options`, NOT `switch_themes`.
	 *
	 * The two are not the same question. This operation changes nothing, and
	 * gating a read on the capability its Pro sibling writes with would refuse a
	 * caller who may see the site's configuration but not alter its appearance.
	 */
	public function test_it_gates_on_administering_the_site_rather_than_on_switching_themes(): void {
		$this->assertSame( [ 'manage_options' ], ThemeList::definition()->requiredCapabilities );
	}

	public function test_a_caller_who_may_not_administer_the_site_is_refused_before_anything_is_read(): void {
		$this->mayManage = false;
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [ 7, 'manage_options', null ], array_values( $this->capabilityChecks[0] ) );
		}
	}

	/**
	 * The refusal names no capability, for the reason PluginListTest records.
	 */
	public function test_the_refusal_names_no_capability(): void {
		$this->mayManage = false;

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );
			$this->assertStringNotContainsString( 'switch_themes', $e->getMessage() );
			$this->assertStringNotContainsString( 'manage_options', (string) $e->remediation );
		}
	}

	/**
	 * The guard order: capability first, inventory second.
	 */
	public function test_the_capability_is_checked_before_the_inventory_probe(): void {
		$this->mayManage = false;

		try {
			$this->operation( $this->presenceWithNoInventory() )->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_a_request_without_the_theme_inventory_is_refused(): void {
		try {
			$this->operation( $this->presenceWithNoInventory() )->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	/**
	 * Exactly one theme is live, and it is the one `get_stylesheet()` names.
	 *
	 * Asserted against the child rather than the parent, because a site running a
	 * child theme has BOTH installed and a reader comparing against the template
	 * would mark the parent live.
	 */
	public function test_the_live_theme_is_the_one_the_site_is_showing(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );
		$this->seedTheme( 'my-child', 'My Child', '1.0', 'twentytwentyfour' );
		$this->liveStylesheet = 'my-child';

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertFalse( $result['themes'][0]['active'] );
		$this->assertTrue( $result['themes'][1]['active'] );
	}

	/**
	 * A child theme names its parent; a theme that is its own parent repeats
	 * itself.
	 */
	public function test_a_child_theme_names_the_parent_it_renders_from(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );
		$this->seedTheme( 'my-child', 'My Child', '1.0', 'twentytwentyfour' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( 'twentytwentyfour', $result['themes'][0]['stylesheet'] );
		$this->assertSame( 'twentytwentyfour', $result['themes'][0]['template'] );

		$this->assertSame( 'my-child', $result['themes'][1]['stylesheet'] );
		$this->assertSame( 'twentytwentyfour', $result['themes'][1]['template'] );
	}

	public function test_every_row_carries_the_header_name_and_version(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( 'Twenty Twenty-Four', $result['themes'][0]['name'] );
		$this->assertSame( '1.2', $result['themes'][0]['version'] );
	}

	/**
	 * The join against the theme transient — whose rows are arrays, not objects —
	 * in both directions at once.
	 */
	public function test_only_the_themes_named_in_the_transient_have_an_update_waiting(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );
		$this->seedTheme( 'my-child', 'My Child', '1.0', 'twentytwentyfour' );
		$this->seedThemeUpdates( [ 'twentytwentyfour' => '1.3' ], 1_799_000_000 );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertTrue( $result['themes'][0]['updateAvailable'] );
		$this->assertSame( '1.3', $result['themes'][0]['newVersion'] );

		$this->assertFalse( $result['themes'][1]['updateAvailable'] );
		$this->assertNull( $result['themes'][1]['newVersion'] );

		$this->assertSame( 1_799_000_000, $result['updateChecked'] );
	}

	/**
	 * An update row for a theme that is not installed adds no row: the answer is
	 * the installed set joined against the transient, never the union.
	 */
	public function test_an_update_for_a_theme_that_is_not_installed_is_ignored(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );
		$this->seedThemeUpdates( [ 'deleted-theme' => '9.9' ] );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertCount( 1, $result['themes'] );
		$this->assertFalse( $result['themes'][0]['updateAvailable'] );
	}

	/**
	 * Null and zero are different answers — see PluginListTest for the full
	 * reasoning.
	 */
	public function test_a_site_with_no_update_transient_reports_no_check_time(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertNull( $result['updateChecked'] );
		$this->assertFalse( $result['themes'][0]['updateAvailable'] );
	}

	/**
	 * An empty inventory is an answer, not an error.
	 */
	public function test_a_site_with_no_themes_answers_with_an_empty_list(): void {
		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( [], $result['themes'] );
		$this->assertNull( $result['updateChecked'] );
	}

	public function test_the_response_conforms_to_the_declared_output_schema(): void {
		$this->seedTheme( 'twentytwentyfour', 'Twenty Twenty-Four', '1.2' );
		$this->seedTheme( 'my-child', 'My Child', '1.0', 'twentytwentyfour' );
		$this->liveStylesheet = 'my-child';
		$this->seedThemeUpdates( [ 'twentytwentyfour' => '1.3' ], 1_799_000_000 );

		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [], $this->context() ),
			ThemeList::definition()->outputSchema
		);
	}

	/**
	 * The empty response conforms too, which the populated one cannot prove.
	 */
	public function test_the_empty_response_conforms_to_the_declared_output_schema(): void {
		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [], $this->context() ),
			ThemeList::definition()->outputSchema
		);
	}

	/**
	 * A presence gate whose theme probe answers false — the seam PluginListTest
	 * documents, on the other inventory.
	 */
	private function presenceWithNoInventory(): ExtensionsPresence {
		return new class() extends ExtensionsPresence {

			/**
			 * Answers as a request in which the theme API is not loaded.
			 */
			protected function adminThemeApiAvailable(): bool {
				return false;
			}
		};
	}
}
