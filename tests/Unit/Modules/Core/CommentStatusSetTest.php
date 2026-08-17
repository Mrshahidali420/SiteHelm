<?php
/**
 * Tests for comment-status-set.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\CommentFields;
use SiteHelm\Modules\Core\CommentStatusSet;
use SiteHelm\Modules\Core\CommentTarget;
use SiteHelm\Tests\Doubles\CommentWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * All six phases of the status write, driven in the order the engine drives them.
 *
 * THE ROUND TRIP IS THE LOAD-BEARING TEST. plan → apply → readBack runs over one
 * mutable store, and the promise is compared against what actually came back, so a
 * status translated wrongly on either side of the three vocabularies fails here
 * rather than writing a value nothing in WordPress displays.
 */
final class CommentStatusSetTest extends TestCase {

	use CommentWordPressStubs;

	private CommentStatusSet $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installCommentStubs();
		$this->operation = new CommentStatusSet( new CommentTarget() );
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_declares_a_previewed_snapshotted_rollbackable_write(): void {
		$definition = CommentStatusSet::definition();

		$this->assertSame( 'comment-status-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * Trash and spam are statuses on a row that stays where it is, and the map the
	 * operation writes through cannot express a deletion at all. Declaring the
	 * write destructive would make every client warn about a reversible change.
	 */
	public function test_the_write_is_not_destructive_because_no_transition_deletes_anything(): void {
		$this->assertFalse( CommentStatusSet::definition()->isDestructive );
	}

	public function test_the_input_enum_is_the_settable_status_list(): void {
		$schema = CommentStatusSet::definition()->inputSchema;

		$this->assertSame( CommentFields::SETTABLE_STATUSES, $schema['properties']['status']['enum'] );
		$this->assertSame( [ 'id', 'status' ], $schema['required'] );
		$this->assertSame( false, $schema['additionalProperties'] );
	}

	public function test_an_absent_comment_is_refused_with_a_remedy_that_names_the_read(): void {
		try {
			$this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );
			$this->fail( 'A comment that does not exist must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringContainsString( 'comment-list', (string) $exception->remediation );
		}
	}

	public function test_the_resolved_target_carries_the_comment_key_and_its_projection(): void {
		$this->seedComment( 118, [ 'comment_approved' => '0' ] );

		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		$this->assertSame( 'comment:118', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'pending', $state->fields['status'] );
	}

	/**
	 * A write validates its own payload rather than assuming a caller reached it
	 * through the one path that validated it. The consequence here is sharper than
	 * a normalisation: an unrecognised status passed to
	 * `wp_set_comment_status()` reaches the column as-is.
	 */
	public function test_a_status_outside_the_settable_list_is_refused_in_plan(): void {
		$this->seedComment( 118 );
		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		foreach ( [ 'post-trashed', 'delete', 'approve', '', '1' ] as $status ) {
			try {
				$this->operation->planChange( $state, [ 'id' => 118, 'status' => $status ], $this->context() );
				$this->fail( "Status '{$status}' must be refused." );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			}
		}
	}

	/**
	 * WordPress owns the status of a comment whose post is trashed and restores
	 * the prior status when the post returns, so a status written here would have
	 * an expiry date the caller was never told about.
	 */
	public function test_a_comment_on_a_trashed_post_is_refused_with_the_real_remedy(): void {
		$this->seedComment( 118, [ 'comment_approved' => 'post-trashed' ] );
		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		try {
			$this->operation->planChange( $state, [ 'id' => 118, 'status' => 'approved' ], $this->context() );
			$this->fail( 'A comment on a trashed post must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'Restore the parent post', (string) $exception->remediation );
		}

		$this->assertSame( [], $this->statusWrites );
	}

	public function test_the_plan_promises_the_requested_status(): void {
		$this->seedComment( 118, [ 'comment_approved' => '0' ] );
		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		$planned = $this->operation->planChange( $state, [ 'id' => 118, 'status' => 'approved' ], $this->context() );

		$this->assertSame( [ 'status' => 'approved' ], $planned->payload );
		$this->assertSame( [ 'status' => 'approved' ], $planned->afterFields );
		$this->assertSame( CommentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	public function test_the_snapshot_records_the_reported_status_not_the_column_value(): void {
		$this->seedComment( 118, [ 'comment_approved' => '0' ] );
		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		$this->assertSame(
			[
				'comment_id' => 118,
				'status'     => 'pending',
			],
			$this->operation->captureSnapshot( $state, $this->context() )
		);
	}

	public function test_capturing_the_snapshot_twice_is_side_effect_free_and_agrees(): void {
		$this->seedComment( 118, [ 'comment_approved' => '1' ] );
		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		$first  = $this->operation->captureSnapshot( $state, $this->context() );
		$second = $this->operation->captureSnapshot( $state, $this->context() );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->statusWrites );
	}

	/**
	 * Every settable status, written and read back over one mutable store.
	 *
	 * @dataProvider settableStatuses
	 *
	 * @param string $status   The status requested.
	 * @param string $argument The argument WordPress must be handed.
	 * @param string $stored   The column value that must end up stored.
	 */
	public function test_each_transition_writes_through_wordpress_and_verifies( string $status, string $argument, string $stored ): void {
		$this->seedComment( 118, [ 'comment_approved' => '0' ] );

		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );
		$planned = $this->operation->planChange( $state, [ 'id' => 118, 'status' => $status ], $context );

		$key = $this->operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'comment:118', $key );
		$this->assertSame( [ [ 'id' => 118, 'status' => $argument ] ], $this->statusWrites );
		$this->assertSame( $stored, $this->storedStatus( 118 ) );

		$after = $this->operation->readBack( $key, $context );

		$this->assertSame( $planned->afterFields['status'], $after->fields['status'] );
	}

	/**
	 * @return array<string, string[]> Status, WordPress argument, stored column.
	 */
	public static function settableStatuses(): array {
		return [
			'approved' => [ 'approved', 'approve', '1' ],
			'pending'  => [ 'pending', 'hold', '0' ],
			'spam'     => [ 'spam', 'spam', 'spam' ],
			'trash'    => [ 'trash', 'trash', 'trash' ],
		];
	}

	public function test_an_approved_plan_that_does_not_name_a_comment_is_refused(): void {
		$this->seedComment( 118 );
		$context = $this->context();

		try {
			$this->operation->applyChange(
				new TargetState( 'post:118', true, [] ),
				new PlannedChange( [ 'status' => 'approved' ], [ 'status' => 'approved' ] ),
				$context
			);
			$this->fail( 'A plan whose key is not a comment key must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->statusWrites );
	}

	public function test_a_refused_write_is_reported_when_wordpress_answers_a_bare_false(): void {
		$this->seedComment( 118 );
		$this->statusWriteFails = true;

		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );
		$planned = $this->operation->planChange( $state, [ 'id' => 118, 'status' => 'spam' ], $context );

		$this->expectException( OperationException::class );

		$this->operation->applyChange( $state, $planned, $context );
	}

	public function test_a_refused_write_is_reported_when_wordpress_answers_a_wp_error(): void {
		$this->seedComment( 118 );
		$this->statusWriteFails = true;
		$this->failWithWpError();

		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );
		$planned = $this->operation->planChange( $state, [ 'id' => 118, 'status' => 'spam' ], $context );

		try {
			$this->operation->applyChange( $state, $planned, $context );
			$this->fail( 'A WP_Error must be reported as a failed write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $exception->completedSteps );
		}
	}

	/**
	 * Verifying against a stale read would report a correct write as unapplied,
	 * which sends an operator looking for a bug in the site rather than the read.
	 */
	public function test_the_verification_read_clears_the_comment_cache_first(): void {
		$this->seedComment( 118 );

		$this->operation->readBack( 'comment:118', $this->context() );

		$this->assertSame( [ 118 ], $this->cacheCleared );
	}

	public function test_a_comment_that_cannot_be_re_read_fails_verification_and_cites_the_correlation(): void {
		try {
			$this->operation->readBack( 'comment:118', $this->context() );
			$this->fail( 'A comment that cannot be re-read must fail verification.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $exception->remediation );
		}
	}

	public function test_the_restore_writes_the_recorded_status_back(): void {
		$this->seedComment( 118, [ 'comment_approved' => 'spam' ] );

		$key = $this->operation->restore(
			[
				'comment_id' => 118,
				'status'     => 'approved',
			],
			$this->context()
		);

		$this->assertSame( 'comment:118', $key );
		$this->assertSame( '1', $this->storedStatus( 118 ) );
	}

	public function test_an_unusable_snapshot_refuses_the_rollback(): void {
		foreach ( [ [], [ 'comment_id' => 118 ], [ 'status' => 'approved' ], [ 'comment_id' => 0, 'status' => 'approved' ] ] as $state ) {
			try {
				$this->operation->restore( $state, $this->context() );
				$this->fail( 'An unusable snapshot must refuse the rollback.' );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
			}
		}
	}

	/**
	 * `post-trashed` belongs to the parent post's lifecycle. Replaying it would
	 * leave the comment holding a value the post's own untrash then overwrites.
	 */
	public function test_a_snapshot_recording_post_trashed_refuses_with_the_post_as_the_remedy(): void {
		$this->seedComment( 118 );

		try {
			$this->operation->restore(
				[
					'comment_id' => 118,
					'status'     => 'post-trashed',
				],
				$this->context()
			);
			$this->fail( 'A post-trashed snapshot must refuse the rollback.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
			$this->assertStringContainsString( 'Restore the parent post', (string) $exception->remediation );
		}

		$this->assertSame( [], $this->statusWrites );
	}

	public function test_a_restore_wordpress_refuses_is_reported_as_a_failed_execution(): void {
		$this->seedComment( 118 );
		$this->statusWriteFails = true;

		try {
			$this->operation->restore(
				[
					'comment_id' => 118,
					'status'     => 'approved',
				],
				$this->context()
			);
			$this->fail( 'A refused restore must be reported.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	/**
	 * The whole promise, over one store: a comment goes to spam and comes back.
	 */
	public function test_a_write_and_its_rollback_return_the_comment_to_where_it_started(): void {
		$this->seedComment( 118, [ 'comment_approved' => '1' ] );

		$context  = $this->context();
		$state    = $this->operation->resolveTarget( [ 'id' => 118 ], $context );
		$snapshot = $this->operation->captureSnapshot( $state, $context );
		$planned  = $this->operation->planChange( $state, [ 'id' => 118, 'status' => 'spam' ], $context );

		$this->operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'spam', $this->operation->readBack( 'comment:118', $context )->fields['status'] );

		$this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( 'approved', $this->operation->readBack( 'comment:118', $context )->fields['status'] );
	}
}
