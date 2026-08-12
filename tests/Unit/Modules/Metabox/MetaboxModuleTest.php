<?php
/**
 * Tests for MetaboxModule's own declarations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Metabox\MetaboxModule;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What the module says about itself before it registers anything.
 *
 * IT IS A SEPARATE FILE FROM MetaboxDefinitionInvariantsTest, and the ACF module —
 * whose shape this module otherwise copies exactly — kept both in one. The split is
 * this module's plan asking for both files by name, and it earns its keep here for
 * a reason ACF did not have: this module registers no operation until a later task,
 * so a combined file would open with a long block of module assertions followed by
 * a definition net that iterates an empty list. Keeping them apart makes it visible
 * which of the two currently has something to say.
 *
 * THE THREE HEALTH STATES ARE THE SUBSTANCE HERE. Each is asserted in a state where
 * it can actually be distinguished from the others: the storage-unavailable case
 * installs Meta Box on purpose, because with Meta Box absent that branch and the
 * plugin-absent branch answer identically and the assertion would pass without the
 * storage check existing at all.
 */
final class MetaboxModuleTest extends TestCase {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Required by the shared double's contract. Nothing here asks a capability
	 * question, but the trait installs one and PHP 8.1 has no trait properties that
	 * would not collide with the using class's own.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order. Required by the double's contract.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled Meta Box call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->capabilityChecks = [];
		$this->metaboxCalls     = [];
	}

	/**
	 * Makes Installer::isAvailable() answer the given readiness.
	 *
	 * @param bool $ready Whether the change-engine tables are usable.
	 */
	private function stubStorage( bool $ready ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key
					? ( $ready ? Installer::STATUS_READY : Installer::STATUS_UNAVAILABLE )
					: $fallback
		);
	}

	// ------------------------------------------------------- module identity

	public function test_the_module_is_an_integration_module_identified_as_metabox(): void {
		$module = new MetaboxModule();

		$this->assertInstanceOf( IntegrationModule::class, $module );
		$this->assertSame( ModuleId::Metabox, $module->id() );
	}

	/**
	 * The name an operator reads is the plugin's own spelling, two words.
	 *
	 * The module id is `metabox` because an enum case is a slug, but the plugin
	 * someone installs and searches for is called Meta Box. A health report naming it
	 * otherwise sends that operator looking for something that does not exist.
	 */
	public function test_the_module_reports_the_plugins_own_name(): void {
		$this->assertSame( 'Meta Box', ( new MetaboxModule() )->displayName() );
	}

	/**
	 * The advertised floor is the enforced floor, by construction.
	 *
	 * Asserted against MetaboxPresence::MIN_VERSION rather than against `'>=5.3.0'`,
	 * so this passes only while the two are the same number. A literal here would let
	 * the module advertise a floor its presence gate had since moved away from, which
	 * invites a client to trust a version check that never runs.
	 */
	public function test_the_dependency_range_is_built_from_the_presence_gates_floor(): void {
		$dependency = ( new MetaboxModule() )->dependency();

		$this->assertSame( 'meta-box', $dependency['name'] );
		$this->assertSame( '>=' . MetaboxPresence::MIN_VERSION, $dependency['versionRange'] );
	}

	/**
	 * No `terms`. A Meta Box field value is post meta and the post row is what a
	 * reader gets it from; V1 addresses post-object groups only, so no operation here
	 * writes a taxonomy term, and declaring a cache group nothing invalidates would
	 * flush a cache on every write for no reason.
	 */
	public function test_the_module_declares_only_the_caches_its_writes_can_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta' ], ( new MetaboxModule() )->cacheCleanup() );
	}

	/**
	 * A module absent from the plugin's table is never constructed, so it registers
	 * nothing however complete its registration method is. The table entry is as much
	 * a part of "this module exists" as the registration method is.
	 */
	public function test_the_plugin_boot_table_carries_the_metabox_module(): void {
		$this->assertContains( MetaboxModule::class, Plugin::MODULE_CLASSES );
	}

	/**
	 * The module is constructible with no arguments, which is what the boot table
	 * does to it.
	 *
	 * Plugin's table holds class names and constructs each with no arguments inside
	 * the isolation boundary. A constructor that had grown a required parameter would
	 * throw there and be caught, so the module would simply never appear — silently,
	 * and with the rest of the plugin still healthy.
	 */
	public function test_the_module_constructs_with_no_arguments(): void {
		$this->assertSame( ModuleId::Metabox, ( new MetaboxModule( null ) )->id() );
	}

	// --------------------------------------------------------- module health

	/**
	 * THE ORDINARY STATE OF MOST SITES: the tables are fine, Meta Box simply is not
	 * installed. Reporting Active here would let the fields-read catalog advertise an
	 * operation every invocation then refuses.
	 *
	 * Meta Box is deliberately NOT installed in this process, which is the shared
	 * process's default state.
	 */
	public function test_storage_ready_but_metabox_absent_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new MetaboxModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_metabox_present_reports_active_with_the_installed_version(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4' );
		$this->stubStorage( true );

		$health = ( new MetaboxModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '5.9.4', $health['version'] );
	}

	/**
	 * The tables branch wins even with Meta Box installed, which is why this test
	 * installs it: with Meta Box absent the two branches are indistinguishable and the
	 * assertion would pass without the tables check existing at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_metabox_present_but_storage_unavailable_reports_inactive_with_no_version(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4' );
		$this->stubStorage( false );

		$health = ( new MetaboxModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * The version is passed through rather than coerced.
	 *
	 * A site whose RWMB_VER holds something unreadable answers a null version with
	 * Active health, and that is the honest pair: the plugin is loaded, and there is
	 * no version to version-block against. Casting null to `''` would turn "not
	 * detectable" into "detected as empty", which a version comparison would then act
	 * on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_version_is_reported_as_null_alongside_active_health(): void {
		define( MetaboxPresence::VERSION_CONSTANT, [ '5.9.4' ] );
		Functions\when( MetaboxPresence::PROBE_FUNCTION )->justReturn( null );
		$this->stubStorage( true );

		$health = ( new MetaboxModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}
}
