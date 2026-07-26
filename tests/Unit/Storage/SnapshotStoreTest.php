<?php
/**
 * Tests for SnapshotStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests snapshot capture, reference generation, lookup, and pruning.
 */
final class SnapshotStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private SnapshotStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new SnapshotStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete snapshot row.
	 */
	private function row(): array {
		return [
			'site_id'         => 'example.com',
			'user_id'         => 7,
			'operation_id'    => 'content-update',
			'module_id'       => 'core',
			'target_key'      => 'post:42',
			'restore_state'   => '{"post_title":"Original title"}',
			'module_versions' => '{"core":"6.8.1"}',
			'created_at'      => 1_800_000_000,
		];
	}

	public function test_capture_returns_an_id_and_a_non_guessable_reference(): void {
		$captured = $this->store->capture( $this->row() );

		$this->assertSame( 1, $captured['id'] );
		$this->assertSame( 1, preg_match( '/^rb-[0-9a-f]{24}$/', $captured['reference'] ) );
		$this->assertSame(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			$this->wpdb->inserts[0]['table']
		);
		$this->assertSame( $captured['reference'], $this->wpdb->inserts[0]['data']['rollback_ref'] );
	}

	public function test_two_captures_never_share_a_reference(): void {
		$first  = $this->store->capture( $this->row() );
		$second = $this->store->capture( $this->row() );

		$this->assertNotSame( $first['reference'], $second['reference'] );
	}

	public function test_capture_returns_null_when_refused(): void {
		$this->wpdb->failInsert = true;

		$this->assertNull( $this->store->capture( $this->row() ) );
	}

	/**
	 * The reference is matched by equality, and the query is the only place
	 * that can be asserted.
	 *
	 * FakeWpdb replays a queued row regardless of the SQL it is handed, so a
	 * test that only checks the returned row and the bound argument still
	 * passes even if the WHERE clause targets the wrong column entirely
	 * (e.g. site_id instead of rollback_ref) as long as the same argument is
	 * bound. The assertion therefore has to include the prepared query text.
	 */
	public function test_find_by_ref_binds_the_reference_and_returns_the_row(): void {
		$expected             = $this->row();
		$expected['id']       = 5;
		$this->wpdb->rowQueue = [ $expected ];

		$this->assertSame( $expected, $this->store->findByRef( 'rb-abc' ) );
		$this->assertStringContainsString( 'WHERE rollback_ref = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertSame( [ 'rb-abc' ], $this->wpdb->prepared[0]['args'] );
	}

	public function test_find_by_ref_returns_null_for_an_unknown_reference(): void {
		$this->assertNull( $this->store->findByRef( 'rb-missing' ) );
	}

	public function test_mark_restored_stamps_the_row(): void {
		$this->assertTrue( $this->store->markRestored( 5, 1_800_000_500 ) );

		$this->assertSame( [ 'id' => 5 ], $this->wpdb->updates[0]['where'] );
		$this->assertSame( 1_800_000_500, $this->wpdb->updates[0]['data']['restored_at'] );
	}

	public function test_prune_deletes_rows_older_than_the_cutoff(): void {
		$this->wpdb->queryRowsQueue = [ 2 ];

		$this->assertSame( 2, $this->store->prune( 1_700_000_000 ) );
		$this->assertStringContainsString( 'created_at < %d', $this->wpdb->prepared[0]['query'] );
	}
}
