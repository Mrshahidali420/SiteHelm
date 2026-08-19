<?php
/**
 * The installer guard that only fires when WordPress has not booted.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * `dbDelta()` LIVES IN AN ADMIN INCLUDE, so the installer loads it on demand —
 * and before it can, it checks that `ABSPATH` exists to load it from. A deletion
 * sweep found that check unpinned, and unpinnable by the suite beside it: every
 * test in `InstallerTest` fakes `dbDelta` in `setUp()`, so the early return above
 * this guard is taken every time and the guard is never reached at all.
 *
 * Delete it and the next line is `require_once ABSPATH . '…'` against a constant
 * that does not exist. What is a clean `false` — storage unavailable, the plugin
 * degraded but alive, exactly the containment this class documents — becomes an
 * uncaught `Error` on whatever request got there first.
 *
 * That request is real. Activation hooks, WP-CLI, and anything that reaches this
 * code before `wp-load.php` has finished all arrive without `ABSPATH`, which is
 * why the check is written before the include rather than after it.
 *
 * The one test below pins TWO guards, because they are one decision written in
 * two places: `install()` asks whether the schema API is loaded before it uses
 * it, and the answer is only reachable through the `ABSPATH` check. Disabling
 * either one reaches `dbDelta()` or `ABSPATH` undefined, and both were unpinned
 * for the same reason — the suite beside them never lacked either.
 *
 * THE ISOLATION IS THE TECHNIQUE. Both conditions are one-way inside a PHP
 * process: Brain Monkey cannot un-define `dbDelta` once another test has defined
 * it, and no constant can be un-defined at all. So this runs in its own process,
 * and asserts both preconditions before asserting anything else — a test that
 * silently lost either of them would pass while proving nothing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class InstallerSchemaApiTest extends TestCase {

	public function test_an_install_without_abspath_records_unavailable_instead_of_failing_fatally(): void {
		$this->assertFalse(
			function_exists( 'dbDelta' ),
			'This test is about the path where the schema API is absent; something has defined it.'
		);
		$this->assertFalse(
			defined( 'ABSPATH' ),
			'This test is about a request WordPress has not booted; something has defined ABSPATH.'
		);

		$options         = [];
		$GLOBALS['wpdb'] = new FakeWpdb();

		// The child process talks to PHPUnit over stdout, and the installer's own
		// log line — the correct behaviour on this path — would corrupt it.
		$log      = (string) tempnam( sys_get_temp_dir(), 'sitehelm-log-' );
		$previous = (string) ini_get( 'error_log' );

		ini_set( 'error_log', $log );

		Functions\when( 'update_option' )->alias(
			function ( string $key, mixed $value, mixed $autoload = null ) use ( &$options ): bool {
				unset( $autoload );
				$options[ $key ] = $value;

				return true;
			}
		);

		try {
			$installed = ( new Installer() )->install();
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
			unset( $GLOBALS['wpdb'] );
		}

		$this->assertFalse( $installed, 'An install with no schema API must report failure, not success.' );
		$this->assertSame(
			Installer::STATUS_UNAVAILABLE,
			$options[ Installer::STATUS_OPTION ] ?? null,
			'The failure must be recorded, so later requests know the change surfaces are down.'
		);
	}
}
