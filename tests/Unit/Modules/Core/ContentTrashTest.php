<?php
/**
 * Tests for ContentTrash (REQ-0019).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteVerifier;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\ContentTrash;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0019: retire content recoverably.
 *
 * The doubles here are typed like the platform on purpose, and one of them is
 * shaped to make a specific defect observable rather than merely to be
 * convenient: `wp_trash_post()` hands back the PRE-trash post object on success,
 * so the double returns a clone taken before it mutates the stub. A double that
 * returned the post as it is AFTER the call would make
 * test_apply_change_does_not_trust_the_returned_posts_status pass whether or not
 * the operation reads the return value, which is precisely the kind of blind
 * harness this branch has paid for before.
 *
 * Every refusal asserts the MESSAGE as well as the code: applyChange() raises
 * ExecutionFailed from one branch reached by three different platform states,
 * and planChange() raises Forbidden, so a code-only assertion would not separate
 * them from anything else this file can produce.
 */
final class ContentTrashTest extends TestCase {

	private ContentTrash $operation;

	/**
	 * The stub post every double reads and the trash double mutates.
	 *
	 * @var stdClass
	 */
	private stdClass $post;

	/** @var array<int, array<string, mixed>> Every wp_update_post payload. */
	private array $writes = [];

	/** @var int[] Every post id handed to wp_trash_post. */
	private array $trashed = [];

	/** @var string[] Every meta key handed to delete_post_meta. */
	private array $metaDeletes = [];

	/** @var int[] Every post id handed to wp_untrash_post_comments. */
	private array $commentUntrashes = [];

	/**
	 * An ordered log of the calls restore() makes, so ordering can be asserted
	 * rather than inferred from three separate unordered records.
	 *
	 * @var string[]
	 */
	private array $callLog = [];

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentTrash( $fields, new ContentTarget( $fields ) );

		$this->writes           = [];
		$this->trashed          = [];
		$this->metaDeletes      = [];
		$this->commentUntrashes = [];
		$this->callLog          = [];

		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$this->stubPost();

		// Typed like the platform, and the reason is in the class docblock: the
		// object handed back on success is the post as it was BEFORE the update,
		// so its post_status is the OLD one.
		Functions\when( 'wp_trash_post' )->alias(
			function ( int $post_id ) {
				$this->trashed[] = $post_id;

				if ( 'trash' === $this->post->post_status ) {
					return false;
				}

				$before                  = clone $this->post;
				$this->post->post_status = 'trash';
				$this->post->post_name   = $this->post->post_name . '__trashed';

				return $before;
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[]  = $postarr;
				$this->callLog[] = 'wp_update_post';

				return (int) $postarr['ID'];
			}
		);

