<?php
/**
 * The hook-registration half of the MediaFetch WordPress stand-in (REQ-0052).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;

/**
 * A miniature `add_filter`/`add_action` registry, and the replay order core uses.
 *
 * Split out of MediaFetchTestCase because that file reached the 800-line ceiling
 * this project holds every file to, and this is the seam: everything here is
 * about WordPress's hook BOOKKEEPING — who registered what, at what priority,
 * and what is left registered afterwards — while what remains in the test case is
 * about the HTTP transport those hooks are fired from.
 *
 * Brain Monkey does not run a hook system, so registrations are recorded here and
 * the transport fake replays them. Recording the priority alongside the hook name
 * is what lets a test assert that what was added is what was taken away, and what
 * makes "this class registers at PHP_INT_MAX so it has the last word" testable at
 * all.
 */
trait FakesWordPressHooks {

	/**
	 * Every add_filter/add_action call the class made, as [ hook, priority ].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	protected array $added = [];

	/**
	 * Every remove_filter/remove_action call the class made, as [ hook, priority ].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	protected array $removed = [];

	/**
	 * The `http_request_args` callbacks registered, as [ priority, callback ].
	 *
	 * @var array<int, array{0: int, 1: callable}>
	 */
	protected array $requestArgFilters = [];

	/**
	 * The `http_api_curl` callbacks registered, as [ priority, callback ].
	 *
	 * @var array<int, array{0: int, 1: callable}>
	 */
	protected array $curlActions = [];

	/**
	 * Installs the four registration fakes and clears what they record.
	 *
	 * Only registrations made as [ $object, 'method' ] are booked into
	 * $added/$removed, and that filter is the leak assertion's whole meaning.
	 * Tests register their own spies as closures; counting those would make
	 * leakedHooks() report a "leak" for every test that watched anything, so the
	 * assertion would have to be loosened to tolerate it and would then no longer
	 * be an assertion about the class under test at all. The class only ever
	 * registers [ $this, 'method' ], so this leaves exactly its own registrations
	 * in view.
	 */
	protected function fakeHookRegistration(): void {
		$this->added             = [];
		$this->removed           = [];
		$this->requestArgFilters = [];
		$this->curlActions       = [];

		$isOwn = static function ( $callback ): bool {
			return is_array( $callback );
		};

		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) use ( $isOwn ) {
				if ( $isOwn( $callback ) ) {
					$this->added[] = [ $hook, $priority ];
				}

				if ( 'http_request_args' === $hook ) {
					$this->requestArgFilters[] = [ $priority, $callback ];
				}

				return true;
			}
		);

		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) use ( $isOwn ) {
				if ( $isOwn( $callback ) ) {
					$this->removed[] = [ $hook, $priority ];
				}

				if ( 'http_request_args' === $hook ) {
					$this->requestArgFilters = $this->withoutCallback( $this->requestArgFilters, $callback, $priority );
				}

				return true;
			}
		);

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) use ( $isOwn ) {
				if ( $isOwn( $callback ) ) {
					$this->added[] = [ $hook, $priority ];
				}

				if ( 'http_api_curl' === $hook ) {
					$this->curlActions[] = [ $priority, $callback ];
				}

				return true;
			}
		);

		Functions\when( 'remove_action' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) use ( $isOwn ) {
				if ( $isOwn( $callback ) ) {
					$this->removed[] = [ $hook, $priority ];
				}

				if ( 'http_api_curl' === $hook ) {
					$this->curlActions = $this->withoutCallback( $this->curlActions, $callback, $priority );
				}

				return true;
			}
		);
	}

	/**
	 * The registrations with one callback removed, the way WordPress removes it.
	 *
	 * ONE callback, matched on identity AND priority — not every callback on the
	 * hook, which is what an earlier version of this fake did. Clearing the whole
	 * list made "the class removed its hook" indistinguishable from "the class
	 * removed SOMETHING and everything went", and left the test's own spies
	 * silently unregistered as a side effect.
	 *
	 * @param array<int, array{0: int, 1: callable}> $callbacks The registrations.
	 * @param mixed                                  $callback  The callback to remove.
	 * @param int                                    $priority  The priority it was registered at.
	 *
	 * @return array<int, array{0: int, 1: callable}> The registrations that remain.
	 */
	private function withoutCallback( array $callbacks, $callback, int $priority ): array {
		foreach ( $callbacks as $at => $registered ) {
			if ( $registered[0] === $priority && $registered[1] === $callback ) {
				unset( $callbacks[ $at ] );

				break;
			}
		}

		return array_values( $callbacks );
	}

	/**
	 * Applies the registered `http_request_args` filters in priority order.
	 *
	 * @param array<string, mixed> $args The arguments so far.
	 * @param string               $url  The URL of the request being prepared.
	 *
	 * @return array<string, mixed> The filtered arguments.
	 */
	protected function applyRequestArgFilters( array $args, string $url ): array {
		foreach ( $this->orderedByPriority( $this->requestArgFilters ) as $filter ) {
			$args = $filter( $args, $url );
		}

		return $args;
	}

	/**
	 * Sorts [ priority, callback ] pairs the way apply_filters runs them.
	 *
	 * Ascending, and stable, so equal priorities keep registration order — which
	 * is what WordPress does and what makes "registered at PHP_INT_MAX" mean
	 * something.
	 *
	 * @param array<int, array{0: int, 1: callable}> $callbacks The registrations.
	 *
	 * @return array<int, callable> The callbacks, in the order they should run.
	 */
	protected function orderedByPriority( array $callbacks ): array {
		usort(
			$callbacks,
			static function ( array $a, array $b ): int {
				return $a[0] <=> $b[0];
			}
		);

		return array_column( $callbacks, 1 );
	}

	/**
	 * Every hook this class added, minus every hook it removed.
	 *
	 * @return array<int, array{0: string, 1: int}> The leaked registrations.
	 */
	protected function leakedHooks(): array {
		$leaked = $this->added;

		foreach ( $this->removed as $gone ) {
			$at = array_search( $gone, $leaked, true );

			if ( false !== $at ) {
				unset( $leaked[ $at ] );
			}
		}

		return array_values( $leaked );
	}
}
