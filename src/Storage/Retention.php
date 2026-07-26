<?php
/**
 * Retention window and scheduled pruning.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Resolves the configured retention window and prunes the three owned tables.
 *
 * Audit events and snapshots share one window, as the design requires:
 * snapshots inherit the configured retention period. Plan rows are governed by
 * their own short expiry instead, because a plan is not a record of anything —
 * it is a pending intent.
 *
 * @package SiteHelm
 */
final class Retention {

	public const RETENTION_OPTION = 'sitehelm_retention_days';
	public const DEFAULT_DAYS     = 30;
	public const MIN_DAYS         = 1;
	public const MAX_DAYS         = 365;
	public const CRON_HOOK        = 'sitehelm_prune_records';

	/**
	 * Seconds in one retention day.
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Constructs the pruner over the three owned stores.
	 *
	 * @param PlanStore     $plans     The pending-plan store.
	 * @param AuditStore    $audit     The audit event store.
	 * @param SnapshotStore $snapshots The rollback snapshot store.
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly AuditStore $audit,
		private readonly SnapshotStore $snapshots,
	) {
	}

	/**
	 * The configured retention window in days, clamped to the supported range.
	 *
	 * @return int Days of retention.
	 */
	public function days(): int {
		$stored = get_option( self::RETENTION_OPTION, self::DEFAULT_DAYS );
		$days   = is_numeric( $stored ) ? (int) $stored : self::DEFAULT_DAYS;

		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Prunes every owned table.
	 *
	 * @param int $now The current server-side time.
	 *
	 * @return array<string, int> Rows deleted per table.
	 */
	public function prune( int $now ): array {
		$cutoff = $now - ( $this->days() * self::SECONDS_PER_DAY );

		return [
			'plans'     => $this->plans->pruneExpired( $now ),
			'audit'     => $this->audit->prune( $cutoff ),
			'snapshots' => $this->snapshots->prune( $cutoff ),
		];
	}

	/**
	 * Registers the daily pruning event if it is not already registered.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::SECONDS_PER_DAY, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clears the daily pruning event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( is_int( $timestamp ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
