<?php
/**
 * Tests for Retention.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\Retention;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests the retention window, pruning fan-out, and cron scheduling.
 */
final class RetentionTest extends TestCase {

	private FakeWpdb $wpdb;
	private Retention $retention;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->retention = new Retention( new PlanStore(), new AuditStore(), new SnapshotStore() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_days_falls_back_to_the_default_and_clamps(): void {
		Functions\when( 'get_option' )->justReturn( 'forever' );
		$this->assertSame( Retention::DEFAULT_DAYS, $this->retention->days() );

		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertSame( Retention::MIN_DAYS, $this->retention->days() );

		Functions\when( 'get_option' )->justReturn( 100_000 );
		$this->assertSame( Retention::MAX_DAYS, $this->retention->days() );
	}

	public function test_prune_uses_the_retention_cutoff_for_audit_and_snapshots(): void {
		Functions\when( 'get_option' )->justReturn( 30 );
		$this->wpdb->queryRowsQueue = [ 1, 2, 3 ];

		$counts = $this->retention->prune( 1_800_000_000 );

		$this->assertSame(
			[
				'plans'     => 1,
				'audit'     => 2,
				'snapshots' => 3,
			],
			$counts
		);
		$this->assertSame( [ 1_800_000_000 - 30 * 86400 ], $this->wpdb->prepared[1]['args'] );
		$this->assertSame( [ 1_800_000_000 - 30 * 86400 ], $this->wpdb->prepared[2]['args'] );
	}

	public function test_schedule_registers_a_daily_event_only_once(): void {
		$scheduled = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $timestamp, string $recurrence, string $hook ) use ( &$scheduled ): bool {
				$scheduled[] = [ $recurrence, $hook ];

				return true;
			}
		);
		Functions\when( 'time' )->justReturn( 1_800_000_000 );

		Retention::schedule();

		$this->assertSame( [ [ 'daily', Retention::CRON_HOOK ] ], $scheduled );
	}

	public function test_schedule_does_nothing_when_already_scheduled(): void {
		$scheduled = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$scheduled ): bool {
				$scheduled[] = true;

				return true;
			}
		);

		Retention::schedule();

		$this->assertSame( [], $scheduled );
	}

	public function test_unschedule_clears_the_registered_event(): void {
		$cleared = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_unschedule_event' )->alias(
			static function ( int $timestamp, string $hook ) use ( &$cleared ): bool {
				$cleared[] = [ $timestamp, $hook ];

				return true;
			}
		);

		Retention::unschedule();

		$this->assertSame( [ [ 1_800_000_000, Retention::CRON_HOOK ] ], $cleared );
	}
}
