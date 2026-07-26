<?php
/**
 * Storage for rollback snapshots.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Captures and resolves the minimum local state required to reverse a write.
 *
 * Snapshots never leave the site: nothing here transmits, exports, or remotely
 * stores a row. Rollback references are random rather than sequential, so
 * possessing one discloses no row count, and possession alone is never
 * authority — the change engine re-checks the original operation's capability
 * and the module's compatibility before restoring.
 *
 * @package SiteHelm
 */
final class SnapshotStore {

	/**
	 * Prefix marking a value as a rollback reference in client-facing output.
	 */
	private const REF_PREFIX = 'rb-';

	/**
	 * Bytes of CSPRNG output per reference; 12 bytes render as 24 hex characters.
	 */
	private const REF_BYTES = 12;

	/**
	 * The columns a snapshot read returns.
	 */
	private const READ_COLUMNS = 'id, rollback_ref, site_id, user_id, operation_id, module_id, target_key, restore_state, module_versions, created_at, restored_at';

	/**
	 * Records one snapshot and mints its rollback reference.
	 *
	 * @param array<string, mixed> $row The snapshot row to store.
	 *
	 * @return array<string, mixed>|null Keys 'id' and 'reference', or null on failure.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 */
	public function capture( array $row ): ?array {
		global $wpdb;

		$reference = self::REF_PREFIX . bin2hex( random_bytes( self::REF_BYTES ) );

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			[
				'rollback_ref'    => $reference,
				'site_id'         => (string) $row['site_id'],
				'user_id'         => (int) $row['user_id'],
				'operation_id'    => (string) $row['operation_id'],
				'module_id'       => (string) $row['module_id'],
				'target_key'      => (string) $row['target_key'],
				'restore_state'   => (string) $row['restore_state'],
				'module_versions' => (string) $row['module_versions'],
				'created_at'      => (int) $row['created_at'],
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return null;
		}

		return [
			'id'        => (int) $wpdb->insert_id,
			'reference' => $reference,
		];
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * Resolves one snapshot by its rollback reference.
	 *
	 * @param string $rollbackRef The reference offered on a previous result.
	 *
	 * @return array<string, mixed>|null The snapshot row, or null when unknown.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function findByRef( string $rollbackRef ): ?array {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_SNAPSHOTS );
		$columns = self::READ_COLUMNS;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE rollback_ref = %s LIMIT 1",
				$rollbackRef
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Stamps a snapshot as restored, so an operator can see it was used.
	 *
	 * @param int $id  The snapshot row identifier.
	 * @param int $now The server-side request time.
	 *
	 * @return bool True when the row was updated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function markRestored( int $id, int $now ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			[ 'restored_at' => $now ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $updated;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Deletes snapshots older than the cutoff.
	 *
	 * @param int $before Rows created strictly before this instant are removed.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function prune( int $before ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_SNAPSHOTS );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", $before ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
