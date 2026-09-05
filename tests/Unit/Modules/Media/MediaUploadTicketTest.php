<?php
/**
 * Tests for the media-upload-ticket operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaUploadTicket;
use SiteHelm\Modules\Media\UploadTickets;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * The operation that hands out permission to upload.
 *
 * The load-bearing test in this file is the one about promised fields. A
 * promised field is copied into the permanent audit row; an unpromised one
 * reaches the caller's response and stops there. The ticket is a secret, so it
 * must never be promised — and nothing but a test can hold that line, because
 * adding a field to afterFields looks harmless at the call site.
 */
final class MediaUploadTicketTest extends TestCase {

	private FakeWpdb $wpdb;
	private MediaUploadTicket $operation;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$fields          = new MediaFields();
		$this->operation = new MediaUploadTicket( new MediaMimeGuard( $fields ), new UploadTickets( new PlanStore() ) );

		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ): string => (string) json_encode( $value ) );
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'rest_url' )->alias( static fn( string $path ): string => 'https://example.com/wp-json/' . $path );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-ticket-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> A complete request.
	 */
	private function input( array $overrides = [] ): array {
		return array_merge(
			[
				'filename'   => 'my-theme.zip',
				'byteLength' => 4_194_304,
			],
			$overrides
		);
	}

	public function test_the_target_is_pending_because_nothing_exists_yet(): void {
		$target = $this->operation->resolveTarget( $this->input(), $this->context() );

		$this->assertSame( MediaUploadTicket::PENDING_KEY, $target->targetKey );
		$this->assertFalse( $target->exists );
	}

	public function test_the_plan_promises_the_declaration_and_nothing_else(): void {
		$context = $this->context();
		$planned = $this->operation->planChange( $this->operation->resolveTarget( $this->input(), $context ), $this->input(), $context );

		// If this list ever grows, the new field becomes permanent audit
		// content. That is the whole reason the assertion is exact.
		$this->assertSame( [ 'filename', 'byteLength' ], array_keys( $planned->afterFields ) );
		$this->assertSame( 'my-theme.zip', $planned->afterFields['filename'] );
		$this->assertSame( 4_194_304, $planned->afterFields['byteLength'] );
	}

	public function test_the_preview_says_where_the_file_goes_and_how_long_there_is_to_send_it(): void {
		$context = $this->context();
		$planned = $this->operation->planChange( $this->operation->resolveTarget( $this->input(), $context ), $this->input(), $context );

		$this->assertStringContainsString( 'sitehelm/v1/upload', (string) $planned->previewDetail['uploadUrl'] );
		$this->assertSame( UploadTickets::TTL_SECONDS, $planned->previewDetail['ttlSeconds'] );
		$this->assertSame( 'not checked', $planned->previewDetail['contentHash'] );
	}

	public function test_a_declared_hash_is_reported_as_checked(): void {
		$input   = $this->input( [ 'contentSha256' => str_repeat( 'A', 64 ) ] );
		$context = $this->context();
		$planned = $this->operation->planChange( $this->operation->resolveTarget( $input, $context ), $input, $context );

		$this->assertSame( 'checked', $planned->previewDetail['contentHash'] );
		// Stored lowercase, because that is the shape hash() produces and the
		// receiver compares against.
		$this->assertSame( str_repeat( 'a', 64 ), $planned->payload['contentSha256'] );
	}

	public function test_a_file_larger_than_the_site_accepts_is_refused_before_a_ticket_exists(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 2_097_152 );

		$input   = $this->input( [ 'byteLength' => 4_194_304 ] );
		$context = $this->context();

		try {
			$this->operation->planChange( $this->operation->resolveTarget( $input, $context ), $input, $context );
			$this->fail( 'An over-cap declaration was accepted.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
			$this->assertStringContainsString( '2097152', $refusal->getMessage() );
		}

		$this->assertSame( [], $this->wpdb->inserts );
	}

	public function test_a_filename_the_library_refuses_is_refused_here_too(): void {
		$input   = $this->input( [ 'filename' => 'shell.php' ] );
		$context = $this->context();

		$this->expectException( OperationException::class );
		$this->operation->planChange( $this->operation->resolveTarget( $input, $context ), $input, $context );
	}

	public function test_the_ticket_reaches_the_caller_but_was_never_promised(): void {
		$context = $this->context();
		$target  = $this->operation->resolveTarget( $this->input(), $context );
		$planned = $this->operation->planChange( $target, $this->input(), $context );

		$key = $this->operation->applyChange( $target, $planned, $context );
		$this->assertSame( MediaUploadTicket::PENDING_KEY, $key );

		$digest                 = (string) $this->wpdb->inserts[0]['data']['token_hash'];
		$this->wpdb->rowQueue[] = [
			'token_hash'   => $digest,
			'site_id'      => 'example.com',
			'user_id'      => 7,
			'operation_id' => UploadTickets::TICKET_OPERATION,
			'plan_body'    => (string) json_encode(
				[
					'filename'   => 'my-theme.zip',
					'byteLength' => 4_194_304,
					'sha256'     => null,
				]
			),
			'expires_at'   => time() + UploadTickets::TTL_SECONDS,
			'consumed_at'  => null,
		];

		$after = $this->operation->readBack( $key, $context );

		$this->assertSame( 64, strlen( (string) $after->fields['ticket'] ) );
		$this->assertSame( $digest, hash( 'sha256', (string) $after->fields['ticket'] ) );
		$this->assertStringContainsString( 'sitehelm/v1/upload', (string) $after->fields['uploadUrl'] );
		$this->assertNotSame( '', (string) $after->fields['expiresAt'] );

		// The three fields the caller needs are exactly the three the plan did
		// not promise, so none of them can reach the audit row.
		foreach ( [ 'ticket', 'uploadUrl', 'expiresAt' ] as $secret ) {
			$this->assertArrayNotHasKey( $secret, $planned->afterFields );
		}
	}

	public function test_a_ticket_that_cannot_be_recorded_authorises_nothing(): void {
		$this->wpdb->failInsert = true;

		$context = $this->context();
		$target  = $this->operation->resolveTarget( $this->input(), $context );
		$planned = $this->operation->planChange( $target, $this->input(), $context );

		try {
			$this->operation->applyChange( $target, $planned, $context );
			$this->fail( 'A refused write reported an authorised upload.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}
	}

	public function test_a_ticket_that_cannot_be_read_back_is_a_verification_failure(): void {
		$context = $this->context();
		$target  = $this->operation->resolveTarget( $this->input(), $context );
		$planned = $this->operation->planChange( $target, $this->input(), $context );
		$key     = $this->operation->applyChange( $target, $planned, $context );

		$this->wpdb->rowQueue[] = null;

		$this->expectException( OperationException::class );
		$this->operation->readBack( $key, $context );
	}

	public function test_there_is_nothing_to_restore_because_a_ticket_changes_nothing(): void {
		try {
			$this->operation->restore( [], $this->context() );
			$this->fail( 'A ticket reported a rollback it cannot perform.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		}
	}

	public function test_nothing_is_snapshotted_because_nothing_is_replaced(): void {
		$context = $this->context();

		$this->assertNull(
			$this->operation->captureSnapshot( $this->operation->resolveTarget( $this->input(), $context ), $context )
		);
	}

	public function test_the_definition_asks_for_the_upload_capability_and_promises_no_repeat(): void {
		$definition = MediaUploadTicket::definition();

		$this->assertSame( 'media-upload-ticket', $definition->id );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertFalse( $definition->isDestructive );
	}
}
