<?php
/**
 * Server-side logging for change engine failures.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use Throwable;

/**
 * The one place the change engine's server-side failure message is formed.
 *
 * Both the engine and the snapshot lifecycle report unexpected failures. Two
 * copies of the message would be two things to keep in step, so it lives here
 * once.
 *
 * @package SiteHelm
 */
final class EngineLog {

	/**
	 * Logs an unexpected failure server-side.
	 *
	 * The message never reaches the client, so it may carry technical detail;
	 * nothing derived from it is placed in an envelope.
	 *
	 * @param Throwable $failure The failure to log.
	 *
	 * @return void
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	public static function unexpected( Throwable $failure ): void {
		error_log( sprintf( 'SiteHelm change engine failure: %s', $failure->getMessage() ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
