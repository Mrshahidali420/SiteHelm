<?php
/**
 * Tests for MediaResize (REQ-0072).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
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
use SiteHelm\Modules\Media\MediaResize;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0072: an oversized asset is brought within the sizes the theme renders.
 *
 * REAL FILES ON DISK, NOT A FAKED file_exists(). The operation asks file_exists()
 * whether the original is there, and that is a PHP internal Brain Monkey cannot
 * redefine. Faking around it would mean not calling it — which is the one thing
 * the test must not change, because "the original is missing" is a refusal path
 * this file has to exercise. So setUp writes a real source file into a real
 * temporary directory and tearDown removes it, and the missing-original test
 * deletes it rather than mocking its absence.
 *
 * THE EDITOR IS DOUBLED, THE IMAGE IS NOT. wp_get_image_editor() is the seam:
 * the doubles below decide what it answers, so every editor failure mode is
 * reachable without a GD build, and the bytes on disk are only ever a handful of
 * characters. What the doubles do NOT do is answer for the operation — the saved
 * path, the attached-file pointer, and the metadata are all read back out of the
 * fake WordPress state the operation itself wrote.
 */
final class MediaResizeTest extends TestCase {

	private MediaResize $operation;

	private stdClass $attachment;

	/** @var array<string, mixed> The attachment metadata the fake library holds. */
	private array $metadata = [];

	/** The `_wp_attached_file` value the fake library holds. */
	private string $attachedFile = '';

	/** The directory the fake uploads live in, with forward slashes. */
	private string $uploadDir = '';

	/** The absolute path of the untouched original. */
	private string $sourcePath = '';

	/** What wp_get_original_image_path() answers, so a test can empty it. */
	private string $originalPath = '';

	/** Which editor step fails: 'open', 'resize', 'save', or '' for none. */
	private string $editorFailure = '';

	/** Whether wp_generate_attachment_metadata() answers something usable. */
	private bool $metadataRegenerates = true;

	/** Whether the regenerated metadata reports the size that was asked for. */
	private bool $metadataReportsReduced = true;

	/** Whether update_post_meta() actually moves the attached-file pointer. */
	private bool $pointerMoves = true;

	/** @var string[] Basenames wp_unique_filename() must treat as taken. */
	private array $takenBasenames = [];

	/** @var array<int, array<string, mixed>> Every resize() call, in order. */
	public array $resizeCalls = [];

	/** @var string[] Every path generate_filename() was asked to compose into. */
	public array $generatedNames = [];

	/** @var string[] Every destination save() was handed, in order. */
	public array $savedPaths = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaResize( $fields, new MediaTarget( $fields ) );

		$this->resizeCalls            = [];
		$this->generatedNames         = [];
		$this->savedPaths             = [];
		$this->editorFailure          = '';
		$this->metadataRegenerates    = true;
		$this->metadataReportsReduced = true;
		$this->pointerMoves           = true;
		$this->takenBasenames         = [];

		$this->uploadDir = str_replace( '\\', '/', rtrim( sys_get_temp_dir(), '/\\' ) ) . '/sitehelm-resize-test';
		if ( ! is_dir( $this->uploadDir ) ) {
			mkdir( $this->uploadDir, 0777, true );
		}
		$this->sourcePath   = $this->uploadDir . '/cat.jpg';
		$this->originalPath = $this->sourcePath;
		file_put_contents( $this->sourcePath, 'pretend these are jpeg bytes' );

