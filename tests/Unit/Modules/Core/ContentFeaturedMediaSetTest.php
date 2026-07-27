<?php
/**
 * Tests for ContentFeaturedMediaSet (REQ-0017).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFeaturedMediaSet;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0017: set a post's featured image from an existing library asset.
 */
final class ContentFeaturedMediaSetTest extends TestCase {

	private ContentFeaturedMediaSet $operation;

	/** @var array<int, array<int, int|string>> */
	private array $thumbnailWrites = [];

	private int $thumbnailId = 0;

	/**
	 * What get_post() answers for an empty identifier, standing in for
	 * $GLOBALS['post']. Null unless a test deliberately sets one.
	 */
	private ?stdClass $globalPost = null;

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentFeaturedMediaSet( $fields, new ContentTarget( $fields ) );

		$this->thumbnailWrites = [];
		$this->thumbnailId     = 0;
		$this->globalPost      = null;

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_update_post' )->justReturn( 42 );
		// Answers what WordPress answers: false when there is no thumbnail, not 0.
		// A `: int` return type here would make false unreachable and quietly
		// retire the (int) cast that compares against it in
		// ContentTarget::restore_featured_media(), under which `false !== 0`
		// would refuse every legitimate "restore to no featured image".
		//
		// Not this operation's applyChange(): planChange() guarantees a media id
		// of at least 1 there, so its own cast cannot change the answer for any
		// reachable input and no fake can pin it. Naming both would credit this
		// fake with coverage it does not provide.
		Functions\when( 'get_post_thumbnail_id' )->alias(
			fn() => 0 === $this->thumbnailId ? false : $this->thumbnailId
		);
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $post_id, int $media_id ): bool {
				$this->thumbnailWrites[] = [ 'set', $post_id, $media_id ];
				$this->thumbnailId       = $media_id;

				return true;
			}
		);
		Functions\when( 'delete_post_thumbnail' )->alias(
			function ( int $post_id ): bool {
				$this->thumbnailWrites[] = [ 'delete', $post_id, 0 ];
				$this->thumbnailId       = 0;

				return true;
			}
		);

		$this->stubPosts();
	}

	/**
	 * Post 42 is the target; 108 is an image attachment; 900 is an ordinary page,
	 * which is the id that must be refused even though it resolves. Anything else
	 * does not exist.
	 *
	 * An EMPTY identifier is modelled the way core models it and not the way the
	 * happy path would: `get_post()` opens with
	 * `if ( empty( $post ) && isset( $GLOBALS['post'] ) ) { $post = $GLOBALS['post']; }`,
	 * so 0 does not mean "nothing" — it means "whatever post is currently
	 * global", which on an attachment page is an attachment. Returning null for 0
	 * here would make the identity check in is_attachment() impossible to fail.
	 */
	private function stubPosts(): void {
		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				if ( 0 === $id ) {
					return $this->globalPost;
				}

				return match ( $id ) {
					42      => $this->post( 42, 'post' ),
					108     => $this->post( 108, 'attachment' ),
					900     => $this->post( 900, 'page' ),
					default => null,
				};
			}
		);
	}

	private function post( int $id, string $type ): stdClass {
		$post                    = new stdClass();
		$post->ID                = $id;
		$post->post_type         = $type;
		$post->post_status       = 'publish';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		return $post;
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

	/**
	 * Plans the promoted case: post 42 gains attachment 108.
	 *
	 * @param int $media_id The requested attachment identifier.
	 *
	 * @return array{0: TargetState, 1: \SiteHelm\Change\PlannedChange} Current state and plan.
	 */
	private function planFor( int $media_id ): array {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => $media_id,
			],
			$context
		);

		return [ $current, $planned ];
	}

	/**
	 * Asserts planChange() refuses one media identifier, returning the message so
	 * a caller can compare two refusals without restating the try/catch.
	 *
	 * @param int $media_id The requested attachment identifier.
	 *
	 * @return string The refusal message.
	 */
	private function refusalMessageFor( int $media_id ): string {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );

		try {
			$this->operation->planChange(
				$current,
				[
					'id'      => 42,
					'mediaId' => $media_id,
				],
				$context
			);
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );

			return $e->getMessage();
		}

		$this->fail( "Expected OperationException for mediaId {$media_id}" );
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_the_attachment_identifier_as_an_integer(): void {
		[ , $planned ] = $this->planFor( 108 );

		$this->assertSame( [ 'featured_media' => 108 ], $planned->afterFields );
		$this->assertSame( [ 'featured_media' => 108 ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * Interpretation I7. WriteVerifier classifies a value WordPress silently
	 * dropped as an ADJUSTMENT, so a nonexistent attachment id would set no
	 * thumbnail, verify as adjusted, and be reported to the operator as a
	 * successful write that WordPress merely altered. Plan-time validation is the
	 * only place operator error and platform adjustment are separable.
	 */
	public function test_plan_change_refuses_a_media_identifier_that_does_not_exist(): void {
		$this->assertNotSame( '', $this->refusalMessageFor( 12345 ) );
	}

	/**
	 * The sharp half of I7: id 900 RESOLVES. It is a real post. It is simply not
	 * an attachment, so WordPress would store `_thumbnail_id` 900 and render no
	 * image at all — a write that succeeds, verifies clean, and produces a broken
	 * thumbnail. A check that only asked "does this id resolve" would pass here.
	 */
	public function test_plan_change_refuses_an_identifier_that_resolves_but_is_not_an_attachment(): void {
		$this->assertNotSame( '', $this->refusalMessageFor( 900 ) );
	}

	/**
	 * An empty identifier must not be answered by whatever post happens to be
	 * global. `get_post( 0 )` returns `$GLOBALS['post']`, so on an attachment
	 * page it hands back a perfectly valid attachment — and without the identity
	 * check in is_attachment() the operation would plan `featured_media => 0`,
	 * which is the removal this requirement does not implement. The global here
	 * is an attachment on purpose: a page or a null would let the post-type check
	 * refuse it and this test could never fail.
	 */
	public function test_plan_change_refuses_an_empty_identifier_rather_than_taking_the_global_post(): void {
		$this->globalPost = $this->post( 108, 'attachment' );

		$this->assertNotSame( '', $this->refusalMessageFor( 0 ) );
	}

	/**
	 * The refusals are indistinguishable from the caller's side on purpose. A
	 * different message for "no such id" and "not an attachment" would turn the
	 * response into a probe for which post ids exist on the site.
	 */
	public function test_every_reference_refusal_discloses_the_same_message(): void {
		$this->globalPost = $this->post( 108, 'attachment' );

		$messages = array_map(
			fn( int $media_id ): string => $this->refusalMessageFor( $media_id ),
			[ 12345, 900, 0 ]
		);

		$this->assertSame( [ $messages[0], $messages[0], $messages[0] ], $messages );
	}

	/**
	 * A post with no featured image records 0, not null and not an absent key.
	 * Returning null would make SnapshotLifecycle refuse the plan with
	 * rollback_unavailable — this operation's snapshot policy is `required` — for
	 * the ordinary case of a post that has no featured image yet.
	 */
	public function test_capture_snapshot_records_zero_for_a_post_with_no_featured_image(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 0,
				'post_id'        => 42,
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_records_the_existing_featured_image(): void {
		$this->thumbnailId = 55;
		$current           = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 55,
				'post_id'        => 42,
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_sets_the_promised_thumbnail(): void {
		[ $current, $planned ] = $this->planFor( 108 );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ [ 'set', 42, 108 ] ], $this->thumbnailWrites );
	}

	/**
	 * Re-issuing set_post_thumbnail() for an id already stored is not a no-op in
	 * core: it re-tests whether the attachment still renders and DELETES
	 * `_thumbnail_id` when it does not. So a repeat apply could destroy the very
	 * featured image it promised. The already-correct case is answered before the
	 * call, which is also what makes the declared `isIdempotent: true` true at
	 * the level of writes issued rather than only outcomes.
	 */
	public function test_apply_change_issues_no_write_when_the_thumbnail_already_matches(): void {
		$this->thumbnailId     = 108;
		[ $current, $planned ] = $this->planFor( 108 );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [], $this->thumbnailWrites );
	}

	/**
	 * A flatly refused write. set_post_thumbnail() returns false and stores
	 * nothing, so the measured id stays 0 and the promise did not take.
	 */
	public function test_apply_change_reports_a_refused_thumbnail_as_execution_failed(): void {
		Functions\when( 'set_post_thumbnail' )->justReturn( false );
		[ $current, $planned ] = $this->planFor( 108 );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	/**
	 * The case the boolean CANNOT express, and the reason applyChange() judges by
	 * re-reading the stored id instead. Core's set_post_thumbnail() does not
	 * decline an attachment that renders no thumbnail markup — a PDF, or one
	 * whose file is gone. It falls into `delete_post_meta( $post->ID,
	 * '_thumbnail_id' )` and returns THAT, which is TRUE whenever the post had a
	 * featured image to destroy.
	 *
	 * So this models a write that returns true, wipes the operator's existing
	 * featured image, and sets nothing. An implementation testing
	 * `false === set_post_thumbnail(...)` would report this as a verified
	 * assignment.
	 */
	public function test_apply_change_reports_a_write_that_erased_the_thumbnail_as_execution_failed(): void {
		$this->thumbnailId = 55;
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $post_id, int $media_id ): bool {
				$this->thumbnailWrites[] = [ 'set', $post_id, $media_id ];
				$this->thumbnailId       = 0;

				return true;
			}
		);
		[ $current, $planned ] = $this->planFor( 108 );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to use the requested attachment as a featured image.', $e->getMessage() );
		}
	}

	public function test_read_back_reports_the_persisted_featured_image(): void {
		$this->thumbnailId = 108;

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 108, $state->fields['featured_media'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	public function test_restore_writes_the_recorded_featured_image_back(): void {
		$this->thumbnailId = 108;

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 55,
				],
				$this->makeContext()
			)
		);

		$this->assertSame( [ [ 'set', 42, 55 ] ], $this->thumbnailWrites );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'featured_media' => 55 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here. The payload is
	 * assembled exactly as ChangeEngine::apply() builds it, from this operation's
	 * own outputs, and checked against the schema the MODULE registered rather
	 * than a restatement of it — so a definition that drifts from what
	 * CoreModule registers fails here.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$context               = $this->makeContext();
		[ $current, $planned ] = $this->planFor( 108 );

		$target = $this->operation->applyChange( $current, $planned, $context );
		$after  = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-featured-media-set' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the oneOf union: WriteOutputSchema::schema()'s
	 * plan branch, which the apply-phase test never exercises.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-featured-media-set' )->outputSchema
		);
	}

	/**
	 * The declared status of the input contract, written as literals. Reading
	 * these back from ContentFeaturedMediaSet::definition() would derive the
	 * expectation from the code under test, which is a test that cannot fail.
	 */
	public function test_the_input_schema_is_closed_and_requires_both_identifiers(): void {
		$schema = ContentFeaturedMediaSet::definition()->inputSchema;

		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'mediaId' ], $schema['required'] );
		$this->assertSame( 1, $schema['properties']['mediaId']['minimum'] );
	}
}
