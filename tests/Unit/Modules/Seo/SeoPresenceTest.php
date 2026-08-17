<?php
/**
 * Tests for SeoPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\RankMathProvider;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\YoastProvider;
use SiteHelm\Tests\TestCase;

/**
 * The one gate allowed to name a plugin symbol.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, not decoration. `WPSEO_VERSION` and
 * `RANK_MATH_VERSION` are constants, and a constant is permanent for the life of a
 * PHP process. Defining either in the shared process would make every later test in
 * the suite — including the no-plugin cases in this very file — run against a site
 * that has an SEO plugin installed, and those cases would then pass or fail for
 * reasons unrelated to what they assert. The shared process is therefore always a
 * site with NO SEO plugin, which is also the ordinary state of a WordPress site and
 * the state this module has to survive without fataling.
 *
 * PRECEDENCE IS THE OTHER SUBSTANCE. A site can carry both plugins, and some do
 * during a migration. The gate picks Yoast first and does so on install base alone;
 * the ordering is arbitrary but it must be STABLE, because a precedence that varied
 * by request would let a write land in a different plugin's store than the read that
 * planned it. The both-installed test below is what holds the order.
 *
 * `version()` REPORTS AN OUT-OF-RANGE INSTALL. An operator told "your SEO plugin is
 * too old" needs to see the version they are updating from; null there reads as
 * "nothing detected", which is a different diagnosis with a different fix. That is
 * why `isInstalled()` and `isLoaded()` are separate questions rather than one.
 */
final class SeoPresenceTest extends TestCase {

	/**
	 * The gate under test.
	 */
	private SeoPresence $presence;

	protected function setUp(): void {
		parent::setUp();
		$this->presence = new SeoPresence();
	}

	/**
	 * Defines a version constant this process will carry for good.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; see the class
	 * docblock for why.
	 *
	 * @param string $constant The constant name.
	 * @param mixed  $version  The value it holds.
	 */
	private function defineVersion( string $constant, mixed $version ): void {
		if ( ! defined( $constant ) ) {
			define( $constant, $version );
		}
	}

	public function test_a_site_with_no_seo_plugin_reports_nothing_rather_than_fataling(): void {
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertFalse( $this->presence->isInstalled() );
		$this->assertNull( $this->presence->provider() );
		$this->assertNull( $this->presence->providerName() );
		$this->assertNull( $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_yoast_is_served_by_the_yoast_provider(): void {
		$this->defineVersion( 'WPSEO_VERSION', '20.13' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( YoastProvider::class, $this->presence->provider() );
		$this->assertSame( 'yoast-seo', $this->presence->providerName() );
		$this->assertSame( '20.13', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_rank_math_is_served_by_the_rank_math_provider(): void {
		$this->defineVersion( 'RANK_MATH_VERSION', '1.0.220' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( RankMathProvider::class, $this->presence->provider() );
		$this->assertSame( 'rank-math', $this->presence->providerName() );
	}

	/**
	 * A version exactly at the floor is supported.
	 *
	 * The boundary is asserted because `>` and `>=` are the same on every value except
	 * this one, and the difference is a site refused for running the oldest release
	 * this module claims to support.
	 */
	public function test_a_version_exactly_at_the_floor_is_supported(): void {
		$this->assertTrue( version_compare( SeoPresence::YOAST_MIN_VERSION, SeoPresence::YOAST_MIN_VERSION, '>=' ) );
	}

	/**
	 * The distinction the Modules screen renders: installed, but not usable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_yoast_below_the_floor_is_installed_but_not_loaded_and_still_reports_its_version(): void {
		$this->defineVersion( 'WPSEO_VERSION', '13.9' );

		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertTrue( $this->presence->isInstalled() );
		$this->assertNull( $this->presence->provider() );
		$this->assertSame( '13.9', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rank_math_below_the_floor_is_installed_but_not_loaded(): void {
		$this->defineVersion( 'RANK_MATH_VERSION', '1.0.39' );

		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertTrue( $this->presence->isInstalled() );
		$this->assertSame( '1.0.39', $this->presence->version() );
	}

	/**
	 * Both installed: Yoast wins, and the answer does not vary by call.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_carrying_both_plugins_answers_yoast_every_time(): void {
		$this->defineVersion( 'WPSEO_VERSION', '20.13' );
		$this->defineVersion( 'RANK_MATH_VERSION', '1.0.220' );

		$this->assertSame( 'yoast-seo', $this->presence->providerName() );
		$this->assertSame( 'yoast-seo', $this->presence->providerName() );
		$this->assertSame( '20.13', $this->presence->version() );
	}

	/**
	 * An out-of-range Yoast does not shadow a usable Rank Math for the PROVIDER, but
	 * it does own the reported VERSION.
	 *
	 * The split is deliberate and is the pair worth holding together: the site is
	 * served, because Rank Math can serve it, and the version an operator sees is the
	 * one belonging to the highest-precedence plugin present — the one they would be
	 * updating.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_out_of_range_yoast_leaves_a_usable_rank_math_serving_the_site(): void {
		$this->defineVersion( 'WPSEO_VERSION', '13.9' );
		$this->defineVersion( 'RANK_MATH_VERSION', '1.0.220' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertSame( 'rank-math', $this->presence->providerName() );
		$this->assertSame( '13.9', $this->presence->version() );
	}

	/**
	 * A constant of the wrong shape answers null instead of fataling.
	 *
	 * `wp-config.php` or an mu-plugin can define a version constant first and to
	 * anything at all, and `(string)` on an array is a fatal — inside a presence check
	 * whose entire job is to not fatal.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_version_constant_holding_an_array_reads_as_no_plugin(): void {
		$this->defineVersion( 'WPSEO_VERSION', [ '20.13' ] );

		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertFalse( $this->presence->isInstalled() );
		$this->assertNull( $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_blank_version_constant_reads_as_no_plugin(): void {
		$this->defineVersion( 'WPSEO_VERSION', '   ' );

		$this->assertFalse( $this->presence->isInstalled() );
		$this->assertNull( $this->presence->version() );
	}

	/**
	 * A numeric constant is usable, because a plugin may define `1.5` unquoted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_numeric_version_constant_is_read_rather_than_refused(): void {
		$this->defineVersion( 'RANK_MATH_VERSION', 1.5 );

		$this->assertTrue( $this->presence->isInstalled() );
		$this->assertSame( '1.5', $this->presence->version() );
	}

	/**
	 * The declared ranges name both plugins and are built from the enforced floors.
	 *
	 * Naming one plugin would misdescribe a site running the other, and a range
	 * written out by hand would drift from the constant the gate actually compares
	 * against — a definition that promises support the code refuses.
	 */
	public function test_the_declared_versions_name_both_plugins_using_the_enforced_floors(): void {
		$versions = SeoPresence::supportedVersions();

		$this->assertSame( [ 'wordpress', 'yoast-seo', 'rank-math' ], array_keys( $versions ) );
		$this->assertSame( '>=' . SeoPresence::YOAST_MIN_VERSION, $versions['yoast-seo'] );
		$this->assertSame( '>=' . SeoPresence::RANK_MATH_MIN_VERSION, $versions['rank-math'] );
		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
	}

	/**
	 * There is no `seo` key, and that absence is a decision rather than an omission.
	 *
	 * OperationDefinition requires a range keyed by the module id for its
	 * plugin-backed modules. This module is deliberately not one of them, because the
	 * one key that rule would demand names nothing anyone can install.
	 */
	public function test_the_declared_versions_carry_no_key_named_after_the_module(): void {
		$this->assertArrayNotHasKey( 'seo', SeoPresence::supportedVersions() );
	}
}
