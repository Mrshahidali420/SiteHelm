<?php
/**
 * All SQL for the two OAuth tables.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use SiteHelm\Storage\Installer;

/**
 * Reads and writes registered clients and issued tokens.
 *
 * Table names come from {@see Installer} constants and nothing else; every
 * value is bound through `$wpdb->prepare`. No method here ever accepts or
 * returns a raw token: callers hand in the sha256 fingerprint and the raw
 * secret stays in the response body it was minted for.
 *
 * @package SiteHelm
 */
final class OAuthStore {

	/**
	 * Token kinds stored in the `token_type` column.
	 */
	public const TYPE_ACCESS  = 'access';
	public const TYPE_REFRESH = 'refresh';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * The clients table.
	 *
	 * @return string The prefixed table name.
	 */
	public static function clientsTable(): string {
		return Installer::tableName( Installer::TABLE_OAUTH_CLIENTS );
	}

	/**
	 * The tokens table.
	 *
	 * @return string The prefixed table name.
	 */
	public static function tokensTable(): string {
		return Installer::tableName( Installer::TABLE_OAUTH_TOKENS );
	}

	/**
	 * Whether any app has ever completed an authorization on this site.
	 *
	 * The Home walkthrough calls this to decide whether the OAuth step is done.
	 * It asks the clients table rather than the tokens table on purpose: tokens
	 * expire, and a site whose owner connected an app in March and has not used
	 * it since has still finished the step. `authorized_at` is stamped once, at
	 * the moment consent is granted, and is never cleared while the registration
	 * survives.
	 *
	 * One indexed query, no writes, and a missing table reads as false rather
	 * than an error, so a site whose install failed still renders its Home
	 * screen.
	 *
	 * @return bool True when at least one registration has been authorized.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public static function has_authenticated(): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		$table = self::clientsTable();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a hardcoded constant; no request data in this statement.
		$found = $wpdb->get_var( "SELECT client_id FROM {$table} WHERE authorized_at > 0 LIMIT 1" );

		return null !== $found && '' !== (string) $found;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Stores one newly registered client.
	 *
	 * @param array<string, mixed> $row The client row.
	 *
	 * @return bool True when the row was written.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function insertClient( array $row ): bool {
		global $wpdb;

		return false !== $wpdb->insert(
			self::clientsTable(),
			[
				'client_id'     => (string) $row['client_id'],
				'client_name'   => (string) $row['client_name'],
				'redirect_uris' => (string) $row['redirect_uris'],
				'created_by'    => (int) ( $row['created_by'] ?? 0 ),
				'created_at'    => (int) $row['created_at'],
				'authorized_at' => (int) ( $row['authorized_at'] ?? 0 ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d' ]
		);
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * One client by identifier.
	 *
	 * @param string $client_id The registered identifier.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function findClient( string $client_id ): ?array {
		global $wpdb;

		$table = self::clientsTable();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ),
			'ARRAY_A'
		);
	}

	/**
	 * The client registered under exactly this name and redirect list.
	 *
	 * Registration is idempotent by shape so a desktop client that registers
	 * itself on every launch reuses one row instead of filling the table.
	 *
	 * @param string $name          The client name.
	 * @param string $redirect_uris The encoded redirect list.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function findClientByShape( string $name, string $redirect_uris ): ?array {
		global $wpdb;

		$table = self::clientsTable();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_name = %s AND redirect_uris = %s LIMIT 1",
				$name,
				$redirect_uris
			),
			'ARRAY_A'
		);
	}

	/**
	 * Every registration, newest first, with a live-token count beside it.
	 *
	 * The count is what tells "never signed in" apart from "signed out": a
	 * registration with no live tokens is still a registration the operator
	 * needs to be able to see and remove.
	 *
	 * `last_token_at` is the newest token ever issued under the registration,
	 * live or expired. There is no per-request timestamp on a token, so this is
	 * the closest honest answer to "when did this app last use the site", and
	 * the screen labels it as the last time the app was let in rather than the
	 * last call it made.
	 *
	 * @param int $now   The current time, for deciding which tokens are live.
	 * @param int $limit The largest page to return.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function listClients( int $now, int $limit = 100 ): array {
		global $wpdb;

		$clients = self::clientsTable();
		$tokens  = self::tokensTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, (
					SELECT COUNT(*) FROM {$tokens} t
					WHERE t.client_id = c.client_id AND t.expires_at > %d
				) AS live_tokens, (
					SELECT MAX(t2.created_at) FROM {$tokens} t2
					WHERE t2.client_id = c.client_id
				) AS last_token_at
				FROM {$clients} c
				ORDER BY c.created_at DESC
				LIMIT %d",
				$now,
				$limit
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * How many registrations never completed a consent.
	 *
	 * @return int The count.
	 */
	public function countNeverAuthorized(): int {
		global $wpdb;

		$table = self::clientsTable();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE authorized_at = 0" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Records that a client completed a consent.
	 *
	 * @param string $client_id The registered identifier.
	 * @param int    $when      The time of consent.
	 *
	 * @return bool True when a row was touched.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function markAuthorized( string $client_id, int $when ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			self::clientsTable(),
			[ 'authorized_at' => $when ],
			[ 'client_id' => $client_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Removes one registration and every token issued under it.
	 *
	 * @param string $client_id The registered identifier.
	 *
	 * @return bool True when the registration is gone.
	 */
	public function deleteClient( string $client_id ): bool {
		global $wpdb;

		$this->deleteTokensForClient( $client_id );

		$clients = self::clientsTable();

		return false !== $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$clients} WHERE client_id = %s", $client_id )
		);
	}

	/**
	 * Stores one issued token by fingerprint.
	 *
	 * @param array<string, mixed> $row The token row; `token_hash` is a sha256 hex digest.
	 *
	 * @return int The new row identifier, or 0 when the write was refused.
	 */
	public function insertToken( array $row ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::tokensTable(),
			[
				'token_hash' => (string) $row['token_hash'],
				'token_type' => (string) $row['token_type'],
				'client_id'  => (string) $row['client_id'],
				'user_id'    => (int) $row['user_id'],
				'scopes'     => (string) ( $row['scopes'] ?? MetadataDocument::SCOPE ),
				'resource'   => (string) ( $row['resource'] ?? '' ),
				'expires_at' => (int) $row['expires_at'],
				'refresh_of' => (string) ( $row['refresh_of'] ?? '' ),
				'created_at' => (int) $row['created_at'],
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d' ]
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One token by fingerprint and kind, whether or not it has expired.
	 *
	 * Expiry is decided by the caller rather than by the query, because the
	 * refresh grant treats a just-expired refresh token differently from an
	 * unknown one: inside the rotation grace window it is still honoured.
	 *
	 * @param string $token_hash The sha256 fingerprint.
	 * @param string $type       One of the TYPE_* constants.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function findToken( string $token_hash, string $type ): ?array {
		global $wpdb;

		$table = self::tokensTable();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND token_type = %s",
				$token_hash,
				$type
			),
			'ARRAY_A'
		);
	}

	/**
	 * Brings a token's expiry forward, which is how a rotated refresh token is
	 * left usable for a short grace window instead of being deleted.
	 *
	 * @param string $token_hash The sha256 fingerprint.
	 * @param int    $expires_at The new expiry.
	 *
	 * @return bool True when a row was touched.
	 */
	public function expireToken( string $token_hash, int $expires_at ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			self::tokensTable(),
			[ 'expires_at' => $expires_at ],
			[ 'token_hash' => $token_hash ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Deletes one token by fingerprint.
	 *
	 * @param string $token_hash The sha256 fingerprint.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteToken( string $token_hash ): int {
		global $wpdb;

		$table = self::tokensTable();

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE token_hash = %s", $token_hash )
		);
	}

	/**
	 * Deletes every token issued under one registration.
	 *
	 * @param string $client_id The registered identifier.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteTokensForClient( string $client_id ): int {
		global $wpdb;

		$table = self::tokensTable();

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE client_id = %s", $client_id )
		);
	}

	/**
	 * Deletes the access tokens minted from one refresh token.
	 *
	 * Used by revocation, which is asked to end a session — never by rotation,
	 * which must leave a live access token alone.
	 *
	 * @param string $refresh_hash The refresh token's fingerprint.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteTokensDerivedFrom( string $refresh_hash ): int {
		global $wpdb;

		$table = self::tokensTable();

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE refresh_of = %s", $refresh_hash )
		);
	}

	/**
	 * Deletes every token that has passed its expiry.
	 *
	 * @param int $now The current time.
	 *
	 * @return int Rows deleted.
	 */
	public function pruneExpiredTokens( int $now ): int {
		global $wpdb;

		$table = self::tokensTable();

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %d", $now )
		);
	}

	/**
	 * Deletes registrations that never completed a consent and are older than
	 * the given cutoff.
	 *
	 * The `authorized_at = 0` clause is load-bearing, not an optimisation. A
	 * client whose refresh token simply lapsed after a month of disuse has no
	 * live tokens and looks exactly like an abandoned registration; deleting it
	 * turns the operator's saved connection into "invalid client" with nothing
	 * to point at. A registration that ever completed a consent is never pruned.
	 *
	 * @param int $before Registrations created before this time may go.
	 *
	 * @return int Rows deleted.
	 */
	public function pruneNeverAuthorizedClients( int $before ): int {
		global $wpdb;

		$table = self::clientsTable();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE authorized_at = 0 AND created_at < %d",
				$before
			)
		);
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
