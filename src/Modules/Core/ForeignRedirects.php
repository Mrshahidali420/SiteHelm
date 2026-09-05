<?php
/**
 * The redirects other plugins hold, so SiteHelm's own are not written blind.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * Looks up the redirects another plugin holds for a path SiteHelm is about to
 * claim.
 *
 * TWO PLUGINS CAN HOLD A RULE FOR ONE PATH AND NOTHING SAYS WHICH ONE WINS. It
 * falls out of hook priority, which no caller can see and neither plugin
 * documents as a contract. So a client that reads the SEO plugin's redirections,
 * finds nothing for `/old-pricing/`, and writes a SiteHelm redirect has done
 * everything right and can still end up with a path that two plugins answer
 * differently — and the one that loses is invisible, because it was stored,
 * reported as stored, and listed back as stored.
 *
 * THIS CLASS ONLY READS, AND IT ONLY WARNS. It does not disable anybody else's
 * rule and it does not refuse the write: an operator moving a site off another
 * plugin's redirections deliberately writes over them, and a refusal would make
 * that impossible. The preview is the one moment the caller is still deciding,
 * so a conflict is named there and the decision stays with the caller.
 *
 * A MATCH IS REPORTED WITH ITS CERTAINTY, never as a flat yes. Rank Math's
 * sources carry a comparison — exact, contains, start, end, regex — and only
 * `exact` can be settled by comparing two strings. A `regex` source is reported
 * as a possible match with the pattern quoted rather than evaluated: running a
 * pattern out of the database against a path is somebody else's engine, and a
 * pattern that fails to compile would turn a warning into a fatal error inside
 * a preview.
 *
 * ONLY RANK MATH IS READ TODAY. It is the plugin the gap was found on, and its
 * table shape is the one this plugin already reads elsewhere. A store whose
 * columns were guessed at would answer "no conflict" for every site that has
 * one, which is worse than saying nothing: the lookup is written per owner so
 * another can be added when its shape is known rather than assumed.
 *
 * @package SiteHelm
 */
final class ForeignRedirects {

	/**
	 * The owner name reported for Rank Math's redirections module.
	 */
	public const RANK_MATH = 'rank-math';

	/**
	 * Rank Math's redirections table, without the site's table prefix.
	 */
	private const RANK_MATH_TABLE = 'rank_math_redirections';

	/**
	 * The greatest number of another plugin's rules one read considers.
	 *
	 * THE TABLE IS READ WHOLE RATHER THAN NARROWED BY THE PATH, because a rule
	 * that answers `/shop/old-page` need not mention it: a `contains` source of
	 * `old-page` catches it and a `start` source of `shop` catches it, and
	 * neither row would come back from a query narrowed on the path. Narrowing
	 * in SQL would therefore hide exactly the rules a caller is least likely to
	 * find by hand, and hide them as a confident "no conflict". Four small
	 * columns of a bounded table is a cheap read; being right is worth it.
	 */
	public const MAX_ROWS = 200;

	/**
	 * Constructs the lookup.
	 *
	 * @param RedirectStore $store The redirect table, used only for its path normaliser.
	 */
	public function __construct( private readonly RedirectStore $store ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The rules another plugin holds that could answer this path.
	 *
	 * Returns an empty array for a path that cannot be normalised, for a site
	 * with no other redirect plugin installed, and for any failure to read one:
	 * this runs inside a preview, and a lookup that cannot answer must leave the
	 * preview it was consulted for intact.
	 *
	 * @param string $path The source path SiteHelm is about to claim.
	 *
	 * @return array<int, array{owner: string, pattern: string, comparison: string, target: string, status: int, active: bool, certain: bool}> The conflicting rules.
	 */
	public function matching( string $path ): array {
		$normalized = $this->store->normalizePath( $path );

		if ( null === $normalized ) {
			return [];
		}

		return $this->rankMathMatches( $normalized );
	}

	/**
	 * Every rule another plugin holds, up to the listing bound.
	 *
	 * Reported beside SiteHelm's own table so one read answers "what redirects
	 * does this site serve" rather than "what redirects does this plugin hold",
	 * which is the question a caller was actually asking and the one that used
	 * to have two half-answers in two places.
	 *
	 * @return array{rules: array<int, array{owner: string, pattern: string, comparison: string, target: string, status: int, active: bool}>, truncated: bool} The rules and whether more exist.
	 */
	public function all(): array {
		$rows      = $this->rankMathRows( self::MAX_ROWS + 1 );
		$truncated = count( $rows ) > self::MAX_ROWS;
		$rules     = [];

		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			foreach ( $this->sources( $row['sources'] ?? null ) as $source ) {
				if ( '' === trim( $source['pattern'] ) ) {
					continue;
				}

				$rules[] = [
					'owner'      => self::RANK_MATH,
					'pattern'    => $source['pattern'],
					'comparison' => $source['comparison'],
					'target'     => (string) ( $row['url_to'] ?? '' ),
					'status'     => (int) ( $row['header_code'] ?? 0 ),
					'active'     => 'active' === (string) ( $row['status'] ?? '' ),
				];
			}
		}

		return [
			'rules'     => $rules,
			'truncated' => $truncated,
		];
	}

