<?php
/**
 * A $wpdb double holding another plugin's redirections table.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for $wpdb where the table under test belongs to another plugin.
 *
 * THIS DOUBLE ANSWERS THE QUERY RATHER THAN A QUEUE, unlike FakeWpdb. A queue is
 * consumed in call order, so a test written against one passes for a lookup that
 * asked the wrong question in the right sequence — and this lookup asks two
 * questions per call, one of which decides whether the second is asked at all. A
 * double that actually reads SHOW TABLES and honours LIMIT can only be satisfied
 * by code that queries correctly.
 *
 * WHEN THE TABLE IS NOT INSTALLED IT REPORTS SO, which is the ordinary case: most
 * sites do not have the other plugin, and the lookup must cost one cheap query
 * and stop.
 */
final class FakeRedirectionsDb {

	/**
	 * The table prefix, matching WordPress's default.
	 */
	public string $prefix = 'wp_';

	/**
	 * Whether the redirections table exists on this site.
	 */
	public bool $installed = true;

	/**
	 * The rows the table holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every query run, in order.
	 *
	 * @var array<int, string>
	 */
	public array $queries = [];

	/**
	 * Binds values into a query the way WordPress does.
	 *
	 * @param string $query   The SQL with placeholders.
	 * @param mixed  ...$args The values to bind.
	 *
	 * @return string The bound SQL.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Answers the table-existence probe.
	 *
	 * @param string $query The SQL to run.
	 *
	 * @return mixed The table name when it exists, null otherwise.
	 */
	public function get_var( string $query ): mixed {
		$this->queries[] = $query;

		if ( ! str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
			return null;
		}

		return $this->installed ? $this->prefix . 'rank_math_redirections' : null;
	}

	/**
	 * Answers a row query, honouring its LIMIT.
	 *
	 * @param string $query  The SQL to run.
	 * @param string $output The requested output shape; ignored by the double.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function get_results( string $query, string $output = 'ARRAY_A' ): array {
		$this->queries[] = $query;

		if ( ! $this->installed ) {
			return [];
		}

		$rows = $this->rows;

		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$rows = array_slice( $rows, 0, (int) $limit[1] );
		}

		return $rows;
	}

	/**
	 * One stored row, with its sources serialised the way the other plugin stores them.
	 *
	 * @param array<int, array{0: string, 1: string}> $sources    Pattern and comparison pairs.
	 * @param string                                  $target     Where the rule sends the visitor.
	 * @param int                                     $status     The status it answers with.
	 * @param bool                                    $active     Whether it is switched on.
	 *
	 * @return array<string, mixed> The row.
	 */
	public static function row( array $sources, string $target = '/replacement', int $status = 301, bool $active = true ): array {
		return [
			'sources'     => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the other plugin's stored column format is what this double exists to reproduce.
				array_map(
					static fn( array $pair ): array => [
						'pattern'    => $pair[0],
						'comparison' => $pair[1],
					],
					$sources
				)
			),
			'url_to'      => $target,
			'header_code' => $status,
			'status'      => $active ? 'active' : 'inactive',
		];
	}
}
