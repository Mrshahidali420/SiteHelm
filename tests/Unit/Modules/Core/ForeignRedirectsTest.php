<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\ForeignRedirects;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Tests\Doubles\FakeRedirectionsDb;
use SiteHelm\Tests\TestCase;

/**
 * The lookup that finds the redirects SiteHelm does not own.
 *
 * THE SITE WITH NO OTHER REDIRECT PLUGIN IS THE CASE THAT MATTERS MOST, because
 * it is nearly every site, and this lookup runs inside a preview on all of them.
 * It must cost one existence probe and stop, and it must never take the preview
 * down with it when the read fails.
 *
 * A REGEX SOURCE IS REPORTED AND NEVER EVALUATED. Running a stored pattern would
 * make somebody else's data an execution surface, and a pattern that fails to
 * compile would turn a preview warning into a fatal error. It is reported as a
 * possible match with the pattern quoted, so the caller can read it themselves.
 *
 * @covers \SiteHelm\Modules\Core\ForeignRedirects
 */
final class ForeignRedirectsTest extends TestCase {

	private FakeRedirectionsDb $db;

	private ForeignRedirects $lookup;

	protected function setUp(): void {
		parent::setUp();

		$this->db        = new FakeRedirectionsDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->lookup    = new ForeignRedirects( new RedirectStore() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	public function test_a_site_without_the_other_plugin_reports_nothing_after_one_probe(): void {
		$this->db->installed = false;

		$this->assertSame( [], $this->lookup->matching( '/old-page' ) );
		$this->assertCount( 1, $this->db->queries, 'The table is probed once and no rows are read.' );
	}

	public function test_a_site_with_no_database_at_all_reports_nothing(): void {
		// A preview must survive being run somewhere $wpdb has not been built.
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( [], $this->lookup->matching( '/old-page' ) );
		$this->assertSame( [ 'rules' => [], 'truncated' => false ], $this->lookup->all() );
	}

	public function test_a_path_that_cannot_be_normalised_is_not_looked_up(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ 'old-page', 'exact' ] ] ) ];

		$this->assertSame( [], $this->lookup->matching( 'https://elsewhere.test/old-page' ) );
		$this->assertSame( [], $this->db->queries, 'A path the store would refuse never reaches the database.' );
	}

	public function test_an_exact_source_for_the_same_path_is_a_certain_match(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ '/old-page/', 'exact' ] ], '/their-target', 302 ) ];

		$rules = $this->lookup->matching( '/old-page' );

		$this->assertCount( 1, $rules );
		$this->assertSame( ForeignRedirects::RANK_MATH, $rules[0]['owner'] );
		$this->assertSame( '/old-page/', $rules[0]['pattern'] );
		$this->assertSame( 'exact', $rules[0]['comparison'] );
		$this->assertSame( '/their-target', $rules[0]['target'] );
		$this->assertSame( 302, $rules[0]['status'] );
		$this->assertTrue( $rules[0]['active'] );
		$this->assertTrue( $rules[0]['certain'] );
	}

	public function test_an_exact_source_for_a_different_path_does_not_match(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ 'old-pricing', 'exact' ] ] ) ];

		$this->assertSame( [], $this->lookup->matching( '/old' ) );
	}

	/**
	 * @dataProvider provideComparisons
	 *
	 * @param string $pattern    The stored pattern.
	 * @param string $comparison The stored comparison.
	 * @param bool   $matches    Whether it should be reported at all.
	 */
	public function test_each_comparison_is_settled_the_way_the_other_plugin_would( string $pattern, string $comparison, bool $matches ): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ $pattern, $comparison ] ] ) ];

		$this->assertCount( $matches ? 1 : 0, $this->lookup->matching( '/shop/old-page' ) );
	}

	/**
	 * The four comparisons two strings can settle, each way round.
	 *
	 * @return array<string, array{string, string, bool}> The cases.
	 */
	public static function provideComparisons(): array {
		return [
			'exact, the same path'      => [ 'shop/old-page', 'exact', true ],
			'exact, a different path'   => [ 'shop/old-page-2', 'exact', false ],
			'contains, present'         => [ 'old-page', 'contains', true ],
			'contains, absent'          => [ 'old-pages', 'contains', false ],
			'start, at the front'       => [ 'shop', 'start', true ],
			'start, not at the front'   => [ 'old-page', 'start', false ],
			'end, at the back'          => [ 'old-page', 'end', true ],
			'end, not at the back'      => [ 'shop', 'end', false ],
		];
	}

	public function test_a_regex_source_is_reported_as_possible_and_never_run(): void {
		// An uncompilable pattern is the point: evaluating this would raise
		// inside a preview, and the caller is better served by reading it.
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ '/old-page(/', 'regex' ] ] ) ];

		$rules = $this->lookup->matching( '/old-page' );

		$this->assertCount( 1, $rules );
		$this->assertFalse( $rules[0]['certain'] );
		$this->assertSame( '/old-page(/', $rules[0]['pattern'] );
	}

	public function test_a_comparison_this_version_has_never_heard_of_is_reported_as_possible(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ 'old-page', 'some-future-comparison' ] ] ) ];

		$rules = $this->lookup->matching( '/old-page' );

		$this->assertCount( 1, $rules );
		$this->assertFalse( $rules[0]['certain'] );
	}

	public function test_a_rule_that_is_switched_off_is_still_reported_and_says_so(): void {
		// It is one click away from answering the path, and a caller deciding
		// whether to claim that path wants to know it is sitting there.
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ 'old-page', 'exact' ] ], '/x', 301, false ) ];

		$rules = $this->lookup->matching( '/old-page' );

		$this->assertCount( 1, $rules );
		$this->assertFalse( $rules[0]['active'] );
		$this->assertStringContainsString( 'switched off', $this->lookup->describe( $rules[0], '/old-page' ) );
	}

	public function test_one_row_holding_several_sources_reports_each_one(): void {
		$this->db->rows = [
			FakeRedirectionsDb::row(
				[
					[ 'old-page', 'exact' ],
					[ 'old-page', 'contains' ],
				]
			),
		];

		$this->assertCount( 2, $this->lookup->matching( '/old-page' ) );
	}

	public function test_a_row_whose_sources_are_not_readable_is_skipped_rather_than_raised(): void {
		$this->db->rows = [
			[
				'sources'     => 'not serialised at all',
				'url_to'      => '/x',
				'header_code' => 301,
				'status'      => 'active',
			],
			FakeRedirectionsDb::row( [ [ 'old-page', 'exact' ] ] ),
		];

		$this->assertCount( 1, $this->lookup->matching( '/old-page' ) );
	}

	public function test_an_empty_pattern_is_not_a_match_for_everything(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ '  ', 'contains' ] ] ) ];

		$this->assertSame( [], $this->lookup->matching( '/old-page' ) );
	}

	public function test_the_listing_reports_every_rule_without_narrowing_by_path(): void {
		$this->db->rows = [
			FakeRedirectionsDb::row( [ [ 'one', 'exact' ] ], '/a' ),
			FakeRedirectionsDb::row( [ [ 'two', 'exact' ] ], '/b' ),
		];

		$listing = $this->lookup->all();

		$this->assertCount( 2, $listing['rules'] );
		$this->assertFalse( $listing['truncated'] );
		$this->assertArrayNotHasKey( 'certain', $listing['rules'][0], 'A listing states what is held, not what a path would hit.' );
	}

	public function test_a_listing_longer_than_the_bound_is_cut_and_says_so(): void {
		$rows = [];

		for ( $index = 0; $index <= ForeignRedirects::MAX_ROWS; $index++ ) {
			$rows[] = FakeRedirectionsDb::row( [ [ 'path-' . $index, 'exact' ] ] );
		}

		$this->db->rows = $rows;
		$listing        = $this->lookup->all();

		$this->assertCount( ForeignRedirects::MAX_ROWS, $listing['rules'] );
		$this->assertTrue( $listing['truncated'] );
	}

	public function test_the_warning_names_the_owner_and_the_path(): void {
		$this->db->rows = [ FakeRedirectionsDb::row( [ [ 'old-page', 'exact' ] ] ) ];

		$warning = $this->lookup->describe( $this->lookup->matching( '/old-page' )[0], '/old-page' );

		$this->assertStringContainsString( 'Rank Math', $warning );
		$this->assertStringContainsString( '/old-page', $warning );
		$this->assertStringContainsString( 'old-page', $warning );
		$this->assertStringNotContainsString( 'switched off', $warning );
	}
}
