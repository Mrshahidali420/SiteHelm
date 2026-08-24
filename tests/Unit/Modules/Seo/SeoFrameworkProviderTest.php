<?php
/**
 * Tests for the SEO Framework store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoFrameworkProvider;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back.
 *
 * NO PROCESS ISOLATION IS NEEDED IN THIS FILE, for the same reason as
 * YoastProviderTest: the provider names no plugin symbol at all, only meta keys.
 *
 * TWO DECLINED FIELDS ARE THE SUBSTANCE HELD HERE. This plugin stores no focus
 * keyword and no separate Twitter image, and a SeoMetaProvider declines a field
 * simply by leaving it out of textKeys()/imageKeys() — project() then promises
 * null for it rather than a value verification could never find. That promise,
 * not the checkbox flags this class shares in shape with SEOPress, is what makes
 * this file worth having beside SeoPressProviderTest.
 */
final class SeoFrameworkProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The provider under test.
	 */
	private SeoFrameworkProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new SeoFrameworkProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'seo-framework', $this->provider->name() );
	}

	public function test_the_plugin_stores_no_analysis_scores(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_text_is_reported_under_the_neutral_field_name(): void {
		$this->seedMeta( 42, '_genesis_title', 'A title' );
		$this->seedMeta( 42, '_genesis_description', 'A description' );
		$this->seedMeta( 42, '_genesis_canonical_uri', 'https://example.com/a' );
		$this->seedMeta( 42, '_open_graph_title', 'An OG title' );
		$this->seedMeta( 42, '_open_graph_description', 'An OG description' );
		$this->seedMeta( 42, '_twitter_title', 'A Twitter title' );
		$this->seedMeta( 42, '_twitter_description', 'A Twitter description' );
		$this->seedMeta( 42, '_social_image_url', 'https://example.com/social.png' );

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/a', $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertSame( 'An OG title', $values[ SeoFields::FIELD_OG_TITLE ] );
		$this->assertSame( 'An OG description', $values[ SeoFields::FIELD_OG_DESCRIPTION ] );
		$this->assertSame( 'A Twitter title', $values[ SeoFields::FIELD_TWITTER_TITLE ] );
		$this->assertSame( 'A Twitter description', $values[ SeoFields::FIELD_TWITTER_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/social.png', $values[ SeoFields::FIELD_OG_IMAGE ] );
	}

	/**
	 * The plugin stores no focus keyword field at all, so a read reports null even
	 * though nothing was seeded to override it — there is no key it could have
	 * been read from.
	 */
	public function test_the_focus_keyword_is_always_null_because_the_plugin_does_not_store_one(): void {
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_FOCUS_KEYWORD ] );
	}

	/**
	 * One stored image serves both cards, and it is reported as ogImage only — the
	 * twitterImage field is declined rather than the same URL reported twice.
	 */
	public function test_the_twitter_image_is_always_null_because_one_image_serves_both_cards(): void {
		$this->seedMeta( 42, '_social_image_url', 'https://example.com/social.png' );

		$values = $this->provider->values( 42 );

		$this->assertSame( 'https://example.com/social.png', $values[ SeoFields::FIELD_OG_IMAGE ] );
		$this->assertNull( $values[ SeoFields::FIELD_TWITTER_IMAGE ] );
	}

	public function test_a_stored_empty_string_reads_as_null_rather_than_as_an_empty_value(): void {
		$this->seedMeta( 42, '_genesis_title', '   ' );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_a_text_value_of_the_wrong_shape_reads_as_null_rather_than_fataling(): void {
		$this->seedMeta( 42, '_genesis_description', [ 'imported', 'badly' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool|null}> Stored value => reported flag.
	 */
	public static function noindexEncodings(): array {
		return [
			'string one means noindex'          => [ '1', true ],
			'integer one means noindex'         => [ 1, true ],
			'string zero is the plugins default' => [ '0', null ],
			'integer zero is the plugins default' => [ 0, null ],
			'nonsense means the plugin decides' => [ 'yes', null ],
		];
	}

	/**
	 * @dataProvider noindexEncodings
	 *
	 * @param mixed     $stored   The value the store holds.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_noindex_directive_is_read_from_the_plugins_checkbox( $stored, ?bool $expected ): void {
		$this->seedMeta( 42, '_genesis_noindex', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_an_absent_noindex_row_means_the_plugin_decides(): void {
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * @dataProvider noindexEncodings
	 *
	 * @param mixed     $stored   The value the store holds.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_nofollow_directive_uses_the_same_checkbox_encoding( $stored, ?bool $expected ): void {
		$this->seedMeta( 42, '_genesis_nofollow', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_writing_text_stores_it_under_the_plugins_own_key(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$this->assertSame( [ 'New title' ], $this->rowsFor( 42, '_genesis_title' ) );
	}

	public function test_a_written_value_is_trimmed_before_it_is_stored(): void {
		$this->provider->apply( 42, [ SeoFields::FIELD_DESCRIPTION => '  padded  ' ] );

		$this->assertSame( [ 'padded' ], $this->rowsFor( 42, '_genesis_description' ) );
	}

	/**
	 * The focus keyword is a declined field, so apply() silently skips it rather
	 * than fataling on a key it has none for — the same silence project() already
	 * promises.
	 */
	public function test_writing_a_declined_field_is_a_silent_no_op(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_FOCUS_KEYWORD => 'widgets' ] ) );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_FOCUS_KEYWORD ] );
	}

	public function test_clearing_a_field_deletes_its_row_rather_than_emptying_it(): void {
		$this->seedMeta( 42, '_genesis_title', 'Old title' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_genesis_title' ) );
	}

	public function test_clearing_a_field_with_an_empty_string_also_deletes_its_row(): void {
		$this->seedMeta( 42, '_genesis_title', 'Old title' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => '' ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_genesis_title' ) );
	}

	public function test_clearing_a_flag_deletes_its_row(): void {
		$this->seedMeta( 42, '_genesis_noindex', '1' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_genesis_noindex' ) );
	}

	public function test_setting_a_flag_true_stores_the_plugins_one(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$this->assertSame( [ '1' ], $this->rowsFor( 42, '_genesis_noindex' ) );
	}

	/**
	 * The plugin's own default meta writes a stored 0 for "unchecked", but this
	 * store has no way to write an explicit false through this module: 0 and
	 * absent render identically, so a false request deletes the row.
	 */
	public function test_setting_a_flag_false_deletes_the_row_rather_than_storing_a_zero(): void {
		$this->seedMeta( 42, '_genesis_nofollow', '1' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOFOLLOW => false ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_genesis_nofollow' ) );
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_the_projection_folds_a_false_flag_into_the_plugin_decides(): void {
		$projected = $this->provider->project(
			[
				SeoFields::FIELD_NOINDEX  => false,
				SeoFields::FIELD_NOFOLLOW => false,
			]
		);

		$this->assertNull( $projected[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $projected[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * A declined field's promise is always null, whatever the caller asks for. The
	 * base class folds "no mapped key" into null before a plan could ever promise
	 * a value this store cannot hold.
	 */
	public function test_a_declined_fields_projection_is_always_null(): void {
		$this->assertSame(
			[ SeoFields::FIELD_FOCUS_KEYWORD => null ],
			$this->provider->project( [ SeoFields::FIELD_FOCUS_KEYWORD => 'anything' ] )
		);
	}

	public function test_the_projection_agrees_with_what_a_read_reports_afterwards(): void {
		$changes = [
			SeoFields::FIELD_TITLE       => '  Trimmed  ',
			SeoFields::FIELD_DESCRIPTION => '',
			SeoFields::FIELD_NOINDEX     => true,
			SeoFields::FIELD_NOFOLLOW    => false,
		];

		$projected = $this->provider->project( $changes );

		$this->assertTrue( $this->provider->apply( 42, $changes ) );

		$values = $this->provider->values( 42 );

		foreach ( $projected as $field => $expected ) {
			$this->assertSame( $expected, $values[ $field ], "The projection for {$field} must match the read." );
		}
	}

	/**
	 * A focus-keyword change on a post with nothing stored is agreement, not
	 * failure: the projection promises null, the store has nothing to read for
	 * the key it was never given, and apply() reports the two matching rather
	 * than reporting a write that could never have taken hold.
	 */
	public function test_applying_a_declined_field_change_to_an_untouched_post_still_reports_true(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_FOCUS_KEYWORD => 'widgets' ] ) );
	}

	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'seo-framework', $this->provider->capture( 42 )['provider'] );
	}

	/**
	 * A capture covers the attachment id beside the social image URL, because the
	 * plugin's own renderer reads the pair and a snapshot that held only the URL
	 * could not put both back.
	 */
	public function test_a_capture_covers_the_unprojected_image_id_the_plugin_renders_from(): void {
		$meta = $this->provider->capture( 42 )['meta'];

		$this->assertArrayHasKey( '_social_image_id', $meta );
	}

	public function test_a_restore_removes_a_value_the_change_added(): void {
		$this->seedMeta( 42, '_genesis_title', 'Original' );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply(
			42,
			[
				SeoFields::FIELD_TITLE       => 'Changed',
				SeoFields::FIELD_DESCRIPTION => 'Added where there was none',
				SeoFields::FIELD_NOINDEX     => true,
			]
		);

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );

		$this->assertSame( 'Original', $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
		$this->assertFalse( $this->hasMeta( 42, '_genesis_description' ) );
		$this->assertFalse( $this->hasMeta( 42, '_genesis_noindex' ) );
	}

	public function test_a_restore_puts_back_an_empty_row_rather_than_dropping_the_key(): void {
		$this->seedMeta( 42, '_genesis_title', '' );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'Changed' ] );

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );
		$this->assertSame( [ '' ], $this->rowsFor( 42, '_genesis_title' ) );
	}

	public function test_a_restore_given_no_meta_clears_every_owned_key_and_reports_no_match(): void {
		$this->seedMeta( 42, '_genesis_title', 'Something' );

		$this->assertFalse( $this->provider->restore( 42, [] ) );
		$this->assertFalse( $this->hasMeta( 42, '_genesis_title' ) );
	}
}
