<?php
/**
 * The redirect table, and the one path normaliser every reader of it shares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * REQ-0079: redirects, so traffic and rankings survive a rename.
 *
 * ONE NORMALISER, USED ON BOTH SIDES, IS THE WHOLE POINT OF THIS CLASS. A
 * redirect is stored by one code path and matched by a completely different one:
 * an operator writes `/old-pricing/` through the gateway, and months later an
 * anonymous visitor arrives with `REQUEST_URI` of `/old-pricing`. If the two
 * sides spell the same path differently the row is stored, reported as stored,
 * listed back as stored — and never fires. Nothing in the system reports that
 * failure, because every component involved did exactly what it promised.
 *
 * So normalizePath() is the ONLY way a path becomes a key, on either side, and
 * the router derives its lookup key by calling it rather than by reproducing
 * what it does. A second implementation that agreed today is a second
 * implementation that can drift tomorrow.
 *
 * THE TABLE IS ONE AUTOLOADED OPTION, and it is bounded because of that. The
 * router has to consult it on every front-end request that would otherwise be
 * served, so a non-autoloaded option would buy one query per page view on every
 * site — including the sites with no redirects at all. The price of autoloading
 * is that the table sits in memory on every request, which is why
 * MAX_REDIRECTS exists: a bound that refuses the five-hundred-and-first row is
 * an answer an operator can act on, where an unbounded option that quietly
 * doubles a site's `alloptions` payload is a performance bug nobody attributes
 * to redirects.
 *
 * A MALFORMED ROW IS DROPPED ON READ, NEVER FATAL. This option is autoloaded
 * into every front-end request, so whatever a failed migration, a hand-edited
 * database or an unrelated plugin leaves in it is read by code running in front
 * of anonymous traffic. Reading defensively here is the difference between one
 * unusable redirect and a white screen.
 *
 * @package SiteHelm
 */
final class RedirectStore {

	/**
	 * The option holding the redirect table.
	 */
	public const OPTION = 'sitehelm_redirects';

	/**
	 * The greatest number of redirects this site will store.
	 *
	 * See the class note: the table is autoloaded so the router can match
	 * without a query, and an unbounded autoloaded option is a performance
	 * regression that presents as "the site got slow" rather than as anything to
	 * do with redirects.
	 */
	public const MAX_REDIRECTS = 500;

	/**
	 * The greatest length of a source path, in bytes after normalisation.
	 *
	 * Comfortably longer than any path a content management system produces, and
	 * short enough that MAX_REDIRECTS rows cannot become a megabyte-scale
	 * autoloaded option.
	 */
	public const MAX_PATH_LENGTH = 500;

	/**
	 * The greatest length of a target, in bytes.
	 */
	public const MAX_TARGET_LENGTH = 2000;

	/**
	 * The status codes a redirect may answer with.
	 *
	 * 410 is in the list because the requirement is about rankings and not only
	 * about traffic: a page that was deleted rather than moved has no target,
	 * and telling a crawler `gone` retires the URL where a 301 to the home page
	 * launders it into a soft 404. When the status is 410 the target is null.
	 *
	 * 302 and 307 are temporary and are offered for the case they are actually
	 * for — a campaign, a maintenance window — rather than as a safer-feeling
	 * default. They do not transfer ranking signals.
	 *
	 * @var int[]
	 */
	public const STATUS_CODES = [ 301, 302, 307, 308, 410 ];

	/**
	 * The status code that means "this page is gone and has no successor".
	 */
	public const STATUS_GONE = 410;

