<?php
/**
 * Tests for the SVG upload operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Modules\Media\MediaAssetPlan;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaSideload;
use SiteHelm\Modules\Media\MediaSvgUpload;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Modules\Media\SvgSanitizer;

/**
 * REQ-0105: the one path that may store markup in the media library.
 *
 * The tests that matter here are the ones about WHICH BYTES: the bytes the plan
 * hashes, the bytes the preview shows, and the bytes that reach the sideload
 * must all be the rebuilt document, never the submitted one. The sanitiser's own
 * rules are SvgSanitizerTest's subject; this file asks what the operation does
 * with its answer.
 */
final class MediaSvgUploadTest extends MediaUploadTestCase {

	private MediaSvgUpload $svg;

	/** @var string|null The bytes that were on disk when the sideload ran. */
	private ?string $storedBytes = null;

	protected function setUp(): void {
		parent::setUp();

		$fields = new MediaFields();

		$this->svg = new MediaSvgUpload(
			new MediaTarget( $fields ),
			new SvgSanitizer(),
			new MediaAssetPlan(),
			new MediaSideload( $fields )
		);

		$this->storedBytes = null;

		// Replaces the parent's sideload fake with one that READS THE TEMPORARY
		// FILE. Asserting on the payload's hash alone would only prove that the
		// operation agreed with itself; this proves the sanitised document is
		// what was actually written for WordPress to store.
		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ): array {
				$this->sideloads[]  = [
					'file'      => $file,
					'overrides' => $overrides,
				];
				$this->storedBytes = is_readable( $file['tmp_name'] )
					? (string) file_get_contents( $file['tmp_name'] )
					: null;

				if ( $this->sideloadFails ) {
					return [ 'error' => 'Sorry, you are not allowed to upload this file type.' ];
				}

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/check-1.svg',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/check-1.svg',
					'type' => 'image/svg+xml',
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> A complete SVG upload payload.
	 */
	private function svgInput( array $overrides = [] ): array {
		return array_merge(
			[
				'filename'                  => 'check.svg',
				MediaSvgUpload::INPUT_CONTENT => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
					. '<path d="M4 12l6 6L20 6" fill="none" stroke="currentColor"/></svg>',
				'title'                     => 'Check mark',
				'alt'                       => 'A check mark',
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return PlannedChange The plan for that input.
	 */
	private function plan( array $overrides = [] ): PlannedChange {
		$context = $this->makeContext();
		$input   = $this->svgInput( $overrides );

		return $this->svg->planChange(
			$this->svg->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	public function test_the_definition_describes_a_previewed_high_risk_write(): void {
		$definition = MediaSvgUpload::definition();

		$this->assertSame( 'media-svg-upload', $definition->id );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( [ 'filename', 'content' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Storing an SVG is publishing markup that runs in the site's own origin, so
	 * it asks for the capability that already means exactly that, on top of the
	 * one every upload needs.
	 */
	public function test_it_asks_for_the_markup_capability_as_well_as_the_upload_one(): void {
		$this->assertSame(
			[ 'upload_files', 'unfiltered_html' ],
			MediaSvgUpload::definition()->requiredCapabilities
		);
	}

	/**
	 * The document must not be larger than the sanitiser will parse, and the
	 * schema is where a caller finds that out before sending it.
	 */
	public function test_the_schema_caps_the_document_at_the_sanitizers_ceiling(): void {
		$this->assertSame(
			SvgSanitizer::MAX_BYTES,
			MediaSvgUpload::definition()->inputSchema['properties']['content']['maxLength']
		);
	}

	public function test_the_plan_hashes_the_rebuilt_document_and_not_the_submitted_one(): void {
		$submitted = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>'
			. '<path d="M0 0" fill="none"/></svg>';

		$planned = $this->plan( [ MediaSvgUpload::INPUT_CONTENT => $submitted ] );

		$this->assertNotSame( hash( 'sha256', $submitted ), $planned->payload['contentSha256'] );
		$this->assertSame(
			hash( 'sha256', $planned->previewDetail['storedDocument'] ),
			$planned->payload['contentSha256']
		);
		$this->assertSame(
			strlen( $planned->previewDetail['storedDocument'] ),
			$planned->payload['byteLength']
		);
	}

	public function test_the_plan_records_the_svg_type_and_extension(): void {
		$planned = $this->plan();

		$this->assertSame( 'image/svg+xml', $planned->payload['mimeType'] );
		$this->assertSame( 'svg', $planned->payload['extension'] );
		$this->assertSame( 'check.svg', $planned->payload['filename'] );
		$this->assertSame( 'image/svg+xml', $planned->afterFields['mimeType'] );
		$this->assertSame( 'Check mark', $planned->afterFields['title'] );
	}

	/**
	 * A caller has to be able to see that their file was changed on the way in,
	 * and an operator has to be able to read the exact document that will exist.
	 */
	public function test_a_removal_is_reported_as_a_warning_and_shown_in_the_preview(): void {
		$planned = $this->plan(
			[
				MediaSvgUpload::INPUT_CONTENT => '<svg xmlns="http://www.w3.org/2000/svg">'
					. '<script>alert(1)</script><path d="M0 0" onclick="x()"/></svg>',
			]
		);

		$this->assertCount( 2, $planned->warnings );
		$this->assertSame( [ 'script' ], $planned->previewDetail['removedElements'] );
		$this->assertSame( [ 'onclick' ], $planned->previewDetail['removedAttributes'] );
		$this->assertStringNotContainsString( 'alert', $planned->previewDetail['storedDocument'] );
		$this->assertStringContainsString( '<path', $planned->previewDetail['storedDocument'] );
	}

	public function test_a_clean_document_produces_no_warnings(): void {
		$planned = $this->plan();

		$this->assertSame( [], $planned->warnings );
		$this->assertSame( [], $planned->previewDetail['removedElements'] );
		$this->assertSame( [], $planned->previewDetail['removedAttributes'] );
	}

	/**
	 * planChange() runs at preview and again at apply. Two different payloads for
	 * one input would mean the approved plan and the applied one disagree.
	 */
	public function test_planning_the_same_input_twice_gives_the_same_payload(): void {
		$first  = $this->plan();
		$second = $this->plan();

		$this->assertSame( $first->payload, $second->payload );
		$this->assertSame( $first->previewDetail, $second->previewDetail );
	}

	public function test_a_filename_of_another_kind_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'stores SVG images only' );

		$this->plan( [ 'filename' => 'check.png' ] );
	}

	/**
	 * The double extension. `icon.svg.php` reads as an SVG to a person and is a
	 * PHP file to a server, and pathinfo() agrees with the server.
	 */
	public function test_a_double_extension_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'stores SVG images only' );

		$this->plan( [ 'filename' => 'icon.svg.php' ] );
	}

	public function test_a_filename_with_nothing_storable_in_it_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'no characters this site can store' );

		$this->plan( [ 'filename' => '..' ] );
	}

	/**
	 * The filename is judged before the document is parsed, so a caller who named
	 * a `.png` is told about the name rather than about their markup.
	 */
	public function test_the_filename_is_judged_before_the_document(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'stores SVG images only' );

		$this->plan(
			[
				'filename'                    => 'check.png',
				MediaSvgUpload::INPUT_CONTENT => 'not markup at all',
			]
		);
	}

	public function test_a_document_the_sanitizer_refuses_never_becomes_a_plan(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'not an SVG image' );

		$this->plan( [ MediaSvgUpload::INPUT_CONTENT => '<html><body>hi</body></html>' ] );
	}

	public function test_applying_writes_the_rebuilt_document(): void {
		$context = $this->makeContext();
		$planned = $this->plan(
			[
				MediaSvgUpload::INPUT_CONTENT => '<svg xmlns="http://www.w3.org/2000/svg">'
					. '<script>alert(1)</script><path d="M0 0"/></svg>',
			]
		);

		$key = $this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );

		$this->assertSame( 'attachment:512', $key );
		$this->assertSame( $planned->previewDetail['storedDocument'], $this->storedBytes );
		$this->assertStringNotContainsString( 'alert', (string) $this->storedBytes );
	}

