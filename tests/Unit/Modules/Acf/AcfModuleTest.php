<?php
/**
 * Tests for AcfModule's own health declaration.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * WHAT THE MODULE SAYS ABOUT ITSELF BEFORE ANY OPERATION RUNS.
 *
 * `health()` is not one report among several: it is the value the dispatcher
 * gates every ACF operation on, so an answer of `inactive` refuses fifteen
 * operations at once and an answer of `active` on a site that cannot serve them
 * lets fifteen through to fail one layer deeper, where the failure is a fatal
 * rather than a refusal. Only the version-blocked path had a test, and it lived
 * in the diagnostics suite because that is what needed it; the three other
 * states this method can return had none anywhere.
 *
 * EACH STATE IS ASSERTED WHERE IT CAN BE TOLD APART FROM THE OTHERS. The
 * storage-unavailable case installs ACF on purpose: with ACF absent that branch
 * and the plugin-absent branch answer identically, and the assertion would pass
 * on a module that had no storage check at all.
 *
 * THE VERSION IS ASSERTED ALONGSIDE THE HEALTH ON EVERY PATH, because the two
 * are one answer. A version-blocked module reporting null would read as "no
 * version could be detected", which is a different diagnosis with a different
 * fix, and an inactive module reporting a version would advertise a readiness
 * that does not exist.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ACF_VERSION` is a constant and a constant
 * is permanent for the life of a PHP process, so a test that defines one in the
 * shared process leaves every later test running against a site that has ACF
 * installed — including the absent-ACF case in this very file.
 */
final class AcfModuleTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Required by the shared double's contract. Nothing here asks a capability
	 * question, but PHP 8.1 has no trait properties that would not collide with
	 * the using class's own, so each user declares them.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order. Required by the double's contract.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made. Required by the double.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->capabilityChecks = [];
		$this->acfCalls         = [];
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

	/**
	 * THE ORDINARY STATE OF MOST SITES: the tables are fine, ACF simply is not
	 * installed. Reporting anything but inactive here would let the field reads
	 * advertise operations every invocation then refuses.
	 *
	 * ACF is deliberately NOT installed in this process, which is the shared
	 * process's default state.
	 */
	public function test_storage_ready_but_acf_absent_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_acf_present_reports_active_with_the_installed_version(): void {
		$this->installAcf( [], [], '6.2.7' );
		$this->stubStorage( true );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '6.2.7', $health['version'] );
	}

	/**
	 * An ACF below the floor this module advertises is version-blocked and keeps
	 * its version, because an operator told to update needs to see the number they
	 * are updating from.
	 *
	 * The installed version is chosen against AcfPresence::MIN_VERSION rather than
	 * written as a bare literal, so this test follows the floor if the floor moves
	 * instead of quietly becoming a test of a supported version.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_acf_below_the_advertised_floor_reports_version_blocked_with_its_version(): void {
		$this->assertTrue(
			version_compare( '5.8.0', AcfPresence::MIN_VERSION, '<' ),
			'This test only means anything while 5.8.0 is below the enforced floor.'
		);

		$this->installAcf( [], [], '5.8.0' );
		$this->stubStorage( true );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '5.8.0', $health['version'] );
	}

	/**
	 * SiteHelm's own tables are a dependency exactly like ACF is, and their
	 * absence wins over everything else: with no tables the module cannot serve a
	 * call whatever ACF is doing, and reporting ACF's version here would advertise
	 * a readiness that does not exist.
	 *
	 * This test installs ACF for that reason. With ACF absent the storage branch
	 * and the plugin-absent branch answer identically and the assertion would pass
	 * on a module whose storage check had been deleted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_acf_present_but_storage_unavailable_reports_inactive_with_no_version(): void {
		$this->installAcf( [], [], '6.2.7' );
		$this->stubStorage( false );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}
}
