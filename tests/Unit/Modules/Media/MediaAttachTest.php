<?php
/**
 * Tests for MediaAttach (REQ-0025).
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
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaAttach;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0025: an operator attaches a library asset to the post that uses it, or
 * detaches it.
 *
 * The capability check on the DESTINATION post is the reason several tests here
 * call planChange() twice. The policy engine resolves one target id from
 * arguments['id'] and never sees the payload, so the destination check can only
 * live inside planChange() — and that placement is only as strong as a gate
 * check because the change engine re-runs planChange() at apply with the payload
 * recovered from the stored plan. A test that planned once could not tell the
 * two placements apart.
 */
final class MediaAttachTest extends TestCase {

	private MediaAttach $operation;

	private stdClass $attachment;

	private stdClass $destination;

	/** Whether the resolved user currently holds edit_post on the DESTINATION post. */
	private bool $mayEditDestination = true;

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaAttach( $fields, new MediaTarget( $fields ) );

		$this->updates            = [];
		$this->callOrder          = [];
		$this->mayEditDestination = true;

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = 'Shot on a Tuesday.';
		$this->attachment->post_content   = 'A long description of the cat.';
		$this->attachment->post_parent    = 0;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		$this->destination            = new stdClass();
		$this->destination->ID        = 42;
		$this->destination->post_type = 'post';