	/**
	 * WordPress does not permit SVG uploads and this plugin does not ask it to.
	 * The type is granted for THIS CALL, so nothing about the media screen or the
	 * other two upload paths changes.
	 */
	public function test_the_svg_type_is_granted_for_this_one_call_only(): void {
		$context = $this->makeContext();
		$planned = $this->plan();

		$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );

		$this->assertSame(
			[ 'svg' => 'image/svg+xml' ],
			$this->sideloads[0]['overrides']['mimes']
		);
		$this->assertSame( 'check.svg', $this->sideloads[0]['file']['name'] );
		$this->assertSame( 'image/svg+xml', $this->sideloads[0]['file']['type'] );
	}

	/**
	 * An ordinary upload must still be able to reach the site's own permissions,
	 * which is what a missing `mimes` key means to wp_handle_sideload(). A member
	 * set to null would mean "permit nothing".
	 */
	public function test_an_upload_without_a_type_map_leaves_the_sites_permissions_alone(): void {
		$fields   = new MediaFields();
		$sideload = new MediaSideload( $fields );

		$sideload->store(
			'bytes',
			[
				'filename'   => 'holiday.png',
				'mimeType'   => 'image/png',
				'byteLength' => 5,
				'parent'     => 0,
			],
			$this->makeContext(),
			'media-upload'
		);

		$this->assertArrayNotHasKey( 'mimes', $this->sideloads[0]['overrides'] );
	}

	/**
	 * The bytes are not in the plan, so the operation checks that the ones it is
	 * holding are the ones its own plan described before anything is written.
	 */
	public function test_bytes_that_do_not_match_the_approved_plan_are_refused(): void {
		$context = $this->makeContext();
		$planned = $this->plan();
		$stale   = new PlannedChange(
			[ 'contentSha256' => hash( 'sha256', 'something else' ) ] + $planned->payload,
			$planned->afterFields
		);

		try {
			$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $stale, $context );
			$this->fail( 'Expected the mismatched content to be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 'plan approved' ], $e->completedSteps );
		}

		$this->assertSame( [], $this->sideloads );
	}

	/**
	 * Applying twice off one plan is refused, because the bytes are released when
	 * the first apply finishes.
	 */
	public function test_a_second_apply_from_the_same_plan_is_refused(): void {
		$context = $this->makeContext();
		$planned = $this->plan();

		$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );

		$this->expectException( OperationException::class );

		$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );
	}

	public function test_the_temporary_file_is_removed_after_a_successful_apply(): void {
		$context = $this->makeContext();
		$planned = $this->plan();

		$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );

		$this->assertCount( 1, $this->deleted );
		$this->assertFileDoesNotExist( $this->deleted[0] );
	}

	public function test_a_refused_sideload_reports_no_internal_detail(): void {
		$context             = $this->makeContext();
		$planned             = $this->plan();
		$this->sideloadFails = true;

		try {
			$this->svg->applyChange( $this->svg->resolveTarget( [], $context ), $planned, $context );
			$this->fail( 'Expected the refused sideload to surface as an execution failure.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertStringNotContainsString( 'tmp', $e->getMessage() );
			$this->assertStringNotContainsString( '/var/www', $e->getMessage() );
		}

		$this->assertCount( 1, $this->deleted );
	}

	public function test_it_captures_nothing_because_there_is_no_prior_state(): void {
		$context = $this->makeContext();

		$this->assertNull(
			$this->svg->captureSnapshot( $this->svg->resolveTarget( [], $context ), $context )
		);
	}

	public function test_it_reads_the_created_asset_back(): void {
		$state = $this->svg->readBack( 'attachment:512', $this->makeContext() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 'attachment:512', $state->targetKey );
	}

	/**
	 * The reversal of an upload is a deletion, and a deletion wearing a
	 * rollback's clothes is not a rollback.
	 */
	public function test_restoring_is_refused_rather_than_deleting_the_asset(): void {
		$this->expectException( OperationException::class );

		try {
			$this->svg->restore( [], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			throw $e;
		}
	}
}
