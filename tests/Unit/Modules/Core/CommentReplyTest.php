<?php
/**
 * Tests for comment-reply.
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
use SiteHelm\Modules\Core\CommentReply;
use SiteHelm\Modules\Core\CommentTarget;
use SiteHelm\Tests\Doubles\CommentWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The reply, driven over a store that reproduces WordPress's reparenting rule.
 *
 * THE PARENT-STATUS REFUSAL IS THE TEST THAT EARNS ITS KEEP. `wp_new_comment()`
 * drops a parent it will not thread under and posts the comment at the top level
 * WITHOUT reporting anything, so the failure mode this operation exists to prevent
 * is a success response describing a reply that is not a reply. The double
 * reproduces that rule exactly, so the refusal is asserted against the behaviour
 * rather than against a comment about it.
 */
final class CommentReplyTest extends TestCase {

	use CommentWordPressStubs;

	private CommentReply $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installCommentStubs();
		$this->operation = new CommentReply( new CommentTarget() );
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

	/**
	 * Plans a reply beneath a seeded parent.
	 *
	 * @param string $body The reply body.
	 *
	 * @return array{0: TargetState, 1: PlannedChange} The parent state and the plan.
	 */
	private function plan( string $body = 'Fixed, thanks.' ): array {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );

		return [ $state, $this->operation->planChange( $state, [ 'id' => 118, 'content' => $body ], $context ) ];
	}

	public function test_the_definition_declares_a_previewed_write_that_snapshots_only_when_it_can(): void {
		$definition = CommentReply::definition();

		$this->assertSame( 'comment-reply', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * Posting the same reply twice posts two comments. Declaring it idempotent
	 * would invite a retry that duplicates a public comment.
	 */
	public function test_the_write_is_not_idempotent(): void {
		$this->assertFalse( CommentReply::definition()->isIdempotent );
	}

	public function test_the_body_is_bounded_by_the_column_the_reply_is_stored_in(): void {
		$schema = CommentReply::definition()->inputSchema;

		$this->assertSame( 1, $schema['properties']['content']['minLength'] );
		$this->assertSame( CommentFields::CONTENT_MAX_LENGTH, $schema['properties']['content']['maxLength'] );
		$this->assertSame( [ 'id', 'content' ], $schema['required'] );
		$this->assertSame( false, $schema['additionalProperties'] );
	}

	public function test_the_resolved_target_is_the_parent_comment(): void {
		$this->seedComment( 118 );

		$state = $this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );

		$this->assertSame( 'comment:118', $state->targetKey );
		$this->assertSame( 42, $state->fields['postId'] );
	}

	public function test_a_parent_that_does_not_exist_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->operation->resolveTarget( [ 'id' => 118 ], $this->context() );
	}

	/**
	 * @dataProvider emptyBodies
	 *
	 * @param string $body A body carrying no text.
	 */
	public function test_a_body_that_is_only_whitespace_is_refused( string $body ): void {
		$this->seedComment( 118 );
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );

		try {
			$this->operation->planChange( $state, [ 'id' => 118, 'content' => $body ], $context );
			$this->fail( 'A reply with no body must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @return array<string, string[]> Bodies that trim to nothing.
	 */
	public static function emptyBodies(): array {
		return [
			'empty'      => [ '' ],
			'spaces'     => [ '   ' ],
			'a newline'  => [ "\n" ],
			'whitespace' => [ " \t\r\n " ],
		];
	}

	public function test_a_body_longer_than_the_column_is_refused_before_it_is_truncated(): void {
		$this->seedComment( 118 );
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );

		try {
			$this->operation->planChange(
				$state,
				[ 'id' => 118, 'content' => str_repeat( 'a', CommentFields::CONTENT_MAX_LENGTH + 1 ) ],
				$context
			);
			$this->fail( 'An oversized reply must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * The refusal that stops a reply from silently becoming a top-level comment.
	 *
	 * @dataProvider unreplyableParents
	 *
	 * @param string $stored The parent's stored status column.
	 */
	public function test_a_parent_wordpress_will_not_thread_under_is_refused( string $stored ): void {
		$this->seedComment( 118, [ 'comment_approved' => $stored ] );
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );

		try {
			$this->operation->planChange( $state, [ 'id' => 118, 'content' => 'Reply.' ], $context );
			$this->fail( "A parent stored as '{$stored}' must be refused." );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'comment-status-set', (string) $exception->remediation );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * @return array<string, string[]> Stored statuses WordPress refuses to thread under.
	 */
	public static function unreplyableParents(): array {
		return [
			'spam'         => [ 'spam' ],
			'trash'        => [ 'trash' ],
			'post-trashed' => [ 'post-trashed' ],
		];
	}

	/**
	 * The double drops the parent exactly as WordPress does, so if the refusal were
	 * ever removed this is what the caller would silently receive.
	 */
	public function test_wordpress_really_would_reparent_a_spam_parent_to_the_top_level(): void {
		$this->seedComment( 118, [ 'comment_approved' => 'spam' ] );

		$created = wp_new_comment(
			[
				'comment_post_ID' => 42,
				'comment_parent'  => 118,
				'comment_content' => 'Reply.',
			],
			true
		);

		$this->assertSame( '0', $this->comments[ $created ]['comment_parent'] );
	}

	public function test_a_pending_parent_is_allowed_and_carries_a_warning_about_visibility(): void {
		$this->seedComment( 118, [ 'comment_approved' => '0' ] );

		[ , $planned ] = $this->plan();

		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'awaiting moderation', $planned->warnings[0] );
	}

	public function test_an_approved_parent_carries_no_warning(): void {
		$this->seedComment( 118, [ 'comment_approved' => '1' ] );

		[ , $planned ] = $this->plan();

		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_user_who_cannot_be_resolved_to_an_account_is_refused(): void {
		$this->seedComment( 118 );
		$this->userExists = false;

		$context = $this->context();
		$state   = $this->operation->resolveTarget( [ 'id' => 118 ], $context );

		try {
			$this->operation->planChange( $state, [ 'id' => 118, 'content' => 'Reply.' ], $context );
			$this->fail( 'A reply with no resolvable author must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	/**
	 * The promise covers what this operation controls exactly. The body is absent
	 * on purpose: kses and `preprocess_comment` may legitimately rewrite it, and a
	 * promised body would turn each of those into a failed verification.
	 */
	public function test_the_promise_covers_the_thread_and_the_status_but_not_the_body(): void {
		$this->seedComment( 118 );

		[ , $planned ] = $this->plan( 'Fixed, thanks.' );

		$this->assertSame(
			[
				'parentId' => 118,
				'postId'   => 42,
				'status'   => CommentFields::STATUS_APPROVED,
			],
			$planned->afterFields
		);
		$this->assertArrayNotHasKey( 'content', $planned->afterFields );
	}

	/**
	 * The operator still approves the exact text, one layer down where a difference
	 * is informative rather than a verification failure.
	 */
	public function test_the_preview_detail_shows_the_body_and_the_author_that_will_be_used(): void {
		$this->seedComment( 118 );

		[ , $planned ] = $this->plan( '  Fixed, thanks.  ' );

		$this->assertSame(
			[
				'author'  => 'Site Editor',
				'content' => 'Fixed, thanks.',
			],
			$planned->previewDetail
		);
	}

	public function test_nothing_is_captured_because_the_reply_does_not_exist_yet(): void {
		$this->seedComment( 118 );

		[ $state ] = $this->plan();

		$this->assertNull( $this->operation->captureSnapshot( $state, $this->context() ) );
	}

	/**
	 * A caller-supplied display name would be a way to put words on a public page
	 * under any name, so every identity member comes from the resolved account.
	 */
	public function test_the_reply_is_authored_by_the_acting_wordpress_user(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$this->operation->applyChange( $state, $planned, $this->context() );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'Site Editor', $this->inserts[0]['comment_author'] );
		$this->assertSame( 'editor@example.com', $this->inserts[0]['comment_author_email'] );
		$this->assertSame( 'https://example.com', $this->inserts[0]['comment_author_url'] );
		$this->assertSame( 7, $this->inserts[0]['user_id'] );
		$this->assertSame( 'comment', $this->inserts[0]['comment_type'] );
	}

	/**
	 * The capability required here is the one WordPress uses to decide who may
	 * approve comments, so a moderator's own reply sitting in the queue would be an
	 * entry only they could clear.
	 */
	public function test_the_reply_is_approved_on_creation(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$key = $this->operation->applyChange( $state, $planned, $this->context() );

		$this->assertSame( 1, $this->inserts[0]['comment_approved'] );
		$this->assertSame( 'approved', $this->operation->readBack( $key, $this->context() )->fields['status'] );
	}

	public function test_the_created_reply_threads_beneath_the_parent_on_the_parents_post(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan( 'Fixed, thanks.' );

		$key   = $this->operation->applyChange( $state, $planned, $this->context() );
		$after = $this->operation->readBack( $key, $this->context() );

		$this->assertSame( 'comment:500', $key );
		$this->assertSame( 118, $after->fields['parentId'] );
		$this->assertSame( 42, $after->fields['postId'] );
		$this->assertSame( 'Fixed, thanks.', $after->fields['content'] );
	}

	/**
	 * The whole promise, checked against what actually came back.
	 */
	public function test_every_promised_field_is_satisfied_by_the_verification_read(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$after = $this->operation->readBack(
			$this->operation->applyChange( $state, $planned, $this->context() ),
			$this->context()
		);

		foreach ( $planned->afterFields as $field => $promised ) {
			$this->assertSame( $promised, $after->fields[ $field ], "The promised {$field} was not stored." );
		}
	}

	public function test_an_author_who_disappears_between_preview_and_apply_is_refused(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$this->userExists = false;

		try {
			$this->operation->applyChange( $state, $planned, $this->context() );
			$this->fail( 'An author who no longer exists must stop the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	public function test_a_refused_insert_is_reported_when_wordpress_answers_a_bare_false(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$this->insertFails = true;

		$this->expectException( OperationException::class );

		$this->operation->applyChange( $state, $planned, $this->context() );
	}

	public function test_a_refused_insert_is_reported_when_wordpress_answers_a_wp_error(): void {
		$this->seedComment( 118 );

		[ $state, $planned ] = $this->plan();

		$this->insertFails = true;
		$this->failWithWpError();

		try {
			$this->operation->applyChange( $state, $planned, $this->context() );
			$this->fail( 'A WP_Error must be reported as a failed insert.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertStringContainsString( 'nothing was posted', (string) $exception->remediation );
		}
	}

	public function test_a_reply_that_cannot_be_re_read_fails_verification(): void {
		try {
			$this->operation->readBack( 'comment:500', $this->context() );
			$this->fail( 'A reply that cannot be re-read must fail verification.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}

	/**
	 * `captureSnapshot()` runs before the reply exists, so there is no moment at
	 * which its identifier could have been recorded. The refusal names the
	 * reversible way to withdraw it rather than leaving the operator to guess.
	 */
	public function test_the_rollback_always_refuses_and_names_the_reversible_withdrawal(): void {
		foreach ( [ [], [ 'comment_id' => 500 ] ] as $restoreState ) {
			try {
				$this->operation->restore( $restoreState, $this->context() );
				$this->fail( 'A posted reply cannot be rolled back.' );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
				$this->assertStringContainsString( 'comment-status-set', (string) $exception->remediation );
			}
		}
	}
}