	/**
	 * A sentence naming one conflict, for a preview warning.
	 *
	 * @param array{owner: string, pattern: string, comparison: string, target: string, status: int, active: bool, certain: bool} $rule One rule from matching().
	 * @param string                                                                                                              $path The path SiteHelm is about to claim.
	 *
	 * @return string The warning.
	 */
	public function describe( array $rule, string $path ): string {
		return sprintf(
			'%s already holds a redirect that %s "%s": its %s source is "%s"%s. Two plugins answering one path is settled by whichever hook runs first, which is not something either of them promises, so this site would have two answers for one address.',
			self::RANK_MATH === $rule['owner'] ? 'Rank Math' : $rule['owner'],
			$rule['certain'] ? 'matches' : 'may match',
			$path,
			$rule['comparison'],
			$rule['pattern'],
			$rule['active'] ? '' : ', and it is switched off today'
		);
	}

	/**
	 * Rank Math's redirections that could answer this path.
	 *
	 * @param string $normalized The normalised source path.
	 *
	 * @return array<int, array{owner: string, pattern: string, comparison: string, target: string, status: int, active: bool, certain: bool}> The rules.
	 */
	private function rankMathMatches( string $normalized ): array {
		$rules = [];

		// The stored column is a serialised array holding a pattern and the way
		// to compare it, so no SQL predicate can settle a match. Every row is
		// decoded and settled in PHP: see MAX_ROWS for why none are filtered out
		// on the way.
		foreach ( $this->rankMathRows( self::MAX_ROWS ) as $row ) {
			foreach ( $this->sources( $row['sources'] ?? null ) as $source ) {
				$verdict = $this->verdict( $normalized, $source['pattern'], $source['comparison'] );

				if ( null === $verdict ) {
					continue;
				}

				$rules[] = [
					'owner'      => self::RANK_MATH,
					'pattern'    => $source['pattern'],
					'comparison' => $source['comparison'],
					'target'     => (string) ( $row['url_to'] ?? '' ),
					'status'     => (int) ( $row['header_code'] ?? 0 ),
					'active'     => 'active' === (string) ( $row['status'] ?? '' ),
					'certain'    => $verdict,
				];
			}
		}

		return $rules;
	}

	/**
	 * Rows out of Rank Math's redirections table.
	 *
	 * Returns an empty array when there is no such table and when the read
	 * fails, which is the same answer for the same reason: this is consulted
	 * inside a preview and inside a listing, and a lookup that cannot answer
	 * must not take either of them down with it.
	 *
	 * @param int $limit The greatest number of rows to read.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	private function rankMathRows( int $limit ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return [];
		}

		$table = $wpdb->prefix . self::RANK_MATH_TABLE;

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return [];
		}

		// The literal 'ARRAY_A' is passed rather than the WordPress constant, for
		// the reason PlanStore gives: it makes the read testable without loading
		// WordPress, and the constant's value is exactly this string.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sources, url_to, header_code, status FROM `{$table}` ORDER BY id ASC LIMIT %d",
				$limit
			),
			'ARRAY_A'
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * Whether one stored source answers this path, and how sure that is.
	 *
	 * Returns true for a match this can settle, false for one it can only
	 * suspect, and null for no match at all. A regex source is never evaluated:
	 * see the class note.
	 *
	 * @param string $normalized The normalised source path.
	 * @param string $pattern    The stored pattern.
	 * @param string $comparison Rank Math's comparison for that pattern.
	 *
	 * @return bool|null True when certain, false when possible, null when it does not match.
	 */
	private function verdict( string $normalized, string $pattern, string $comparison ): ?bool {
		$path  = trim( $normalized, '/' );
		$other = trim( trim( $pattern ), '/' );

		if ( '' === $other ) {
			return null;
		}

		switch ( $comparison ) {
			case 'exact':
				return $path === $other ? true : null;
			case 'contains':
				return str_contains( $path, $other ) ? true : null;
			case 'start':
				return str_starts_with( $path, $other ) ? true : null;
			case 'end':
				return str_ends_with( $path, $other ) ? true : null;
			default:
				// A regex, or a comparison this version has never heard of. The
				// row was returned because the path appears in it somewhere,
				// which is enough to be worth naming and not enough to assert.
				return false;
		}
	}

	/**
	 * The patterns one stored row holds.
	 *
	 * @param mixed $stored The serialised `sources` column.
	 *
	 * @return array<int, array{pattern: string, comparison: string}> The patterns.
	 */
	private function sources( mixed $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return [];
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- another plugin's column format, decoded with classes forbidden; a malformed row must read as empty rather than raise inside a preview.
		$decoded = @unserialize( $stored, [ 'allowed_classes' => false ] );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$sources = [];

		foreach ( $decoded as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$sources[] = [
				'pattern'    => (string) ( $source['pattern'] ?? '' ),
				'comparison' => (string) ( $source['comparison'] ?? 'exact' ),
			];
		}

		return $sources;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
