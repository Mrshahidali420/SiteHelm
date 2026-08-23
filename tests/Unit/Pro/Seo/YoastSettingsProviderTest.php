<?php
/**
 * Yoast's settings store: the option mappings, the separator set, and restore.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use SiteHelm\Pro\Seo\SeoSettingsFields;
use SiteHelm\Pro\Seo\YoastSettingsProvider;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class YoastSettingsProviderTest extends TestCase {

	private YoastSettingsProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		AdminWordPressStubs::$options = [];
		$this->provider               = new YoastSettingsProvider();
	}

	public function test_site_values_decode_the_separator_code_and_read_both_options(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [
			'separator'          => 'sc-pipe',
			'company_name'       => ' Acme ',
			'company_logo'       => '',
			'breadcrumbs-enable' => true,
		];
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_SOCIAL ] = [
			'og_default_image' => 'https://example.test/og.png',
		];

		$this->assertSame(
			[
				'separator'          => '|',
				'knowledgeGraphName' => 'Acme',
				'knowledgeGraphLogo' => null,
				'defaultSocialImage' => 'https://example.test/og.png',
				'breadcrumbs'        => true,
			],
			$this->provider->values( null )
		);
	}

	public function test_an_unknown_separator_code_and_missing_options_read_as_empty(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [ 'separator' => 'sc-unknown' ];

		$values = $this->provider->values( null );

		$this->assertNull( $values['separator'] );
		$this->assertNull( $values['defaultSocialImage'] );
		$this->assertFalse( $values['breadcrumbs'] );
	}

	public function test_type_values_derive_in_sitemap_from_noindex(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [
			'title-post'    => '%%title%%',
			'metadesc-post' => '',
			'noindex-post'  => true,
		];

		$this->assertSame(
			[
				'titleTemplate'       => '%%title%%',
				'descriptionTemplate' => null,
				'noindex'             => true,
				'inSitemap'           => false,
			],
			$this->provider->values( 'post' )
		);
	}

	public function test_a_separator_outside_the_fixed_set_is_refused_with_the_set_listed(): void {
		$refusal = $this->provider->refusal( null, [ 'separator' => '%' ] );

		$this->assertIsString( $refusal );
		$this->assertStringContainsString( '|', $refusal );
		$this->assertNull( $this->provider->refusal( null, [ 'separator' => ' | ' ] ) );
		$this->assertIsString( $this->provider->refusal( null, [ 'separator' => null ] ) );
	}

	public function test_in_sitemap_on_a_post_type_is_refused_and_points_at_noindex(): void {
		$refusal = $this->provider->refusal( 'post', [ 'inSitemap' => false ] );

		$this->assertIsString( $refusal );
		$this->assertStringContainsString( 'noindex', $refusal );
		$this->assertNull( $this->provider->refusal( 'post', [ 'noindex' => true ] ) );
	}

	public function test_projecting_noindex_on_a_type_also_projects_in_sitemap(): void {
		$this->assertSame(
			[
				'titleTemplate' => null,
				'noindex'       => true,
				'inSitemap'     => false,
			],
			$this->provider->project( 'post', [ 'titleTemplate' => '  ', 'noindex' => true ] )
		);
	}

	public function test_applying_site_settings_stores_the_separator_code_and_clears_the_image_id(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_SOCIAL ] = [ 'og_default_image_id' => 12 ];

		$ok = $this->provider->apply(
			null,
			[
				'separator'          => '–',
				'knowledgeGraphName' => 'Acme',
				'breadcrumbs'        => false,
				'defaultSocialImage' => 'https://example.test/new.png',
			]
		);

		$this->assertTrue( $ok );
		$this->assertSame(
			[
				'separator'          => 'sc-ndash',
				'company_name'       => 'Acme',
				'breadcrumbs-enable' => false,
			],
			AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ]
		);
		$this->assertSame(
			[
				'og_default_image_id' => '',
				'og_default_image'    => 'https://example.test/new.png',
			],
			AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_SOCIAL ]
		);
	}

	public function test_applying_an_unknown_separator_writes_nothing_and_reports_failure(): void {
		$this->assertFalse( $this->provider->apply( null, [ 'separator' => '%' ] ) );
		$this->assertArrayNotHasKey( YoastSettingsProvider::OPTION_TITLES, AdminWordPressStubs::$options );
	}

	public function test_applying_type_settings_keeps_the_rest_of_the_option(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [
			'separator'  => 'sc-dash',
			'title-page' => 'keep',
		];

		$this->assertTrue( $this->provider->apply( 'post', [ 'titleTemplate' => '%%title%%', 'noindex' => true, 'descriptionTemplate' => null ] ) );
		$this->assertSame(
			[
				'separator'     => 'sc-dash',
				'title-page'    => 'keep',
				'title-post'    => '%%title%%',
				'metadesc-post' => '',
				'noindex-post'  => true,
			],
			AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ]
		);
	}

	public function test_capture_records_absent_keys_as_absent_and_restore_puts_them_back(): void {
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [ 'title-post' => 'before' ];

		$snapshot = $this->provider->capture( 'post' );

		$this->assertSame( YoastSettingsProvider::NAME, $snapshot['provider'] );
		$this->assertSame(
			[
				'title-post'    => [ 'before' ],
				'metadesc-post' => [],
				'noindex-post'  => [],
			],
			$snapshot['options'][ YoastSettingsProvider::OPTION_TITLES ]
		);

		$this->provider->apply( 'post', [ 'titleTemplate' => 'after', 'noindex' => true ] );
		$this->assertTrue( $this->provider->restore( 'post', $snapshot ) );
		$this->assertSame( [ 'title-post' => 'before' ], AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] );
	}

	public function test_restore_refuses_a_snapshot_without_options(): void {
		$this->assertFalse( $this->provider->restore( null, [ 'provider' => 'yoast-seo' ] ) );
	}

	public function test_the_field_lists_match_the_shared_field_catalogue(): void {
		$this->assertSame( SeoSettingsFields::SITE_FIELDS, array_keys( $this->provider->values( null ) ) );
		$this->assertSame( SeoSettingsFields::TYPE_FIELDS, array_keys( $this->provider->values( 'post' ) ) );
	}
}
