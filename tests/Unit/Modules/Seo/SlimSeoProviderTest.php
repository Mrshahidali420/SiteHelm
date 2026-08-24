<?php
/**
 * Tests for the Slim SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SlimSeoProvider;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back.
 *
 * ONE ARRAY, NOT ONE KEY PER FIELD is the shape SeoArrayMetaProvider exists to
 * cover, and this file is the half of its contract that reads mostly null:
 * Slim SEO's own vocabulary is five sub-keys under `slim_seo`, so most of
 * SiteHelm's field names have nowhere to live. Declined-field null is asserted
 * here as its own promise, not merely as the absence of a passing assertion.
 *
 * NO PROCESS ISOLATION IS NEEDED IN THIS FILE, for the same reason as Yoast's:
 * the provider names no plugin symbol, only a meta key.
 */
final class SlimSeoProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The provider under test.
	 */
	private SlimSeoProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new SlimSeoProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'slim-seo', $this->provider->name() );
	}

	public function test_the_provider_reports_no_analysis_scores(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	/**
	 * Every field is present in a read, whether or not the plugin can store it.
	 *
	 * A caller never has to tell "this plugin does not support the field" from
	 * "the field is not set", because the first case does not exist: the answer
	 * always carries all twelve names.
	 */
	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_sub_keys_are_reported_under_the_neutral_field_names(): void {
		$this->seedMeta(
			42,
			'slim_seo',
			[
				'title'          => 'A title',
				'description'    => 'A description',
				'facebook_image' => 'https://example.com/og.png',
				'twitter_image'  => 'https://example.com/twitter.png',
			]
		);

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/og.png', $values[ SeoFields::FIELD_OG_IMAGE ] );
		$this->assertSame( 'https://example.com/twitter.png', $values[ SeoFields::FIELD_TWITTER_IMAGE ] );
	}

	public function test_a_stored_empty_sub_key_reads_as_null_rather_than_as_an_empty_value(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => '' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_an_absent_sub_key_reads_as_null(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'description' => 'Only description is set' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	/**
	 * Post meta is a store anything can write into, including something that
	 * is not the array this plugin expects.
	 *
	 * A path read is guarded on shape before a sub-key is followed, so a
	 * malformed row reads every field null rather than fataling on the array
	 * access.
	 */
	public function test_a_non_array_stored_value_reads_every_field_as_null(): void {
		$this->seedMeta( 42, 'slim_seo', 'not-an-array' );

		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $this->provider->values( 42 ) );
	}

	/**
	 * The declined-field promise: a field with nowhere to live reads as null.
	 *
	 * Slim SEO's own vocabulary has no canonical override, no focus keyword, no
	 * per-card social text, and no nofollow directive, and this is deliberately
	 * indistinguishable from "unset" rather than surfaced as an error — the
	 * honest answer for a plugin that has nowhere to put the value.
	 */
	public function test_declined_fields_always_read_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertNull( $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertNull( $values[ SeoFields::FIELD_FOCUS_KEYWORD ] );
		$this->assertNull( $values[ SeoFields::FIELD_NOFOLLOW ] );
		$this->assertNull( $values[ SeoFields::FIELD_OG_TITLE ] );
		$this->assertNull( $values[ SeoFields::FIELD_OG_DESCRIPTION ] );
		$this->assertNull( $values[ SeoFields::FIELD_TWITTER_TITLE ] );
		$this->assertNull( $values[ SeoFields::FIELD_TWITTER_DESCRIPTION ] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool|null}> Stored noindex sub-key value => reported flag.
	 */
	public static function noindexEncodings(): array {
		return [
			'boolean true means hidden from search' => [ true, true ],
			'integer one means hidden from search'  => [ 1, true ],
			'string one means hidden from search'   => [ '1', true ],
			'boolean false leaves it open'          => [ false, null ],
			'integer zero leaves it open'           => [ 0, null ],
			'empty string leaves it open'           => [ '', null ],
			'nonsense means the plugin decides'     => [ 'no', null ],
		];
	}

	/**
	 * @dataProvider noindexEncodings
	 *
	 * @param mixed     $stored   The value the store holds at the sub-key.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_noindex_directive_is_read_from_the_plugins_truthy_check( mixed $stored, ?bool $expected ): void {
		$this->seedMeta( 42, 'slim_seo', [ 'noindex' => $stored ] );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_an_absent_noindex_sub_key_leaves_the_plugin_deciding(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => 'Something' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_written_value_is_trimmed_before_it_is_projected(): void {
		$this->assertSame( 'Trimmed', $this->provider->project( [ SeoFields::FIELD_TITLE => '  Trimmed  ' ] )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_an_empty_projected_value_reads_as_null(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_DESCRIPTION => '   ' ] )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * A declined text field projects to null rather than to the value asked for.
	 *
	 * The plan promise has to match what a read can ever report, and this
	 * plugin can never report a canonical override — so the honest projection
	 * is null, not the value the caller sent.
	 */
	public function test_a_declined_field_projects_to_null_rather_than_to_the_requested_value(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_CANONICAL => 'https://example.com/a' ] )[ SeoFields::FIELD_CANONICAL ] );
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_FOCUS_KEYWORD => 'widgets' ] )[ SeoFields::FIELD_FOCUS_KEYWORD ] );
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_OG_TITLE => 'An OG title' ] )[ SeoFields::FIELD_OG_TITLE ] );
	}

	public function test_a_true_flag_projects_to_true(): void {
		$this->assertTrue( $this->provider->project( [ SeoFields::FIELD_NOINDEX => true ] )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_false_or_cleared_flag_projects_to_null(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOINDEX => false ] )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOINDEX => null ] )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * Nofollow is a flag field this plugin never stores, so even asking for
	 * true projects to null — the same declined-field promise the text fields
	 * carry, restated for a flag.
	 */
	public function test_nofollow_projects_to_null_even_when_asked_true(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOFOLLOW => true ] )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_writing_one_sub_key_stores_it_inside_the_single_array(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$this->assertSame( [ [ 'title' => 'New title' ] ], $this->rowsFor( 42, 'slim_seo' ) );
	}

	/**
	 * A write is a read-modify-write of the owning array, so a sub-key this
	 * module never addresses survives a write to a different sub-key.
	 */
	public function test_writing_one_sub_key_preserves_a_foreign_sub_key_already_in_the_array(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'facebook_image' => 'https://example.com/og.png' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$row = $this->rowsFor( 42, 'slim_seo' )[0];
		$this->assertSame( 'New title', $row['title'] );
		$this->assertSame( 'https://example.com/og.png', $row['facebook_image'] );
	}

	public function test_clearing_a_sub_key_unsets_it_but_leaves_the_rest_of_the_array(): void {
		$this->seedMeta(
			42,
			'slim_seo',
			[
				'title'       => 'Old title',
				'description' => 'Kept',
			]
		);

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => null ] ) );

		$row = $this->rowsFor( 42, 'slim_seo' )[0];
		$this->assertArrayNotHasKey( 'title', $row );
		$this->assertSame( 'Kept', $row['description'] );
	}

	public function test_clearing_the_last_sub_key_deletes_the_whole_row(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => 'Only thing set' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => '' ] ) );

		$this->assertFalse( $this->hasMeta( 42, 'slim_seo' ) );
	}

	/**
	 * The plugin's own reader defaults from a boolean, so a flag write stores
	 * the PHP boolean rather than a numeric or string stand-in for it.
	 */
	public function test_setting_the_noindex_flag_stores_a_boolean_true_inside_the_array(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$row = $this->rowsFor( 42, 'slim_seo' )[0];
		$this->assertTrue( $row['noindex'] );
	}

	public function test_clearing_the_noindex_flag_unsets_its_sub_key(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'noindex' => true ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, 'slim_seo' ) );
	}

	/**
	 * The projection is what the plan promises, so it has to match what a read
	 * gives back — including for a field the plugin declines.
	 */
	public function test_the_projection_agrees_with_what_a_read_reports_afterwards(): void {
		$changes = [
			SeoFields::FIELD_TITLE     => '  Trimmed  ',
			SeoFields::FIELD_NOINDEX   => true,
			SeoFields::FIELD_CANONICAL => 'https://example.com/declined',
		];

		$projected = $this->provider->project( $changes );

		$this->assertTrue( $this->provider->apply( 42, $changes ) );

		$values = $this->provider->values( 42 );

		foreach ( $projected as $field => $expected ) {
			$this->assertSame( $expected, $values[ $field ], "The projection for {$field} must match the read." );
		}
	}

	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'slim-seo', $this->provider->capture( 42 )['provider'] );
	}

	public function test_a_capture_covers_only_the_one_owned_key(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => 'Something' ] );

		$this->assertSame( [ 'slim_seo' ], array_keys( $this->provider->capture( 42 )['meta'] ) );
	}

	/**
	 * A capture preserves a sub-key no field ever projects.
	 *
	 * The provider addresses five sub-keys; anything else another tool wrote
	 * into the same array survives capture and restore untouched, because the
	 * snapshot is the raw row rather than a re-serialization of what this
	 * module understands.
	 */
	public function test_a_capture_preserves_a_sub_key_the_provider_never_reads(): void {
		$this->seedMeta(
			42,
			'slim_seo',
			[
				'title'        => 'Something',
				'vendor_extra' => 'left alone',
			]
		);

		$row = $this->provider->capture( 42 )['meta']['slim_seo'][0];

		$this->assertSame( 'left alone', $row['vendor_extra'] );
	}

	public function test_a_restore_removes_a_value_the_change_added(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => 'Original' ] );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply(
			42,
			[
				SeoFields::FIELD_TITLE       => 'Changed',
				SeoFields::FIELD_DESCRIPTION => 'Added where there was none',
			]
		);

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );

		$row = $this->rowsFor( 42, 'slim_seo' )[0];
		$this->assertSame( 'Original', $row['title'] );
		$this->assertArrayNotHasKey( 'description', $row );
	}

	public function test_a_restore_given_an_empty_snapshot_deletes_the_row(): void {
		$this->seedMeta( 42, 'slim_seo', [ 'title' => 'Something' ] );

		$this->assertFalse( $this->provider->restore( 42, [] ) );
		$this->assertFalse( $this->hasMeta( 42, 'slim_seo' ) );
	}
}
