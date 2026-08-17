<?php
/**
 * A WP_User_Query stand-in for the user listing unit tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * A hand-written double in the style of FakeWpQuery.
 *
 * The recorded arguments are the point of this double as much as the replayed
 * rows: the listing operation's search behaviour lives entirely in the arguments
 * it builds — the wildcards and the explicit `search_columns` that stop an
 * @-containing term becoming an exact email match — and none of that is visible
 * in the returned page. Reading `$calls` back is the only way to pin it.
 */
final class FakeWpUserQuery {

	/** @var array<int, array<string, mixed>> Every argument set handed to this double, in order. */
	public static array $calls = [];

	/** @var object[] The user-shaped rows the next constructed query reports. */
	public static array $rows = [];

	/** @var int The unpaginated match count the next constructed query reports. */
	public static int $total = 0;

	/** @var object[] The rows this query reported. */
	private array $results = [];

	/** @var int The unpaginated match count this query reported. */
	private int $found = 0;

	/**
	 * Records the arguments and replays the queued result.
	 *
	 * @param array<string, mixed> $args The query arguments.
	 */
	public function __construct( array $args = [] ) {
		self::$calls[] = $args;
		$this->results = self::$rows;
		$this->found   = self::$total;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The method names are WordPress's own.

	/**
	 * The rows this query matched.
	 *
	 * @return object[] The user-shaped rows.
	 */
	public function get_results(): array {
		return $this->results;
	}

	/**
	 * The unpaginated match count.
	 *
	 * @return int The total.
	 */
	public function get_total(): int {
		return $this->found;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Clears recorded calls and queued results between tests.
	 */
	public static function reset(): void {
		self::$calls = [];
		self::$rows  = [];
		self::$total = 0;
	}
}
