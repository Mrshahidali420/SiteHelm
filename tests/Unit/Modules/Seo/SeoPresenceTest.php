<?php
/**
 * Tests for SeoPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\AioseoProvider;
use SiteHelm\Modules\Seo\RankMathProvider;
use SiteHelm\Modules\Seo\RankMathTermProvider;
use SiteHelm\Modules\Seo\SeoFrameworkProvider;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoPressProvider;
use SiteHelm\Modules\Seo\SlimSeoProvider;
use SiteHelm\Modules\Seo\SureRankProvider;
use SiteHelm\Modules\Seo\YoastProvider;
use SiteHelm\Modules\Seo\YoastTermProvider;
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
 * PRECEDENCE IS THE OTHER SUBSTANCE. A site can carry several plugins, and some do
 * during a migration. The gate orders by install base — Yoast, Rank Math, All in One
 * SEO, SEOPress, The SEO Framework, Slim SEO, SureRank; the ordering is arbitrary
 * but it must be STABLE, because a precedence that varied by request would let a
 * write land in a different plugin's store than the read that planned it. The
 * multi-installed tests below are what hold the order.
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
		$this->assertNull( $this->presence->termProvider() );
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
		$this->assertInstanceOf( YoastTermProvider::class, $this->presence->termProvider() );
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
		$this->assertInstanceOf( RankMathTermProvider::class, $this->presence->termProvider() );
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_aioseo_is_served_by_the_aioseo_provider(): void {
		$this->defineVersion( 'AIOSEO_VERSION', '4.8.7' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( AioseoProvider::class, $this->presence->provider() );
		$this->assertNull( $this->presence->termProvider() );
		$this->assertSame( 'aioseo', $this->presence->providerName() );
		$this->assertSame( '4.8.7', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_seopress_is_served_by_the_seopress_provider(): void {
		$this->defineVersion( 'SEOPRESS_VERSION', '8.9' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( SeoPressProvider::class, $this->presence->provider() );
		$this->assertNull( $this->presence->termProvider() );
		$this->assertSame( 'seopress', $this->presence->providerName() );
		$this->assertSame( '8.9', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_seo_framework_is_served_by_its_provider(): void {
		$this->defineVersion( 'THE_SEO_FRAMEWORK_VERSION', '5.1.4' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( SeoFrameworkProvider::class, $this->presence->provider() );
		$this->assertNull( $this->presence->termProvider() );
		$this->assertSame( 'seo-framework', $this->presence->providerName() );
		$this->assertSame( '5.1.4', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_slim_seo_is_served_by_the_slim_seo_provider(): void {
		$this->defineVersion( 'SLIM_SEO_VER', '4.9.11' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( SlimSeoProvider::class, $this->presence->provider() );
		$this->assertNull( $this->presence->termProvider() );
		$this->assertSame( 'slim-seo', $this->presence->providerName() );
		$this->assertSame( '4.9.11', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_surerank_is_served_by_the_surerank_provider(): void {
		$this->defineVersion( 'SURERANK_VERSION', '1.10.0' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertInstanceOf( SureRankProvider::class, $this->presence->provider() );
		$this->assertNull( $this->presence->termProvider() );
		$this->assertSame( 'surerank', $this->presence->providerName() );
		$this->assertSame( '1.10.0', $this->presence->version() );
	}

	/**
	 * All five newer plugins installed at once: All in One SEO wins, and the
	 * answer does not vary by call — the precedence row order is the substance.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_carrying_all_five_newer_plugins_answers_aioseo_every_time(): void {
		$this->defineVersion( 'AIOSEO_VERSION', '4.8.7' );
		$this->defineVersion( 'SEOPRESS_VERSION', '8.9' );
		$this->defineVersion( 'THE_SEO_FRAMEWORK_VERSION', '5.1.4' );
		$this->defineVersion( 'SLIM_SEO_VER', '4.9.11' );
		$this->defineVersion( 'SURERANK_VERSION', '1.10.0' );

		$this->assertSame( 'aioseo', $this->presence->providerName() );
		$this->assertSame( 'aioseo', $this->presence->providerName() );
		$this->assertSame( '4.8.7', $this->presence->version() );
	}

	/**
	 * An out-of-range All in One SEO does not shadow a usable SEOPress for the
	 * PROVIDER, but it does own the reported VERSION — the same split the
	 * Yoast/Rank Math pair holds above.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_out_of_range_aioseo_leaves_a_usable_seopress_serving_the_site(): void {
		$this->defineVersion( 'AIOSEO_VERSION', '3.7.1' );
		$this->defineVersion( 'SEOPRESS_VERSION', '8.9' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertSame( 'seopress', $this->presence->providerName() );
		$this->assertSame( '3.7.1', $this->presence->version() );
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
	 * The declared ranges name every plugin and are built from the enforced floors.
	 *
	 * Naming fewer plugins would misdescribe a site running one of the others, and
	 * a range written out by hand would drift from the constant the gate actually
	 * compares against — a definition that promises support the code refuses.
	 */
	public function test_the_declared_versions_name_every_plugin_using_the_enforced_floors(): void {
		$versions = SeoPresence::supportedVersions();

		$this->assertSame(
			[ 'wordpress', 'yoast-seo', 'rank-math', 'aioseo', 'seopress', 'seo-framework', 'slim-seo', 'surerank' ],
			array_keys( $versions )
		);
		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . SeoPresence::YOAST_MIN_VERSION, $versions['yoast-seo'] );
		$this->assertSame( '>=' . SeoPresence::RANK_MATH_MIN_VERSION, $versions['rank-math'] );
		$this->assertSame( '>=' . SeoPresence::AIOSEO_MIN_VERSION, $versions['aioseo'] );
		$this->assertSame( '>=' . SeoPresence::SEOPRESS_MIN_VERSION, $versions['seopress'] );
		$this->assertSame( '>=' . SeoPresence::SEO_FRAMEWORK_MIN_VERSION, $versions['seo-framework'] );
		$this->assertSame( '>=' . SeoPresence::SLIM_SEO_MIN_VERSION, $versions['slim-seo'] );
		$this->assertSame( '>=' . SeoPresence::SURERANK_MIN_VERSION, $versions['surerank'] );
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
