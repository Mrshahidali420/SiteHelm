<?php
/**
 * Tests for the SEOPress store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoPressProvider;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back.
 *
 * NO PROCESS ISOLATION IS NEEDED IN THIS FILE, for the same reason as
 * YoastProviderTest: the provider names no plugin symbol at all, only meta keys.
 *
 * THE ROBOTS KEYS ARE NAMED FOR THE DIRECTIVE THEY SWITCH ON, NOT ITS MEANING, and
 * neither key has a stored "explicitly off" value — an unchecked box deletes the
 * row. That single-negative shape is the substance this file holds: a stored
 * `'yes'` is the only true, and everything else, including a stored `false`
 * passed through `apply()`, reads back as the plugin deciding for itself.
 */
final class SeoPressProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The provider under test.
	 */
	private SeoPressProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new SeoPressProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'seopress', $this->provider->name() );
	}

	/**
	 * SEOPress keeps its content analysis out of plain post meta, so neither score
	 * is ever read from a stored key.
	 */
	public function test_the_plugin_stores_no_analysis_scores(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_text_is_reported_under_the_neutral_field_name(): void {
		$this->seedMeta( 42, '_seopress_titles_title', 'A title' );
		$this->seedMeta( 42, '_seopress_titles_desc', 'A description' );
		$this->seedMeta( 42, '_seopress_robots_canonical', 'https://example.com/a' );
		$this->seedMeta( 42, '_seopress_analysis_target_kw', 'widgets' );
		$this->seedMeta( 42, '_seopress_social_fb_title', 'An OG title' );
		$this->seedMeta( 42, '_seopress_social_fb_desc', 'An OG description' );
		$this->seedMeta( 42, '_seopress_social_twitter_title', 'A Twitter title' );
		$this->seedMeta( 42, '_seopress_social_twitter_desc', 'A Twitter description' );
		$this->seedMeta( 42, '_seopress_social_fb_img', 'https://example.com/og.png' );
		$this->seedMeta( 42, '_seopress_social_twitter_img', 'https://example.com/twitter.png' );

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/a', $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertSame( 'widgets', $values[ SeoFields::FIELD_FOCUS_KEYWORD ] );
		$this->assertSame( 'An OG title', $values[ SeoFields::FIELD_OG_TITLE ] );
		$this->assertSame( 'An OG description', $values[ SeoFields::FIELD_OG_DESCRIPTION ] );
		$this->assertSame( 'A Twitter title', $values[ SeoFields::FIELD_TWITTER_TITLE ] );
		$this->assertSame( 'A Twitter description', $values[ SeoFields::FIELD_TWITTER_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/og.png', $values[ SeoFields::FIELD_OG_IMAGE ] );
		$this->assertSame( 'https://example.com/twitter.png', $values[ SeoFields::FIELD_TWITTER_IMAGE ] );
	}

	/**
	 * A stored empty string reads the same as no row at all.
	 */
	public function test_a_stored_empty_string_reads_as_null_rather_than_as_an_empty_value(): void {
		$this->seedMeta( 42, '_seopress_titles_title', '   ' );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	/**
	 * Post meta is a store anything can write an array into.
	 */
	public function test_a_text_value_of_the_wrong_shape_reads_as_null_rather_than_fataling(): void {
		$this->seedMeta( 42, '_seopress_titles_desc', [ 'imported', 'badly' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool|null}> Stored value => reported flag.
	 */
	public static function noindexEncodings(): array {
		return [
			'yes means noindex'                 => [ 'yes', true ],
			'padded yes still means noindex'    => [ '  yes  ', true ],
			'no is not this plugins on value'   => [ 'no', null ],
			'one is not this plugins on value'  => [ '1', null ],
			'nonsense means the plugin decides' => [ 'sure', null ],
		];
	}

	/**
	 * @dataProvider noindexEncodings
	 *
	 * @param mixed     $stored   The value the store holds.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_noindex_directive_is_read_from_seopresss_own_word( $stored, ?bool $expected ): void {
		$this->seedMeta( 42, '_seopress_robots_index', $stored );

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
	public function test_the_nofollow_directive_uses_the_same_single_word_encoding( $stored, ?bool $expected ): void {
		$this->seedMeta( 42, '_seopress_robots_follow', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_writing_text_stores_it_under_the_plugins_own_key(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$this->assertSame( [ 'New title' ], $this->rowsFor( 42, '_seopress_titles_title' ) );
	}

	public function test_a_written_value_is_trimmed_before_it_is_stored(): void {
		$this->provider->apply( 42, [ SeoFields::FIELD_DESCRIPTION => '  padded  ' ] );

		$this->assertSame( [ 'padded' ], $this->rowsFor( 42, '_seopress_titles_desc' ) );
	}

	public function test_clearing_a_field_deletes_its_row_rather_than_emptying_it(): void {
		$this->seedMeta( 42, '_seopress_titles_title', 'Old title' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_seopress_titles_title' ) );
	}

	public function test_clearing_a_field_with_an_empty_string_also_deletes_its_row(): void {
		$this->seedMeta( 42, '_seopress_titles_title', 'Old title' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => '' ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_seopress_titles_title' ) );
	}

	public function test_clearing_a_flag_deletes_its_row(): void {
		$this->seedMeta( 42, '_seopress_robots_index', 'yes' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_seopress_robots_index' ) );
	}

	public function test_setting_a_flag_true_stores_the_plugins_one_word(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$this->assertSame( [ 'yes' ], $this->rowsFor( 42, '_seopress_robots_index' ) );
	}

	/**
	 * This store has no way to write an explicit false: unchecking the box deletes
	 * the row, so `false` and "the plugin decides" land in the same place.
	 */
	public function test_setting_a_flag_false_deletes_the_row_rather_than_storing_a_negative(): void {
		$this->seedMeta( 42, '_seopress_robots_follow', 'yes' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOFOLLOW => false ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_seopress_robots_follow' ) );
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * The projected value for a false flag is null before the write is even
	 * attempted, because this store cannot hold an explicit negative for either
	 * flag.
	 */
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
	 * The projection is what the plan promises, so it has to match what a read
	 * gives back.
	 */
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

	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'seopress', $this->provider->capture( 42 )['provider'] );
	}

	/**
	 * A capture covers the robots keys even though no text field owns them.
	 */
	public function test_a_capture_covers_the_flag_rows(): void {
		$this->seedMeta( 42, '_seopress_robots_index', 'yes' );
		$this->seedMeta( 42, '_seopress_robots_follow', 'yes' );

		$meta = $this->provider->capture( 42 )['meta'];

		$this->assertArrayHasKey( '_seopress_robots_index', $meta );
		$this->assertArrayHasKey( '_seopress_robots_follow', $meta );
	}

	public function test_a_restore_removes_a_value_the_change_added(): void {
		$this->seedMeta( 42, '_seopress_titles_title', 'Original' );
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
		$this->assertFalse( $this->hasMeta( 42, '_seopress_titles_desc' ) );
		$this->assertFalse( $this->hasMeta( 42, '_seopress_robots_index' ) );
	}

	public function test_a_restore_puts_back_an_empty_row_rather_than_dropping_the_key(): void {
		$this->seedMeta( 42, '_seopress_titles_title', '' );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'Changed' ] );

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );
		$this->assertSame( [ '' ], $this->rowsFor( 42, '_seopress_titles_title' ) );
	}

	public function test_a_restore_given_no_meta_clears_every_owned_key_and_reports_no_match(): void {
		$this->seedMeta( 42, '_seopress_titles_title', 'Something' );

		$this->assertFalse( $this->provider->restore( 42, [] ) );
		$this->assertFalse( $this->hasMeta( 42, '_seopress_titles_title' ) );
	}
}
