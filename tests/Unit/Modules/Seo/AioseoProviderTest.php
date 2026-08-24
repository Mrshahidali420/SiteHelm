<?php
/**
 * Tests for the All in One SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\AioseoProvider;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * What this provider reads, writes, snapshots and puts back — against a table.
 *
 * THIS IS THE ONE PROVIDER WITH NO POST META IN IT, so the double is FakeWpdb
 * rather than the meta stubs: every read pops a queued row and every write is
 * recorded for assertion. The queue discipline is the shape of the provider —
 * values() and capture() cost one row read each, apply() costs two (the current
 * row, then the verification re-read), restore() costs one after its delete.
 *
 * THE COUPLED ROBOTS SWITCH IS THE SUBSTANCE. `robots_default` = 1 ignores every
 * directive column; 0 makes both explicit at once. The tests below hold the two
 * promises that follow: clearing a flag projects (and verifies) as false rather
 * than null, and a flag write flips the row to explicit mode while the untouched
 * directive keeps the value it was effectively rendering.
 */
final class AioseoProviderTest extends TestCase {

	/**
	 * The provider under test.
	 */
	private AioseoProvider $provider;

	/**
	 * The recorded database double.
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->provider  = new AioseoProvider();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A full table row, defaulted to the shape a fresh plugin write leaves.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function tableRow( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                  => '7',
				'post_id'             => '42',
				'title'               => '',
				'description'         => '',
				'canonical_url'       => '',
				'og_title'            => '',
				'og_description'      => '',
				'twitter_title'       => '',
				'twitter_description' => '',
				'robots_default'      => '1',
				'robots_noindex'      => '0',
				'robots_nofollow'     => '0',
				'seo_score'           => '0',
				'created'             => '2026-01-01 00:00:00',
				'updated'             => '2026-01-01 00:00:00',
			],
			$overrides
		);
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'aioseo', $this->provider->name() );
	}

	public function test_a_post_with_no_row_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
		$this->assertStringContainsString( 'wp_aioseo_posts', $this->wpdb->queries[0] );
	}

	public function test_stored_text_columns_are_read_trimmed(): void {
		$this->wpdb->rowQueue[] = $this->tableRow(
			[
				'title'         => '  Summer sale  ',
				'description'   => 'All rackets half price.',
				'canonical_url' => 'https://example.com/sale',
				'og_title'      => 'Sale!',
			]
		);

		$values = $this->provider->values( 42 );

		$this->assertSame( 'Summer sale', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'All rackets half price.', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/sale', $values[ SeoFields::FIELD_CANONICAL ] );
		$this->assertSame( 'Sale!', $values[ SeoFields::FIELD_OG_TITLE ] );
		$this->assertNull( $values[ SeoFields::FIELD_TWITTER_TITLE ] );
	}

	/**
	 * The focus keyword and both images are declined: their storage is
	 * version-dependent (keyword) or composed from several columns (images), so
	 * reporting them would be a guess about the site's schema generation.
	 */
	public function test_declined_fields_read_null_even_when_the_row_carries_lookalike_columns(): void {
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'focus_keyword' => 'racket' ] );

		$values = $this->provider->values( 42 );

		$this->assertNull( $values[ SeoFields::FIELD_FOCUS_KEYWORD ] );
		$this->assertNull( $values[ SeoFields::FIELD_OG_IMAGE ] );
		$this->assertNull( $values[ SeoFields::FIELD_TWITTER_IMAGE ] );
	}

	public function test_a_row_in_default_robots_mode_reports_both_flags_as_null(): void {
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'robots_noindex' => '1' ] );

		$values = $this->provider->values( 42 );

		$this->assertNull( $values[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $values[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_a_row_in_explicit_robots_mode_reports_both_flags_as_booleans(): void {
		$this->wpdb->rowQueue[] = $this->tableRow(
			[
				'robots_default' => '0',
				'robots_noindex' => '1',
			]
		);

		$values = $this->provider->values( 42 );

		$this->assertTrue( $values[ SeoFields::FIELD_NOINDEX ] );
		$this->assertFalse( $values[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * A corrupt switch column reads as the default mode, because inventing
	 * "explicit" from garbage would report directives the plugin never renders.
	 */
	public function test_a_non_numeric_robots_switch_reads_as_default_mode(): void {
		$this->wpdb->rowQueue[] = $this->tableRow(
			[
				'robots_default' => 'yes',
				'robots_noindex' => '1',
			]
		);

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_stored_score_is_read_as_a_clamped_integer(): void {
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'seo_score' => '87' ] );

		$this->assertSame( [ 'seoScore' => 87, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_an_out_of_band_score_is_clamped_and_a_missing_row_reads_null(): void {
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'seo_score' => '140' ] );

		$this->assertSame( 100, $this->provider->scores( 42 )['seoScore'] );
		$this->assertNull( $this->provider->scores( 42 )['seoScore'] );
	}

	public function test_projection_trims_text_and_reads_empty_as_a_clear(): void {
		$projected = $this->provider->project(
			[
				SeoFields::FIELD_TITLE       => '  Spring menu  ',
				SeoFields::FIELD_DESCRIPTION => '   ',
			]
		);

		$this->assertSame( 'Spring menu', $projected[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $projected[ SeoFields::FIELD_DESCRIPTION ] );
	}

	public function test_projection_declines_the_focus_keyword(): void {
		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_FOCUS_KEYWORD => 'racket' ] )[ SeoFields::FIELD_FOCUS_KEYWORD ] );
	}

	/**
	 * The plan's promise for the coupled store: a flag never projects to null,
	 * because a written row cannot return one directive to the default alone.
	 * False and "clear" both promise false — explicit mode, directive off.
	 */
	public function test_a_cleared_flag_projects_to_false_rather_than_null(): void {
		$projected = $this->provider->project(
			[
				SeoFields::FIELD_NOINDEX  => null,
				SeoFields::FIELD_NOFOLLOW => true,
			]
		);

		$this->assertFalse( $projected[ SeoFields::FIELD_NOINDEX ] );
		$this->assertTrue( $projected[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_a_text_write_updates_the_existing_row_and_verifies(): void {
		$this->wpdb->rowQueue[] = $this->tableRow();
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'title' => 'Spring menu' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => '  Spring menu  ' ] ) );

		$this->assertCount( 1, $this->wpdb->updates );
		$this->assertSame( 'wp_aioseo_posts', $this->wpdb->updates[0]['table'] );
		$this->assertSame( [ 'title' => 'Spring menu' ], $this->wpdb->updates[0]['data'] );
		$this->assertSame( [ 'post_id' => 42 ], $this->wpdb->updates[0]['where'] );
	}

	public function test_a_write_to_a_post_with_no_row_inserts_one(): void {
		$this->wpdb->rowQueue[] = null;
		$this->wpdb->rowQueue[] = $this->tableRow(
			[
				'id'    => '9',
				'title' => 'Spring menu',
			]
		);

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'Spring menu' ] ) );

		$this->assertCount( 1, $this->wpdb->inserts );
		$insert = $this->wpdb->inserts[0];
		$this->assertSame( 'wp_aioseo_posts', $insert['table'] );
		$this->assertSame( 42, $insert['data']['post_id'] );
		$this->assertSame( 'Spring menu', $insert['data']['title'] );
		$this->assertArrayHasKey( 'created', $insert['data'] );
		$this->assertArrayHasKey( 'updated', $insert['data'] );
	}

	/**
	 * The documented side effect, held on purpose: a flag write flips the row to
	 * explicit mode, and the untouched directive is pinned to the value it was
	 * effectively rendering — false, when the row was still on the site rules.
	 */
	public function test_a_flag_write_flips_the_row_explicit_and_pins_the_untouched_directive(): void {
		$this->wpdb->rowQueue[] = $this->tableRow();
		$this->wpdb->rowQueue[] = $this->tableRow(
			[
				'robots_default' => '0',
				'robots_noindex' => '1',
			]
		);

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$this->assertSame(
			[
				'robots_default'  => 0,
				'robots_noindex'  => 1,
				'robots_nofollow' => 0,
			],
			$this->wpdb->updates[0]['data']
		);
	}

	public function test_a_flag_write_preserves_an_explicit_untouched_directive(): void {
		$explicit               = [
			'robots_default'  => '0',
			'robots_nofollow' => '1',
		];
		$this->wpdb->rowQueue[] = $this->tableRow( $explicit );
		$this->wpdb->rowQueue[] = $this->tableRow( $explicit + [ 'robots_noindex' => '1' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$this->assertSame( 1, $this->wpdb->updates[0]['data']['robots_nofollow'] );
	}

	public function test_a_write_the_store_does_not_echo_back_reports_failure(): void {
		$this->wpdb->rowQueue[] = $this->tableRow();
		$this->wpdb->rowQueue[] = $this->tableRow();

		$this->assertFalse( $this->provider->apply( 42, [ SeoFields::FIELD_TITLE => 'Spring menu' ] ) );
	}

	/**
	 * A change set of only declined fields writes nothing — and verifies, because
	 * the projection promised null and null is what the store reports.
	 */
	public function test_a_change_set_of_only_declined_fields_writes_nothing_and_verifies(): void {
		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_FOCUS_KEYWORD => 'racket' ] ) );

		$this->assertSame( [], $this->wpdb->inserts );
		$this->assertSame( [], $this->wpdb->updates );
	}

	public function test_capture_carries_the_whole_row_or_its_absence(): void {
		$row                    = $this->tableRow( [ 'seo_score' => '55' ] );
		$this->wpdb->rowQueue[] = $row;

		$this->assertSame(
			[
				'provider' => 'aioseo',
				'row'      => $row,
			],
			$this->provider->capture( 42 )
		);
		$this->assertSame(
			[
				'provider' => 'aioseo',
				'row'      => null,
			],
			$this->provider->capture( 42 )
		);
	}

	public function test_restore_deletes_then_reinserts_the_snapshot_row_whole(): void {
		$row                    = $this->tableRow();
		$this->wpdb->rowQueue[] = $row;

		$this->assertTrue( $this->provider->restore( 42, [ 'row' => $row ] ) );

		$this->assertStringContainsString( 'DELETE FROM wp_aioseo_posts', $this->wpdb->queries[0] );
		$this->assertSame( [ 42 ], $this->wpdb->prepared[0]['args'] );
		$this->assertSame( $row, $this->wpdb->inserts[0]['data'] );
	}

	/**
	 * A snapshot with no row restores to no row: the delete runs, nothing is
	 * re-inserted, and the empty re-read is the agreement.
	 */
	public function test_restoring_an_absent_row_removes_what_the_change_created(): void {
		$this->assertTrue( $this->provider->restore( 42, [ 'row' => null ] ) );

		$this->assertSame( [], $this->wpdb->inserts );
	}

	public function test_a_restore_the_store_does_not_reflect_reports_failure(): void {
		$row                    = $this->tableRow();
		$this->wpdb->rowQueue[] = $this->tableRow( [ 'title' => 'drifted' ] );

		$this->assertFalse( $this->provider->restore( 42, [ 'row' => $row ] ) );
	}
}
