<?php
/**
 * Tests for the SureRank store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SureRankProvider;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back.
 *
 * A FAMILY OF KEYS, NOT ONE BLOB. Unlike Slim SEO's single array, SureRank
 * spreads its per-post settings across two grouped arrays — general and
 * social — plus two flags each stored as its own scalar whole row rather
 * than as a sub-key. Every one of SiteHelm's twelve fields has a home here,
 * so this file has no declined-field cases: the interesting behaviour is the
 * flag rows' three-way read and the fact that a "whole row" write and a
 * "sub-key inside an array" write are different code paths in the same
 * provider.
 *
 * NO PROCESS ISOLATION IS NEEDED IN THIS FILE, for the same reason as
 * Yoast's and Slim SEO's: the provider names no plugin symbol, only meta
 * keys.
 */
final class SureRankProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The provider under test.
	 */
	private SureRankProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new SureRankProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'surerank', $this->provider->name() );
	}

	public function test_the_provider_reports_no_analysis_scores(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	/**
	 * Every field is present in a read, whether or not the store holds it.
	 */
	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_general_sub_keys_are_reported_under_the_neutral_field_names(): void {
		$this->seedMeta(
			42,
			'surerank_settings_general',
			[
				'page_title'       => 'A title',
				'page_description' => 'A description',
				'canonical_url'    => 'https://example.com/a',
				'focus_keyword'    => 'widgets',
			]
		);

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/a', $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertSame( 'widgets', $values[ SeoFields::FIELD_FOCUS_KEYWORD ] );
	}

	public function test_stored_social_sub_keys_are_reported_under_the_neutral_field_names(): void {
		$this->seedMeta(
			42,
			'surerank_settings_social',
			[
				'facebook_title'       => 'An OG title',
				'facebook_description' => 'An OG description',
				'facebook_image_url'   => 'https://example.com/og.png',
				'twitter_title'        => 'A Twitter title',
				'twitter_description'  => 'A Twitter description',
				'twitter_image_url'    => 'https://example.com/twitter.png',
			]
		);

		$values = $this->provider->values( 42 );

		$this->assertSame( 'An OG title', $values[ SeoFields::FIELD_OG_TITLE ] );
		$this->assertSame( 'An OG description', $values[ SeoFields::FIELD_OG_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/og.png', $values[ SeoFields::FIELD_OG_IMAGE ] );
		$this->assertSame( 'A Twitter title', $values[ SeoFields::FIELD_TWITTER_TITLE ] );
		$this->assertSame( 'A Twitter description', $values[ SeoFields::FIELD_TWITTER_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/twitter.png', $values[ SeoFields::FIELD_TWITTER_IMAGE ] );
	}

	public function test_a_stored_empty_sub_key_reads_as_null_rather_than_as_an_empty_value(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'page_title' => '' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_an_absent_sub_key_reads_as_null(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'page_description' => 'Only description is set' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	/**
	 * Post meta is a store anything can write into, including something that
	 * is not the array this plugin expects.
	 */
	public function test_a_non_array_stored_group_reads_its_fields_as_null(): void {
		$this->seedMeta( 42, 'surerank_settings_general', 'not-an-array' );

		$values = $this->provider->values( 42 );

		$this->assertNull( $values[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $values[ SeoFields::FIELD_CANONICAL ] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool|null}> Stored flag row value => reported flag.
	 */
	public static function flagEncodings(): array {
		return [
			'boolean true means the directive is on' => [ true, true ],
			'integer one means the directive is on'  => [ 1, true ],
			'string one means the directive is on'   => [ '1', true ],
			'string true means the directive is on'  => [ 'true', true ],
			'empty string is the inherited default'  => [ '', null ],
			'the string no is an explicit negative'  => [ 'no', false ],
			'integer zero is an explicit negative'   => [ 0, false ],
			'string zero is an explicit negative'    => [ '0', false ],
		];
	}

	/**
	 * @dataProvider flagEncodings
	 *
	 * @param mixed     $stored   The value the store holds as the whole row.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_noindex_directive_is_read_from_the_plugins_own_boolean_spellings( mixed $stored, ?bool $expected ): void {
		$this->seedMeta( 42, 'surerank_settings_post_no_index', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * @dataProvider flagEncodings
	 *
	 * @param mixed     $stored   The value the store holds as the whole row.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_nofollow_directive_is_read_from_the_plugins_own_boolean_spellings( mixed $stored, ?bool $expected ): void {
		$this->seedMeta( 42, 'surerank_settings_post_no_follow', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_an_absent_flag_row_means_the_plugin_decides(): void {
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * The whole-row read is guarded on shape, so a value the sanitizer never
	 * produced does not fatal and does not misread as one of the true
	 * spellings.
	 */
	public function test_an_array_stored_as_the_flag_row_reads_as_null(): void {
		$this->seedMeta( 42, 'surerank_settings_post_no_index', [ 'unexpected' => true ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_written_value_is_trimmed_before_it_is_projected(): void {
		$this->assertSame( 'Trimmed', $this->provider->project( [ SeoFields::FIELD_TITLE => '  Trimmed  ' ] )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_an_empty_projected_value_reads_as_null(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_DESCRIPTION => '   ' ] )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	public function test_a_true_flag_projects_to_true(): void {
		$this->assertTrue( $this->provider->project( [ SeoFields::FIELD_NOINDEX => true ] )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertTrue( $this->provider->project( [ SeoFields::FIELD_NOFOLLOW => true ] )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * Neither flag can hold an explicit stored negative, so false and "clear"
	 * both project to null — the same promise Yoast's Rank Math counterpart
	 * makes, restated for a store whose default is inherited rather than
	 * absent.
	 */
	public function test_a_false_or_cleared_flag_projects_to_null(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOINDEX => false ] )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOINDEX => null ] )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_writing_a_general_sub_key_stores_it_inside_the_general_array(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$this->assertSame( [ [ 'page_title' => 'New title' ] ], $this->rowsFor( 42, 'surerank_settings_general' ) );
	}

	/**
	 * A write is a read-modify-write of the owning array, so a sub-key this
	 * module never addresses — or one it addresses but did not change —
	 * survives a write to a different sub-key in the same group.
	 */
	public function test_writing_one_sub_key_preserves_a_foreign_sub_key_already_in_the_array(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'canonical_url' => 'https://example.com/a' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$row = $this->rowsFor( 42, 'surerank_settings_general' )[0];
		$this->assertSame( 'New title', $row['page_title'] );
		$this->assertSame( 'https://example.com/a', $row['canonical_url'] );
	}

	public function test_clearing_a_sub_key_unsets_it_but_leaves_the_rest_of_the_array(): void {
		$this->seedMeta(
			42,
			'surerank_settings_general',
			[
				'page_title'       => 'Old title',
				'page_description' => 'Kept',
			]
		);

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => null ] ) );

		$row = $this->rowsFor( 42, 'surerank_settings_general' )[0];
		$this->assertArrayNotHasKey( 'page_title', $row );
		$this->assertSame( 'Kept', $row['page_description'] );
	}

	public function test_clearing_the_last_sub_key_deletes_the_whole_group_row(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'page_title' => 'Only thing set' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => '' ] ) );

		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_general' ) );
	}

	/**
	 * The flag path has no sub-key, so a write stores the scalar as the
	 * WHOLE row rather than nesting it inside an array — the code path
	 * SlimSeoProviderTest's noindex write never exercises.
	 */
	public function test_setting_the_noindex_flag_stores_the_scalar_as_the_whole_row(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$this->assertSame( [ '1' ], $this->rowsFor( 42, 'surerank_settings_post_no_index' ) );
	}

	public function test_clearing_the_noindex_flag_deletes_its_row(): void {
		$this->seedMeta( 42, 'surerank_settings_post_no_index', '1' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_post_no_index' ) );
	}

	/**
	 * The projection is what the plan promises, so it has to match what a
	 * read gives back, across both groups and a flag in the same change set.
	 */
	public function test_the_projection_agrees_with_what_a_read_reports_afterwards(): void {
		$changes = [
			SeoFields::FIELD_TITLE    => '  Trimmed  ',
			SeoFields::FIELD_OG_TITLE => 'A social title',
			SeoFields::FIELD_NOINDEX  => true,
			SeoFields::FIELD_NOFOLLOW => false,
		];

		$projected = $this->provider->project( $changes );

		$this->assertTrue( $this->provider->apply( 42, $changes ) );

		$values = $this->provider->values( 42 );

		foreach ( $projected as $field => $expected ) {
			$this->assertSame( $expected, $values[ $field ], "The projection for {$field} must match the read." );
		}
	}

	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'surerank', $this->provider->capture( 42 )['provider'] );
	}

	public function test_a_capture_covers_all_four_owned_keys(): void {
		$meta = $this->provider->capture( 42 )['meta'];

		$this->assertSame(
			[
				'surerank_settings_general',
				'surerank_settings_social',
				'surerank_settings_post_no_index',
				'surerank_settings_post_no_follow',
			],
			array_keys( $meta )
		);
	}

	/**
	 * A capture preserves a sub-key no field ever projects.
	 */
	public function test_a_capture_preserves_a_sub_key_the_provider_never_reads(): void {
		$this->seedMeta(
			42,
			'surerank_settings_general',
			[
				'page_title'   => 'Something',
				'vendor_extra' => 'left alone',
			]
		);

		$row = $this->provider->capture( 42 )['meta']['surerank_settings_general'][0];

		$this->assertSame( 'left alone', $row['vendor_extra'] );
	}

	public function test_a_restore_removes_a_value_the_change_added(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'page_title' => 'Original' ] );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply(
			42,
			[
				SeoFields::FIELD_TITLE    => 'Changed',
				SeoFields::FIELD_NOINDEX  => true,
				SeoFields::FIELD_OG_TITLE => 'Added where there was none',
			]
		);

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );

		$row = $this->rowsFor( 42, 'surerank_settings_general' )[0];
		$this->assertSame( 'Original', $row['page_title'] );
		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_post_no_index' ) );
		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_social' ) );
	}

	public function test_a_restore_given_an_empty_snapshot_clears_every_owned_key(): void {
		$this->seedMeta( 42, 'surerank_settings_general', [ 'page_title' => 'Something' ] );
		$this->seedMeta( 42, 'surerank_settings_post_no_index', '1' );

		$this->assertFalse( $this->provider->restore( 42, [] ) );

		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_general' ) );
		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_social' ) );
		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_post_no_index' ) );
		$this->assertFalse( $this->hasMeta( 42, 'surerank_settings_post_no_follow' ) );
	}
}
