<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: the redirect table and the one normaliser both sides of the lookup
 * share.
 *
 * THE NORMALISER IS THE MOST LOAD-BEARING FUNCTION IN THIS FEATURE, so it is
 * pinned as a table of pairs rather than by a handful of examples. A redirect is
 * stored by one code path and matched by another, months apart; a spelling this
 * function changes silently on one side and not the other produces a row that is
 * written, listed and verified and never fires, with nothing reporting a fault.
 *
 * @covers \SiteHelm\Modules\Core\RedirectStore
 */
final class RedirectStoreTest extends RedirectTestCase {

	/**
	 * @dataProvider provide_paths
	 */
	public function test_the_normaliser_reduces_a_path_to_one_spelling( string $raw, ?string $expected ): void {
		$this->assertSame( $expected, $this->store->normalizePath( $raw ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provide_paths(): array {
		return [
			'already canonical'          => [ '/old-pricing', '/old-pricing' ],
			'no leading slash'           => [ 'old-pricing', '/old-pricing' ],
			'trailing slash'             => [ '/old-pricing/', '/old-pricing' ],
			'several trailing slashes'   => [ '/old-pricing///', '/old-pricing' ],
			'surrounding whitespace'     => [ '  /old-pricing  ', '/old-pricing' ],
			'doubled inner slash'        => [ '/old//pricing', '/old/pricing' ],
			'query string'               => [ '/old-pricing?utm_source=x', '/old-pricing' ],
			'fragment'                   => [ '/old-pricing#section', '/old-pricing' ],
			'percent-encoded letters'    => [ '/caf%C3%A9', '/café' ],
			'nested path'                => [ '/2024/06/old-post/', '/2024/06/old-post' ],
			'encoded question mark'      => [ '/a%3Fb', null ],
			'encoded hash'               => [ '/a%23b', null ],
			'encoded null byte'          => [ '/a%00b', null ],
			'the home page'              => [ '/', null ],
			'empty'                      => [ '', null ],
			'whitespace only'            => [ '   ', null ],
			'protocol relative'          => [ '//example.com/x', null ],
			'absolute url'               => [ 'https://example.com/x', null ],
			'javascript uri'             => [ 'javascript:alert(1)', null ],
			'data uri'                   => [ 'data:text/html,x', null ],
			'parent segment'             => [ '/a/../b', null ],
			'current segment'            => [ '/a/./b', null ],
			'parent segment at the end'  => [ '/a/b/..', null ],
			'encoded parent segment'     => [ '/a/%2E%2E/b', null ],
		];
	}

	public function test_the_length_bound_is_on_the_normalised_path(): void {
		$at_bound = '/' . str_repeat( 'a', RedirectStore::MAX_PATH_LENGTH - 1 );

		$this->assertSame( $at_bound, $this->store->normalizePath( $at_bound ) );
		$this->assertNull( $this->store->normalizePath( $at_bound . 'a' ) );
	}

	public function test_two_spellings_that_differ_only_in_case_are_two_paths(): void {
		// WordPress permalinks are case sensitive, and folding case here would
		// make /About and /about one row an operator could not separate.
		$this->assertSame( '/About', $this->store->normalizePath( '/About' ) );
		$this->assertSame( '/about', $this->store->normalizePath( '/about' ) );
	}

	public function test_the_table_is_keyed_by_the_canonical_spelling_of_what_is_stored(): void {
		// The stored source is a spelling this version would not write. It must
		// come back under the key the ROUTER will look it up by, or not at all.
		$this->seed( [ $this->row( '/Old-Pricing/', '/pricing' ) ] );

		$this->assertSame( [ '/Old-Pricing' ], array_keys( $this->store->all() ) );
		$this->assertNotNull( $this->store->find( '/Old-Pricing?utm=1' ) );
	}

	public function test_the_table_is_ordered_by_source_path(): void {
		$this->seed(
			[
				$this->row( '/zebra', '/z' ),
				$this->row( '/apple', '/a' ),
				$this->row( '/mango', '/m' ),
			]
		);

		$this->assertSame( [ '/apple', '/mango', '/zebra' ], array_keys( $this->store->all() ) );
	}

	/**
	 * @dataProvider provide_malformed_rows
	 */
	public function test_a_malformed_row_is_dropped_rather_than_raised( mixed $row ): void {
		// This option is autoloaded into every anonymous front-end request, so
		// reading defensively is the difference between one unusable redirect and
		// a white screen.
		$this->seed( [ $row, $this->row( '/good', '/fine' ) ] );

		$this->assertSame( [ '/good' ], array_keys( $this->store->all() ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_malformed_rows(): array {
		return [
			'not an array'          => [ 'nonsense' ],
			'no source'             => [ [ 'target' => '/x', 'status' => 301 ] ],
			'unusable source'       => [ [ 'source' => '/', 'target' => '/x', 'status' => 301 ] ],
			'source not a string'   => [ [ 'source' => [ '/x' ], 'target' => '/x', 'status' => 301 ] ],
			'unsupported status'    => [ [ 'source' => '/a', 'target' => '/x', 'status' => 404 ] ],
			'no status'             => [ [ 'source' => '/a', 'target' => '/x' ] ],
			'no target on a 301'    => [ [ 'source' => '/a', 'status' => 301 ] ],
			'empty target on a 301' => [ [ 'source' => '/a', 'target' => '  ', 'status' => 301 ] ],
			'target not a string'   => [ [ 'source' => '/a', 'target' => [ '/x' ], 'status' => 301 ] ],
		];
	}

	public function test_an_over_long_target_is_dropped(): void {
		$this->seed(
			[
				[
					'source' => '/a',
					'target' => 'https://example.test/' . str_repeat( 'x', RedirectStore::MAX_TARGET_LENGTH ),
					'status' => 301,
				],
			]
		);

		$this->assertSame( [], $this->store->all() );
	}

	public function test_a_stored_option_that_is_not_an_array_reads_as_an_empty_table(): void {
		$this->options[ RedirectStore::OPTION ] = 'wrecked';

		$this->assertSame( [], $this->store->all() );
		$this->assertSame( 0, $this->store->count() );
	}

	public function test_a_gone_row_carries_no_target_however_it_was_stored(): void {
		$this->seed( [ $this->row( '/deleted', '/somewhere', RedirectStore::STATUS_GONE ) ] );

		$record = $this->store->find( '/deleted' );

		$this->assertNotNull( $record );
		$this->assertNull( $record['target'] );
		$this->assertSame( RedirectStore::STATUS_GONE, $record['status'] );
	}

	public function test_a_row_that_does_not_say_reports_the_query_string_as_forwarded(): void {
		// Defaulting to true is what keeps campaign and analytics arguments alive
		// across a redirect, and it is the default on both sides of the store.
		$this->seed( [ [ 'source' => '/a', 'target' => '/b', 'status' => 301 ] ] );

		$this->assertTrue( $this->store->find( '/a' )['forwardQuery'] );
	}

	public function test_a_record_carries_its_members_in_a_fixed_order(): void {
		$this->seed( [ [ 'status' => 301, 'forwardQuery' => false, 'target' => '/b', 'source' => '/a' ] ] );

		$this->assertSame( RedirectStore::RECORD_FIELDS, array_keys( (array) $this->store->find( '/a' ) ) );
	}

	public function test_find_answers_null_for_a_path_that_cannot_be_a_source(): void {
		$this->seed( [ $this->row( '/a', '/b' ) ] );

		$this->assertNull( $this->store->find( 'https://elsewhere.test/a' ) );
		$this->assertNull( $this->store->find( '/' ) );
	}

	public function test_replace_stores_a_list_under_one_autoloaded_option(): void {
		$this->assertTrue( $this->store->replace( [ '/b' => $this->row( '/b', '/2' ), '/a' => $this->row( '/a', '/1' ) ] ) );

		$this->assertCount( 1, $this->writes );
		$this->assertSame( RedirectStore::OPTION, $this->writes[0]['name'] );
		$this->assertTrue( $this->writes[0]['autoload'], 'The table must be autoloaded so the router needs no query.' );

		$value = $this->stored();

		$this->assertIsArray( $value );
		$this->assertTrue( array_is_list( $value ), 'The table is stored as a list; all() re-derives the keys.' );
		$this->assertSame( [ '/a', '/b' ], array_column( $value, 'source' ) );
	}

	public function test_replace_drops_a_row_it_cannot_store(): void {
		$this->assertTrue( $this->store->replace( [ 'junk', $this->row( '/a', '/1' ) ] ) );

		$this->assertSame( [ '/a' ], array_keys( $this->store->all() ) );
	}

	public function test_storing_the_table_that_is_already_stored_succeeds(): void {
		// update_option() answers false both when the write failed and when the
		// value was already identical, and the second is the ORDINARY result of
		// re-applying an idempotent redirect write. Reporting it as a failure
		// would make every second identical apply look like a broken database.
		$table = [ '/a' => $this->row( '/a', '/1' ) ];

		$this->assertTrue( $this->store->replace( $table ) );
		$this->assertTrue( $this->store->replace( $table ) );
		$this->assertCount( 2, $this->writes, 'Both applies must actually reach the option.' );
	}

	public function test_replace_reports_a_write_that_did_not_take(): void {
		$this->storePersists = false;

		$this->assertFalse( $this->store->replace( [ '/a' => $this->row( '/a', '/1' ) ] ) );
	}

	public function test_an_empty_table_removes_the_option_rather_than_storing_nothing(): void {
		// Otherwise removing a site's last redirect would leave a permanent
		// autoloaded artefact behind as the trace of a feature no longer in use.
		$this->seed( [ $this->row( '/a', '/1' ) ] );

		$this->assertTrue( $this->store->replace( [] ) );
		$this->assertNull( $this->stored() );
		$this->assertSame( [], $this->store->all() );
	}

	public function test_emptying_a_table_that_is_already_empty_succeeds(): void {
		$this->assertTrue( $this->store->replace( [] ) );
	}

	public function test_count_counts_the_rows_that_can_actually_be_served(): void {
		$this->seed(
			[
				$this->row( '/a', '/1' ),
				'junk',
				[ 'source' => '/b', 'status' => 301 ],
			]
		);

		$this->assertSame( 1, $this->store->count() );
	}
}
