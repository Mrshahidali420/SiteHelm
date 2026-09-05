<?php
/**
 * Every supported provider answers the whole interface.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use ReflectionClass;
use SiteHelm\Modules\Seo\SeoProvider;
use SiteHelm\Tests\TestCase;

/**
 * The census that would have caught a method added to the interface and missed.
 *
 * SIX OF THE SEVEN PROVIDERS INHERIT FROM AN ABSTRACT BASE and the seventh does
 * not, because it reads a custom table rather than post meta. So a method added
 * to the interface with a default written into both bases still leaves one class
 * short, and PHP does not say so until something loads that class — which in a
 * suite of thousands of tests is a fatal several minutes in, not a failure next
 * to the change that caused it.
 *
 * Loading the class is the whole assertion. An incomplete implementation cannot
 * be loaded at all, so this file names every provider once and the test fails
 * where the mistake was made.
 */
final class SeoProviderCensusTest extends TestCase {

	/**
	 * Every concrete provider this plugin ships.
	 */
	private const PROVIDERS = [
		\SiteHelm\Modules\Seo\YoastProvider::class,
		\SiteHelm\Modules\Seo\RankMathProvider::class,
		\SiteHelm\Modules\Seo\AioseoProvider::class,
		\SiteHelm\Modules\Seo\SeoPressProvider::class,
		\SiteHelm\Modules\Seo\SeoFrameworkProvider::class,
		\SiteHelm\Modules\Seo\SlimSeoProvider::class,
		\SiteHelm\Modules\Seo\SureRankProvider::class,
	];

	public function test_every_provider_implements_the_whole_interface(): void {
		foreach ( self::PROVIDERS as $provider ) {
			$reflection = new ReflectionClass( $provider );

			$this->assertFalse( $reflection->isAbstract(), $provider . ' is missing an interface method.' );
			$this->assertTrue( $reflection->implementsInterface( SeoProvider::class ), $provider . ' does not implement the provider interface.' );
		}
	}

	/**
	 * The list itself is checked, so a new provider cannot skip the census.
	 */
	public function test_the_census_names_every_provider_the_module_can_load(): void {
		$declared = [];

		foreach ( glob( dirname( __DIR__, 4 ) . '/src/Modules/Seo/*Provider.php' ) as $file ) {
			$class = '\\SiteHelm\\Modules\\Seo\\' . basename( $file, '.php' );

			// TERM PROVIDERS ARE A DIFFERENT INTERFACE and share the directory and
			// the file-name suffix, so the filter is what the class implements
			// rather than what it is called.
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$reflection = new ReflectionClass( $class );

			if ( $reflection->isAbstract() || ! $reflection->implementsInterface( SeoProvider::class ) ) {
				continue;
			}

			$declared[] = ltrim( $class, '\\' );
		}

		sort( $declared );
		$named = array_map( static fn( string $class ): string => ltrim( $class, '\\' ), self::PROVIDERS );
		sort( $named );

		$this->assertSame( $named, $declared );
	}
}
