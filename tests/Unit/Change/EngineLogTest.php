<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use RuntimeException;
use SiteHelm\Change\EngineLog;
use SiteHelm\Tests\TestCase;

/**
 * The note this class produces is what a site's administrator reads after a
 * write failed for a reason nothing predicted. Every guarantee about it is
 * asserted here rather than through the engine, because the engine's own tests
 * are about the branch that calls it, not about the sentence it produces.
 */
final class EngineLogTest extends TestCase {

	public function test_a_note_names_the_class_the_message_and_the_position(): void {
		$note = EngineLog::note( new RuntimeException( 'the upload could not be stored' ) );

		$this->assertStringContainsString( 'RuntimeException', $note );
		$this->assertStringContainsString( 'the upload could not be stored', $note );
		$this->assertStringContainsString( 'EngineLogTest.php', $note );
	}

	/**
	 * The namespace is dropped. `TypeError` and `SiteHelm\Contracts\Whatever`
	 * both read as the name of the thing that went wrong, and the leading path
	 * only pushes the message itself past the width of the column it is shown
	 * in.
	 */
	public function test_a_namespaced_class_is_named_without_its_namespace(): void {
		$note = EngineLog::note( new \SiteHelm\Contracts\OperationException(
			\SiteHelm\Contracts\ErrorCode::ExecutionFailed,
			'refused',
			'retry'
		) );

		$this->assertStringStartsWith( 'OperationException:', $note );
		$this->assertStringNotContainsString( 'SiteHelm\\Contracts', $note );
	}

	/**
	 * A directory path in the message is cut back to the file's own name. The
	 * rest of it says nothing an administrator can act on and is the part of a
	 * failure most likely to end up pasted into a public support thread, where
	 * it names the server's account and directory layout.
	 *
	 * Both separators are covered: the host reports one, and the message may
	 * already carry the other.
	 */
	public function test_directory_paths_in_the_message_are_reduced_to_file_names(): void {
		$note = EngineLog::note(
			new RuntimeException( 'failed to open stream: /home/site42/public_html/wp-content/uploads/photo.jpg' )
		);

		$this->assertStringContainsString( 'photo.jpg', $note );
		$this->assertStringNotContainsString( 'site42', $note );
		$this->assertStringNotContainsString( 'public_html', $note );
	}

	public function test_a_windows_path_in_the_message_is_reduced_too(): void {
		$note = EngineLog::note(
			new RuntimeException( 'could not write C:\\inetpub\\sites\\live\\wp-content\\cache\\entry.tmp' )
		);

		$this->assertStringContainsString( 'entry.tmp', $note );
		$this->assertStringNotContainsString( 'inetpub', $note );
	}

	/**
	 * The note is stored in a text column beside the rest of an audit summary
	 * and rendered in a table cell. A third party's exception can carry a whole
	 * stack trace or a dumped query in its message, and neither belongs in
	 * either place at full length.
	 */
	public function test_a_very_long_message_is_cut_to_the_declared_limit(): void {
		$note = EngineLog::note( new RuntimeException( str_repeat( 'x', 5000 ) ) );

		$this->assertSame( EngineLog::MAX_NOTE_LENGTH, mb_strlen( $note ) );
	}

	/**
	 * An exception thrown with no message at all still has to produce a note
	 * that reads as a sentence, because the class and the position are on their
	 * own enough to find the fault.
	 */
	public function test_a_throwable_with_no_message_still_produces_a_readable_note(): void {
		$note = EngineLog::note( new RuntimeException( '' ) );

		$this->assertStringContainsString( 'RuntimeException: no message', $note );
		$this->assertStringContainsString( 'EngineLogTest.php', $note );
	}
}
