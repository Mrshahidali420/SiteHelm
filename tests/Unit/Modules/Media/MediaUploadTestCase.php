<?php
/**
 * The WordPress fixture every MediaUpload test runs on.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Modules\Media\MediaUpload;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0023: add a client-approved asset to the media library.
 *
 * Split out of MediaUploadTest, which had passed the file-size ceiling, along
 * the seam the tests themselves already had: MediaUploadTest asks what the
 * OPERATION promises and refuses, and MediaSideloadTest asks what reaches, and
 * leaves, the FILESYSTEM. Both need this one fixture, and neither may own a
 * private copy of it — two drifting fakes of the same four core calls is the
 * defect this file exists to make impossible.
 *
 * THE TEMPORARY FILE HERE IS REAL. wp_tempnam() is aliased to a real
 * tempnam(), and wp_delete_file() really unlinks, because the cleanup
 * assertions in MediaSideloadTest are about bytes that were really written and
 * are really gone. A fake that returned a string would make every one of them
 * vacuous.
 */
abstract class MediaUploadTestCase extends TestCase {

	protected MediaUpload $operation;

	/** @var array<int, array<string, mixed>> Every sideload argument set seen. */
	protected array $sideloads = [];

	/** @var array<int, array<string, mixed>> Every wp_insert_attachment call seen. */
	protected array $inserts = [];

	/** @var array<int, string> Every temp path wp_tempnam() handed out. */
	protected array $tempFiles = [];

	/** @var array<int, string> Every path wp_delete_file() was asked to remove. */
	protected array $deleted = [];

	/** @var array<string, mixed> Post meta written during a test. */
	protected array $meta = [];

	/** @var bool Whether wp_handle_sideload() should report a failure. */
	protected bool $sideloadFails = false;

	protected function setUp(): void {
		parent::setUp();

		$this->sideloads     = [];
		$this->inserts       = [];
		$this->tempFiles     = [];
		$this->deleted       = [];
		$this->meta          = [];
		$this->sideloadFails = false;

		$fields          = new MediaFields();
		$this->operation = new MediaUpload( $fields, new MediaTarget( $fields ), new MediaMimeGuard( $fields ) );

		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_slash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			]
		);
		Functions\when( 'wp_get_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'svg'          => 'image/svg+xml',
				'htm|html'     => 'text/html',
			]
		);
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			static function ( string $file, string $filename, $mimes = null ): array {
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				foreach ( (array) $mimes as $pattern => $mime ) {
					if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
						return [
							'ext'             => $extension,
							'type'            => $mime,
							'proper_filename' => false,
						];
					}
				}

				return [
					'ext'             => false,
					'type'            => false,
					'proper_filename' => false,
				];
			}
		);

		$this->stubWritePath();
		$this->stubStoredAttachment();
	}

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Fakes the four core calls the write path makes, using a REAL temporary
	 * file. Faking wp_tempnam() with a string would make the temp-file cleanup
	 * assertion vacuous — the whole point of that test is that bytes written to
	 * a real path are really gone afterwards.
	 */
	private function stubWritePath(): void {
		Functions\when( 'wp_tempnam' )->alias(
			function ( string $filename = '' ): string {
				$path              = (string) tempnam( sys_get_temp_dir(), 'sitehelm-upload-' );
				$this->tempFiles[] = $path;

				return $path;
			}
		);

		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ): void {
				$this->deleted[] = $path;
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);

		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ): array {
				$this->sideloads[] = [
					'file'      => $file,
					'overrides' => $overrides,
				];

				if ( $this->sideloadFails ) {
					return [ 'error' => 'Sorry, you are not allowed to upload this file type.' ];
				}

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/holiday-1.png',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/holiday-1.png',
					'type' => 'image/png',
				];
			}
		);

		Functions\when( 'wp_insert_attachment' )->alias(
			function ( array $attachment, $file = false, $parent = 0, $wp_error = false ): int {
				$this->inserts[] = [
					'attachment' => $attachment,
					'file'       => $file,
					'parent'     => $parent,
				];

				return 512;
			}
		);

		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [ 'width' => 1 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $key ] ?? ''
		);
	}

	/**
	 * The persisted attachment, as MediaFields::read() re-reads it during
	 * readBack(). The filename is UNIQUIFIED — `holiday-1.png` where the caller
	 * asked for `holiday.png` — because that routine collision is exactly what
	 * must not read as an adjustment.
	 */
	private function stubStoredAttachment(): void {
		$post                    = new stdClass();
		$post->ID                = 512;
		$post->post_type         = 'attachment';
		$post->post_mime_type    = 'image/png';
		$post->post_title        = 'Holiday photo';
		$post->post_excerpt      = 'On the beach.';
		$post->post_content      = 'A long description.';
		$post->post_parent       = 0;
		$post->post_status       = 'inherit';
		$post->post_date_gmt     = '2026-08-02 09:00:00';
		$post->post_modified_gmt = '2026-08-02 09:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/html/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'file'   => '2026/08/holiday-1.png',
				'width'  => 1,
				'height' => 1,
				'sizes'  => [],
			]
		);
		Functions\when( 'wp_basename' )->alias( static fn( string $p ): string => basename( $p ) );
		// MediaTarget::verifyRead() drops the post cache before re-reading, so
		// the read-back sees the row the write just made rather than a stale one.
		Functions\when( 'clean_post_cache' )->justReturn( null );
		// MediaFields::read() calls wp_filesize() alongside wp_basename() on
		// every path where get_attached_file() answers a non-empty string;
		// without it the test fatals on an undefined function.
		Functions\when( 'wp_filesize' )->justReturn( 0 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-upload-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	protected function pngBase64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> A complete upload payload.
	 */
	protected function input( array $overrides = [] ): array {
		return array_merge(
			[
				'filename'      => 'holiday.png',
				'contentBase64' => $this->pngBase64(),
				'title'         => 'Holiday photo',
				'alt'           => 'A beach at sunset',
				'caption'       => 'On the beach.',
				'description'   => 'A long description.',
			],
			$overrides
		);
	}
}
