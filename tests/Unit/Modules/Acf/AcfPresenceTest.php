<?php
/**
 * Tests for AcfPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Tests\TestCase;

/**
 * The one gate that is allowed to name an ACF symbol.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is that `isLoaded()` requires BOTH
 * signals. The reference implementation gates on `function_exists(
 * 'acf_get_field_groups' )` alone, and one signal is not enough for the reason
 * it was not enough for Elementor: `ACF_VERSION` is a constant any
 * `wp-config.php` or mu-plugin may define for an unrelated reason, and a bare
 * function name can be shipped by anything. Only the pair means "ACF is here
 * and the API this module calls can be addressed". Two tests below hold each
 * half of the conjunction on its own, so mutating the `&&` into an `||` kills
 * both while the both-present case keeps passing.
 *
 * `version()` NEVER CASTS. A constant holding an array answers null, because
 * `(string)` on an array is a fatal and a version this code cannot read is
 * indistinguishable, for planning purposes, from no version at all.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, not decoration. `ACF_VERSION` is a
 * constant and a function Brain Monkey defines is a real global function; both
 * are permanent for the life of a PHP process. Defining either in the shared
 * process would make every later test in the suite — including the
 * absent-plugin cases in this very file and the absent-plugin refusals in
 * AcfGroupListTest — run against a site that has ACF installed, and those cases
 * would then pass or fail for reasons unrelated to what they assert. The shared
 * process is therefore always a site WITHOUT ACF, which is also the ordinary
 * state of most WordPress sites.
 */
final class AcfPresenceTest extends TestCase {

	/**
	 * The gate under test.
	 */
	private AcfPresence $presence;

	protected function setUp(): void {
		parent::setUp();
		$this->presence = new AcfPresence();
	}

	/**
	 * Defines the version constant this process will carry for good.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; see the class
	 * docblock for why.
	 *
	 * @param mixed $version The value ACF_VERSION holds.
	 */
	private function defineVersionConstant( mixed $version ): void {
		if ( ! defined( AcfPresence::VERSION_CONSTANT ) ) {
			define( AcfPresence::VERSION_CONSTANT, $version );
		}
	}

	/**
	 * Installs the probe function this process will carry for good.
	 *
	 * The body is never invoked by the gate — `function_exists()` asks only
	 * whether the name is defined — so the double deliberately models no ACF
	 * behaviour at all. AcfApi's tests are where the answer shape matters.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 */
	private function defineProbeFunction(): void {
		Functions\when( AcfPresence::PROBE_FUNCTION )->justReturn( [] );
	}

	/**
	 * Installs a fake ACF at a chosen version. Permanent in the process, so
	 * every caller runs in its own.
	 *
	 * @param mixed $version The value ACF_VERSION should hold.
	 */
	private function installAcfVersion( mixed $version ): void {
		Functions\when( AcfPresence::PROBE_FUNCTION )->justReturn( [] );
		if ( ! defined( AcfPresence::VERSION_CONSTANT ) ) {
			define( AcfPresence::VERSION_CONSTANT, $version );
		}
	}

	public function test_a_site_without_acf_reports_not_loaded_and_no_version(): void {
		// Neither signal is installed in this process, which is the ordinary state
		// of most WordPress sites and therefore the state the module has to survive
		// without fataling.
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertNull( $this->presence->version() );
	}

	/**
	 * The version constant alone is not ACF.
	 *
	 * Reachable in practice: a constant is global namespace with no owner, and a
	 * `wp-config.php`, an mu-plugin, or a deactivated ACF whose bootstrap file was
	 * still read can each leave it defined with no ACF API behind it. Loading on
	 * this signal alone would send every call in the module into an undefined
	 * function.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_version_constant_without_the_probe_function_is_not_loaded(): void {
		$this->defineVersionConstant( '6.2.7' );

		$this->assertFalse(
			$this->presence->isLoaded(),
			'A defined ACF_VERSION with no acf_get_field_groups() behind it is not ACF.'
		);
	}

	/**
	 * The probe function alone is not ACF either.
	 *
	 * This is the half the reference implementation relies on by itself. A bare
	 * function name is the weakest possible uniqueness claim on a WordPress site,
	 * and without the constant there is no version for the policy layer to
	 * version-block against, so a site passing on this signal alone would be
	 * served by operations that could not be blocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_probe_function_without_the_version_constant_is_not_loaded(): void {
		$this->defineProbeFunction();

		$this->assertFalse(
			$this->presence->isLoaded(),
			'A defined acf_get_field_groups() with no ACF_VERSION is not ACF.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_both_signals_together_report_loaded_and_the_installed_version(): void {
		$this->defineVersionConstant( '6.2.7' );
		$this->defineProbeFunction();

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertSame( '6.2.7', $this->presence->version() );
	}

	/**
	 * A scalar that is not a string is still a readable version.
	 *
	 * `define( 'ACF_VERSION', 6 )` is legal and a site could carry it. Reading it
	 * as `'6'` is a version range comparison can act on; refusing it would report
	 * a site with ACF as a site without a detectable version.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_non_string_scalar_version_constant_is_read_as_a_string(): void {
		$this->defineVersionConstant( 6 );

		$this->assertSame( '6', $this->presence->version() );
	}

	/**
	 * THE NO-BLIND-CAST TEST. `define()` accepts an array, and `(string)` on an
	 * array is a fatal — not a notice, not an empty string. A version this code
	 * cannot read answers null, which is the same answer a site with no ACF gives,
	 * and that is the correct collapse: neither can be version-blocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_version_constant_holding_an_array_answers_null_rather_than_being_cast(): void {
		$this->defineVersionConstant( [ '6.2.7' ] );

		$this->assertNull(
			$this->presence->version(),
			'A non-scalar ACF_VERSION must answer null; casting it is a fatal.'
		);
	}

	/**
	 * Absent is not the same answer as too old, and neither is supported.
	 */
	public function test_an_absent_acf_is_not_supported(): void {
		$this->assertFalse( ( new AcfPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_acf_below_the_floor_is_not_supported(): void {
		$this->installAcfVersion( '5.8.0' );

		$this->assertFalse( ( new AcfPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_acf_at_or_above_the_floor_is_supported(): void {
		$this->installAcfVersion( AcfPresence::MIN_VERSION );

		$this->assertTrue( ( new AcfPresence() )->isSupported() );
	}

	/**
	 * AN UNREADABLE VERSION IS NOT TREATED AS AN OLD ONE. A constant another
	 * plugin mangled is a claim this gate cannot substantiate in either
	 * direction, and refusing on it would block a working site over someone
	 * else's bug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_version_is_still_supported(): void {
		$this->installAcfVersion( [ '5.8.0' ] );

		$this->assertTrue( ( new AcfPresence() )->isSupported() );
	}

	public function test_the_declared_symbol_names_are_the_ones_acf_publishes(): void {
		// Pinned because AcfApi's containment guarantee is stated in terms of these
		// two names, and because isLoaded() probes the exact API surface this module
		// calls rather than some other ACF function that happens to exist.
		$this->assertSame( 'ACF_VERSION', AcfPresence::VERSION_CONSTANT );
		$this->assertSame( 'acf_get_field_groups', AcfPresence::PROBE_FUNCTION );
	}

	public function test_the_minimum_supported_version_is_frozen_at_five_nine(): void {
		// Pinned because every ACF definition's supportedVersions range is built
		// from this constant, and the golden definition fixture would absorb a
		// change to it silently.
		$this->assertSame( '5.9.0', AcfPresence::MIN_VERSION );
	}
}