		$this->attachedFile = '2026/07/cat.jpg';
		$this->metadata     = [
			'width'  => 4000,
			'height' => 3000,
			'file'   => '2026/07/cat.jpg',
			'sizes'  => [ 'thumbnail' => [ 'file' => 'cat-150x150.jpg' ] ],
		];

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = '';
		$this->attachment->post_content   = '';
		$this->attachment->post_parent    = 0;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		$this->stubMediaLibrary();
		$this->stubImageEditor();
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->uploadDir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	/**
	 * The WordPress functions MediaFields, MediaTarget, and the operation read.
	 */
	private function stubMediaLibrary(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		// MediaTarget re-checks the capability on the resolved item itself. The
		// gate that placement backstops is PolicyEngine's, which is tested where
		// it lives; here it only has to answer so resolve() can complete.
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
		Functions\when( 'trailingslashit' )->alias(
			static fn( string $value ): string => rtrim( $value, '/\\' ) . '/'
		);
		Functions\when( 'wp_slash' )->alias(
			static fn( $value ) => is_string( $value ) ? addslashes( $value ) : $value
		);

		// A WordPress error is any object that answers get_error_message(), which
		// is what the media suite already models rather than pulling in WP_Error.
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => is_object( $thing ) && method_exists( $thing, 'get_error_message' )
		);

		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => 108 === (int) $id ? $this->attachment : null
		);

		// $single-aware: MediaFields::read() asks for the alt text with
		// $single = true and casts the answer to string, and this operation reads
		// the attached-file pointer the same way. A fake that answered [] to every
		// call would turn every read into an array-to-string error.
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				if ( '_wp_attached_file' === $key ) {
					return $single ? $this->attachedFile : [ $this->attachedFile ];
				}

				return $single ? '' : [];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev = '' ): bool {
				if ( '_wp_attached_file' === $key && $this->pointerMoves ) {
					$this->attachedFile = stripslashes( (string) $value );
				}

				return true;
			}
		);

		Functions\when( 'get_attached_file' )->alias(
			fn( $id, $unfiltered = false ): string => $this->uploadDir . '/' . basename( $this->attachedFile )
		);
		Functions\when( 'wp_get_original_image_path' )->alias(
			fn( $id, $unfiltered = false ): string => $this->originalPath
		);

		Functions\when( 'wp_get_attachment_metadata' )->alias( fn( $id = 0, $unfiltered = false ) => $this->metadata );
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $data ): bool {
				$this->metadata = (array) $data;

				return true;
			}
		);
		Functions\when( 'update_attached_file' )->alias(
			function ( $id, $file ): bool {
				if ( $this->pointerMoves ) {
					$this->attachedFile = '2026/07/' . basename( (string) $file );
				}

				return true;
			}
		);

		Functions\when( 'wp_generate_attachment_metadata' )->alias(
			function ( $id, $file ) {
				if ( ! $this->metadataRegenerates ) {
					return false;
				}

				$last = end( $this->resizeCalls );
				$size = $this->metadataReportsReduced && is_array( $last )
					? [ (int) $last['width'], (int) $last['height'] ]
					: [ (int) $this->metadata['width'], (int) $this->metadata['height'] ];

				return [
					'width'  => $size[0],
					'height' => $size[1],
					'file'   => '2026/07/' . basename( (string) $file ),
					'sizes'  => [ 'thumbnail' => [ 'file' => 'cat-150x150.jpg' ] ],
				];
			}
		);

		Functions\when( 'wp_unique_filename' )->alias(
			function ( $dir, $filename, $callback = null ): string {
				return in_array( $filename, $this->takenBasenames, true )
					? preg_replace( '/\.jpg$/', '-1.jpg', (string) $filename )
					: (string) $filename;
			}
		);

		// Core's own arithmetic, reproduced rather than approximated: it preserves
		// the aspect ratio, it never upscales, and it reads 0 on an axis as
		// "no limit" — which is exactly the contract planChange() relies on when
		// the caller bounds only one axis.
		Functions\when( 'wp_constrain_dimensions' )->alias(
			static function ( $width, $height, $max_width = 0, $max_height = 0 ): array {
				if ( ! $max_width && ! $max_height ) {
					return [ (int) $width, (int) $height ];
				}

				$width_ratio  = ( $max_width > 0 && $width > $max_width ) ? $max_width / $width : 1.0;
				$height_ratio = ( $max_height > 0 && $height > $max_height ) ? $max_height / $height : 1.0;
				$ratio        = min( $width_ratio, $height_ratio );

				return [ (int) round( $width * $ratio ), (int) round( $height * $ratio ) ];
			}
		);
	}

	/**
	 * The image editor seam.
	 */
	private function stubImageEditor(): void {
		Functions\when( 'wp_get_image_editor' )->alias(
			function ( $path, $args = [] ) {
				if ( 'open' === $this->editorFailure ) {
					return $this->wpError( 'no editor is installed at /var/www/uploads' );
				}

				return $this->makeEditor();
			}
		);
	}

	/**
	 * A doubled WP_Image_Editor.
	 *
	 * @return object The editor.
	 */
	private function makeEditor(): object {
		$test = $this;

		return new class( $test ) {

			/**
			 * @param MediaResizeTest $test The test recording the calls.
			 */
			public function __construct( private MediaResizeTest $test ) {
			}

			/**
			 * @param int  $width  Target width.
			 * @param int  $height Target height.
			 * @param bool $crop   Whether to crop.
			 *
			 * @return object|bool The failure, or true.
			 */
			public function resize( $width, $height, $crop = false ) {
				return $this->test->recordResize( (int) $width, (int) $height, (bool) $crop );
			}

			/**
			 * @param string $suffix    The dimension suffix.
			 * @param string $directory The destination directory.
			 *
			 * @return string The composed path.
			 */
			public function generate_filename( $suffix = null, $directory = null, $extension = null ) {
				return $this->test->recordGenerate( (string) $suffix, (string) $directory );
			}

			/**
			 * @param string $destination The absolute destination path.
			 *
			 * @return object|array<string, mixed> The failure, or the saved file.
			 */
			public function save( $destination = null, $mime_type = null ) {
				return $this->test->recordSave( (string) $destination );
			}
		};
	}

	/**
	 * Records a resize and answers the configured outcome.
	 *
	 * @param int  $width  Target width.
	 * @param int  $height Target height.
	 * @param bool $crop   Whether to crop.
	 *
	 * @return object|bool The failure, or true.
	 */
	public function recordResize( int $width, int $height, bool $crop ) {
		$this->resizeCalls[] = [
			'width'  => $width,
			'height' => $height,
			'crop'   => $crop,
		];

		return 'resize' === $this->editorFailure ? $this->wpError( 'resize failed in /var/www' ) : true;
	}

	/**
	 * Records the name the editor would have chosen, unaware of the directory.
	 *
	 * The double reproduces the property that motivates the operation's own
	 * uniqueness step: it composes a name from the suffix and never asks the
	 * filesystem whether that name is free.
	 *
	 * @param string $suffix    The dimension suffix.
	 * @param string $directory The destination directory.
	 *
	 * @return string The composed path.
	 */
	public function recordGenerate( string $suffix, string $directory ): string {
		$name                   = rtrim( $directory, '/\\' ) . '/cat-' . $suffix . '.jpg';
		$this->generatedNames[] = $name;

		return $name;
	}

	/**
	 * Records a save and answers the configured outcome.
	 *
	 * @param string $destination The absolute destination path.
	 *
	 * @return object|array<string, mixed> The failure, or the saved file.
	 */
	public function recordSave( string $destination ) {
		if ( 'save' === $this->editorFailure ) {
			return $this->wpError( 'could not write to /var/www/uploads' );
		}

		$this->savedPaths[] = $destination;
		file_put_contents( $destination, 'pretend these are smaller jpeg bytes' );

		return [
			'path'      => $destination,
			'file'      => basename( $destination ),
			'mime-type' => 'image/jpeg',
		];
	}

	/**
	 * A WordPress-shaped failure.
	 *
	 * @param string $message The message.
	 *
	 * @return object The failure.
	 */
	private function wpError( string $message ): object {
		return new class( $message ) {

			/**
			 * @param string $message The message.
			 */
			public function __construct( private string $message ) {
			}

			/**
			 * @return string The message.
			 */
			public function get_error_message(): string {
				return $this->message;
			}
		};
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-resize',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 108 ], $this->makeContext() );
	}

	/**
	 * Plans alone and reports the outcome without letting a throwable escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array{0: PlannedChange|null, 1: OperationException|null, 2: string}
	 */
	private function planOutcome( array $input ): array {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );

			return [ $this->operation->planChange( $current, $input, $context ), null, 'the plan threw nothing' ];
		} catch ( OperationException $error ) {
			return [ null, $error, $error->getMessage() ];
		} catch ( Throwable $error ) {
			return [ null, null, 'the plan threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	/**
	 * Runs the whole write and reports the refusal without letting it escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );
			$planned = $this->operation->planChange( $current, $input, $context );
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0072(): void {
		$definition = MediaResize::definition();

		$this->assertSame( 'media-resize', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'edit_post', 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse(
			$definition->isDestructive,
			'Nothing is removed: the original file stays on disk and the snapshot points back at it.'
		);
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertArrayHasKey( 'wordpress', $definition->supportedVersions );
	}

	public function test_the_input_schema_is_closed_and_bounds_both_axes(): void {
		$schema = MediaResize::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $schema['required'] );

		foreach ( [ 'maxWidth', 'maxHeight' ] as $key ) {
			$this->assertSame( 1, $schema['properties'][ $key ]['minimum'], $key );
			$this->assertSame(
				MediaResize::MAX_BOUND,
				$schema['properties'][ $key ]['maximum'],
				$key . ' must be capped, or a bound no photograph can exceed costs a full decode for nothing.'
			);
		}
	}

	public function test_a_request_naming_no_bound_is_refused(): void {
		[ $planned, $refusal ] = $this->planOutcome( [ 'id' => 108 ] );

		$this->assertNull( $planned );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'maximum width or height', $refusal->getMessage() );
	}

	public function test_an_item_with_no_recorded_dimensions_is_refused(): void {
		$this->metadata = [ 'file' => '2026/07/cat.pdf' ];

		[ $planned, $refusal ] = $this->planOutcome(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertNull( $planned );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
	}

	public function test_an_image_already_within_the_bound_is_refused_rather_than_re_saved(): void {
		[ $planned, $refusal ] = $this->planOutcome(
			[
				'id'        => 108,
				'maxWidth'  => 5000,
				'maxHeight' => 5000,
			]
		);

		$this->assertNull( $planned );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame(
			ErrorCode::Conflict,
			$refusal->errorCode,
			'Refusing is the whole of this operation\'s idempotence claim: a retry must not reduce twice.'
		);
	}

	public function test_a_missing_original_is_refused_without_naming_the_path(): void {
		unlink( $this->sourcePath );

		[ $planned, $refusal ] = $this->planOutcome(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertNull( $planned );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringNotContainsString( $this->uploadDir, $refusal->getMessage() );
		$this->assertStringNotContainsString( $this->uploadDir, (string) $refusal->remediation );
	}

	public function test_the_plan_constrains_both_axes_and_promises_the_result(): void {
		[ $planned ] = $this->planOutcome(
			[
				'id'        => 108,
				'maxWidth'  => 1568,
				'maxHeight' => 1568,
			]
		);

		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( 1568, $planned->afterFields['width'] );
		$this->assertSame( 1176, $planned->afterFields['height'], 'The aspect ratio is preserved, not squashed.' );
		$this->assertSame( [ 'width', 'height' ], $planned->fieldOrder );
		$this->assertSame( 4000, $planned->previewDetail['from']['width'] );
	}

	public function test_a_width_only_bound_leaves_the_height_unconstrained(): void {
		[ $planned ] = $this->planOutcome(
			[
				'id'       => 108,
				'maxWidth' => 2000,
			]
		);

		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( 2000, $planned->afterFields['width'] );
		$this->assertSame( 1500, $planned->afterFields['height'] );
		$this->assertSame( 0, $planned->payload['maxHeight'], 'An unbounded axis is 0, which core reads as no limit.' );
	}

	public function test_the_snapshot_records_both_the_pointer_and_the_whole_metadata(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertIsArray( $snapshot );
		$this->assertSame( 108, $snapshot['post_id'] );
		$this->assertSame( '2026/07/cat.jpg', $snapshot['attached_file'] );
		$this->assertSame(
			$this->metadata,
			$snapshot['metadata'],
			'The metadata is kept whole, because a rebuilt subset silently drops image_meta and every registered rendition.'
		);
	}

	public function test_the_snapshot_is_null_when_there_is_no_such_item(): void {
		$absent = new TargetState( 'attachment:404', false, [] );

		$this->assertNull( $this->operation->captureSnapshot( $absent, $this->makeContext() ) );
	}

	public function test_the_write_stores_a_new_file_and_re_points_the_item_at_it(): void {
		$refusal = $this->planAndApply(
			[
				'id'        => 108,
				'maxWidth'  => 1568,
				'maxHeight' => 1568,
			]
		);

		$this->assertNull( $refusal, null === $refusal ? '' : $refusal->getMessage() );
		$this->assertCount( 1, $this->savedPaths );
		$this->assertSame( $this->uploadDir . '/cat-1568x1176.jpg', $this->savedPaths[0] );
		$this->assertSame( '2026/07/cat-1568x1176.jpg', $this->attachedFile );
		$this->assertSame( 1568, $this->metadata['width'] );
		$this->assertSame( 1176, $this->metadata['height'] );
		$this->assertFalse( $this->resizeCalls[0]['crop'], 'Bringing an image down must never crop it.' );
	}

	public function test_the_original_file_is_left_on_disk(): void {
		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertFileExists(
			$this->sourcePath,
			'REQ-0056 excludes irreversible deletion, and a rollback needs the file it recorded.'
		);
	}

	public function test_the_first_reduction_records_the_original_it_read(): void {
		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertSame( 'cat.jpg', $this->metadata['original_image'] );
	}

	public function test_a_second_reduction_keeps_pointing_at_the_true_original(): void {
		$this->metadata['original_image'] = 'cat.jpg';
		$this->attachedFile               = '2026/07/cat-2000x1500.jpg';
		$this->metadata['width']          = 2000;
		$this->metadata['height']         = 1500;

		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1000,
			]
		);

		$this->assertSame(
			'cat.jpg',
			$this->metadata['original_image'],
			'Re-deriving it would point original_image at the first reduction and make the camera original unreachable.'
		);
	}

	public function test_the_source_read_is_the_original_rather_than_the_current_file(): void {
		$this->attachedFile = '2026/07/cat-2000x1500.jpg';
		$this->originalPath = $this->sourcePath;

		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1000,
			]
		);

		$this->assertSame(
			$this->uploadDir . '/cat-1000x750.jpg',
			$this->savedPaths[0],
			'Reducing the previous derivative would throw away detail the original still holds.'
		);
	}

	public function test_the_destination_is_made_unique_before_the_save(): void {
		$this->takenBasenames = [ 'cat-1568x1176.jpg' ];

		$this->planAndApply(
			[
				'id'        => 108,
				'maxWidth'  => 1568,
				'maxHeight' => 1568,
			]
		);

		$this->assertSame( $this->uploadDir . '/cat-1568x1176.jpg', $this->generatedNames[0] );
		$this->assertSame(
			$this->uploadDir . '/cat-1568x1176-1.jpg',
			$this->savedPaths[0],
			'generate_filename() never consults the directory, so an un-uniqued save overwrites the file a live snapshot points at.'
		);
	}

	public function test_an_unavailable_editor_refuses_without_leaking_its_message(): void {
		$this->editorFailure = 'open';

		$refusal = $this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringNotContainsString( '/var/www', $refusal->getMessage() );
		$this->assertSame( [ 'plan approved', 'snapshot captured' ], $refusal->completedSteps );
		$this->assertSame( [], $this->savedPaths );
	}

	public function test_a_failed_resize_refuses_and_writes_nothing(): void {
		$this->editorFailure = 'resize';

		$refusal = $this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringNotContainsString( '/var/www', $refusal->getMessage() );
		$this->assertSame( [], $this->savedPaths );
		$this->assertSame( '2026/07/cat.jpg', $this->attachedFile );
	}

	public function test_a_failed_save_refuses_and_leaves_the_item_pointing_where_it_was(): void {
		$this->editorFailure = 'save';

		$refusal = $this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringNotContainsString( '/var/www', $refusal->getMessage() );
		$this->assertSame( '2026/07/cat.jpg', $this->attachedFile );
	}

	public function test_metadata_that_cannot_be_rebuilt_refuses_and_says_how_far_it_got(): void {
		$this->metadataRegenerates = false;

		$refusal = $this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			[ 'plan approved', 'snapshot captured', 'reduced image stored', 'media item re-pointed' ],
			$refusal->completedSteps
		);
	}

	public function test_a_library_still_reporting_the_previous_size_refuses(): void {
		$this->metadataReportsReduced = false;

		$refusal = $this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'previous size', $refusal->getMessage() );
	}

	public function test_a_restore_puts_back_both_the_pointer_and_the_metadata(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );
		$this->assertIsArray( $snapshot );

		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);
		$this->assertNotSame( '2026/07/cat.jpg', $this->attachedFile );

		$restored = $this->operation->restore( $snapshot, $this->makeContext() );

		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame( '2026/07/cat.jpg', $this->attachedFile );
		$this->assertSame( 4000, $this->metadata['width'] );
		$this->assertSame( 3000, $this->metadata['height'] );
	}

	public function test_a_restore_leaves_the_reduced_file_on_disk(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );
		$this->assertIsArray( $snapshot );

		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);
		$this->operation->restore( $snapshot, $this->makeContext() );

		$this->assertFileExists(
			$this->savedPaths[0],
			'Deleting it would make the change un-redoable and would be the irreversible deletion this plugin excludes.'
		);
	}

	public function test_a_snapshot_naming_no_file_cannot_be_restored(): void {
		$refusal = null;

		try {
			$this->operation->restore(
				[
					'post_id'       => 108,
					'attached_file' => '',
					'metadata'      => [],
				],
				$this->makeContext()
			);
		} catch ( OperationException $error ) {
			$refusal = $error;
		}

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
	}

	public function test_a_snapshot_with_no_metadata_cannot_be_restored(): void {
		$refusal = null;

		try {
			$this->operation->restore(
				[
					'post_id'       => 108,
					'attached_file' => '2026/07/cat.jpg',
				],
				$this->makeContext()
			);
		} catch ( OperationException $error ) {
			$refusal = $error;
		}

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
	}

	public function test_a_restore_that_does_not_land_is_reported_rather_than_assumed(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );
		$this->assertIsArray( $snapshot );

		$this->planAndApply(
			[
				'id'       => 108,
				'maxWidth' => 1568,
			]
		);

		$this->pointerMoves = false;
		$refusal            = null;

		try {
			$this->operation->restore( $snapshot, $this->makeContext() );
		} catch ( OperationException $error ) {
			$refusal = $error;
		}

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$refusal->errorCode,
			'A restore has no verifier downstream, so it has to measure its own result.'
		);
	}
}
