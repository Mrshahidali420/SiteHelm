<?php
/**
 * Checking that discovery actually works from outside.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Fetches this site's own discovery documents the way a client would, and
 * reports what came back.
 *
 * The check compares **identity**, not just status. `/.well-known/` is shared
 * ground, and a site with another OAuth plugin installed can return a perfectly
 * valid document there that belongs to somebody else's authorization server.
 * A test that only looked at the status code would report that as a pass and
 * leave the operator debugging a connection that never had a chance.
 *
 * @package SiteHelm
 */
final class DiscoverySelfTest {

	/**
	 * Outcomes, worst last.
	 */
	public const PASS        = 'pass';
	public const WRONG_OWNER = 'wrong_owner';
	public const UNREACHABLE = 'unreachable';

	/**
	 * The last run, kept so the Health screen can report it without fetching
	 * four documents every time somebody opens a page.
	 */
	public const OPTION_LAST = 'sitehelm_discovery_last';

	/**
	 * How long to wait for the site to answer itself.
	 */
	private const TIMEOUT = 10;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Constructs the test.
	 *
	 * @param MetadataDocument $metadata The identifier comparer.
	 * @param PublicUrl        $urls     The address resolver.
	 */
	public function __construct(
		private readonly MetadataDocument $metadata,
		private readonly PublicUrl $urls
	) {
	}

	/**
	 * Runs the check against all four addresses a client might use.
	 *
	 * @return array<int, array<string, mixed>> One row per address: `url`,
	 *                                          `status`, `outcome`, `detail`.
	 */
	public function run(): array {
		$expected_resource = $this->urls->resource();
		$expected_issuer   = $this->urls->issuer();
		$root              = $this->urls->base();

		$checks = [
			[ $root . Discovery::WELL_KNOWN_RESOURCE, 'resource', $expected_resource ],
			[ $root . Discovery::WELL_KNOWN_SERVER, 'issuer', $expected_issuer ],
			[ $this->urls->restUrl( 'oauth/protected-resource' ), 'resource', $expected_resource ],
			[ $this->urls->restUrl( 'oauth/authorization-server' ), 'issuer', $expected_issuer ],
		];

		$results = [];

		foreach ( $checks as [ $url, $key, $expected ] ) {
			$results[] = $this->check( $url, $key, $expected );
		}

		return $results;
	}

	/**
	 * Runs the check and keeps the result for other screens to read.
	 *
	 * @return array<int, array<string, mixed>> The result rows.
	 */
	public function runAndRemember(): array {
		$rows = $this->run();

		update_option(
			self::OPTION_LAST,
			[
				'at'   => time(),
				'rows' => $rows,
			],
			false
		);

		return $rows;
	}

	/**
	 * The last run, or an empty array when the check has never been run.
	 *
	 * @return array{at: int, rows: array<int, array<string, mixed>>}|array{} The stored run.
	 */
	public static function last(): array {
		$stored = get_option( self::OPTION_LAST, [] );

		if ( ! is_array( $stored ) || ! isset( $stored['rows'] ) || ! is_array( $stored['rows'] ) ) {
			return [];
		}

		return [
			'at'   => (int) ( $stored['at'] ?? 0 ),
			'rows' => $stored['rows'],
		];
	}

	/**
	 * The worst outcome in a set of rows, because one address answering with
	 * somebody else's document is the whole answer however many pass.
	 *
	 * @param array<int, array<string, mixed>> $rows The result rows.
	 *
	 * @return string An outcome constant, or '' when there are no rows.
	 */
	public static function worst( array $rows ): string {
		$worst = '';

		foreach ( $rows as $row ) {
			$outcome = (string) ( $row['outcome'] ?? '' );

			if ( self::UNREACHABLE === $outcome ) {
				return self::UNREACHABLE;
			}

			if ( self::WRONG_OWNER === $outcome || '' === $worst ) {
				$worst = $outcome;
			}
		}

		return $worst;
	}

	/**
	 * Fetches one document and judges it.
	 *
	 * @param string $url      The address to fetch.
	 * @param string $key      The member carrying the identifier.
	 * @param string $expected The identifier this site publishes.
	 *
	 * @return array<string, mixed> The result row.
	 */
	private function check( string $url, string $key, string $expected ): array {
		$response = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT ] );

		if ( is_wp_error( $response ) ) {
			return $this->row( $url, 0, self::UNREACHABLE, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return $this->row(
				$url,
				$status,
				self::UNREACHABLE,
				__( 'The site did not serve this document. A CDN, a firewall or a security plugin is the usual cause; allow this exact path through.', 'sitehelm' )
			);
		}

		$found = is_array( $body ) && isset( $body[ $key ] ) && is_string( $body[ $key ] ) ? $body[ $key ] : '';

		if ( ! $this->metadata->sameIdentifier( $found, $expected ) ) {
			return $this->row(
				$url,
				$status,
				self::WRONG_OWNER,
				sprintf(
					/* translators: 1: the identifier that was served, 2: the identifier this site publishes. */
					__( 'Something else answered this address. It returned %1$s where SiteHelm publishes %2$s.', 'sitehelm' ),
					'' !== $found ? $found : __( 'no identifier at all', 'sitehelm' ),
					$expected
				)
			);
		}

		return $this->row( $url, $status, self::PASS, '' );
	}

	/**
	 * Builds one result row.
	 *
	 * @param string $url     The address checked.
	 * @param int    $status  The HTTP status.
	 * @param string $outcome One of the outcome constants.
	 * @param string $detail  What to do about it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row( string $url, int $status, string $outcome, string $detail ): array {
		return [
			'url'     => $url,
			'status'  => $status,
			'outcome' => $outcome,
			'detail'  => $detail,
		];
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
