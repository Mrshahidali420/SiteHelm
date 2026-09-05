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
	 * The most characters of a failure note kept for the audit row.
	 */
	public const MAX_NOTE_LENGTH = 300;

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
		error_log( sprintf( 'SiteHelm change engine failure: %s', self::note( $failure ) ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * What an unexpected failure was, in one line an administrator can act on.
	 *
	 * The engine used to promise that "the details were logged on the server"
	 * and then write them only to PHP's error log, which on most hosting is a
	 * file the site owner cannot reach and on some is not written at all. So a
	 * write could fail and leave nobody, anywhere, able to find out why. This
	 * note is the same text, in a place the plugin owns: it goes on the audit
	 * row, and the Activity screen shows it beside the failed entry.
	 *
	 * It is never returned to the caller. The class name, the message and the
	 * file and line are exactly the detail the envelope withholds on purpose,
	 * and an administrator reading their own site's activity log is a different
	 * audience from a client holding an access token.
	 *
	 * Directory paths are cut back to the file's own name. The full path adds
	 * nothing an administrator needs and is the one part of a failure most
	 * likely to be pasted into a public support thread.
	 *
	 * @param Throwable $failure The failure to describe.
	 *
	 * @return string The one-line note.
	 */
	public static function note( Throwable $failure ): string {
		$message = self::without_paths( $failure->getMessage() );
		$note    = sprintf(
			'%s: %s (%s:%d)',
			self::short_class( $failure ),
			'' === $message ? 'no message' : $message,
			basename( $failure->getFile() ),
			$failure->getLine()
		);

		if ( strlen( $note ) <= self::MAX_NOTE_LENGTH ) {
			return $note;
		}

		return substr( $note, 0, self::MAX_NOTE_LENGTH - 1 ) . '…';
	}

	/**
	 * The failure's class without its namespace.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return string The short class name.
	 */
	private static function short_class( Throwable $failure ): string {
		$class    = $failure::class;
		$position = strrpos( $class, '\\' );

		return false === $position ? $class : substr( $class, $position + 1 );
	}

	/**
	 * A message with any directory paths in it reduced to file names.
	 *
	 * Both separators are handled because a Windows host reports one and the
	 * message may already carry the other.
	 *
	 * @param string $message The message as thrown.
	 *
	 * @return string The message with paths shortened.
	 */
	private static function without_paths( string $message ): string {
		$message = (string) preg_replace(
			'#(?:[A-Za-z]:)?[/\\\\][^\s:*?"<>|]*[/\\\\]([^\s/\\\\:*?"<>|]+)#',
			'$1',
			$message
		);

		return trim( preg_replace( '/\s+/', ' ', $message ) ?? '' );
	}
}
