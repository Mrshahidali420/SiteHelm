<?php
/**
 * Tests for ElementorModule's own health declaration.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * WHAT THE MODULE SAYS ABOUT ITSELF BEFORE ANY OPERATION RUNS.
 *
 * `health()` is not one report among several: it is the value the dispatcher
 * gates every Elementor operation on, so an answer of `inactive` refuses the
 * whole module at once and an answer of `active` on a site that cannot serve it
 * lets every read and every write through to fail one layer deeper, where the
 * failure is a fatal rather than a refusal. Only the version-blocked path had a
 * test, and it lived in the diagnostics suite because that is what needed it;
 * the three other states this method can return had none anywhere.
 *
 * EACH STATE IS ASSERTED WHERE IT CAN BE TOLD APART FROM THE OTHERS. The
 * storage-unavailable case installs Elementor on purpose: with Elementor absent
 * that branch and the plugin-absent branch answer identically, and the assertion
 * would pass on a module that had no storage check at all.
 *
 * THE VERSION IS ASSERTED ALONGSIDE THE HEALTH ON EVERY PATH, because the two
 * are one answer. A version-blocked module reporting null would read as "no
 * version could be detected", which is a different diagnosis with a different
 * fix, and an inactive module reporting a version would advertise a readiness
 * that does not exist.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `\Elementor\Plugin` is a class alias; both are permanent for the life of a PHP
 * process, so a test that installs either in the shared process leaves every
 * later test running against a site that has Elementor installed — including the
 * absent-Elementor case in this very file.
 */
final class ElementorModuleTest extends TestCase {

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

	/**
	 * Installs a fake Elementor at a chosen version.
	 *
	 * The alias target is a user-defined class carrying a static `$instance`,
	 * because that is the shape `\Elementor\Plugin` really has and the shape the
	 * presence gate reads one method away from the two gates used here.
	 * class_alias() also refuses an internal class such as stdClass outright.
	 *
	 * The fake is declared in this file rather than shared, because a class
	 * declared beside a test class is not reachable from another test file: the
	 * suite autoloads test classes by PSR-4 from their own file names, so a fake
	 * living in a sibling's file would resolve only when that sibling happened to
	 * have been loaded first. Its behaviour is copied from the presence suite's
	 * double, not invented.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 *
	 * @param string $version The value ELEMENTOR_VERSION should hold.
	 */
	private function installElementor( string $version ): void {
		if ( ! class_exists( ElementorPresence::PLUGIN_CLASS, false ) ) {
			class_alias( ModuleHealthElementorPlugin::class, ElementorPresence::PLUGIN_CLASS );
		}
		if ( ! defined( ElementorPresence::VERSION_CONSTANT ) ) {
			define( ElementorPresence::VERSION_CONSTANT, $version );
		}
	}

	/**
	 * THE ORDINARY STATE OF MOST SITES: the tables are fine, Elementor simply is
	 * not installed. Reporting anything but inactive here would let the document
	 * reads advertise operations every invocation then refuses.
	 *
	 * Elementor is deliberately NOT installed in this process, which is the shared
	 * process's default state.
	 */
	public function test_storage_ready_but_elementor_absent_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_elementor_present_reports_active_with_the_installed_version(): void {
		$this->installElementor( '3.25.0' );
		$this->stubStorage( true );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '3.25.0', $health['version'] );
	}

	/**
	 * An Elementor below the floor this module advertises is version-blocked and
	 * keeps its version, because an operator told to update needs to see the
	 * number they are updating from.
	 *
	 * The installed version is checked against ElementorPresence::MIN_VERSION
	 * rather than trusted as a bare literal, so this test follows the floor if the
	 * floor moves instead of quietly becoming a test of a supported version.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_below_the_advertised_floor_reports_version_blocked_with_its_version(): void {
		$this->assertTrue(
			version_compare( '2.9.14', ElementorPresence::MIN_VERSION, '<' ),
			'This test only means anything while 2.9.14 is below the enforced floor.'
		);

		$this->installElementor( '2.9.14' );
		$this->stubStorage( true );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '2.9.14', $health['version'] );
	}

	/**
	 * SiteHelm's own tables are a dependency exactly like Elementor is, and their
	 * absence wins over everything else: with no tables the module cannot serve a
	 * call whatever Elementor is doing, and reporting Elementor's version here
	 * would advertise a readiness that does not exist.
	 *
	 * This test installs Elementor for that reason. With Elementor absent the
	 * storage branch and the plugin-absent branch answer identically and the
	 * assertion would pass on a module whose storage check had been deleted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_elementor_present_but_storage_unavailable_reports_inactive_with_no_version(): void {
		$this->installElementor( '3.25.0' );
		$this->stubStorage( false );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}
}

/**
 * Stands in for `\Elementor\Plugin`.
 *
 * It reproduces exactly one upstream behaviour, because it is the only one the
 * gates in this file reach: a public static `$instance` holding the plugin
 * singleton, null before Elementor has booted. It deliberately models nothing
 * else — no widget manager, no boot sequence. Any assertion needing those must
 * be written against the presence suite's fuller double instead.
 *
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The double belongs beside the tests that need it; a file of its own would be autoloaded into every Elementor test, and this one exists to prove what happens when Elementor is absent.
final class ModuleHealthElementorPlugin {

	/**
	 * The plugin singleton, null before Elementor has booted.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
