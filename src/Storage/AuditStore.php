<?php
/**
 * Storage for audit events.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Reads and writes audit events.
 *
 * Filter column names come only from the hardcoded FILTERS map below, never
 * from request data, and every value is bound through $wpdb->prepare. A client
 * cannot influence the SQL structure even by inventing filter keys: unknown
 * keys are simply absent from the map and are ignored.
 *
 * @package SiteHelm
 */
final class AuditStore {

	/**
	 * The largest page an audit read may request.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Equality filters: request key to column and placeholder.
	 */
	private const FILTERS = [
		'operationId'   => [
			'column'      => 'operation_id',
			'placeholder' => '%s',
		],
		'correlationId' => [
			'column'      => 'correlation_id',
			'placeholder' => '%s',
		],
		'actorId'       => [
			'column'      => 'actor_id',
			'placeholder' => '%d',
		],
		'outcome'       => [
			'column'      => 'outcome',
			'placeholder' => '%s',
		],
	];

	/**
	 * The columns an audit read returns.
	 */
	private const READ_COLUMNS = 'id, correlation_id, actor_id, actor_login, client_id, operation_id, target_key, plan_fingerprint, outcome, summary, snapshot_id, rollback_ref, recorded_at, duration_ms';

	/**
	 * Writes one audit event.
	 *
	 * @param array<string, mixed> $row The audit row to store.
	 *
	 * @return int The new row identifier, or 0 when the write was refused.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$snapshot_id  = isset( $row['snapshot_id'] ) ? (int) $row['snapshot_id'] : null;
		$rollback_ref = isset( $row['rollback_ref'] ) ? (string) $row['rollback_ref'] : null;

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_AUDIT ),
			[
				'correlation_id'   => (string) $row['correlation_id'],
				'site_id'          => (string) $row['site_id'],
				'actor_id'         => (int) $row['actor_id'],
				'actor_login'      => (string) $row['actor_login'],
				'client_id'        => (string) $row['client_id'],
				'operation_id'     => (string) $row['operation_id'],
				'target_key'       => (string) $row['target_key'],
				'plan_fingerprint' => (string) $row['plan_fingerprint'],
				'outcome'          => (string) $row['outcome'],
				'summary'          => (string) $row['summary'],
				'snapshot_id'      => $snapshot_id,
				'rollback_ref'     => $rollback_ref,
				'recorded_at'      => (int) $row['recorded_at'],
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		// $wpdb::insert() returns the number of AFFECTED ROWS, never an
		// identifier. The new row id lives on $wpdb->insert_id, and returning the
		// affected-row count instead would hand every write the reference
		// 'audit-1' and make finish() overwrite one row forever.
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * Finalizes one audit event after execution.
	 *
	 * @param int         $id          The audit row identifier.
	 * @param string      $outcome     The final outcome.
	 * @param int|null    $snapshotId  The captured snapshot row, when there is one.
	 * @param string|null $rollbackRef The rollback reference, when one is offered.
	 *                                 Stored here as well as on the snapshot so an
	 *                                 audit read can hand a recovery handle straight
	 *                                 to rollback-apply without a join.
	 * @param string      $targetKey   The concrete target key, which a creation
	 *                                 only learns after execution.
	 * @param string      $summary     The redacted change summary as JSON.
	 * @param int|null    $durationMs  Milliseconds from the opening row to this
	 *                                 finalization, when the caller timed it.
	 *
	 * @return bool True when the row was updated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function finish(
		int $id,
		string $outcome,
		?int $snapshotId,
		?string $rollbackRef,
		string $targetKey,
		string $summary,
		?int $durationMs = null
	): bool {
		global $wpdb;

		$data    = [
			'outcome'    => $outcome,
			'target_key' => $targetKey,
			'summary'    => $summary,
		];
		$formats = [ '%s', '%s', '%s' ];

		// A null recovery handle here means "leave it alone", never "clear it".
		// start() already wrote the snapshot id and rollback reference onto the
		// opening row, which is what makes interpretation I4's guarantee
		// unconditional rather than true only on the happy path. Writing nulls
		// back would let a caller finalizing a failed write orphan a snapshot
		// that really exists — precisely the state the opening-row write was
		// introduced to prevent.
		if ( null !== $snapshotId ) {
			$data['snapshot_id'] = $snapshotId;
			$formats[]           = '%d';
		}
		if ( null !== $rollbackRef ) {
			$data['rollback_ref'] = $rollbackRef;
			$formats[]            = '%s';
		}

		// A negative elapsed time cannot be true and would render as a negative
		// duration in the console, so it is dropped rather than stored.
		if ( null !== $durationMs && $durationMs >= 0 ) {
			$data['duration_ms'] = $durationMs;
			$formats[]           = '%d';
		}

		$updated = $wpdb->update(
			Installer::tableName( Installer::TABLE_AUDIT ),
			$data,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		// id is the primary key, so a successful finalization always touches
		// exactly one row. Accepting 0 would report success for an audit row
		// that does not exist — including the id 0 a refused start() returns.
		return 1 === $updated;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Reads one page of audit events, newest first.
	 *
	 * @param array<string, mixed> $filters Accepted keys only; others ignored.
	 * @param int                  $limit   Page size, clamped to MAX_LIMIT.
	 * @param int                  $offset  Rows to skip, floored at zero.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	 */
	public function query( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$table                  = Installer::tableName( Installer::TABLE_AUDIT );
		$columns                = self::READ_COLUMNS;
		list( $where, $values ) = $this->where_clause( $filters );

		$values[] = max( 1, min( self::MAX_LIMIT, $limit ) );
		$values[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY recorded_at DESC, id DESC LIMIT %d OFFSET %d",
				$values
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? $rows : [];
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	/**
	 * Counts the audit events matching the filters.
	 *
	 * @param array<string, mixed> $filters Accepted keys only; others ignored.
	 *
	 * @return int The total row count.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	 */
	public function count( array $filters ): int {
		global $wpdb;

		$table                  = Installer::tableName( Installer::TABLE_AUDIT );
		list( $where, $values ) = $this->where_clause( $filters );
		$sql                    = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		// prepare() with no values emits a WordPress notice, so it is skipped.
		$total = [] === $values ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		return is_numeric( $total ) ? (int) $total : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	/**
	 * Deletes audit events older than the cutoff.
	 *
	 * @param int $before Rows recorded strictly before this instant are removed.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function prune( int $before ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_AUDIT );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %d", $before ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Builds the WHERE clause and its bound values from accepted filters only.
	 *
	 * @param array<string, mixed> $filters The requested filters.
	 *
	 * @return array<int, mixed> The clause string and the ordered values.
	 */
	private function where_clause( array $filters ): array {
		$clauses = [];
		$values  = [];

		foreach ( self::FILTERS as $key => $spec ) {
			if ( ! isset( $filters[ $key ] ) ) {
				continue;
			}
			$clauses[] = $spec['column'] . ' = ' . $spec['placeholder'];
			$values[]  = '%d' === $spec['placeholder']
				? (int) $filters[ $key ]
				: (string) $filters[ $key ];
		}
		if ( isset( $filters['since'] ) ) {
			$clauses[] = 'recorded_at >= %d';
			$values[]  = (int) $filters['since'];
		}
		if ( isset( $filters['until'] ) ) {
			$clauses[] = 'recorded_at <= %d';
			$values[]  = (int) $filters['until'];
		}

		return [ [] === $clauses ? '1=1' : implode( ' AND ', $clauses ), $values ];
	}
}
