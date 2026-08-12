<?php
/**
 * Tests for MetaboxPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Tests\TestCase;

/**
 * The one gate that is allowed to name an RWMB symbol.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is that `isLoaded()` requires BOTH
 * signals. One signal is not enough for the reason it was not enough for Elementor
 * or for ACF: `RWMB_VER` is a constant any `wp-config.php` or mu-plugin may define
 * for an unrelated reason, and a bare function name can be shipped by anything.
 * Only the pair means "Meta Box is here and the registry this module calls can be
 * addressed". Two tests below hold each half of the conjunction on its own, so
 * mutating the `&&` into an `||` kills both while the both-present case keeps
 * passing.
 *
 * `version()` NEVER CASTS. A constant holding an array answers null, because
 * `(string)` on an array is a fatal and a version this code cannot read is
 * indistinguishable, for planning purposes, from no version at all.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, not decoration. `RWMB_VER` is a constant and a
 * function Brain Monkey defines is a real global function; both are permanent for
 * the life of a PHP process. Defining either in the shared process would make every
 * later test in the suite — including the absent-plugin cases in this very file —
 * run against a site that has Meta Box installed, and those cases would then pass
 * or fail for reasons unrelated to what they assert. The shared process is
 * therefore always a site WITHOUT Meta Box, which is also the ordinary state of
 * most WordPress sites.
 */
final class MetaboxPresenceTest extends TestCase {

	/**
	 * The gate under test.
	 */
	private MetaboxPresence $presence;

	protected function setUp(): void {
		parent::setUp();
		$this->presence = new MetaboxPresence();
	}

	/**
	 * Defines the version constant this process will carry for good.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; see the class
	 * docblock for why.
	 *
	 * @param mixed $version The value RWMB_VER holds.
	 */
	private function defineVersionConstant( mixed $version ): void {
		if ( ! defined( MetaboxPresence::VERSION_CONSTANT ) ) {
			define( MetaboxPresence::VERSION_CONSTANT, $version );
		}
	}

	/**
	 * Installs the probe function this process will carry for good.
	 *
	 * The body is never invoked by the gate — `function_exists()` asks only whether
	 * the name is defined — so the double deliberately models no Meta Box behaviour
	 * at all. MetaboxApi's tests are where the answer shape matters.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 */
	private function defineProbeFunction(): void {
		Functions\when( MetaboxPresence::PROBE_FUNCTION )->justReturn( null );
	}

	public function test_a_site_without_metabox_reports_not_loaded_and_no_version(): void {
		// Neither signal is installed in this process, which is the ordinary state of
		// most WordPress sites and therefore the state the module has to survive
		// without fataling.
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertNull( $this->presence->version() );
	}

	/**
	 * The version constant alone is not Meta Box.
	 *
	 * Reachable in practice: a constant is global namespace with no owner, and a
	 * `wp-config.php`, an mu-plugin, or a deactivated Meta Box whose bootstrap file
	 * was still read can each leave it defined with no Meta Box API behind it.
	 * Loading on this signal alone would send every call in the module into an
	 * undefined function.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_version_constant_without_the_probe_function_is_not_loaded(): void {
		$this->defineVersionConstant( '5.9.4' );

		$this->assertFalse(
			$this->presence->isLoaded(),
			'A defined RWMB_VER with no rwmb_get_registry() behind it is not Meta Box.'
		);
	}

	/**
	 * The probe function alone is not Meta Box either.
	 *
	 * A bare function name is the weakest possible uniqueness claim on a WordPress
	 * site, and without the constant there is no version for the policy layer to
	 * version-block against, so a site passing on this signal alone would be served
	 * by operations that could not be blocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_probe_function_without_the_version_constant_is_not_loaded(): void {
		$this->defineProbeFunction();

		$this->assertFalse(
			$this->presence->isLoaded(),
			'A defined rwmb_get_registry() with no RWMB_VER is not Meta Box.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_both_signals_together_report_loaded_and_the_installed_version(): void {
		$this->defineVersionConstant( '5.9.4' );
		$this->defineProbeFunction();

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertSame( '5.9.4', $this->presence->version() );
	}

	/**
	 * A scalar that is not a string is still a readable version.
	 *
	 * `define( 'RWMB_VER', 5 )` is legal and a site could carry it. Reading it as
	 * `'5'` is a version range comparison can act on; refusing it would report a site
	 * with Meta Box as a site without a detectable version.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_non_string_scalar_version_constant_is_read_as_a_string(): void {
		$this->defineVersionConstant( 5 );

		$this->assertSame( '5', $this->presence->version() );
	}

	/**
	 * THE NO-BLIND-CAST TEST. `define()` accepts an array, and `(string)` on an array
	 * is a fatal — not a notice, not an empty string. A version this code cannot read
	 * answers null, which is the same answer a site with no Meta Box gives, and that
	 * is the correct collapse: neither can be version-blocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_version_constant_holding_an_array_answers_null_rather_than_being_cast(): void {
		$this->defineVersionConstant( [ '5.9.4' ] );

		$this->assertNull(
			$this->presence->version(),
			'A non-scalar RWMB_VER must answer null; casting it is a fatal.'
		);
	}

	public function test_the_declared_symbol_names_are_the_ones_metabox_publishes(): void {
		// Pinned because MetaboxApi's containment guarantee is stated in terms of
		// these two names, and because isLoaded() probes the exact API surface this
		// module enters through rather than some other Meta Box function that happens
		// to exist.
		$this->assertSame( 'RWMB_VER', MetaboxPresence::VERSION_CONSTANT );
		$this->assertSame( 'rwmb_get_registry', MetaboxPresence::PROBE_FUNCTION );
	}

	public function test_the_minimum_supported_version_is_frozen_at_five_three(): void {
		// Pinned because every Metabox definition's supportedVersions range is built
		// from this constant, and the golden definition fixture would absorb a change
		// to it silently.
		//
		// 5.3.0 IS THE ALL-SYMBOLS FLOOR, not the registry floor. `rwmb_get_registry()`
		// appears at 4.11 and the registry gains `get_by()` at 4.13.0, but
		// `rwmb_set_meta()` — the write this module's one write operation is built on
		// — first appears at 5.3.0. A floor below it would advertise support for sites
		// where the write cannot run at all.
		$this->assertSame( '5.3.0', MetaboxPresence::MIN_VERSION );
	}
}
