<?php
/**
 * Tests for MediaUpload (REQ-0023).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Modules\Media\MediaUpload;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0023: add a client-approved asset to the media library.
 */
final class MediaUploadTest extends TestCase {

	private MediaUpload $operation;

	/** @var array<int, array<string, mixed>> Every sideload argument set seen. */
	private array $sideloads = [];

	/** @var array<int, array<string, mixed>> Every wp_insert_attachment call seen. */
	private array $inserts = [];

	/** @var array<int, string> Every temp path wp_tempnam() handed out. */
	private array $tempFiles = [];

	/** @var array<int, string> Every path wp_delete_file() was asked to remove. */
	private array $deleted = [];

	/** @var array<string, mixed> Post meta written during a test. */
	private array $meta = [];

	/** @var bool Whether wp_handle_sideload() should report a failure. */
	private bool $sideloadFails = false;

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

	private function makeContext(): OperationContext {
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

	private function pngBase64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> A complete upload payload.
	 */
	private function input( array $overrides = [] ): array {
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

	public function test_the_definition_declares_the_matrix_row_for_req_0023(): void {
		$definition = MediaUpload::definition();

		$this->assertSame( 'media-upload', $definition->id );
		$this->assertSame( Domain::Media, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent, 'Each apply creates a new attachment.' );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
	}

	public function test_the_input_schema_is_closed_and_declares_no_mime_type_property(): void {
		$schema = MediaUpload::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'filename', 'contentBase64' ], $schema['required'] );
		$this->assertArrayNotHasKey(
			'mimeType',
			$schema['properties'],
			'A client-declared MIME type is a second source of truth that can disagree with the bytes.'
		);
		$this->assertSame( 255, $schema['properties']['filename']['maxLength'] );
		$this->assertSame(
			MediaMimeGuard::MAX_BASE64_LENGTH,
			$schema['properties']['contentBase64']['maxLength'],
			'The schema bound is what stops an unbounded blob before it is ever decoded.'
		);
	}

	public function test_resolve_target_is_the_stable_pending_key(): void {
		$state = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertSame( 'attachment:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_capture_snapshot_is_null_because_a_creation_has_no_prior_state(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
	}

	public function test_capture_snapshot_is_side_effect_free_and_repeatable(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_an_upload_cannot_be_rolled_back(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->restore( [ 'post_id' => 512 ], $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertDoesNotMatchRegularExpression(
				'#(/|\\\\|wp-content|uploads|[A-Za-z]:)#',
				$refusal->getMessage() . ' ' . (string) $refusal->remediation
			);

			throw $refusal;
		}
	}

	public function test_plan_change_promises_the_sniffed_type_and_every_named_text_field(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			[
				'mimeType'    => 'image/png',
				'title'       => 'Holiday photo',
				'alt'         => 'A beach at sunset',
				'caption'     => 'On the beach.',
				'description' => 'A long description.',
			],
			$planned->afterFields
		);
	}

	public function test_plan_change_never_promises_a_field_the_payload_did_not_name(): void {
		$input   = [
			'filename'      => 'holiday.png',
			'contentBase64' => $this->pngBase64(),
		];
		$current = $this->operation->resolveTarget( $input, $this->makeContext() );
		$planned = $this->operation->planChange( $current, $input, $this->makeContext() );

		$this->assertSame( [ 'mimeType' => 'image/png' ], $planned->afterFields );
	}

	public function test_the_filename_is_deliberately_not_promised(): void {
		// WordPress uniquifies on collision. Promising the filename would make
		// every collision an adjustment and emit a warning on a routine event.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertArrayNotHasKey( 'filename', $planned->afterFields );
		$this->assertArrayNotHasKey( 'parent', $planned->afterFields );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_uniquified_filename_is_disclosed_in_the_read_back_state_and_produces_no_warning(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$key     = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$state = $this->operation->readBack( $key, $this->makeContext() );

		$this->assertSame( 'holiday.png', $planned->payload['filename'], 'The caller asked for holiday.png.' );
		$this->assertSame( 'holiday-1.png', $state->fields['filename'], 'WordPress stored a uniquified name.' );
		$this->assertSame( [], $planned->warnings );
		$this->assertArrayNotHasKey( 'filename', $planned->afterFields );
	}

	public function test_plan_change_writes_nothing_to_disk(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( [], $this->tempFiles, 'planChange() runs at preview and must not create a file.' );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_the_planned_payload_carries_a_content_hash_and_never_the_raw_bytes(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			hash( 'sha256', (string) base64_decode( $this->pngBase64(), true ) ),
			$planned->payload['contentSha256']
		);

		foreach ( $planned->payload as $key => $value ) {
			$this->assertTrue(
				! is_string( $value ) || '' === $value || false !== json_encode( $value ),
				sprintf( "Payload member '%s' is not JSON-encodable, which would collapse the payload fingerprint.", $key )
			);
		}
	}

	public function test_two_different_uploads_do_not_share_a_payload_fingerprint(): void {
		// The defect this guards: PayloadNormalizer canonicalises with
		// wp_json_encode, which returns false for non-UTF-8. Raw bytes in the
		// payload would make every upload hash identically, and the change
		// engine would then accept any upload against any upload plan.
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, int $options = 0 ) => json_encode( $data, $options )
		);

		$normalizer = new PayloadNormalizer();
		$current    = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$gif = base64_encode( "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;" );

		$first  = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$second = $this->operation->planChange(
			$current,
			$this->input(
				[
					'filename'      => 'other.gif',
					'contentBase64' => $gif,
				]
			),
			$this->makeContext()
		);

		$this->assertNotSame( '', $normalizer->canonicalJson( $first->payload ) );
		$this->assertNotSame(
			$normalizer->fingerprint( $first->payload ),
			$normalizer->fingerprint( $second->payload ),
			'Two different uploads must not fingerprint identically.'
		);
	}

