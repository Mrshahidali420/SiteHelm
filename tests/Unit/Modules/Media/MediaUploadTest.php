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
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaUpload;

/**
 * REQ-0023: add a client-approved asset to the media library.
 *
 * WHAT THIS FILE IS ABOUT is what the OPERATION promises: the definition row,
 * the closed input schema, the pending target, the planned payload — which
 * carries a fingerprint of the bytes and never the bytes — and the plan/apply
 * coupling that makes an approved preview the only thing that can be applied.
 *
 * WHAT IT IS NOT ABOUT is the filesystem. What reaches the temporary file, what
 * is left behind when core refuses, and what a refusal is allowed to say about a
 * path are MediaSideloadTest's; both files run on MediaUploadTestCase's single
 * fixture rather than each faking core's four write calls separately.
 */
final class MediaUploadTest extends MediaUploadTestCase {

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