	/**
	 * The members one stored redirect record holds.
	 *
	 * @var string[]
	 */
	public const RECORD_FIELDS = [ 'source', 'target', 'status', 'forwardQuery' ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Reduces a path to the single spelling this table is keyed by.
	 *
	 * Returns null for anything that cannot be a path on this site, rather than
	 * a best-effort repair: a source that had to be guessed at is a source the
	 * router will guess differently.
	 *
	 * THE QUERY AND FRAGMENT ARE CUT BEFORE DECODING, because that is the order
	 * a web server splits a request line in, and doing it the other way round
	 * would let an encoded `%3F` in a stored source truncate the path it was
	 * part of. After decoding, a literal `?` or `#` is REFUSED rather than
	 * accepted: a request can only ever deliver those two characters encoded, so
	 * a source containing one un-encoded could never match anything, and storing
	 * it would be storing a redirect that cannot fire.
	 *
	 * A `..` segment is refused for the same reason and not as path-traversal
	 * defence — this value never reaches a filesystem — but because `/a/../b`
	 * and `/b` are the same resource written two ways, and a table with both
	 * spellings in it has two rows that can disagree.
	 *
	 * THE COMPARISON IS CASE SENSITIVE, deliberately. WordPress permalinks are,
	 * and folding case here would make `/About` and `/about` one row, so an
	 * operator could not redirect one without redirecting the other.
	 *
	 * @param string $raw The path as supplied, or as a request delivered it.
	 *
	 * @return string|null The canonical key, or null when the value cannot be
	 *                     a source path on this site.
	 */
	public function normalizePath( string $raw ): ?string {
		$path = trim( $raw );

		if ( '' === $path ) {
			return null;
		}

		// A scheme or an authority means this names a resource somewhere, not a
		// path here. `//host/x` is protocol-relative and is a URL despite
		// looking like a path with a doubled slash, so it is tested before the
		// slash run below collapses it into one.
		if ( str_starts_with( $path, '//' ) || 1 === preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path ) ) {
			return null;
		}

		$path = (string) preg_replace( '/[?#].*$/', '', $path );
		$path = rawurldecode( $path );

		// Control bytes, a null byte, or either of the two characters a request
		// can only deliver encoded. Each one means the value cannot round-trip
		// through a request line, so it can never match.
		if ( 1 === preg_match( '/[\x00-\x1f\x7f?#]/', $path ) ) {
			return null;
		}

		$path = (string) preg_replace( '#/+#', '/', '/' . $path );

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return null;
			}
		}

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		// The home page is refused. A redirect whose source is `/` sends every
		// visitor who reaches the site's front door somewhere else, including
		// the operator trying to undo it, and on a target that is itself
		// relative it is a loop the browser reports as a broken site.
		if ( '/' === $path || '' === $path ) {
			return null;
		}

		if ( strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return null;
		}

		return $path;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Every stored redirect, keyed by canonical source path.
	 *
	 * Malformed rows are dropped rather than repaired or raised. See the class
	 * note: this runs in front of anonymous traffic.
	 *
	 * The keys are re-derived through normalizePath() rather than trusted, so a
	 * row written by an older version of this plugin, or by hand, is either
	 * matchable under the spelling this version uses or is not returned at all.
	 * A key that survives here is a key the router can hit.
	 *
	 * @return array<string, array<string, mixed>> The redirect table.
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$table = [];

		foreach ( $stored as $row ) {
			$record = $this->sanitize( $row );

			if ( null === $record ) {
				continue;
			}

			$table[ $record['source'] ] = $record;
		}

		ksort( $table, SORT_STRING );

		return $table;
	}

	/**
	 * The redirect matching one path, or null when none does.
	 *
	 * @param string $path The path to match, in any spelling.
	 *
	 * @return array<string, mixed>|null The matching record, or null.
	 */
	public function find( string $path ): ?array {
		$key = $this->normalizePath( $path );

		if ( null === $key ) {
			return null;
		}

		$table = $this->all();

		return $table[ $key ] ?? null;
	}

	/**
	 * Stores the whole table, replacing what was there.
	 *
	 * The table is written WHOLE because it is one option: there is no way to
	 * write one row without writing the value that holds them all. That is also
	 * why the write operations snapshot the entire table rather than one row —
	 * a snapshot of one row could only be restored by merging it into a table
	 * read later, carrying every unrelated row edited in between into a
	 * rollback that claims to reverse one redirect.
	 *
	 * Stored as a LIST rather than a keyed map. The canonical source lives
	 * inside each record, so the key would be a second copy of it that a future
	 * hand edit could put out of step with the first, and all() re-derives the
	 * keys anyway.
	 *
	 * @param array<string, array<string, mixed>> $table The table to store.
	 *
	 * @return bool True when the option now holds this table.
	 */
	public function replace( array $table ): bool {
		$rows = [];

		foreach ( $table as $row ) {
			$record = $this->sanitize( $row );

			if ( null !== $record ) {
				$rows[ $record['source'] ] = $record;
			}
		}

		ksort( $rows, SORT_STRING );

		// Autoloaded deliberately; see the class note. An empty table is DELETED
		// rather than stored as an empty array, so a site that never used
		// redirects and a site that removed its last one both carry no row —
		// otherwise removing the last redirect would leave a permanent
		// autoloaded artefact behind as the trace of a feature no longer in use.
		if ( [] === $rows ) {
			return delete_option( self::OPTION ) || false === get_option( self::OPTION, false );
		}

		return update_option( self::OPTION, array_values( $rows ), true )
			|| $this->matches( array_values( $rows ) );
	}

	/**
	 * How many redirects are stored.
	 *
	 * @return int The row count.
	 */
	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Builds a storable record, or null when the input is not one.
	 *
	 * Every gate here is a gate the write operations also apply with a spoken
	 * refusal. This is the last one, and it is silent, because it also runs over
	 * whatever is already in the option — where there is no caller to refuse to.
	 *
	 * @param mixed $row The candidate row.
	 *
	 * @return array<string, mixed>|null The record, or null.
	 */
	private function sanitize( mixed $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$source = is_string( $row['source'] ?? null ) ? $this->normalizePath( $row['source'] ) : null;
		$status = is_numeric( $row['status'] ?? null ) ? (int) $row['status'] : 0;

		if ( null === $source || ! in_array( $status, self::STATUS_CODES, true ) ) {
			return null;
		}

		$target = is_string( $row['target'] ?? null ) ? trim( $row['target'] ) : null;

		if ( self::STATUS_GONE === $status ) {
			$target = null;
		} elseif ( null === $target || '' === $target || strlen( $target ) > self::MAX_TARGET_LENGTH ) {
			return null;
		}

		// RECORD_FIELDS order, fixed rather than sorted. It is the order a person
		// reads a redirect in, and any fixed order keeps the snapshot's canonical
		// JSON identical for identical state, which is the only property the
		// change engine needs from it.
		return [
			'source'       => $source,
			'target'       => $target,
			'status'       => $status,
			'forwardQuery' => (bool) ( $row['forwardQuery'] ?? true ),
		];
	}

	/**
	 * Whether the option already holds the rows a write meant to store.
	 *
	 * `update_option()` answers false both when the write failed and when the
	 * stored value was already identical, and the second is the ordinary result
	 * of re-applying an idempotent redirect write. Treating it as a failure
	 * would make the second identical apply report a broken database.
	 *
	 * @param array[] $rows The rows that were written.
	 *
	 * @return bool True when the stored value equals the rows.
	 */
	private function matches( array $rows ): bool {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) && array_values( $stored ) === $rows;
	}
}
