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
		$this->assertSame( [ 1_800_000_100, $digest ], $prepared['args'] );
	}

	public function test_find_returns_null_when_no_row_matches(): void {
		$this->assertNull( $this->store->find( str_repeat( 'f', 64 ) ) );
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
