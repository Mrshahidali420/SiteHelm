<?php
/**
 * Tests for MediaDelete (REQ-0124).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
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
use SiteHelm\Modules\Media\MediaDelete;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0124: an operator removes a media item and the files behind it for good.
 *
 * The tests that matter most here are the ones about what the operation does
 * NOT promise. A delete is the one media write with no way back, so the
 * declared policies, the refusing restore(), and the inverted read-back are the
 * behaviour rather than the paperwork around it, and each is asserted by name.
 */
final class MediaDeleteTest extends TestCase {

	private MediaDelete $operation;

	private stdClass $attachment;

	/** Whether the attachment still exists as far as the fakes are concerned. */
	private bool $exists = true;

	/** Whether the resolved user holds delete_post on the attachment. */
	private bool $mayDelete = true;

	/** Whether wp_delete_attachment reports success. */
	private bool $deleteSucceeds = true;

	/** @var int[] The content identifiers using the attachment as a featured image. */
	private array $featuredIn = [];

	/** @var array<int, array{0: int, 1: bool}> Every wp_delete_attachment call, in order. */
	private array $deletes = [];

	/** @var string[] The size names the attachment's metadata reports. */
	private array $sizes = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaDelete( $fields, new MediaTarget( $fields ) );

		$this->exists         = true;
		$this->mayDelete      = true;
		$this->deleteSucceeds = true;
		$this->featuredIn     = [];
		$this->deletes        = [];
		$this->sizes          = [ 'thumbnail', 'medium', 'large' ];

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = '';
		$this->attachment->post_content   = '';
		$this->attachment->post_parent    = 0;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_filesize' )->justReturn( 0 );

