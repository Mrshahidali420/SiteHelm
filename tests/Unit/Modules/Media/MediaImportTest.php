<?php
/**
 * Behaviour of MediaImport (REQ-0052).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

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
use SiteHelm\Modules\Media\MediaImport;

/**
 * What REQ-0052's write operation must do, on top of the WordPress and transport
 * fixture MediaImportTestCase installs.
 *
 * The two halves are separate files only because one file carrying both would
 * pass this project's 800-line ceiling. MediaImportTestCase's docblock explains
 * what the fixture models and, more importantly, what it deliberately does not:
 * read it before adding a case here.
 */
final class MediaImportTest extends MediaImportTestCase {

	public function test_the_definition_declares_the_upload_files_capability(): void {
		$definition = MediaImport::definition();

		$this->assertSame( 'media-import', $definition->id );
		$this->assertSame( Domain::Media, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame(
			[ 'upload_files' ],
			$definition->requiredCapabilities,
			'upload_files is the capability WordPress gates its own sideload behind. A stricter one invented here is a capability no role editor knows to grant.'
		);
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
	}

	public function test_the_definition_requires_preview_and_is_high_risk(): void {
		$definition = MediaImport::definition();

		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent, 'Each apply creates a new attachment.' );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	public function test_the_input_schema_forbids_additional_properties(): void {
		$schema = MediaImport::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'url' ], $schema['required'] );
		$this->assertSame( 2048, $schema['properties']['url']['maxLength'] );
		$this->assertSame( 255, $schema['properties']['filename']['maxLength'] );
		$this->assertArrayNotHasKey(
			'mimeType',
			$schema['properties'],
			'A client-declared MIME type is a second source of truth that can disagree with the bytes.'
		);
		$this->assertArrayNotHasKey(
			'contentBase64',
			$schema['properties'],
			'An import takes its bytes from the address and from nowhere else.'
		);
	}

	public function test_the_description_states_that_two_requests_are_made(): void {
		$description = MediaImport::definition()->description;

		$this->assertMatchesRegularExpression(
			'/fetch(ed|es)?\b[^.]*\bpreview\b/i',
			$description,
			'The description must tell the caller the preview phase performs a fetch.'
		);
		$this->assertMatchesRegularExpression(
			'/\bapplied\b|\bapply\b/i',
			$description,
			'The description must tell the caller the apply phase performs a fetch too.'
		);
		$this->assertMatchesRegularExpression(
			'/chang(ed|es)\b/i',
			$description,
			'The description must tell the caller a source that changed between the two is refused.'
		);
	}

	public function test_the_target_is_the_pending_key(): void {
		$state = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertSame( 'attachment:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	/**
	 * The whole point of the guard's position in planChange(): a URL this site
	 * will not fetch must never become a request, because a guard that ran after
	 * the fetch would already have performed the probe it exists to prevent.
	 */
	public function test_planning_validates_the_url_before_fetching(): void {
		$this->dns['metadata.example.com'] = [ '169.254.169.254' ];
		$input                             = $this->input( [ 'url' => 'https://metadata.example.com/photos/holiday.png' ] );
		$context                           = $this->makeContext();
		$current                           = $this->operation->resolveTarget( $input, $context );

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'A link-local address must be refused.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		}

		$this->assertSame(
			[],
			$this->requestedUrls,
			'A refused address must produce no request at all; a fetch here is the SSRF probe the guard exists to prevent.'
		);
		$this->assertNull( $this->heldBytes() );
	}

	public function test_planning_fetches_and_inspects_the_bytes(): void {
		$planned = $this->planWith( $this->pngBytes(), $this->input() );

		$this->assertSame( [ 'https://cdn.example.com/photos/holiday.png' ], $this->requestedUrls );
		$this->assertSame(
			hash( 'sha256', $this->pngBytes() ),
			$planned->payload['contentSha256'],
			'The payload must fingerprint the bytes THIS run fetched.'
		);
		$this->assertSame( strlen( $this->pngBytes() ), $planned->payload['byteLength'] );
		$this->assertSame(
			'image/png',
			$planned->payload['mimeType'],
			'The type is sniffed from the content by MediaMimeGuard, which is what proves the fetched bytes were inspected rather than trusted.'
		);
		$this->assertSame(
			[
				'mimeType' => 'image/png',
				'title'    => 'Holiday photo',
				'alt'      => 'A beach at sunset',
			],
			$planned->afterFields
		);
	}

	public function test_the_payload_records_the_validated_source_url(): void {
		$planned = $this->planWith( $this->pngBytes(), $this->input() );

		$this->assertSame(
			'https://cdn.example.com/photos/holiday.png',
			$planned->payload['sourceUrl'],
			'The payload binds the plan to the source that was reviewed.'
		);
		$this->assertArrayNotHasKey(
			'sourceUrl',
			$planned->afterFields,
			'sourceUrl is not an attachment field, so there is nothing to read back and verify it against; promising it would make WriteVerifier report a missing field on every import.'
		);
	}

	public function test_the_filename_is_derived_from_the_url_path(): void {
		$planned = $this->planWith(
			$this->pngBytes(),
			[ 'url' => 'https://cdn.example.com/photos/a.png' ]
		);

		$this->assertSame( 'a.png', $planned->payload['filename'] );
	}

	public function test_an_explicit_filename_overrides_the_derived_one(): void {
		$planned = $this->planWith(
			$this->pngBytes(),
			$this->input( [ 'filename' => 'b.png' ] )
		);

		$this->assertSame(
			'b.png',
			$planned->payload['filename'],
			'The caller naming the file is the caller deciding; holiday.png from the address must not win.'
		);
	}

	public function test_a_url_with_no_usable_basename_is_refused(): void {
		$input   = [ 'url' => 'https://cdn.example.com/' ];
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'A path with no basename must be refused rather than guessed at.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
			$this->assertStringContainsString(
				'filename',
				(string) $refusal->remediation,
				'The remedy must name the argument that fixes it.'
			);
		}

		$this->assertSame(
			[],
			$this->requestedUrls,
			'A name that cannot be derived is known before any request is worth making.'
		);
	}

	public function test_a_url_path_basename_without_an_extension_is_refused(): void {
		$input   = [ 'url' => 'https://cdn.example.com/photo' ];
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'An extension must never be invented: the deny list keys off it.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		}

		$this->assertSame( [], $this->requestedUrls );
	}

	public function test_a_query_string_is_not_part_of_the_filename(): void {
		$planned = $this->planWith(
			$this->pngBytes(),
			[ 'url' => 'https://cdn.example.com/photos/a.png?v=2' ]
		);

		$this->assertSame(
			'a.png',
			$planned->payload['filename'],
			'A cache-buster is not part of the asset and must not become part of the stored file name.'
		);
		$this->assertSame(
			[ 'https://cdn.example.com/photos/a.png?v=2' ],
			$this->requestedUrls,
			'The query string is still part of the ADDRESS that was fetched; only the filename drops it.'
		);
	}

	public function test_content_that_is_not_an_allowed_type_is_refused(): void {
		$input   = $this->input( [ 'url' => 'https://cdn.example.com/photos/a.png' ] );
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$this->serve( "<?php echo 'payload'; ?>\n" );

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'PHP source is not one of the types this site accepts.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		}

		$this->assertSame(
			[ 'https://cdn.example.com/photos/a.png' ],
			$this->requestedUrls,
			'The refusal must come from inspecting what arrived, not from declining to ask.'
		);
		$this->assertSame( [], $this->sideloads );
	}

	/**
	 * The header is a claim by the same server that supplied the bytes. If it
	 * were consulted anywhere, this import would succeed.
	 */
	public function test_a_declared_content_type_is_never_consulted(): void {
		$input             = $this->input( [ 'url' => 'https://cdn.example.com/photos/a.png' ] );
		$context           = $this->makeContext();
		$current           = $this->operation->resolveTarget( $input, $context );
		$this->responses[] = [
			'response' => [
				'code'    => 200,
				'message' => 'canned',
			],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => "<?php echo 'payload'; ?>\n",
		];

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'A PHP script served as image/png must be refused for what it is.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
			$this->assertStringNotContainsStringIgnoringCase(
				'content-type',
				$refusal->getMessage() . ' ' . (string) $refusal->remediation
			);
		}
	}

	public function test_apply_refuses_when_the_held_bytes_do_not_match_the_plan(): void {
		$input   = $this->input();
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->planWith( $this->pngBytes(), $input );

		// The plan the engine hands back at apply describes DIFFERENT content
		// from the bytes this instance is holding. That is the coupling between
		// planChange() and applyChange() being broken, and nothing may be written.
		$forged = new PlannedChange(
			array_merge( $planned->payload, [ 'contentSha256' => hash( 'sha256', 'other bytes' ) ] ),
			$planned->afterFields,
			[ 'mimeType', 'title', 'alt', 'caption', 'description' ]
		);

		try {
			$this->operation->applyChange( $current, $forged, $context );
			$this->fail( 'Unreviewed content must never be written.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		}

		$this->assertSame( [], $this->sideloads, 'Nothing may reach disk on the mismatch path.' );
		$this->assertSame( [], $this->tempFiles );
	}

	public function test_apply_stores_the_asset_and_returns_its_target_key(): void {
		$input   = $this->input();
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->planWith( $this->pngBytes(), $input );

		$key = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'attachment:512', $key );
		$this->assertCount( 1, $this->sideloads, 'Exactly one asset is stored per apply.' );
		$this->assertSame( 'holiday.png', $this->sideloads[0]['file']['name'] );
		$this->assertSame( 'image/png', $this->sideloads[0]['file']['type'] );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'Holiday photo', $this->inserts[0]['attachment']['post_title'] );
		$this->assertSame( 'A beach at sunset', $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_the_pending_bytes_are_cleared_after_apply(): void {
		$input   = $this->input();
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->planWith( $this->pngBytes(), $input );

		$this->assertSame(
			$this->pngBytes(),
			$this->heldBytes(),
			'Between the two phases the operation must actually be holding the bytes, or the clearing assertions below are vacuous.'
		);

		$this->operation->applyChange( $current, $planned, $context );

		$this->assertNull( $this->heldBytes(), 'Cleared on the success path.' );

		// And again on the failure path, from a fresh plan.
		$planned = $this->planWith( $this->pngBytes(), $input );
		$this->assertSame( $this->pngBytes(), $this->heldBytes() );

		$forged = new PlannedChange(
			array_merge( $planned->payload, [ 'contentSha256' => hash( 'sha256', 'other bytes' ) ] ),
			$planned->afterFields,
			[ 'mimeType', 'title', 'alt', 'caption', 'description' ]
		);

		try {
			$this->operation->applyChange( $current, $forged, $context );
			$this->fail( 'The forged plan must be refused.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		}

		$this->assertNull( $this->heldBytes(), 'Cleared on the refusal path too.' );
	}

	public function test_a_snapshot_is_never_captured(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $this->input(), $context );

		$this->assertNull( $this->operation->captureSnapshot( $current, $context ) );
		$this->assertNull( $this->operation->captureSnapshot( $current, $context ) );
		$this->assertSame( [], $this->requestedUrls, 'Capturing a snapshot must be side-effect free.' );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_restore_always_refuses(): void {
		try {
			$this->operation->restore( [ 'post_id' => 512 ], $this->makeContext() );
			$this->fail( 'An import has no prior state to restore.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		}
	}

	public function test_read_back_discloses_the_stored_filename(): void {
		$input   = $this->input();
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->planWith( $this->pngBytes(), $input );

		$key   = $this->operation->applyChange( $current, $planned, $context );
		$state = $this->operation->readBack( $key, $context );

		$this->assertSame( 'holiday.png', $planned->payload['filename'], 'The address offered holiday.png.' );
		$this->assertSame( 'holiday-1.png', $state->fields['filename'], 'WordPress stored a uniquified name.' );
		$this->assertArrayNotHasKey(
			'filename',
			$planned->afterFields,
			'A promised filename would make every routine collision read as an adjustment.'
		);
		$this->assertSame( [], $planned->warnings );
	}

	/**
	 * Every refusal this operation can produce, swept for the four things an
	 * attacker harvests from a blind SSRF probe: a resolved address, a redirect
	 * target, a response header, and a transport error string.
	 *
	 * The refusals are collected by RUNNING each failing case rather than by
	 * reading literals out of the source, so a message added later without a
	 * thought for disclosure is caught here rather than agreed with.
	 */
	public function test_no_refusal_message_names_an_address_a_header_or_a_path(): void {
		$refusals = [];

		foreach ( $this->disclosureCases() as $label => $case ) {
			// Each case starts from an empty queue. A case refused BEFORE the
			// fetch leaves its response unconsumed, and a leftover valid image
			// would then be served to a later case and satisfy it — which is how
			// a sweep quietly stops sweeping.
			$this->responses = [];

			try {
				$case();
				$this->fail( "The '{$label}' case must refuse." );
			} catch ( OperationException $refusal ) {
				$refusals[ $label ] = $refusal->getMessage() . ' ' . (string) $refusal->remediation;
			}
		}

		$this->assertGreaterThanOrEqual( 5, count( $refusals ), 'The sweep must actually reach every refusal path.' );

		foreach ( $refusals as $label => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $text, "IP address in '{$label}'." );
			$this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{0,4}:[0-9a-f]{0,4}:/i', $text, "IPv6 address in '{$label}'." );
			$this->assertDoesNotMatchRegularExpression( '#(/|\\\\|[A-Za-z]:\\\\|wp-content|uploads)#', $text, "Path or URL in '{$label}'." );
			$this->assertDoesNotMatchRegularExpression( '/\bcurl\b|content-type|\blocation\b|\bheader\b|\bDNS\b/i', $text, "Transport or header detail in '{$label}'." );
			$this->assertStringNotContainsString( 'cdn.example.com', $text, "Host name in '{$label}'." );
			$this->assertStringNotContainsString( 'boom', $text, "Transport error string in '{$label}'." );
		}
	}

	/**
	 * One closure per refusal this operation can reach, each of which must throw.
	 *
	 * @return array<string, callable> The cases.
	 */
	private function disclosureCases(): array {
		return [
			'private address'    => function (): void {
				$this->dns['metadata.example.com'] = [ '169.254.169.254' ];
				$this->planWith( $this->pngBytes(), [ 'url' => 'https://metadata.example.com/a.png' ] );
			},
			'unresolvable host'  => function (): void {
				$this->planWith( $this->pngBytes(), [ 'url' => 'https://nowhere.example.com/a.png' ] );
			},
			'no basename'        => function (): void {
				$this->planWith( $this->pngBytes(), [ 'url' => 'https://cdn.example.com/' ] );
			},
			'transport failure'  => function (): void {
				$this->responses[] = new class() {

					public function get_error_message(): string {
						return 'boom: could not connect to 93.184.216.34 via curl';
					}
				};
				$context           = $this->makeContext();
				$input             = [ 'url' => 'https://cdn.example.com/a.png' ];
				$this->operation->planChange( $this->operation->resolveTarget( $input, $context ), $input, $context );
			},
			'refused content'    => function (): void {
				$this->planWith( "<?php echo 'payload'; ?>\n", [ 'url' => 'https://cdn.example.com/a.png' ] );
			},
			'rollback'           => function (): void {
				$this->operation->restore( [ 'post_id' => 512 ], $this->makeContext() );
			},
			'unmatched approval' => function (): void {
				$context = $this->makeContext();
				$input   = $this->input();
				$this->operation->applyChange(
					$this->operation->resolveTarget( $input, $context ),
					new PlannedChange(
						[ 'contentSha256' => hash( 'sha256', 'other bytes' ) ],
						[ 'mimeType' => 'image/png' ],
						[ 'mimeType' ]
					),
					$context
				);
			},
		];
	}

	/**
	 * THE POINT OF THE WHOLE DESIGN, and the reason the fetch is inside
	 * planChange() rather than cached from the preview.
	 *
	 * The engine runs planChange() at preview and again at apply. Because the
	 * payload fingerprints the bytes THIS run fetched, a remote that served
	 * something else the second time yields a different payload, and
	 * PlanAdmission::assertPayloadMatches() refuses the approved plan as stale
	 * rather than storing content nobody reviewed.
	 */
	public function test_a_remote_whose_bytes_changed_between_phases_produces_a_different_payload(): void {
		$input = $this->input();

		$preview = $this->planWith( $this->pngBytes(), $input );
		$apply   = $this->planWith( $this->otherPngBytes(), $input );

		$this->assertNotSame(
			$this->pngBytes(),
			$this->otherPngBytes(),
			'The two bodies must genuinely differ, or this test cannot fail.'
		);
		$this->assertSame( 'image/png', $apply->payload['mimeType'], 'Both bodies must PASS every content guard, so that the only difference left is the content itself.' );
		$this->assertNotSame(
			$preview->payload['contentSha256'],
			$apply->payload['contentSha256'],
			'A changed remote must change the payload, or the approved plan would be applied to unreviewed content.'
		);
		$this->assertSame(
			[
				'https://cdn.example.com/photos/holiday.png',
				'https://cdn.example.com/photos/holiday.png',
			],
			$this->requestedUrls,
			'Two phases, two fetches. One fetch reused across both is a preview that reviewed something other than what is stored.'
		);
	}
}
