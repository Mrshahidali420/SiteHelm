<?php
/**
 * Schema installer for the three SiteHelm-owned tables.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Creates and upgrades the three local tables the change engine needs: pending
 * plans, audit events, and rollback snapshots. Ordinary settings live in the
 * options API and are not managed here.
 *
 * Failure is contained: when the tables cannot be created the installer records
 * an unavailable status and returns false. The gateway, registry, policy engine,
 * catalogs, and every operation that does not touch these tables keep working.
 *
 * @package SiteHelm
 */
final class Installer {

	/**
	 * Current schema version. Bump when a statement below changes; dbDelta then
	 * migrates additively in place on the next request.
	 */
	public const DB_VERSION = 1;

	public const DB_VERSION_OPTION  = 'sitehelm_db_version';
	public const STATUS_OPTION      = 'sitehelm_db_status';
	public const STATUS_READY       = 'ready';
	public const STATUS_UNAVAILABLE = 'unavailable';

	public const TABLE_PLANS     = 'plans';
	public const TABLE_AUDIT     = 'audit';
	public const TABLE_SNAPSHOTS = 'snapshots';

	/**
	 * The plugin's table-name segment, appended to $wpdb->prefix.
	 */
	private const TABLE_PREFIX = 'sitehelm_';

	/**
	 * Every table this installer owns.
	 */
	private const TABLES = [ self::TABLE_PLANS, self::TABLE_AUDIT, self::TABLE_SNAPSHOTS ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The prefixed name of one owned table.
	 *
	 * @param string $suffix One of the TABLE_* constants.
	 *
	 * @return string The fully prefixed table name.
	 */
	public static function tableName( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $suffix;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Creates or migrates every owned table.
	 *
	 * @return bool True when all three tables are present afterwards.
	 */
	public function install(): bool {
		global $wpdb;

		if ( ! $this->schema_api_loaded() ) {
			$this->record_status( false );

			return false;
		}

		foreach ( $this->statements( $wpdb->get_charset_collate() ) as $statement ) {
			dbDelta( $statement );
		}

		foreach ( self::TABLES as $suffix ) {
			if ( ! $this->table_exists( self::tableName( $suffix ) ) ) {
				$this->record_status( false );

				return false;
			}
		}

		update_option( self::DB_VERSION_OPTION, (string) self::DB_VERSION, false );
		$this->record_status( true );

		return true;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Runs the installer when the stored schema version is behind, or when a
	 * previous attempt left storage unavailable.
	 *
	 * @return bool True when an install was performed and succeeded.
	 */
	public function maybeUpgrade(): bool {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $stored >= self::DB_VERSION && $this->isAvailable() ) {
			return false;
		}

		return $this->install();
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether the storage-dependent surfaces may run.
	 *
	 * @return bool True when the tables were confirmed present.
	 */
	public function isAvailable(): bool {
		return self::STATUS_READY === get_option( self::STATUS_OPTION, self::STATUS_UNAVAILABLE );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Records the storage status, logging server-side on failure.
	 *
	 * The log line names no table, no SQL, and no path: it is a durable record
	 * that the change surfaces are down, nothing more.
	 *
	 * @param bool $ready Whether storage is usable.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function record_status( bool $ready ): void {
		update_option( self::STATUS_OPTION, $ready ? self::STATUS_READY : self::STATUS_UNAVAILABLE, false );

		if ( ! $ready ) {
			error_log( 'SiteHelm could not create its local tables; the change, audit, and rollback surfaces are unavailable.' );
		}
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Ensures dbDelta is callable.
	 *
	 * @return bool True when dbDelta can be called.
	 */
	private function schema_api_loaded(): bool {
		if ( function_exists( 'dbDelta' ) ) {
			return true;
		}
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return function_exists( 'dbDelta' );
	}

	/**
	 * Whether one table is present.
	 *
	 * @param string $table The fully prefixed table name.
	 *
	 * @return bool True when the table exists.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return is_string( $found ) && $found === $table;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * The CREATE TABLE statements, in dbDelta's required format: one field per
	 * line, two spaces after PRIMARY KEY, KEY rather than INDEX, and index names
	 * that match their column list so dbDelta recognises them as unchanged.
	 *
	 * Table names come from $wpdb->prefix plus a hardcoded constant, never from
	 * request data, so interpolating them carries no injection risk.
	 *
	 * @param string $charset_collate The site's charset and collation clause.
	 *
	 * @return string[] One statement per owned table.
	 */
	private function statements( string $charset_collate ): array {
		$plans     = self::tableName( self::TABLE_PLANS );
		$audit     = self::tableName( self::TABLE_AUDIT );
		$snapshots = self::tableName( self::TABLE_SNAPSHOTS );

		return [
			"CREATE TABLE {$plans} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token_hash char(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	operation_id varchar(64) NOT NULL,
	schema_version smallint(5) unsigned NOT NULL,
	target_key varchar(191) NOT NULL,
	payload_hash char(64) NOT NULL,
	state_fingerprint char(64) NOT NULL,
	plan_body longtext NOT NULL,
	created_at bigint(20) unsigned NOT NULL,
	expires_at bigint(20) unsigned NOT NULL,
	consumed_at bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY expires_at (expires_at)
) {$charset_collate};",
			"CREATE TABLE {$audit} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	correlation_id varchar(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	actor_id bigint(20) unsigned NOT NULL,
	actor_login varchar(60) NOT NULL,
	client_id varchar(191) NOT NULL,
	operation_id varchar(64) NOT NULL,
	target_key varchar(191) NOT NULL,
	plan_fingerprint char(64) NOT NULL,
	outcome varchar(32) NOT NULL,
	summary text NOT NULL,
	snapshot_id bigint(20) unsigned DEFAULT NULL,
	rollback_ref varchar(64) DEFAULT NULL,
	recorded_at bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY recorded_at (recorded_at),
	KEY correlation_id (correlation_id),
	KEY actor_id (actor_id),
	KEY operation_target (operation_id,target_key)
) {$charset_collate};",
			"CREATE TABLE {$snapshots} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	rollback_ref varchar(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	operation_id varchar(64) NOT NULL,
	module_id varchar(32) NOT NULL,
	target_key varchar(191) NOT NULL,
	restore_state longtext NOT NULL,
	module_versions text NOT NULL,
	created_at bigint(20) unsigned NOT NULL,
	restored_at bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY rollback_ref (rollback_ref),
	KEY created_at (created_at),
	KEY target_key (target_key)
) {$charset_collate};",
		];
	}
}