		Functions\when( 'wp_untrash_post_comments' )->alias(
			function ( int $post_id ): void {
				$this->commentUntrashes[] = $post_id;
				$this->callLog[]          = 'wp_untrash_post_comments';
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->metaDeletes[] = $key;
				$this->callLog[]     = 'delete_post_meta:' . $key;

				return true;
			}
		);
	}

	private function stubPost( string $status = 'draft', string $slug = 'original-title' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = $status;
		$post->post_title        = 'Original title';
		$post->post_name         = $slug;
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		$this->post = $post;

		Functions\when( 'get_post' )->alias( fn() => $this->post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
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

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertSame( 'draft', $state->fields['post_status'] );
		$this->assertSame( 'original-title', $state->fields['post_name'] );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * Both keys, written as a literal in the order ksort produces. A plan that
	 * promised only post_status would leave post_name changed but unpromised,
	 * which the engine reports as an unpromised change rather than as the
	 * intended half of the operation.
	 */
	public function test_the_plan_promises_the_trash_status_and_the_renamed_slug(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'post_name'   => 'original-title__trashed',
				'post_status' => 'trash',
			],
			$planned->afterFields
		);
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * The one part of the rename that IS a pure function of the input, so the one
	 * part the promise models exactly: core's
	 * wp_add_trashed_suffix_to_post_name_for_post() opens with
	 * `if ( str_ends_with( $post->post_name, '__trashed' ) ) { return
	 * $post->post_name; }` and touches nothing.
	 */
	public function test_the_plan_promises_an_already_trashed_slug_unchanged(): void {
		$this->stubPost( 'draft', 'already__trashed' );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'already__trashed', $planned->afterFields['post_name'] );
	}

	/**
	 * An empty prior slug promises the bare suffix rather than being special-cased.
	 * WordPress fills an empty slug from the title during the same insert, so
	 * whatever it stores is an adjustment either way, and a different promise here
	 * would only make the preview less honest. Pinned so a later "fix" that
	 * invented a title-derived promise has to argue with a test.
	 */
	public function test_an_empty_prior_slug_promises_the_bare_suffix(): void {
		$this->stubPost( 'draft', '' );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( '__trashed', $planned->afterFields['post_name'] );
	}

	public function test_the_promise_and_the_payload_are_the_same_two_keys(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( $planned->afterFields, $planned->payload );
		$this->assertSame( [ 'post_name', 'post_status' ], array_keys( $planned->payload ) );
	}

	public function test_capture_snapshot_records_every_restorable_field(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				// Deliberately empty, and legal: most posts have no excerpt. It is
				// what separates array_key_exists from isset or ! empty() in
				// ContentTarget's restore loop.
				'post_excerpt' => '',
				'post_id'      => 42,
				'post_name'    => 'original-title',
				'post_status'  => 'draft',
				'post_title'   => 'Original title',
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_moves_the_item_to_the_trash(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ 42 ], $this->trashed );
	}

	/**
	 * The idempotence case, and the reason the boolean is not the signal.
	 * wp_trash_post() returns FALSE for a post that is already trashed —
	 * `if ( 'trash' === $post->post_status ) { return false; }` — which means the
	 * promised state already holds. Treating it as failure would break the
	 * declared isIdempotent for the ordinary case of a retried apply.
	 */
	public function test_apply_change_succeeds_for_a_post_that_is_already_trashed(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->stubPost( 'trash', 'original-title__trashed' );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ 42 ], $this->trashed );
	}

	/**
	 * The same false, meaning the opposite thing: a pre_trash_post filter vetoed,
	 * or the inner wp_update_post() failed, and the post is still a draft. One
	 * return value, three meanings — which is why the status is measured instead.
	 */
	public function test_apply_change_reports_a_vetoed_trash_as_execution_failed(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		Functions\when( 'wp_trash_post' )->alias(
			function ( int $post_id ): bool {
				$this->trashed[] = $post_id;

				return false;
			}
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress did not move the content item to the trash.', $e->getMessage() );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	/**
	 * The shape a permanent delete leaves behind. planChange() refuses the
	 * configuration that causes it, but a filter on pre_trash_post can delete too,
	 * and hooks run after the guard has already answered.
	 */
	public function test_apply_change_reports_a_vanished_post_as_execution_failed(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		Functions\when( 'wp_trash_post' )->justReturn( null );
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress did not move the content item to the trash.', $e->getMessage() );
		}
	}

	/**
	 * THE TEST THAT FAILS IF ANYONE READS THE RETURN VALUE. wp_trash_post()
	 * returns the post as it was BEFORE the update, so the returned object's
	 * post_status is the OLD status; the stored row is trashed. A write judged
	 * from the returned object would conclude the trash did not happen and refuse
	 * a change that landed — leaving the engine to compensate a successful write.
	 */
	public function test_apply_change_does_not_trust_the_returned_posts_status(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$pre_trash              = new stdClass();
		$pre_trash->ID          = 42;
		$pre_trash->post_status = 'draft';
		$pre_trash->post_name   = 'original-title';

		Functions\when( 'wp_trash_post' )->alias(
			function ( int $post_id ) use ( $pre_trash ) {
				$this->trashed[]         = $post_id;
				$this->post->post_status = 'trash';
				$this->post->post_name   = 'original-title__trashed';

				return $pre_trash;
			}
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'draft', $pre_trash->post_status );
	}

	/**
	 * A stored post whose post_status is not a readable property — only a magic
	 * __get — is refused rather than accepted on the magic value.
	 *
	 * This is the input that makes `! isset( $stored->post_status )` the SOLE
	 * refusing term of the three-term measurement, and it is here because
	 * disclosing a term as "defence in depth" without first varying the input is
	 * the mistake this branch has corrected three times. Without it, deleting that
	 * term changes no answer: `(string) $missing` is '' for an ordinary object and
	 * for null alike, which fails the status comparison anyway.
	 *
	 * The shape is producible. get_post() forwards WP_Post::get_instance(), which
	 * reads the `posts` cache group, and a persistent object cache drop-in decides
	 * what that group hands back — a lazy proxy resolving members through __get is
	 * exactly what such a drop-in returns. WP_Post itself declares __isset and
	 * __get for its derived members, so the shape is native to the class.
	 *
	 * Refusing is the correct answer: a status this operation cannot read as a
	 * property is a status it has not measured, and reporting a write verified on
	 * the strength of a value produced by arbitrary code is what measurement
	 * exists to avoid. The write is not lost — the engine compensates from the
	 * snapshot and the refusal names it.
	 */
	public function test_apply_change_refuses_a_status_it_can_only_read_through_a_magic_getter(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		Functions\when( 'wp_trash_post' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( new MagicStatusPost() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress did not move the content item to the trash.', $e->getMessage() );
		}
	}

	public function test_read_back_reports_the_persisted_status(): void {
		$this->stubPost( 'trash', 'original-title__trashed' );

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 'trash', $state->fields['post_status'] );
		$this->assertSame( 'original-title__trashed', $state->fields['post_name'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $e->remediation );
		}
	}

	public function test_restore_writes_the_recorded_status_and_slug_back(): void {
		$this->assertSame( 'post:42', $this->operation->restore( $this->snapshot(), $this->makeContext() ) );

		$this->assertSame( 'draft', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
	}

	public function test_restore_clears_the_two_trash_meta_keys(): void {
		$this->operation->restore( $this->snapshot(), $this->makeContext() );

		$this->assertSame( [ '_wp_trash_meta_status', '_wp_trash_meta_time' ], $this->metaDeletes );
	}

	/**
	 * wp_trash_post_comments() hides EVERY comment on the post and stashes the
	 * prior statuses in _wp_trash_meta_comments_status. A restore that wrote back
	 * only the columns would put the post live again with every comment on it
	 * still hidden and report the rollback verified, and the admin's Restore
	 * button — the only UI that calls wp_untrash_post_comments() — is no longer
	 * offered once the post has left the trash. The recorded post id is asserted,
	 * not merely the fact of a call: a restore that untrashed the comments of some
	 * other post would be worse than one that untrashed none.
	 */
	public function test_restore_untrashes_the_comments_the_trash_hid(): void {
		$this->operation->restore( $this->snapshot(), $this->makeContext() );

		$this->assertSame( [ 42 ], $this->commentUntrashes );
	}

	/**
	 * The ordering, asserted as one ordered log rather than inferred from three
	 * unordered records.
	 *
	 * This DIVERGES FROM CORE and the divergence is deliberate: wp_untrash_post()
	 * deletes _wp_trash_meta_status and _wp_trash_meta_time BEFORE its
	 * wp_update_post(). The comment restoration is not a divergence — core calls
	 * wp_untrash_post_comments() after its update, exactly as this does.
	 *
	 * The reason for the divergence is what a FAILURE leaves behind, and the test
	 * below is the one that pins it. See ContentTrash::restore()'s docblock.
	 */
	public function test_restore_clears_the_trash_meta_after_the_column_write_not_before(): void {
		$this->operation->restore( $this->snapshot(), $this->makeContext() );

		$this->assertSame(
			[
				'wp_update_post',
				'wp_untrash_post_comments',
				'delete_post_meta:_wp_trash_meta_status',
				'delete_post_meta:_wp_trash_meta_time',
			],
			$this->callLog
		);
	}

	/**
	 * THE REASON THE CLEANUP RUNS LAST, asserted rather than argued.
	 *
	 * A refused column write leaves the post still in the trash, and the
	 * ExecutionFailed it raises tells the operator to recover another way — on a
	 * trashed post, that is the WordPress admin's Restore button, which is
	 * wp_untrash_post() reading exactly these two meta keys. Under core's own
	 * ordering, which deletes them before the update, this refusal would have
	 * removed the operator's fallback while reporting that the restore stopped.
	 *
	 * This test fails under core's order and passes under ours, which is what
	 * makes the divergence a decision with evidence rather than an accident. It is
	 * also the second killer for the reordering mutation, and the one whose
	 * assertion says WHY the order matters instead of only that it changed.
	 */
	public function test_restore_that_fails_the_column_write_leaves_the_trash_residue_intact(): void {
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		try {
			$this->operation->restore( $this->snapshot(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}

		$this->assertSame( [], $this->metaDeletes );
		$this->assertSame( [], $this->commentUntrashes );
	}

	/**
	 * A snapshot naming no target stops before anything at all, which the empty
	 * call log is what proves: a restore that cleared trash meta on post 0 or
	 * untrashed the comments of post 0 would have side effects on a rollback it
	 * then reported as unavailable.
	 */
	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'post_status' => 'draft' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( [], $this->callLog );
	}

	/**
	 * THE DESIGNED OUTCOME, pinned on the case that cannot be predicted at plan
	 * time. Core truncates the slug base to 191 characters and then runs
	 * wp_unique_post_slug(), which appends `-2`, `-3` and so on when the trashed
	 * name collides with another row — a query against whatever else exists at the
	 * moment of the insert. So the stored slug differs from BOTH the promise and
	 * the prior value, WriteVerifier's three-way rule reports it as ADJUSTED, and
	 * the write is applied. The operator is told the field changed and is shown
	 * the stored value in data.state; nothing fails.
	 *
	 * Run through the real WriteVerifier rather than restating its rule, because
	 * the claim being pinned is about the engine's classification and not about a
	 * restatement of it.
	 */
	public function test_a_slug_wordpress_uniquified_on_collision_is_an_adjustment_not_a_failure(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$outcome = ( new WriteVerifier( new PayloadNormalizer() ) )->classify(
			$planned,
			$current,
			$this->stateWith( 'trash', 'original-title__trashed-2' )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [ 'post_name' ], $outcome->adjustedFields );
	}

	/**
	 * The control for the test above, and the reason it is here rather than
	 * omitted as obvious: without it, `adjustedFields` reporting `post_name` would
	 * be indistinguishable from a verifier that names every promised field
	 * whatever happened. The exact case must come back with nothing adjusted.
	 */
	public function test_a_slug_wordpress_stored_verbatim_is_exact_rather_than_adjusted(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$outcome = ( new WriteVerifier( new PayloadNormalizer() ) )->classify(
			$planned,
			$current,
			$this->stateWith( 'trash', 'original-title__trashed' )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * The second control, on the other arm of the same three-way rule: a stored
	 * value equal to the PRIOR one is `not applied`, not an adjustment. This is
	 * what separates "WordPress renamed the slug differently" — which this
	 * operation expects and discloses — from "the rename never happened", which is
	 * a failed write. If this harness could not produce a not-applied verdict, the
	 * adjusted verdict above would prove nothing about the classification.
	 */
	public function test_a_slug_that_never_changed_is_reported_as_not_applied(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$outcome = ( new WriteVerifier( new PayloadNormalizer() ) )->classify(
			$planned,
			$current,
			$this->stateWith( 'trash', 'original-title' )
		);

		$this->assertFalse( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime. The payload is assembled exactly as
	 * ChangeEngine::apply() builds it, and checked against the schema the MODULE
	 * registered rather than a restatement of it.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$target = $this->operation->applyChange( $current, $planned, $context );
		$after  = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-trash' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the oneOf union: the plan branch.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-trash' )->outputSchema
		);
	}

	/**
	 * The declared policies, written as literals rather than read back from the
	 * enum cases, because the point is that THESE values were chosen. This is the
	 * only operation in V1 whose rollback is required, and the only one that
	 * declares delete_post.
	 */
	public function test_the_definition_declares_delete_post_and_a_required_rollback(): void {
		$definition = ContentTrash::definition();

		$this->assertSame( [ 'delete_post' ], $definition->requiredCapabilities );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		// True because docs/product/phase-2-foundation-contract.md's isDestructive
		// row names "moving content to trash" as its own definitional example. The
		// contract is frozen and governs; see the class docblock for the nuance
		// that argued the other way and why it does not win.
		$this->assertTrue( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
	}

	public function test_the_input_schema_takes_only_a_required_id_and_rejects_anything_else(): void {
		$schema = ContentTrash::definition()->inputSchema;

		$this->assertSame( [ 'id' ], $schema['required'] );
		$this->assertSame( [ 'id' ], array_keys( $schema['properties'] ) );
		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( 1, $schema['properties']['id']['minimum'] );
	}

	/**
	 * A site that defines EMPTY_TRASH_DAYS as 0 has the trash disabled, and
	 * wp_trash_post() then calls wp_delete_post( $id, true ) — a PERMANENT delete.
	 * This operation's required rollback could not reverse that: the row would be
	 * gone. So the refusal happens while planning, before anything executes.
	 *
	 * Process-isolated because a PHP constant cannot be undefined once set.
	 * Defining it in the shared bootstrap would make one branch or the other
	 * permanently unreachable, and defining it in a non-isolated test would leak
	 * into every test that ran afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_the_trash_disabled_is_refused(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );

		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame(
				'This site is configured to delete content permanently rather than move it to the trash, so this operation cannot retire content recoverably.',
				$e->getMessage()
			);
		}

		$this->assertSame( [], $this->trashed );
	}

	/**
	 * THE OTHER HALF OF THE SAME GUARD, and it needs its own isolated process for
	 * a reason found by mutation rather than by reading: nothing in the parent
	 * process ever defines EMPTY_TRASH_DAYS, so `defined()` short-circuits there
	 * and the `! constant()` term is NEVER EVALUATED in any non-isolated test.
	 * Deleting that term outright — leaving `if ( defined( 'EMPTY_TRASH_DAYS' ) )`
	 * — passed the entire 740-test suite, and would have refused every trash on
	 * every real WordPress site, because core defines the constant during
	 * wp_plugin_directory_constants() before any plugin loads and its default is
	 * 30. This test is the only thing that kills that mutation.
	 *
	 * 30 rather than 1, because 30 is core's own default in
	 * wp-includes/default-constants.php and therefore the value nearly every real
	 * site carries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_the_trash_enabled_is_planned_normally(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );

		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'post_name'   => 'original-title__trashed',
				'post_status' => 'trash',
			],
			$planned->afterFields
		);
	}

	/**
	 * The isolation itself, asserted. If the annotations above were ever dropped,
	 * EMPTY_TRASH_DAYS would leak into this process and every other test in the
	 * suite that plans a trash would start refusing — a failure that would be
	 * blamed on the wrong file. Named to sort after the isolated test.
	 */
	public function test_zz_the_disabled_trash_constant_did_not_leak_into_this_process(): void {
		$this->assertFalse( defined( 'EMPTY_TRASH_DAYS' ) );
	}

	/**
	 * The recorded restore state a trash of the stub post produces.
	 *
	 * @return array<string, mixed> The snapshot.
	 */
	private function snapshot(): array {
		return [
			'post_content' => '<p>Original body.</p>',
			'post_excerpt' => '',
			'post_id'      => 42,
			'post_name'    => 'original-title',
			'post_status'  => 'draft',
			'post_title'   => 'Original title',
		];
	}

	/**
	 * A read-back state carrying one status and one slug.
	 *
	 * @param string $status The stored status.
	 * @param string $slug   The stored slug.
	 *
	 * @return TargetState The state.
	 */
	private function stateWith( string $status, string $slug ): TargetState {
		return new TargetState(
			'post:42',
			true,
			[
				'post_status' => $status,
				'post_name'   => $slug,
			]
		);
	}
}

/**
 * A post object whose post_status resolves only through __get, with no __isset
 * beside it — the shape a lazy object-cache proxy has.
 *
 * Declared beside the test rather than in tests/Doubles because it exists to
 * make one term of one guard assertable and has no other consumer. Two of the
 * last four structurally-dead constructs found on this branch were in newly
 * written test doubles, so this one is shaped by what it must OBSERVE: if
 * __isset were declared, isset() would answer true and the double could no
 * longer distinguish the guard from its absence.
 */
final class MagicStatusPost {

	/**
	 * Resolves a member that is not a declared property.
	 *
	 * Answers unconditionally. A `'post_status' === $name` branch beside a `: ''`
	 * arm reads as discrimination but is not: the operation asks this object for
	 * one member and no other, so the second arm was unreachable and its deletion
	 * left the file green. Removed rather than kept, because a test double's dead
	 * arm suggests coverage of a distinction nothing makes.
	 *
	 * @param string $name The member name.
	 *
	 * @return string The resolved value.
	 */
	public function __get( string $name ): string {
		return 'trash';
	}
}
