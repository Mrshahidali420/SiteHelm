<?php
/**
 * A WP_Query stand-in for content listing unit tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Brain Monkey fakes functions, and WP_Query is a class, so the one WordPress
 * class the listing operation instantiates gets a hand-written double in the
 * style of FakeWpdb. A test aliases it onto the global WP_Query name, queues
 * the rows and unpaginated total one query should report, and afterwards reads
 * back the arguments the operation actually asked for.
 */
final class FakeWpQuery {

	/** @var array<int, array<string, mixed>> Every argument set handed to this double, in order. */
	public static array $calls = [];

	/** @var object[] The post-shaped rows the next constructed query reports. */
	public static array $rows = [];

	/** @var int The unpaginated match count the next constructed query reports. */
	public static int $foundPosts = 0;

	/** @var object[] The rows this query reported. */
	public array $posts = [];

	/** @var int The unpaginated match count this query reported. */
	public int $found_posts = 0;

	/**
	 * Records the arguments and replays the queued result.
	 *
	 * @param array<string, mixed> $args The query arguments.
	 */
	public function __construct( array $args = [] ) {
		self::$calls[]     = $args;
		$this->posts       = self::$rows;
		$this->found_posts = self::$foundPosts;
	}

	/**
	 * Clears recorded calls and queued results between tests.
	 */
	public static function reset(): void {
		self::$calls      = [];
		self::$rows       = [];
		self::$foundPosts = 0;
	}
}
