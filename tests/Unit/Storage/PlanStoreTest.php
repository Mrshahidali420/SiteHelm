<?php
/**
 * Tests for PlanStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests token generation, digest-only storage, atomic single use, and pruning.
 */
final class PlanStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private PlanStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new PlanStore();
		Functions\when( 'get_option' )->justReturn( PlanStore::DEFAULT_TTL );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete plan row.
	 */
	private function row( string $digest ): array {
		return [
			'token_hash'        => $digest,
			'site_id'           => 'example.com',
			'user_id'           => 7,
			'operation_id'      => 'content-update',
			'schema_version'    => 1,
			'target_key'        => 'post:42',
			'payload_hash'      => str_repeat( 'a', 64 ),
			'state_fingerprint' => str_repeat( 'b', 64 ),
			'plan_body'         => '{"human":"x","machine":{}}',
			'created_at'        => 1_800_000_000,
			'expires_at'        => 1_800_000_900,
		];
	}

	public function test_issued_tokens_are_64_hex_characters_and_unique(): void {
		$first  = PlanStore::issueToken();
		$second = PlanStore::issueToken();

		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $first ) );
		$this->assertNotSame( $first, $second );
	}

	public function test_digest_is_sha256_and_the_raw_token_is_never_stored(): void {
		$token  = PlanStore::issueToken();
		$digest = PlanStore::digest( $token );
		$this->assertSame( hash( 'sha256', $token ), $digest );

		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->assertTrue( $this->store->store( $this->row( $digest ) ) );

		$stored = $this->wpdb->inserts[0]['data'];
		$this->assertSame( $digest, $stored['token_hash'] );
		foreach ( $stored as $value ) {
			$this->assertNotSame( $token, $value );
		}
	}

	public function test_store_writes_to_the_prefixed_plans_table(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];

		$this->store->store( $this->row( str_repeat( 'c', 64 ) ) );

		$this->assertSame(
			Installer::tableName( Installer::TABLE_PLANS ),
			$this->wpdb->inserts[0]['table']
		);
	}

	public function test_store_returns_false_when_the_insert_is_refused(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->wpdb->failInsert     = true;

		$this->assertFalse( $this->store->store( $this->row( str_repeat( 'c', 64 ) ) ) );
	}

	public function test_store_opportunistically_prunes_expired_rows(): void {
		$this->wpdb->queryRowsQueue = [ 4 ];

		$this->store->store( $this->row( str_repeat( 'c', 64 ) ) );

		$this->assertStringContainsString( 'DELETE FROM', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'expires_at <', $this->wpdb->queries[0] );
	}

	public function test_consume_succeeds_once_then_refuses_the_replay(): void {
		$digest                     = str_repeat( 'd', 64 );
		$this->wpdb->queryRowsQueue = [ 1, 0 ];

		$this->assertTrue( $this->store->consume( $digest, 1_800_000_100 ) );
		$this->assertFalse( $this->store->consume( $digest, 1_800_000_200 ) );
	}

	public function test_consume_binds_the_digest_and_requires_an_unconsumed_row(): void {
		$digest                     = str_repeat( 'e', 64 );
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->store->consume( $digest, 1_800_000_100 );

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'consumed_at IS NULL', $prepared['query'] );
		$this->assertSame( [ 1_800_000_100, $digest, 1_800_000_100 ], $prepared['args'] );
	}

	/**
	 * Single use means exactly one row, not at least one.
	 *
	 * Without this, weakening the check to `0 < $wpdb->rows_affected` passes the
	 * whole suite: the replay test only ever supplies 1 then 0, and both
	 * comparisons agree on those two values. A count above 1 means the WHERE
	 * clause matched more rows than the unique index should allow, which is a
	 * corrupted index or a rewritten query — either way the claim is not
	 * exclusive and must not be reported as success.
	 */
	public function test_consume_refuses_when_more_than_one_row_was_affected(): void {
		$this->wpdb->queryRowsQueue = [ 2 ];

		$this->assertFalse( $this->store->consume( str_repeat( 'e', 64 ), 1_800_000_100 ) );
	}

	/**
	 * A failed query must not be read as a successful claim.
	 *
	 * rows_affected still holds the previous statement's count when wpdb returns
	 * false before flushing, so a caller that trusts the count alone claims a
	 * plan that was never marked consumed. The first consume here sets the count
	 * to 1; the second query fails, and the stale 1 must not be believed.
	 */
	public function test_consume_refuses_when_the_query_itself_fails(): void {
		$digest                     = str_repeat( 'e', 64 );
		$this->wpdb->queryRowsQueue = [ 1, false ];

		$this->assertTrue( $this->store->consume( $digest, 1_800_000_100 ) );
		$this->assertSame( 1, $this->wpdb->rows_affected );
		$this->assertFalse( $this->store->consume( $digest, 1_800_000_200 ) );
	}

	/**
	 * Expiry is re-checked in the claim itself, as defence in depth.
	 *
	 * ChangeEngine::apply() refuses an expired plan before reaching this method
	 * and does so at least as strictly, so this can never reject a plan the
	 * engine accepted. It exists so a row that outlived the prune grace cannot
	 * bind for a direct caller that forgot the check.
	 */
	public function test_consume_requires_the_plan_to_be_unexpired(): void {
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->store->consume( str_repeat( 'e', 64 ), 1_800_000_100 );

		$this->assertStringContainsString( 'expires_at >= %d', $this->wpdb->prepared[0]['query'] );
	}

	public function test_find_returns_null_when_no_row_matches(): void {
		$this->assertNull( $this->store->find( str_repeat( 'f', 64 ) ) );
	}

	/**
	 * The digest is matched by equality, and the query is the only place that
	 * can be asserted.
	 *
	 * FakeWpdb replays a queued row regardless of the SQL it is handed, so a
	 * test that only checks the returned row passes even if the comparison is
	 * inverted to `!=` — which would hand the caller somebody else's plan. The
	 * assertion therefore has to be on the prepared statement itself.
	 */
	public function test_find_binds_the_digest_with_an_equality_comparison(): void {
		$digest = str_repeat( 'f', 64 );

		$this->store->find( $digest );

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'WHERE token_hash = %s', $prepared['query'] );
		$this->assertStringNotContainsString( '!=', $prepared['query'] );
		$this->assertSame( [ $digest ], $prepared['args'] );
	}

	/**
	 * A bounded DELETE needs a deterministic order.
	 *
	 * `DELETE ... LIMIT` without `ORDER BY` is unsafe for statement-based
	 * replication and warns on hosts running binlog_format=STATEMENT. Ordering
	 * by expires_at also makes the bounded prune remove the longest-expired
	 * rows first, which is the useful order.
	 */
	public function test_prune_orders_before_limiting(): void {
		$this->wpdb->queryRowsQueue = [ 3 ];

		$this->store->pruneExpired( 1_800_000_100 );

		$this->assertStringContainsString( 'ORDER BY expires_at LIMIT', $this->wpdb->prepared[0]['query'] );
	}

	public function test_find_returns_the_matching_row(): void {
		$expected              = $this->row( str_repeat( 'f', 64 ) );
		$expected['id']        = 3;
		$this->wpdb->rowQueue  = [ $expected ];

		$this->assertSame( $expected, $this->store->find( str_repeat( 'f', 64 ) ) );
	}

	public function test_ttl_falls_back_to_the_default_for_a_non_numeric_option(): void {
		Functions\when( 'get_option' )->justReturn( 'soon' );

		$this->assertSame( PlanStore::DEFAULT_TTL, $this->store->ttl() );
	}

	public function test_ttl_is_clamped_to_the_supported_window(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		$this->assertSame( PlanStore::MIN_TTL, $this->store->ttl() );

		Functions\when( 'get_option' )->justReturn( 99_999 );
		$this->assertSame( PlanStore::MAX_TTL, $this->store->ttl() );
	}
}