		// $single-aware: MediaFields::read() asks for the alt text with
		// $single = true and casts the answer to string, and a fake answering []
		// to every call turns every read into an array-to-string warning that
		// PHPUnit raises as an error before the operation is reached.
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single ? '' : []
		);

		Functions\when( 'wp_get_attachment_metadata' )->alias(
			fn() => [
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => array_fill_keys( $this->sizes, [ 'file' => 'cat-x.jpg' ] ),
			]
		);

		// get_post( 0 ) answers $GLOBALS['post'] in core rather than null, so the
		// fake answers the attachment for an empty id too. Anything but 108 is
		// unknown, which is what makes the not-found path reachable.
		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => $this->exists && 108 === (int) $id ? $this->attachment : null
		);

		Functions\when( 'user_can' )->alias(
			fn( $user, $capability, ...$args ): bool => 'delete_post' === $capability
				? $this->mayDelete
				: true
		);

		Functions\when( 'get_posts' )->alias( fn( $args = [] ): array => $this->featuredIn );

		Functions\when( 'wp_delete_attachment' )->alias(
			function ( $attachment_id, $force_delete = false ) {
				$this->deletes[] = [ (int) $attachment_id, (bool) $force_delete ];

				if ( ! $this->deleteSucceeds ) {
					return false;
				}

				$this->exists = false;

				return $this->attachment;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-delete',
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
	 * Runs the whole write and reports the refusal without letting it escape.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function runWrite(): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( [ 'id' => 108 ], $context );
			$planned = $this->operation->planChange( $current, [ 'id' => 108 ], $context );
			$written = $this->operation->applyChange( $current, $planned, $context );
			$this->operation->readBack( $written, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	public function test_the_definition_promises_a_preview_and_no_way_back(): void {
		$definition = MediaDelete::definition();

		$this->assertSame( 'media-delete', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( Risk::High, $definition->risk, 'Extreme is reserved for operations whose payload is a program; this is a delete.' );
		$this->assertSame( [ 'delete_post' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );

		// The pair that says "no way back" in the contract rather than only in
		// the prose. losesStateWithoutSnapshot would force both of these to Required.
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertFalse( $definition->losesStateWithoutSnapshot );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertFalse( $definition->isReadOnly );
	}

	public function test_it_deletes_the_item_and_reports_it_gone(): void {
		$this->assertNull( $this->runWrite() );

		$this->assertSame( [ [ 108, true ] ], $this->deletes, 'The delete must be forced, so the media trash cannot make the outcome depend on the site.' );

		$state = $this->operation->readBack( 'attachment:108', $this->makeContext() );

		$this->assertFalse( $state->exists );
		$this->assertSame( [ 'deleted' => true ], $state->fields );
	}

	public function test_it_refuses_when_the_user_may_not_delete_the_item(): void {
		$this->mayDelete = false;

		$refusal = $this->runWrite();

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame( [], $this->deletes );
	}

	public function test_it_asks_for_the_capability_again_at_apply(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 108 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 108 ], $context );

		// The right is taken away between the approved plan and the apply, which
		// is the only window the re-check exists for.
		$this->mayDelete = false;

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'The apply must ask for the capability again rather than trusting the plan.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::Forbidden, $error->errorCode );
		}

		$this->assertSame( [], $this->deletes );
	}

	public function test_the_plan_names_the_file_the_resized_copies_and_the_content_that_loses_it(): void {
		$this->featuredIn = [ 42, 17 ];

		$context = $this->makeContext();
		$planned = $this->operation->planChange( $this->currentState(), [ 'id' => 108 ], $context );

		$this->assertSame( [ 'deleted' => true ], $planned->afterFields );
		$this->assertSame( [ 'deleted' ], $planned->fieldOrder );

		$warnings = implode( "\n", $planned->warnings );

		$this->assertStringContainsString( 'cat.jpg', $warnings );
		$this->assertStringContainsString( '3 resized copies', $warnings );
		$this->assertStringContainsString( 'stops loading once the file is gone', $warnings );
		$this->assertStringContainsString( '17, 42', $warnings, 'The using content is named, ascending.' );
	}

	public function test_the_plan_says_nothing_about_resized_copies_when_there_are_none(): void {
		$this->sizes = [];

		$planned = $this->operation->planChange( $this->currentState(), [ 'id' => 108 ], $this->makeContext() );

		$this->assertStringNotContainsString( 'resized copies', implode( "\n", $planned->warnings ) );
	}

	public function test_the_plan_stops_naming_using_content_past_the_limit(): void {
		$this->featuredIn = range( 1, 21 );

		$planned = $this->operation->planChange( $this->currentState(), [ 'id' => 108 ], $this->makeContext() );
		$warnings = implode( "\n", $planned->warnings );

		$this->assertStringContainsString( 'More than 20 content items', $warnings );
		$this->assertStringNotContainsString( ', 21.', $warnings );
	}

	public function test_it_records_no_snapshot(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( $this->currentState(), $this->makeContext() ),
			'A snapshot of a deleted file would tell an operator they had a way back when they do not.'
		);
	}

	public function test_it_reports_a_delete_wordpress_did_not_do(): void {
		// WordPress answers success while the item is still readable, which is
		// the one thing a delete read-back exists to catch.
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( $attachment_id, $force_delete = false ) {
				$this->deletes[] = [ (int) $attachment_id, (bool) $force_delete ];

				return $this->attachment;
			}
		);

		$refusal = $this->runWrite();

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode );
	}

	public function test_it_reports_a_refused_delete_as_an_execution_failure(): void {
		$this->deleteSucceeds = false;

		$refusal = $this->runWrite();

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_it_refuses_an_approved_plan_that_names_no_item(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange( $current, [ 'id' => 108 ], $context );

		try {
			$this->operation->applyChange( new TargetState( 'nothing:at:all', true, [] ), $planned, $context );
			$this->fail( 'An approved plan that names no media item must not reach WordPress.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}

		$this->assertSame( [], $this->deletes );
	}

	public function test_it_refuses_an_unknown_item(): void {
		$this->exists = false;

		try {
			$this->operation->resolveTarget( [ 'id' => 108 ], $this->makeContext() );
			$this->fail( 'An item that cannot be read must not be planned against.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::TargetNotFound, $error->errorCode );
		}
	}

	public function test_restore_refuses_because_a_delete_is_a_one_way_door(): void {
		try {
			$this->operation->restore( [], $this->makeContext() );
			$this->fail( 'restore() must refuse rather than pretend a delete can be undone.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $error->errorCode );
		}
	}
}
