<?php
/**
 * The registered-callback bag WordPress keeps in $wp_filter.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * A stand-in for WP_Hook, aliased to that name by tests/bootstrap.php.
 *
 * ONLY THE SHAPE MATTERS. `ForeignNotices` reads one public property and calls
 * nothing, so this double carries that property and the `instanceof` identity
 * the class guards on, and nothing else. A fuller reimplementation of WP_Hook
 * would be a second thing to keep correct without a second thing being tested.
 *
 * The nesting is WordPress's own: priority, then an arbitrary key, then an
 * array with a `function` member. A double that flattened it would let code
 * that misreads the real structure keep passing.
 */
final class FakeWpHook {

	/**
	 * Registered callbacks, keyed by priority and then by an arbitrary id.
	 *
	 * @var array<int, array<string, array{function: mixed, accepted_args: int}>>
	 */
	public array $callbacks = [];

	/**
	 * Registers a callback at a priority.
	 *
	 * @param mixed $callback The callback.
	 * @param int   $priority The priority.
	 */
	public function add( mixed $callback, int $priority = 10 ): void {
		$this->callbacks[ $priority ][ 'id' . count( $this->callbacks[ $priority ] ?? [] ) ] = [
			'function'      => $callback,
			'accepted_args' => 1,
		];
	}
}
