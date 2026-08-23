<?php
/**
 * Rank Math's settings store: the two-key robots switch, the sitemap option, restore.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use SiteHelm\Pro\Seo\RankMathSettingsProvider;
use SiteHelm\Pro\Seo\SeoSettingsFields;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class RankMathSettingsProviderTest extends TestCase {

	private RankMathSettingsProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		AdminWordPressStubs::$options = [];
		$this->provider               = new RankMathSettingsProvider();
	}

	public function test_site_values_read_the_titles_option(): void {
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] = [
			'title_separator'     => '-',
			'knowledgegraph_name' => 'Acme',
			'knowledgegraph_logo' => 'https://example.test/logo.png',
			'open_graph_image'    => '',
			'breadcrumbs'         => 'on',
		];

		$this->assertSame(
			[
				'separator'          => '-',
				'knowledgeGraphName' => 'Acme',
				'knowledgeGraphLogo' => 'https://example.test/logo.png',
				'defaultSocialImage' => null,
				'breadcrumbs'        => true,
			],
			$this->provider->values( null )
		);
		$this->assertSame( SeoSettingsFields::SITE_FIELDS, array_keys( $this->provider->values( null ) ) );
	}

	public function test_a_type_without_a_robots_override_is_not_noindex_even_if_its_list_says_so(): void {
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] = [
			'pt_post_title'         => '%title%',
			'pt_post_custom_robots' => 'off',
			'pt_post_robots'        => [ 'noindex' ],
		];
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_SITEMAP ] = [ 'pt_post_sitemap' => 'on' ];

		$this->assertSame(
			[
				'titleTemplate'       => '%title%',
				'descriptionTemplate' => null,
				'noindex'             => false,
				'inSitemap'           => true,
			],
			$this->provider->values( 'post' )
		);
	}

	public function test_a_type_with_an_override_listing_noindex_reads_as_noindex(): void {
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] = [
			'pt_post_custom_robots' => 'on',
			'pt_post_robots'        => [ 'noindex', 'nofollow' ],
		];

		$values = $this->provider->values( 'post' );

		$this->assertTrue( $values['noindex'] );
		$this->assertFalse( $values['inSitemap'] );
	}

	public function test_it_refuses_nothing_and_projects_text_and_flags(): void {
		$this->assertNull( $this->provider->refusal( 'post', [ 'inSitemap' => false ] ) );
		$this->assertSame(
			[ 'titleTemplate' => null, 'noindex' => true, 'inSitemap' => false ],
			$this->provider->project( 'post', [ 'titleTemplate' => '', 'noindex' => true, 'inSitemap' => false ] )
		);
	}

	public function test_setting_noindex_switches_the_override_on_and_keeps_the_other_directives(): void {
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] = [
			'pt_post_custom_robots' => 'off',
			'pt_post_robots'        => [ 'index', 'nofollow' ],
		];

		$this->assertTrue( $this->provider->apply( 'post', [ 'noindex' => true, 'inSitemap' => false ] ) );
		$this->assertSame(
			[
				'pt_post_custom_robots' => 'on',
				'pt_post_robots'        => [ 'noindex', 'nofollow' ],
			],
			AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ]
		);
		$this->assertSame( [ 'pt_post_sitemap' => 'off' ], AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_SITEMAP ] );

		$this->assertTrue( $this->provider->apply( 'post', [ 'noindex' => false ] ) );
		$this->assertSame( [ 'index', 'nofollow' ], AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ]['pt_post_robots'] );
	}

	public function test_applying_site_settings_maps_breadcrumbs_to_on_off(): void {
		$this->assertTrue( $this->provider->apply( null, [ 'separator' => '|', 'breadcrumbs' => true, 'knowledgeGraphLogo' => null ] ) );
		$this->assertSame(
			[
				'title_separator'     => '|',
				'knowledgegraph_logo' => '',
				'breadcrumbs'         => 'on',
			],
			AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ]
		);
	}

	public function test_capture_and_restore_cover_both_options(): void {
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ]  = [ 'pt_post_title' => 'before' ];
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_SITEMAP ] = [ 'pt_post_sitemap' => 'on', 'other' => 'x' ];

		$snapshot = $this->provider->capture( 'post' );

		$this->assertSame( RankMathSettingsProvider::NAME, $snapshot['provider'] );
		$this->assertSame( [ 'pt_post_sitemap' => [ 'on' ] ], $snapshot['options'][ RankMathSettingsProvider::OPTION_SITEMAP ] );

		$this->provider->apply( 'post', [ 'titleTemplate' => 'after', 'noindex' => true, 'inSitemap' => false ] );
		$this->assertTrue( $this->provider->restore( 'post', $snapshot ) );
		$this->assertSame( [ 'pt_post_title' => 'before' ], AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] );
		$this->assertSame( [ 'pt_post_sitemap' => 'on', 'other' => 'x' ], AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_SITEMAP ] );
	}
}
