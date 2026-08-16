<?php
/**
 * Tests for what MediaUpload's sideload puts on disk, and takes off it again.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0023, the filesystem half: MediaSideload::store() as MediaUpload drives it.
 *
 * WHAT THIS FILE IS ABOUT is the bytes. That the reviewed content, and nothing
 * else, reaches the temporary file; that the temporary file is gone afterwards
 * on EVERY path out, success and failure alike; and that when core refuses —
 * the sideload, the insert, or the temporary path itself — the refusal says so
 * without naming a path, a directory or core's own error string. An orphan file
 * left behind by a refused import is a real one, on a real site, that nobody
 * knows to go and delete.
 *
 * WHAT IT IS NOT ABOUT is what the operation PROMISES: the definition, the
 * schema, the planned payload and the plan/apply coupling are MediaUploadTest's,
 * and both files share MediaUploadTestCase's single fixture rather than each
 * faking core's four write calls separately.
 *
 * Every refusal is asserted on its specific ErrorCode inside a try/catch. Every
 * refusal in this codebase is OperationException, so a bare expectException()
 * would pass on a completely different refusal than the one the test aimed at.
 */
final class MediaSideloadTest extends MediaUploadTestCase {

	public function test_apply_change_writes_the_validated_bytes_to_the_temporary_file(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$seen = null;
		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ) use ( &$seen ): array {
				$seen = (string) file_get_contents( $file['tmp_name'] );

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/holiday-1.png',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/holiday-1.png',
					'type' => 'image/png',
				];
			}
		);

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( (string) base64_decode( $this->pngBase64(), true ), $seen );
	}

	public function test_the_temporary_file_is_removed_after_a_successful_upload(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertCount( 1, $this->tempFiles );
		$this->assertSame( $this->tempFiles, $this->deleted );
		$this->assertFileDoesNotExist( $this->tempFiles[0] );
	}

	public function test_a_failed_sideload_leaves_no_bytes_behind(): void {
		$this->sideloadFails = true;

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() reported success for a failed sideload.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}

		$this->assertCount( 1, $this->tempFiles );
		$this->assertFileDoesNotExist(
			$this->tempFiles[0],
			'A failed sideload must not leave the uploaded bytes on disk.'
		);
		$this->assertSame( [], $this->inserts );
	}

	public function test_a_failed_sideload_does_not_leak_the_core_error_or_a_path(): void {
		$this->sideloadFails = true;

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() reported success for a failed sideload.' );
		} catch ( OperationException $failure ) {
			$text = $failure->getMessage() . ' ' . (string) $failure->remediation;

			$this->assertDoesNotMatchRegularExpression( '#(/|\\\\|wp-content|uploads|[A-Za-z]:\\\\)#', $text );
			$this->assertStringNotContainsString( 'not allowed to upload', $text );
		}
	}

	/**
	 * The insert failure branch: WordPress stored the file but refused the row.
	 *
	 * The completed-step list must say so, because the operator's next action
	 * depends on it — there is now an orphan file in the uploads directory that
	 * no attachment references.
	 */
	public function test_a_refused_attachment_insert_reports_that_the_content_was_already_stored(): void {
		Functions\when( 'wp_insert_attachment' )->justReturn( 0 );

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() reported success for a refused insert.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
			$this->assertSame( [ 'plan approved', 'content stored' ], $failure->completedSteps );
			$this->assertDoesNotMatchRegularExpression(
				'#(/|\\\\|wp-content|uploads|[A-Za-z]:\\\\)#',
				$failure->getMessage() . ' ' . (string) $failure->remediation
			);
		}

		$this->assertFileDoesNotExist( $this->tempFiles[0] );
	}

	/**
	 * The temp-storage failure branch. wp_tempnam() answering falsely means the
	 * site has no writable temporary directory, and nothing may be attempted.
	 *
	 * @dataProvider unusableTempResults
	 *
	 * @param mixed $result What wp_tempnam() returns.
	 */
	public function test_an_unusable_temporary_path_is_refused_before_anything_is_written( $result ): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		Functions\when( 'wp_tempnam' )->justReturn( $result );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() proceeded without a usable temporary path.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
			$this->assertDoesNotMatchRegularExpression(
				'#(/|\\\\|wp-content|uploads|[A-Za-z]:\\\\)#',
				$failure->getMessage() . ' ' . (string) $failure->remediation
			);
		}

		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->deleted, 'There is no path to delete, so nothing may be deleted.' );
	}

	/**
	 * Both operands of the temporary-path guard, because each is separately
	 * reachable: core's wp_tempnam() is documented to return a string but is
	 * filterable, and a filter may answer with either.
	 *
	 * @return array<string, array{0: mixed}> The unusable results.
	 */
	public static function unusableTempResults(): array {
		return [
			'a false result'  => [ false ],
			'an empty string' => [ '' ],
		];
	}

	/**
	 * The write-failure branch. A temporary path inside a directory that does not
	 * exist makes file_put_contents() fail for real, rather than being faked.
	 *
	 * The E_WARNING that PHP raises for the failed open is expected, and is
	 * swallowed for the duration of the call only. PHPUnit promotes warnings to
	 * errors, so without this the test could not observe the guard it exists to
	 * pin; suppressing it in production instead would hide a genuine filesystem
	 * problem from the site's own log.
	 */
	public function test_content_that_cannot_be_written_to_temporary_storage_is_refused_before_the_sideload(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$unwritable = sys_get_temp_dir() . '/sitehelm-absent-' . uniqid() . '/upload.png';
		Functions\when( 'wp_tempnam' )->justReturn( $unwritable );

		set_error_handler( static fn (): bool => true, E_WARNING );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() sideloaded content it never managed to write.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
			$this->assertDoesNotMatchRegularExpression(
				'#(/|\\\\|wp-content|uploads|[A-Za-z]:\\\\)#',
				$failure->getMessage() . ' ' . (string) $failure->remediation
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $this->sideloads, 'Nothing may be sideloaded when the bytes never landed.' );
		$this->assertSame( [ $unwritable ], $this->deleted, 'The cleanup still runs for a path that was claimed.' );
	}
}
