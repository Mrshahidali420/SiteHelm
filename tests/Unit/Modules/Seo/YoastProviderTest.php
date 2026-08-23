<?php
/**
 * Tests for the Yoast SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\YoastProvider;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back.
 *
 * NO PROCESS ISOLATION IS NEEDED IN THIS FILE. The provider names no plugin symbol
 * at all — only meta keys — so nothing here has to define `WPSEO_VERSION`. That is
 * the containment SeoPresence exists to provide, and its absence from this file is
 * the evidence the containment holds.
 *
 * THE THREE-STATE ROBOTS ENCODING IS THE SUBSTANCE. Yoast stores `'1'` noindex,
 * `'2'` index, and absent for "use the site's setting", so each of the three reads
 * back as a distinct answer and each is held below on its own. A provider that
 * collapsed the third state into `false` would tell a caller a post is explicitly
 * indexed when nothing has ever said so — and a write echoing that back would
 * create the explicit row the site never had.
 */
final class YoastProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The provider under test.
	 */
	private YoastProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new YoastProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'yoast-seo', $this->provider->name() );
	}

	/**
	 * Every field is present in a read, whether or not the store holds it.
	 *
	 * A caller never has to tell "this plugin does not support the field" from "the
	 * field is not set", because the first case does not exist: the answer always
	 * carries all twelve names.
	 */
	public function test_an_unscored_post_reports_both_scores_as_null(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_stored_scores_are_read_as_clamped_integers(): void {
		$this->seedMeta( 42, '_yoast_wpseo_linkdex', '73' );
		$this->seedMeta( 42, '_yoast_wpseo_content_score', '120' );

		$this->assertSame( [ 'seoScore' => 73, 'readabilityScore' => 100 ], $this->provider->scores( 42 ) );
	}

	public function test_a_non_numeric_score_reads_as_null_rather_than_zero(): void {
		$this->seedMeta( 42, '_yoast_wpseo_linkdex', 'n/a' );

		$this->assertNull( $this->provider->scores( 42 )['seoScore'] );
	}

	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_text_is_reported_under_the_neutral_field_name(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', 'A title' );
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', 'A description' );
		$this->seedMeta( 42, '_yoast_wpseo_canonical', 'https://example.com/a' );
		$this->seedMeta( 42, '_yoast_wpseo_focuskw', 'widgets' );

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/a', $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertSame( 'widgets', $values[ SeoFields::FIELD_FOCUS_KEYWORD ] );
	}

	/**
	 * A stored empty string reads the same as no row at all.
	 *
	 * Both are "nothing is set", and reporting `''` for one of them would make a
	 * caller comparing a read to a write see a change where none happened.
	 */
	public function test_a_stored_empty_string_reads_as_null_rather_than_as_an_empty_value(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', '   ' );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_TITLE ] );
	}

	/**
	 * Post meta is a store anything can write an array into.
	 *
	 * `trim()` on an array is a fatal rather than a wrong answer, which is why the
	 * read is guarded on shape rather than cast.
	 */
	public function test_a_text_value_of_the_wrong_shape_reads_as_null_rather_than_fataling(): void {
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', [ 'imported', 'badly' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * @return array<string, array{0: string, 1: bool|null}> Stored noindex value => reported flag.
	 */
	public static function noindexEncodings(): array {
		return [
			'one means noindex'                 => [ '1', true ],
			'two means index'                   => [ '2', false ],
			'zero is not a Yoast noindex value' => [ '0', null ],
			'nonsense means the plugin decides' => [ 'yes', null ],
		];
	}

	/**
	 * @dataProvider noindexEncodings
	 *
	 * @param string    $stored   The value the store holds.
	 * @param bool|null $expected The flag a read reports.
	 */
	public function test_the_noindex_directive_is_read_from_yoasts_own_numbers( string $stored, ?bool $expected ): void {
		$this->seedMeta( 42, '_yoast_wpseo_meta-robots-noindex', $stored );

		$this->assertSame( $expected, $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_an_absent_noindex_row_means_the_plugin_decides(): void {
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * Nofollow uses a different negative than noindex does.
	 *
	 * `'0'` is Yoast's stored "follow" for this key and its "the plugin decides" for
	 * the other one, so a provider that shared one negative between the two would
	 * report one of them wrong.
	 */
	public function test_the_nofollow_directive_uses_zero_as_its_explicit_negative(): void {
		$this->seedMeta( 42, '_yoast_wpseo_meta-robots-nofollow', '0' );

		$this->assertFalse( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_writing_text_stores_it_under_the_plugins_own_key(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'New title' ] ) );

		$this->assertSame( [ 'New title' ], $this->rowsFor( 42, '_yoast_wpseo_title' ) );
	}

	public function test_a_written_value_is_trimmed_before_it_is_stored(): void {
		$this->provider->apply( 42, [ SeoFields::FIELD_DESCRIPTION => '  padded  ' ] );

		$this->assertSame( [ 'padded' ], $this->rowsFor( 42, '_yoast_wpseo_metadesc' ) );
	}

	/**
	 * Clearing deletes the row rather than storing an empty string.
	 *
	 * An absent row and a stored `''` render identically, and only the absent row is
	 * honest about never having been set — which is also what makes a later snapshot
	 * of an untouched post distinguishable from one this module cleared.
	 */
	public function test_clearing_a_field_deletes_its_row_rather_than_emptying_it(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Old title' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_title' ) );
	}

	public function test_clearing_a_flag_deletes_its_row_rather_than_storing_the_default_number(): void {
		$this->seedMeta( 42, '_yoast_wpseo_meta-robots-noindex', '1' );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_meta-robots-noindex' ) );
	}

	public function test_setting_a_flag_false_stores_yoasts_explicit_negative(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => false ] ) );

		$this->assertSame( [ '2' ], $this->rowsFor( 42, '_yoast_wpseo_meta-robots-noindex' ) );
		$this->assertFalse( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * Yoast can store an explicit "follow", so `nofollow: false` survives the round trip.
	 *
	 * This is the half of the contract Rank Math cannot honour, and holding it here
	 * is what makes RankMathProviderTest's opposite assertion mean something.
	 */
	public function test_this_plugin_can_store_an_explicit_follow(): void {
		$this->provider->apply( 42, [ SeoFields::FIELD_NOFOLLOW => false ] );

		$this->assertFalse( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
		$this->assertFalse( $this->provider->project( [ SeoFields::FIELD_NOFOLLOW => false ] )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * The projection is what the plan promises, so it has to match what a read gives back.
	 *
	 * Asserted as a pair — project, then apply, then read — because two different
	 * answers to "what should be there now" is how a documented store limitation
	 * becomes an intermittent failed write.
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

	/**
	 * A capture carries the provider's own name beside the meta.
	 *
	 * `restore()` on the operation refuses a snapshot another provider took, and this
	 * is the value it refuses on.
	 */
	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'yoast-seo', $this->provider->capture( 42 )['provider'] );
	}

	/**
	 * A capture covers keys no field projects.
	 *
	 * The `-image-id` beside each social image URL is what the plugin's renderer
	 * reads, so a snapshot that held only the projected keys could not put the pair
	 * back.
	 */
	public function test_a_capture_covers_the_unprojected_keys_the_plugin_renders_from(): void {
		$meta = $this->provider->capture( 42 )['meta'];

		$this->assertArrayHasKey( '_yoast_wpseo_opengraph-image-id', $meta );
		$this->assertArrayHasKey( '_yoast_wpseo_twitter-image-id', $meta );
	}

	/**
	 * A snapshot puts the store back exactly, including removing what the change added.
	 *
	 * Every owned key is deleted and re-added rather than updated, because a key the
	 * change ADDED has no row in the snapshot and an update-only restore would leave
	 * its value behind — a rollback that removes some of a change and not all of it.
	 */
	public function test_a_restore_removes_a_value_the_change_added(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Original' );
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
		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_metadesc' ) );
		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_meta-robots-noindex' ) );
	}

	/**
	 * The restore keeps the distinction the single-value read erases.
	 *
	 * A key holding one empty row is not the same stored state as a key with no rows,
	 * and a restore that turned the first into the second would report success for a
	 * store it had changed.
	 */
	public function test_a_restore_puts_back_an_empty_row_rather_than_dropping_the_key(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', '' );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'Changed' ] );

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );
		$this->assertSame( [ '' ], $this->rowsFor( 42, '_yoast_wpseo_title' ) );
	}

	/**
	 * A restore handed no meta clears the owned keys AND reports that it did not match.
	 *
	 * Both halves matter. The clearing is what keeps a malformed snapshot from leaving
	 * half a change in place; the false is what keeps the operation from reporting a
	 * successful rollback it cannot substantiate. A snapshot with no `meta` member is
	 * not a snapshot this provider took, so "the store now equals the snapshot" is a
	 * claim there is no evidence for.
	 */
	public function test_a_restore_given_no_meta_clears_every_owned_key_and_reports_no_match(): void {
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Something' );

		$this->assertFalse( $this->provider->restore( 42, [] ) );
		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_title' ) );
	}
}
