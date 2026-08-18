<?php
/**
 * Tests for AuditStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests audit insertion, finalization, filtered reads, and pruning.
 */
final class AuditStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new AuditStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete audit row.
	 */
	private function row(): array {
		return [
			'correlation_id'   => 'corr-1',
			'site_id'          => 'example.com',
			'actor_id'         => 7,
			'actor_login'      => 'operator',
			'client_id'        => 'demo-client',
			'operation_id'     => 'content-update',
			'target_key'       => 'post:42',
			'plan_fingerprint' => str_repeat( 'b', 64 ),
			'outcome'          => 'started',
			'summary'          => '{}',
			'snapshot_id'      => 9,
			'rollback_ref'     => 'rb-0123456789abcdef01234567',
			'recorded_at'      => 1_800_000_000,
		];
	}

	/**
	 * Inserts TWICE deliberately. Asserting only that the first insert returns 1
	 * cannot distinguish the correct implementation from the wrong one: real
	 * $wpdb::insert() returns a ROW COUNT, so `return (int) $inserted;` also
	 * yields 1 and the test would pass. With that bug every write would receive
	 * auditRef 'audit-1' and AuditRecorder::finish() would overwrite audit row 1
	 * forever, destroying REQ-0009's accountability guarantee while the suite
	 * stayed green. Only reading $wpdb->insert_id produces 2 on the second call.
	 */
	public function test_insert_returns_the_new_row_id_not_the_affected_row_count(): void {
		$this->assertSame( 1, $this->store->insert( $this->row() ) );
		$this->assertSame( 2, $this->store->insert( $this->row() ) );
		$this->assertSame(
			Installer::tableName( Installer::TABLE_AUDIT ),
			$this->wpdb->inserts[0]['table']
		);
	}

	public function test_insert_returns_zero_when_refused(): void {
		$this->wpdb->failInsert = true;

		$this->assertSame( 0, $this->store->insert( $this->row() ) );
	}

	/**
	 * The recovery handle must be on the row from the moment it exists, so a
	 * fatal inside the write cannot strand a captured snapshot that no audit row
	 * references. See D6.
	 */
	public function test_insert_stores_the_snapshot_handle_on_the_opening_row(): void {
		$this->store->insert( $this->row() );

		$data = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 9, $data['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $data['rollback_ref'] );
	}

	public function test_insert_accepts_an_absent_snapshot_handle(): void {
		$row                 = $this->row();
		$row['snapshot_id']  = null;
		$row['rollback_ref'] = null;

		$this->assertSame( 1, $this->store->insert( $row ) );
		$this->assertNull( $this->wpdb->inserts[0]['data']['snapshot_id'] );
		$this->assertNull( $this->wpdb->inserts[0]['data']['rollback_ref'] );
	}

	public function test_finish_updates_outcome_snapshot_reference_target_and_summary(): void {
		$this->assertTrue(
			$this->store->finish( 3, 'applied', 9, 'rb-0123456789abcdef01234567', 'post:77', '{"changed":["post_title"]}' )
		);

		$update = $this->wpdb->updates[0];
		$this->assertSame( [ 'id' => 3 ], $update['where'] );
		$this->assertSame( 'applied', $update['data']['outcome'] );
		$this->assertSame( 9, $update['data']['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $update['data']['rollback_ref'] );
		$this->assertSame( 'post:77', $update['data']['target_key'] );
		$this->assertSame( '{"changed":["post_title"]}', $update['data']['summary'] );
	}

	public function test_finish_stores_a_measured_duration(): void {
		$this->store->finish( 3, 'applied', null, null, 'post:77', '{}', 412 );

		$this->assertSame( 412, $this->wpdb->updates[0]['data']['duration_ms'] );
	}

	/**
	 * A null duration means "not measured", and writing it back would erase a
	 * real measurement from an earlier finalization of the same row.
	 */
	public function test_finish_leaves_the_duration_alone_when_none_was_measured(): void {
		$this->store->finish( 3, 'applied', null, null, 'post:77', '{}' );

		$this->assertArrayNotHasKey( 'duration_ms', $this->wpdb->updates[0]['data'] );
	}

	/**
	 * A clock that moved backwards cannot describe an elapsed time, and the
	 * console would render the negative number as if it were one.
	 */
	public function test_finish_refuses_a_negative_duration(): void {
		$this->store->finish( 3, 'applied', null, null, 'post:77', '{}', -5 );

		$this->assertArrayNotHasKey( 'duration_ms', $this->wpdb->updates[0]['data'] );
	}

	public function test_query_can_be_narrowed_to_one_outcome(): void {
		$this->wpdb->resultQueue = [ [] ];

		$this->store->query( [ 'outcome' => 'restore-failed' ], 10, 0 );

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'outcome = %s', $prepared['query'] );
		$this->assertContains( 'restore-failed', $prepared['args'] );
	}

	public function test_finish_returns_false_when_refused(): void {
		$this->wpdb->failUpdate = true;

		$this->assertFalse( $this->store->finish( 3, 'applied', null, null, 'post:77', '{}' ) );
	}

	public function test_query_without_filters_orders_newest_first_and_clamps_the_limit(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];

		$rows = $this->store->query( [], 5000, -3 );

		$this->assertCount( 1, $rows );
		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'ORDER BY recorded_at DESC, id DESC', $prepared['query'] );
		$this->assertStringContainsString( 'WHERE 1=1', $prepared['query'] );
		$this->assertSame( [ AuditStore::MAX_LIMIT, 0 ], $prepared['args'] );
	}

	public function test_query_builds_only_whitelisted_filter_clauses(): void {
		$this->wpdb->resultQueue = [ [] ];

		$this->store->query(
			[
				'operationId'   => 'content-update',
				'correlationId' => 'corr-1',
				'actorId'       => '7',
				'since'         => 1_700_000_000,
				'until'         => 1_900_000_000,
				'DROP TABLE'    => 'x',
			],
			10,
			0
		);

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString(
			'operation_id = %s AND correlation_id = %s AND actor_id = %d AND recorded_at >= %d AND recorded_at <= %d',
			$prepared['query']
		);
		$this->assertStringNotContainsString( 'DROP TABLE', $prepared['query'] );
		$this->assertSame(
			[ 'content-update', 'corr-1', 7, 1_700_000_000, 1_900_000_000, 10, 0 ],
			$prepared['args']
		);
	}

	public function test_count_without_filters_skips_prepare_entirely(): void {
		$this->wpdb->varQueue = [ '12' ];

		$this->assertSame( 12, $this->store->count( [] ) );
		$this->assertSame( [], $this->wpdb->prepared );
	}

	public function test_count_with_filters_binds_them(): void {
		$this->wpdb->varQueue = [ 4 ];

		$this->assertSame( 4, $this->store->count( [ 'actorId' => 7 ] ) );
		$this->assertSame( [ 7 ], $this->wpdb->prepared[0]['args'] );
	}

	public function test_prune_deletes_rows_older_than_the_cutoff(): void {
		$this->wpdb->queryRowsQueue = [ 6 ];

		$this->assertSame( 6, $this->store->prune( 1_700_000_000 ) );
		$this->assertStringContainsString( 'recorded_at < %d', $this->wpdb->prepared[0]['query'] );
		$this->assertSame( [ 1_700_000_000 ], $this->wpdb->prepared[0]['args'] );
	}
}
