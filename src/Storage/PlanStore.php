<?php
/**
 * Storage for pending change plans.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Issues, resolves, and atomically consumes pending change plans.
 *
 * The plan token is a bearer credential, so only its SHA-256 digest is stored:
 * a disclosed backup, an unrelated injection, or a query log then yields nothing
 * usable, while lookup stays one indexed equality comparison.
 *
 * Single use is enforced by a conditional UPDATE accepted only when exactly one
 * row changed, so two concurrent applies of the same plan cannot both win
 * without needing an explicit transaction.
 *
 * @package SiteHelm
 */
final class PlanStore {

	public const TTL_OPTION  = 'sitehelm_plan_ttl';
	public const DEFAULT_TTL = 900;
	public const MIN_TTL     = 60;
	public const MAX_TTL     = 3600;

	/**
	 * Bytes of CSPRNG output per token. 32 bytes render as 64 hex characters,
	 * comfortably above the ChangePlan value object's 32-character floor.
	 */
	private const TOKEN_BYTES = 32;

	/**
	 * Expired rows deleted per opportunistic prune, so a site whose cron never
	 * runs still cannot accumulate plans without bound.
	 */
	private const PRUNE_LIMIT = 50;

	/**
	 * Grace period before an expired plan row is deleted. Expired and unknown
	 * tokens both answer `stale_plan`, so the grace exists only to keep a
	 * recently expired plan inspectable.
	 */
	private const GRACE_SECONDS = 86400;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * A fresh opaque plan token.
	 *
	 * The CSPRNG is random_bytes; wp_generate_password and wp_rand are not used
	 * because neither guarantees cryptographic strength on every host.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 */
	public static function issueToken(): string {
		return bin2hex( random_bytes( self::TOKEN_BYTES ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The stored form of a plan token.
	 *
	 * @param string $token The raw token as issued to the client.
	 *
	 * @return string The digest stored server-side.
	 */
	public static function digest( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * The configured plan lifetime in seconds, clamped to the supported window.
	 *
	 * @return int Seconds a plan token remains valid.
	 */
	public function ttl(): int {
		$stored = get_option( self::TTL_OPTION, self::DEFAULT_TTL );
		$ttl    = is_numeric( $stored ) ? (int) $stored : self::DEFAULT_TTL;

		return max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
	}

	/**
	 * Persists one pending plan and prunes expired rows on the way through.
	 *
	 * @param array<string, mixed> $row The plan row to store.
	 *
	 * @return bool True when the row was written.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 */
	public function store( array $row ): bool {
		global $wpdb;

		$this->pruneExpired( (int) $row['created_at'] );

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_PLANS ),
			[
				'token_hash'        => (string) $row['token_hash'],
				'site_id'           => (string) $row['site_id'],
				'user_id'           => (int) $row['user_id'],
				'operation_id'      => (string) $row['operation_id'],
				'schema_version'    => (int) $row['schema_version'],
				'target_key'        => (string) $row['target_key'],
				'payload_hash'      => (string) $row['payload_hash'],
				'state_fingerprint' => (string) $row['state_fingerprint'],
				'plan_body'         => (string) $row['plan_body'],
				'created_at'        => (int) $row['created_at'],
				'expires_at'        => (int) $row['expires_at'],
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		return false !== $inserted;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * Resolves one plan row by token digest.
	 *
	 * The literal 'ARRAY_A' is passed rather than the WordPress constant so the
	 * store is unit-testable without loading WordPress; the constant's value is
	 * exactly this string.
	 *
	 * @param string $tokenDigest The stored digest of the client's token.
	 *
	 * @return array<string, mixed>|null The plan row, or null when unknown.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function find( string $tokenDigest ): ?array {
		global $wpdb;

		$table = Installer::tableName( Installer::TABLE_PLANS );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, token_hash, site_id, user_id, operation_id, schema_version, target_key, payload_hash, state_fingerprint, plan_body, created_at, expires_at, consumed_at FROM {$table} WHERE token_hash = %s LIMIT 1",
				$tokenDigest
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Claims one plan for single use.
	 *
	 * Exactly one row must be affected. The UNIQUE index on token_hash bounds
	 * the match to at most one row, and `consumed_at IS NULL` is re-evaluated
	 * under InnoDB's row lock, so of two concurrent applies presenting the same
	 * token the winner sees 1 and the loser sees 0. Reading first and writing
	 * second would race; this does not.
	 *
	 * The expiry condition is defence in depth. PlanAdmission::findValidPlan()
	 * already refuses an expired plan with the shared stale_plan exception
	 * before it reaches this method, and does so at least as strictly (it compares
	 * `expires_at <= requestTime` against the same clock). Repeating the check
	 * here keeps a stale row that outlived the prune grace from binding for a
	 * direct caller that forgot it. This method still reports failure the same
	 * way for every cause, so nothing here lets a caller distinguish an expired
	 * plan from an unknown or already-claimed one.
	 *
	 * @param string $tokenDigest The stored digest of the client's token.
	 * @param int    $now         The server-side request time.
	 *
	 * @return bool True when this call is the one that claimed the plan.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function consume( string $tokenDigest, int $now ): bool {
		global $wpdb;

		$table    = Installer::tableName( Installer::TABLE_PLANS );
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %d WHERE token_hash = %s AND consumed_at IS NULL AND expires_at >= %d",
				$now,
				$tokenDigest,
				$now
			)
		);

		// A failed query leaves rows_affected holding whatever the previous
		// statement set, so the count is only meaningful once the query itself
		// is known to have run.
		return false !== $affected && 1 === $wpdb->rows_affected;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Deletes a bounded batch of plan rows past their grace period.
	 *
	 * @param int $now The server-side request time.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function pruneExpired( int $now ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_PLANS );
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %d ORDER BY expires_at LIMIT %d",
				$now - self::GRACE_SECONDS,
				self::PRUNE_LIMIT
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
