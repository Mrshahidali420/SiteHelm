<?php
/**
 * Tests for system-plugin-list.
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
use SiteHelm\Modules\Extensions\PluginList;
use SiteHelm\Tests\Doubles\ExtensionsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the inventory rows it returns.
 *
 * THE UPDATE COLUMN IS THE PART WORTH TESTING HARDEST. It is a join between two
 * WordPress structures that are maintained independently — the installed set and
 * the update transient — and every way that join can go wrong reads as a
 * plausible answer: a plugin absent from the transient reported as having an
 * update, a plugin present in it reported as current, or a stale check reported
 * as a fresh one. So a plugin that is NOT in the transient is seeded in the same
 * test as one that is, because a mapping that returned the same verdict for both
 * would pass a test containing only the second.
 */
final class PluginListTest extends TestCase {

	use ExtensionsWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installExtensionsStubs();
	}

	private function operation( ?ExtensionsPresence $presence = null ): PluginList {
		return new PluginList( $presence ?? new ExtensionsPresence() );
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
		$definition = PluginList::definition();

		$this->assertSame( 'system-plugin-list', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( ModuleId::Extensions, $definition->module );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
	}

	/**
	 * The capability is re-asked inside the handler, not merely declared.
	 *
	 * The declaration gates the dispatcher; this asks again, because an operation
	 * reachable by any other route would otherwise answer with the whole
	 * inventory.
	 */
	public function test_a_caller_who_may_not_administer_the_site_is_refused_before_anything_is_read(): void {
		$this->mayManage = false;
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3', true );

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [ 7, 'manage_options', null ], array_values( $this->capabilityChecks[0] ) );
		}
	}

	/**
	 * THE REFUSAL DOES NOT NAME THE CAPABILITY.
	 *
	 * A refusal that said `manage_options` would tell a caller who has just been
	 * denied the site's inventory exactly which grant to go after, and would do
	 * it in a message that reaches whatever client asked. The remedy sentence
	 * says who to ask instead.
	 */
	public function test_the_refusal_names_no_capability(): void {
		$this->mayManage = false;

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );
			$this->assertStringNotContainsString( 'manage_options', (string) $e->remediation );
		}
	}

	/**
	 * The guard order: capability first, inventory second.
	 *
	 * Asserted by refusing a caller on a request where the inventory is ALSO
	 * unreachable. If the probe ran first, this would return the integration
	 * error and hand an unauthorised caller a fact about the site.
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

	/**
	 * A request that never loaded WordPress's administration API is refused as an
	 * unavailable integration rather than fatalling on an undefined function.
	 */
	public function test_a_request_without_the_plugin_inventory_is_refused(): void {
		try {
			$this->operation( $this->presenceWithNoInventory() )->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_it_returns_every_installed_plugin_with_its_header_facts(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3', true );
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertCount( 2, $result['plugins'] );

		$this->assertSame( 'akismet/akismet.php', $result['plugins'][0]['file'] );
		$this->assertSame( 'akismet', $result['plugins'][0]['slug'] );
		$this->assertSame( 'Akismet Anti-spam', $result['plugins'][0]['name'] );
		$this->assertSame( '5.3', $result['plugins'][0]['version'] );
		$this->assertTrue( $result['plugins'][0]['active'] );

		$this->assertFalse( $result['plugins'][1]['active'] );
	}

	/**
	 * A single-file plugin's slug is its own filename.
	 *
	 * `hello.php` has no directory, and an empty slug would be unusable for
	 * exactly the plugins whose wordpress.org name is easiest to guess wrong.
	 */
	public function test_a_single_file_plugin_takes_its_filename_as_its_slug(): void {
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( 'hello', $result['plugins'][0]['slug'] );
	}

	/**
	 * Network activation is reported as its own flag.
	 *
	 * On a real multisite `is_plugin_active()` also answers true for a
	 * network-activated plugin, so the two are seeded true together here — a
	 * reader that had collapsed them would still pass a test where only one was
	 * set.
	 */
	public function test_a_network_activated_plugin_is_flagged_as_such(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3', true, true );
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2', true );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertTrue( $result['plugins'][0]['networkActivated'] );
		$this->assertTrue( $result['plugins'][1]['active'] );
		$this->assertFalse( $result['plugins'][1]['networkActivated'] );
	}

	/**
	 * The join against the update transient, in both directions at once.
	 */
	public function test_only_the_plugins_named_in_the_transient_have_an_update_waiting(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3', true );
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$this->seedPluginUpdates( [ 'akismet/akismet.php' => '5.4' ], 1_799_000_000 );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertTrue( $result['plugins'][0]['updateAvailable'] );
		$this->assertSame( '5.4', $result['plugins'][0]['newVersion'] );

		$this->assertFalse( $result['plugins'][1]['updateAvailable'] );
		$this->assertNull( $result['plugins'][1]['newVersion'] );

		$this->assertSame( 1_799_000_000, $result['updateChecked'] );
	}

	/**
	 * An update row for a plugin that is not installed adds no row.
	 *
	 * The transient outlives the plugins it describes, so it can name a plugin
	 * that has since been deleted. The answer is the installed set, joined
	 * against the transient — never the union of the two.
	 */
	public function test_an_update_for_a_plugin_that_is_not_installed_is_ignored(): void {
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$this->seedPluginUpdates( [ 'deleted/deleted.php' => '9.9' ] );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertCount( 1, $result['plugins'] );
		$this->assertFalse( $result['plugins'][0]['updateAvailable'] );
	}

	/**
	 * NULL AND ZERO ARE DIFFERENT ANSWERS. A site WordPress has never checked has
	 * no time to report, which is not the same as a check that happened at the
	 * epoch — and a caller deciding whether to trust the update column needs to
	 * be able to tell those apart.
	 */
	public function test_a_site_with_no_update_transient_reports_no_check_time(): void {
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertNull( $result['updateChecked'] );
		$this->assertFalse( $result['plugins'][0]['updateAvailable'] );
	}

	/**
	 * A transient without a check time reports none, rather than reporting the
	 * updates it carries as freshly found.
	 */
	public function test_a_transient_with_no_check_time_reports_no_check_time(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3' );
		$this->seedPluginUpdates( [ 'akismet/akismet.php' => '5.4' ] );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertTrue( $result['plugins'][0]['updateAvailable'] );
		$this->assertNull( $result['updateChecked'] );
	}

	/**
	 * A site with no plugins answers with an empty list, not with an error.
	 *
	 * EMPTY IS AN ANSWER HERE. `[]` says the site has none; anything else would
	 * make a caller unable to distinguish "none" from "could not tell".
	 */
	public function test_a_site_with_no_plugins_answers_with_an_empty_list(): void {
		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( [], $result['plugins'] );
		$this->assertNull( $result['updateChecked'] );
	}

	/**
	 * The response is the shape the catalog promises, checked against the
	 * declared schema rather than against a copy of it written here.
	 */
	public function test_the_response_conforms_to_the_declared_output_schema(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3', true, true );
		$this->seedPlugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$this->seedPluginUpdates( [ 'akismet/akismet.php' => '5.4' ], 1_799_000_000 );

		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [], $this->context() ),
			PluginList::definition()->outputSchema
		);
	}

	/**
	 * The empty response conforms too, which the populated one cannot prove: an
	 * `items` schema is never consulted when there are no items.
	 */
	public function test_the_empty_response_conforms_to_the_declared_output_schema(): void {
		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [], $this->context() ),
			PluginList::definition()->outputSchema
		);
	}

	/**
	 * A presence gate whose probe answers false.
	 *
	 * `function_exists()` cannot be made to answer false for a function the test
	 * suite has defined, so the refusal branch is reached the way MediaFetch's
	 * is: by overriding the protected probe, which contains the probe and
	 * nothing else.
	 */
	private function presenceWithNoInventory(): ExtensionsPresence {
		return new class() extends ExtensionsPresence {

			/**
			 * Answers as a request that never loaded the administration API.
			 */
			protected function adminPluginApiAvailable(): bool {
				return false;
			}
		};
	}

	public function test_a_plugin_parked_behind_its_own_wizard_is_reported_as_pending(): void {
		// Every other column reports this plugin as healthy: installed, active,
		// current version, no update waiting. It is doing nothing at all.
		$this->seedPlugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO', '1.0.230', true );

		$row = $this->operation()->handle( [], $this->context() )['plugins'][0];

		$this->assertTrue( $row['active'] );
		$this->assertSame( 'pending', $row['onboarding'] );
	}

	public function test_a_plugin_whose_setup_was_finished_is_reported_as_complete(): void {
		$this->seedPlugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO', '1.0.230', true );
		$this->seedOption( 'rank_math_is_configured', true );

		$this->assertSame( 'complete', $this->operation()->handle( [], $this->context() )['plugins'][0]['onboarding'] );
	}

	public function test_a_plugin_siteHelm_has_no_recipe_for_reports_null_rather_than_complete(): void {
		$this->seedPlugin( 'akismet/akismet.php', 'Akismet', '5.3', true );

		$this->assertNull( $this->operation()->handle( [], $this->context() )['plugins'][0]['onboarding'] );
	}
}