		// A SECOND attachment, so "the parent must not itself be an attachment"
		// is testable against a real attachment rather than against nothing.
		$other            = new stdClass();
		$other->ID        = 200;
		$other->post_type = 'attachment';

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => [],
			]
		);
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );
		// $single-aware, because MediaFields::read() asks for the alt with
		// $single = true and casts the answer to string. A fake that answered []
		// to every call would make every read in this file emit an
		// array-to-string warning, which PHPUnit converts into an error — the
		// operation under test would never be reached at all.
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single ? '' : []
		);

		// MediaFields::read() calls both of these on every path once
		// get_attached_file() answers a non-empty string, so faking
		// get_attached_file() without them fatals on an undefined function.
		// Same pair, same aliases, as MediaGetTest.
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_filesize' )->justReturn( 0 );

		// get_post( 0 ) answers $GLOBALS['post'] in core, and the fake reproduces
		// that rather than answering null, because the identity check in the
		// operation exists precisely for it. A fake that returned null for 0 would
		// make that check unreachable and therefore deletable without any test
		// noticing.
		Functions\when( 'get_post' )->alias(
			function ( $id = null ) use ( $other ) {
				$map = [
					108 => $this->attachment,
					42  => $this->destination,
					200 => $other,
				];

				if ( empty( $id ) ) {
					return $this->destination;
				}

				return $map[ (int) $id ] ?? null;
			}
		);

		// True for the attachment always — the gate already required it — and
		// controllable for the destination, which is the capability this operation
		// checks itself.
		Functions\when( 'user_can' )->alias(
			fn( $user, $capability, ...$args ): bool => 42 === (int) ( $args[0] ?? 0 )
				? $this->mayEditDestination
				: true
		);

		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				foreach ( $postarr as $column => $value ) {
					if ( 'ID' === $column ) {
						continue;
					}
					$this->attachment->{$column} = $value;
				}

				return (int) $postarr['ID'];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ): bool {
				$this->callOrder[] = 'update_post_meta';

				return true;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-2',
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
	 * Runs the WHOLE write — plan then apply — and reports the refusal without
	 * letting it escape.
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

	/**
	 * Plans alone and reports the outcome without letting a throwable escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array{0: PlannedChange|null, 1: string} The planned change or null,
	 *                                                 and a description of any
	 *                                                 throwable.
	 */
	private function planOutcome( array $input ): array {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );

			return [ $this->operation->planChange( $current, $input, $context ), 'the plan threw nothing' ];
		} catch ( Throwable $error ) {
			return [ null, 'the plan threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0025(): void {
		$definition = MediaAttach::definition();

		$this->assertSame( 'media-attach', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse(
			$definition->isDestructive,
			'post_parent is a pointer, the snapshot restores it exactly, and declaring this destructive would force preview, snapshot and rollback all to required.'
		);
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id', 'parent' ], $definition->inputSchema['required'] );
		$this->assertSame( 1, $definition->inputSchema['properties']['id']['minimum'] );
		$this->assertSame(
			0,
			$definition->inputSchema['properties']['parent']['minimum'],
			'0 is a legal parent and means detach, so the bound must be 0 rather than 1.'
		);
	}

	public function test_the_plan_promises_only_the_parent(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'parent' => 42 ], $planned->afterFields );
		$this->assertSame( [ 'parent' => 42 ], $planned->payload );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_parent_of_zero_plans_a_detach_without_looking_for_a_post(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'     => 108,
				'parent' => 0,
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'parent' => 0 ], $planned->afterFields );
	}

	public function test_a_parent_that_names_nothing_is_refused_at_planning_time(): void {
		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 999,
			]
		);

		$this->assertSame(
			[],
			$this->callOrder,
			'A dangling parent must never reach wp_update_post: WriteVerifier classifies a silently-dropped value as an ADJUSTMENT and reports the write as a success.'
		);
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'The requested parent identifier does not name a content item on this site.',
			$refusal->getMessage()
		);
	}

	public function test_a_parent_that_is_itself_an_attachment_is_refused(): void {
		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 200,
			]
		);

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'The requested parent identifier does not name a content item on this site.',
			$refusal->getMessage()
		);
	}

	public function test_a_missing_parent_argument_is_refused_rather_than_defaulted_to_detach(): void {
		$refusal = $this->planAndApply( [ 'id' => 108 ] );

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'No parent identifier was supplied, so there is nothing to attach or detach.',
			$refusal->getMessage()
		);
	}

	public function test_a_caller_without_edit_post_on_the_destination_is_refused(): void {
		$this->mayEditDestination = false;

		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame(
			'Your WordPress user may not edit the requested parent content item.',
			$refusal->getMessage()
		);
	}

	public function test_a_caller_who_previews_with_the_capability_and_loses_it_cannot_apply(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$input   = [
			'id'     => 108,
			'parent' => 42,
		];

		$planned = $this->operation->planChange( $current, $input, $context );
		$this->assertSame( [ 'parent' => 42 ], $planned->afterFields );

		// The engine re-runs planChange() at apply with the payload recovered from
		// the stored plan. This is that second run, and it must refuse.
		$this->mayEditDestination = false;

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'A capability lost between preview and apply must stop the write.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::Forbidden, $error->errorCode );
		}

		$this->assertSame(
			[],
			$this->callOrder,
			'Nothing may be written after the second plan refuses.'
		);
	}

	public function test_apply_writes_the_parent_and_returns_the_target_key(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		$written = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'attachment:108', $written );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 42,
				],
			],
			$this->updates,
			'The write must name post_parent alone — never post_status, never post_name.'
		);
		$this->assertSame( 42, $this->attachment->post_parent );
	}

	public function test_apply_refuses_when_wordpress_silently_drops_the_parent(): void {
		// The exact shape interpretation I7 names: wp_update_post() accepts the
		// call, returns the attachment id, and stores a different post_parent.
		// WriteVerifier would classify that as an adjustment and report success,
		// so the operation has to measure it itself.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[]             = 'wp_update_post';
				$this->updates[]               = $postarr;
				$this->attachment->post_parent = 0;

				return (int) $postarr['ID'];
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A dropped post_parent must be refused rather than reported as an adjustment.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame(
				'WordPress did not store the requested parent for this media item.',
				$error->getMessage()
			);
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}
	}

	public function test_apply_refuses_when_wordpress_rejects_the_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( 'WordPress refused to change this media item\'s parent.', $error->getMessage() );
		}
	}

	public function test_the_snapshot_records_the_current_parent_and_the_identifier_only(): void {
		$this->attachment->post_parent = 42;

		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame( [ 'parent', 'post_id' ], array_keys( (array) $snapshot ) );
		$this->assertSame( 108, $snapshot['post_id'] );
		$this->assertSame( 42, $snapshot['parent'] );
	}

	public function test_the_snapshot_records_a_detached_attachment_as_zero_rather_than_null(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame(
			0,
			$snapshot['parent'],
			'Returning null would be read as "nothing recoverable", and this operation\'s snapshot policy is required, so an ordinary detached attachment could never be planned.'
		);
	}

	public function test_the_snapshot_is_side_effect_free_and_identical_when_called_twice(): void {
		$current = $this->currentState();
		$context = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_the_snapshot_is_null_for_a_target_key_that_names_no_identifier(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:new', true, [] ),
			$this->makeContext()
		);

		$this->assertNull( $snapshot );
	}

	/**
	 * Separate from the no-identifier case above, and the target key is a
	 * PERFECTLY GOOD one on purpose: it isolates the `exists` gate. A key that
	 * named no identifier would be refused by the second guard whether the first
	 * one were there or not, so the two together could not tell a missing
	 * `exists` check from a present one.
	 */
	public function test_the_snapshot_is_null_for_a_target_that_does_not_exist_yet(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:108', false, [] ),
			$this->makeContext()
		);

		$this->assertNull( $snapshot );
	}

	/**
	 * The engine hands applyChange() the target state it planned against. A state
	 * whose key names no identifier cannot be written, and it must refuse rather
	 * than reach wp_update_post() with a null id — which would report a write
	 * against whichever row WordPress coerced that null to.
	 */
	public function test_apply_refuses_when_the_planned_target_key_names_no_media_item(): void {
		$context = $this->makeContext();
		$planned = $this->operation->planChange(
			$this->currentState(),
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		try {
			$this->operation->applyChange(
				new TargetState( 'attachment:new', true, [] ),
				$planned,
				$context
			);
			$this->fail( 'A target key naming no media item must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}

		$this->assertSame(
			[],
			$this->callOrder,
			'Nothing may be written when the planned target cannot be identified.'
		);
	}

	public function test_a_rollback_puts_the_parent_back_and_names_no_other_column(): void {
		$context  = $this->makeContext();
		$current  = $this->currentState();
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );
		$this->updates = [];

		$restored = $this->operation->restore( $snapshot, $context );

		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 0,
				],
			],
			$this->updates,
			'Detaching is reversible precisely because the snapshot records the pointer and the restore writes it back, which is why this operation is not destructive.'
		);
		$this->assertSame( 0, $this->attachment->post_parent );
	}

	public function test_read_back_returns_the_persisted_state(): void {
		$state = $this->operation->readBack( 'attachment:108', $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 0, $state->fields['parent'] );
	}

	public function test_no_refusal_message_names_a_path_a_query_or_a_credential(): void {
		$refusals = [
			$this->planAndApply( [ 'id' => 108 ] ),
			$this->planAndApply(
				[
					'id'     => 108,
					'parent' => 999,
				]
			),
		];

		$this->mayEditDestination = false;
		$refusals[]               = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ '/', '\\', 'SELECT', 'wp_posts', 'Authorization', 'corr-media-2' ] as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $refusal->getMessage() );
			}
		}
	}
}