	public function test_apply_change_sideloads_without_the_form_test_and_returns_the_real_target_key(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$key = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'attachment:512', $key );
		$this->assertCount( 1, $this->sideloads );
		$this->assertSame( [ 'test_form' => false ], $this->sideloads[0]['overrides'] );
		$this->assertSame( 'holiday.png', $this->sideloads[0]['file']['name'] );
		$this->assertSame( 'image/png', $this->sideloads[0]['file']['type'] );
		$this->assertSame( 'inherit', $this->inserts[0]['attachment']['post_status'] );
		$this->assertSame( 'A beach at sunset', $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	/**
	 * The `?? ''` trap, in creation clothing.
	 *
	 * A caller who names `alt` with an empty string is describing a decorative
	 * image and is asking for empty alternative text to be stored. A caller who
	 * omits `alt` is asking for nothing to be written. Gating the meta write on
	 * the VALUE rather than on the presence of the KEY collapses those two
	 * different requests into one and silently discards the first.
	 */
	public function test_an_explicitly_empty_alt_text_is_stored_rather_than_skipped(): void {
		$input   = $this->input( [ 'alt' => '' ] );
		$current = $this->operation->resolveTarget( $input, $this->makeContext() );
		$planned = $this->operation->planChange( $current, $input, $this->makeContext() );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertArrayHasKey( MediaFields::ALT_META_KEY, $this->meta );
		$this->assertSame( '', $this->meta[ MediaFields::ALT_META_KEY ] );
		$this->assertSame( '', $planned->afterFields['alt'] );
	}

	public function test_an_omitted_alt_text_writes_no_meta_at_all(): void {
		$input   = [
			'filename'      => 'holiday.png',
			'contentBase64' => $this->pngBase64(),
		];
		$current = $this->operation->resolveTarget( $input, $this->makeContext() );
		$planned = $this->operation->planChange( $current, $input, $this->makeContext() );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( [], $this->meta, 'A field the caller never named must not be touched.' );
	}

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
			'a false result'      => [ false ],
			'an empty string'     => [ '' ],
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

	public function test_apply_change_refuses_when_the_bytes_it_holds_do_not_match_the_approved_plan(): void {
		// The coupling between planChange() and applyChange() is verified, not
		// assumed. An applyChange() reached without a matching planChange()
		// writes nothing at all.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$tampered = new PlannedChange(
			array_merge( $planned->payload, [ 'contentSha256' => str_repeat( 'a', 64 ) ] ),
			$planned->afterFields,
			$planned->fieldOrder
		);

		try {
			$this->operation->applyChange( $current, $tampered, $this->makeContext() );
			$this->fail( 'applyChange() wrote a file whose content did not match the approved plan.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}

		$this->assertSame( [], $this->tempFiles, 'Nothing may be written before the content check passes.' );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	/**
	 * The other operand of the same guard: an applyChange() that was never
	 * preceded by a planChange() holds no bytes at all.
	 */
	public function test_apply_change_refuses_when_it_holds_no_bytes_at_all(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = new PlannedChange(
			[
				'byteLength'    => 70,
				'contentSha256' => hash( 'sha256', (string) base64_decode( $this->pngBase64(), true ) ),
				'extension'     => 'png',
				'filename'      => 'holiday.png',
				'mimeType'      => 'image/png',
				'parent'        => 0,
			],
			[ 'mimeType' => 'image/png' ]
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() proceeded without any reviewed bytes.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}

		$this->assertSame( [], $this->tempFiles );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_a_disallowed_upload_is_refused_at_plan_time_and_creates_no_attachment(): void {
		// REQ-0023's acceptance evidence, end to end.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input(
					[
						'filename'      => 'shell.php',
						'contentBase64' => $this->pngBase64(),
					]
				),
				$this->makeContext()
			);
			$this->fail( 'planChange() accepted a disallowed upload.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		}

		$this->assertSame( [], $this->tempFiles );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_plan_change_refuses_in_both_phases_so_a_stale_plan_cannot_be_applied(): void {
		// planChange() runs again at apply. A site whose administrator narrowed
		// the allowlist between preview and apply refuses the second run, and
		// ChangeEngine::apply() never reaches applyChange().
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );

		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'jpg|jpeg' => 'image/jpeg' ] );

		$this->expectException( OperationException::class );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );
	}

	public function test_no_superglobal_is_read(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- No form is processed; the superglobal is populated only to prove it is ignored.
		$_FILES = [
			'file' => [
				'tmp_name' => '/tmp/evil',
				'name'     => 'evil.php',
			],
		];

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'holiday.png', $this->sideloads[0]['file']['name'] );
		$this->assertNotSame( '/tmp/evil', $this->sideloads[0]['file']['tmp_name'] );

		$_FILES = [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
