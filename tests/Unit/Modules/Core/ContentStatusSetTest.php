<?php
/**
 * Tests for ContentStatusSet (REQ-0018).
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
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentStatusSet;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0018: move content through draft, review and publish states.
 *
 * Every refusal below asserts the MESSAGE as well as the code. This operation
 * raises Forbidden from two different branches and the codes are identical, so
 * a test that asserted only ErrorCode::Forbidden would pass just as happily if
 * the fail-closed branch had swallowed the case the capability check was meant
 * to answer.
 */
final class ContentStatusSetTest extends TestCase {

	private ContentStatusSet $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	/** @var array<int, array<int, mixed>> Every user_can call, as [userId, capability]. */
	private array $capabilityChecks = [];

	/** @var string[] The capabilities this user holds. */
	private array $granted = [];

	/**
	 * The value the post type object exposes as `cap->publish_posts`.
	 *
	 * Typed mixed, not string, because a post type's `cap` members come from a
	 * plugin-supplied `capabilities` array that register_post_type() merges over
	 * the defaults without validating it, so a non-string can genuinely reach
	 * this operation.
	 *
	 * @var mixed
	 */
	private $publishCap = 'publish_posts';

	/**
	 * Whether the post type object exposes `cap->publish_posts` at all.
	 */
	private bool $declaresPublishCap = true;

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentStatusSet( $fields, new ContentTarget( $fields ) );

		$this->writes           = [];
		$this->capabilityChecks = [];
		$this->granted            = [];
		$this->publishCap         = 'publish_posts';
		$this->declaresPublishCap = true;

		// Records what was ASKED, not only what was answered. A refusal test that
		// trusted the message could not tell publish_products from publish_posts,
		// and substituting the generic primitive for a custom type's own
		// capability is exactly the bug worth catching.
		//
		// $capability is deliberately UNTYPED, matching core: user_can( $user,
		// $capability, ...$args ) declares no type for it. Narrowing the double to
		// string would make a non-string name a TypeError at the double's own
		// boundary, which is an incidental failure that proves nothing — and it
		// would delete the coverage of the is_string() guard written for exactly
		// that input.
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, $capability ): bool {
				$this->capabilityChecks[] = [ $user_id, $capability ];

				return in_array( $capability, $this->granted, true );
			}
		);
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		// The field map always reads the page-template meta key; a post that
		// never had one answers with an empty string.
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key = '', bool $single = false ) => $single ? '' : []
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		// Typed like the platform: get_post_type_object() answers null for a type
		// this site does not register, which is an ordinary production state for a
		// post left behind by a deactivated plugin.
		Functions\when( 'get_post_type_object' )->alias( fn(): ?stdClass => $this->postTypeObject() );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);

		$this->stubPost();
	}

	private function postTypeObject(): stdClass {
		$caps = new stdClass();
		if ( $this->declaresPublishCap ) {
			$caps->publish_posts = $this->publishCap;
		}

		$type      = new stdClass();
		$type->cap = $caps;

		return $type;
	}

	private function stubPost( string $status = 'draft' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = $status;
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
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
	 * @return array<string, mixed> A status-change payload.
	 */
	private function input( string $status ): array {
		return [
			'id'     => 42,
			'status' => $status,
		];
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertSame( 'draft', $state->fields['post_status'] );
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
	 * A draft-like target needs no publish capability, and the assertion on
	 * capabilityChecks is what proves it: a user holding nothing at all reaches a
	 * planned change, and user_can was never consulted about publishing.
	 */
	public function test_a_draft_like_transition_needs_no_publish_capability(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input( 'pending' ), $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'pending' ], $planned->afterFields );
		$this->assertSame( [], $this->capabilityChecks );
	}

	public function test_a_draft_transition_needs_no_publish_capability(): void {
		$this->stubPost( 'publish' );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input( 'draft' ), $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'draft' ], $planned->afterFields );
		$this->assertSame( [], $this->capabilityChecks );
	}

	public function test_a_permitted_publish_is_planned(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'publish' ], $planned->afterFields );
		$this->assertSame( [ 'post_status' => 'publish' ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( [ [ 7, 'publish_posts' ] ], $this->capabilityChecks );
	}

	public function test_an_unpermitted_publish_is_forbidden(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your WordPress user may not publish this content type.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'publish_posts' ] ], $this->capabilityChecks );
	}

	/**
	 * `private` requires publish_posts in WordPress, so treating it as draft-like
	 * would be a capability bypass rather than a convenience. It is deliberately
	 * absent from ContentFields::DRAFT_LIKE_STATUSES, and this is the test that
	 * would fail if anyone added it.
	 *
	 * Core agrees: WP_REST_Posts_Controller::handle_status_param() falls `private`
	 * through to the same `cap->publish_posts` check it applies to `publish`.
	 */
	public function test_setting_a_private_status_requires_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'private' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your WordPress user may not publish this content type.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'publish_posts' ] ], $this->capabilityChecks );
	}

	/**
	 * A custom post type registered with its own capability_type maps publish to
	 * a distinct name. The capability the code ASKS for is what matters, and
	 * asserting the recorded call is the only way to see it: a message assertion
	 * would pass identically if the generic primitive had been substituted, which
	 * would let a caller publish a type they hold no capability for.
	 */
	public function test_the_content_types_own_publish_capability_is_the_one_checked(): void {
		$this->publishCap = 'publish_products';
		$this->granted    = [ 'publish_posts' ];
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your WordPress user may not publish this content type.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'publish_products' ] ], $this->capabilityChecks );
	}

	/**
	 * A post type object exposing no `cap` at all fails closed. Falling back to
	 * the generic primitive would let a caller publish a type whose registration
	 * says they may not, and user_can must not even be consulted — asking it about
	 * a name that was never established is how a fallback creeps back in.
	 *
	 * This covers only the FIRST condition of the guard. `cap` present but
	 * carrying no usable publish_posts is a separate reachable shape and gets its
	 * own tests below; without them, deleting the publish_posts conditions from
	 * the guard passes the whole file.
	 */
	public function test_a_post_type_object_exposing_no_capabilities_fails_closed(): void {
		Functions\when( 'get_post_type_object' )->justReturn( new stdClass() );
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to publish this content type could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The reachable half of the same guard, and the ordinary one: a post whose
	 * type is no longer registered — the plugin that declared it having been
	 * deactivated — makes get_post_type_object() answer null rather than an
	 * object missing a member. A fake that could only ever return an object would
	 * leave this branch untested while the file still read as covered.
	 */
	public function test_a_post_type_that_is_no_longer_registered_fails_closed(): void {
		Functions\when( 'get_post_type_object' )->justReturn( null );
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to publish this content type could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * A `cap` object that declares no publish_posts member at all. This is the
	 * second condition of the same guard, and it needs its own case: the bare
	 * stdClass above is refused by the `! isset( $object->cap )` test before the
	 * publish_posts conditions are ever evaluated, so deleting those conditions
	 * left the whole file green until this test existed.
	 */
	public function test_a_content_type_declaring_no_publish_capability_fails_closed(): void {
		$this->declaresPublishCap = false;
		$this->granted            = [ 'publish_posts' ];
		$current                  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to publish this content type could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * A publish_posts member that is present but is not a capability NAME.
	 * register_post_type() merges a plugin's `capabilities` array over the
	 * defaults without validating its values, so this shape is reachable.
	 *
	 * Handing it to user_can() would not merely refuse: core forwards it to
	 * WP_User::has_cap() and then map_meta_cap(), which uses the value as an
	 * array key — a fatal, not a denial. So the refusal has to happen before the
	 * call, and the assertion that user_can was never reached is what says so.
	 */
	public function test_a_non_string_publish_capability_name_fails_closed(): void {
		$this->publishCap = [ 'publish_posts' ];
		$this->granted    = [ 'publish_posts' ];
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to publish this content type could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The payload reaches planChange() again at apply, so the status is
	 * re-validated rather than trusted to have come through the schema. `future`
	 * is the case that matters: WordPress produces it as an adjustment to a
	 * publish on a future-dated post, so a caller could plausibly submit it back.
	 *
	 * The empty capabilityChecks assertion pins the ORDER too. An unknown status
	 * is invalid input whatever the caller may publish, so the settable check has
	 * to answer before the capability one.
	 */
	public function test_a_status_outside_the_settable_set_is_invalid_input(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'future' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'The requested status is not one this operation can set.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * `trash` is REQ-0019, not this operation. It is named separately from
	 * `future` because the two are absent for different reasons and a single
	 * example would leave whichever reason it did not name unpinned.
	 */
	public function test_the_trash_is_not_settable_through_this_operation(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'trash' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'The requested status is not one this operation can set.', $e->getMessage() );
		}
	}

	public function test_capture_snapshot_records_every_restorable_field(): void {
		$this->stubPost( 'publish' );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				// An integer, not a string, and the whole reason the order column
				// is recorded through its own list.
				'menu_order'    => 0,
				// Restored through a meta write of its own, because core ignores an
				// empty page_template and a restore to none would be a silent no-op.
				'page_template' => '',
				'post_content'  => '<p>Original body.</p>',
				// Deliberately empty, and legal: most posts have no excerpt. It is
				// what separates array_key_exists from isset or ! empty() in
				// ContentTarget's restore loop.
				'post_excerpt'  => '',
				'post_id'       => 42,
				'post_name'     => 'original-title',
				'post_parent'   => 0,
				'post_status'   => 'publish',
				'post_title'    => 'Original title',
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_writes_only_the_status(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame(
			[
				'ID'          => 42,
				'post_status' => 'publish',
			],
			$this->writes[0]
		);
	}

	public function test_apply_change_reports_a_refused_save_as_execution_failed(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to change the status of the content item.', $e->getMessage() );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	/**
	 * The other failure shape wp_update_post() has. Read from core: it answers a
	 * WP_Error rather than 0 when $wp_error is true and the row cannot be read,
	 * and then forwards wp_insert_post(), which does the same. Both halves of the
	 * guard are therefore reachable, and a mutation deleting the is_wp_error()
	 * half would otherwise pass — the 0 case would still be caught by the test
	 * above while a WP_Error was reported as a written target key.
	 */
	public function test_apply_change_reports_a_wp_error_save_as_execution_failed(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
		Functions\when( 'wp_update_post' )->justReturn( new stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to change the status of the content item.', $e->getMessage() );
		}
	}

	public function test_read_back_reports_the_persisted_status(): void {
		$this->stubPost( 'publish' );

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 'publish', $state->fields['post_status'] );
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

	public function test_restore_writes_the_recorded_status_back(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'     => 42,
					'post_status' => 'draft',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'draft', $this->writes[0]['post_status'] );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'post_status' => 'draft' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
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

		$this->granted = [ 'publish_posts' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $context );

		$target = $this->operation->applyChange( $current, $planned, $context );
		$this->stubPost( 'publish' );
		$after = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-status-set' )->outputSchema
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
			$registry->definition( 'content-status-set' )->outputSchema
		);
	}

	/**
	 * The declared status set, written as literals. Reading it back from
	 * ContentStatusSet::definition() or from SETTABLE_STATUSES would derive the
	 * expectation from the code under test. `future` and `trash` must be absent:
	 * `future` is an adjustment WordPress produces, and `trash` is REQ-0019.
	 */
	public function test_the_declared_status_enum_is_exactly_the_four_settable_statuses(): void {
		$schema = ContentStatusSet::definition()->inputSchema;

		$this->assertSame(
			[ 'draft', 'pending', 'private', 'publish' ],
			$schema['properties']['status']['enum']
		);
		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'status' ], $schema['required'] );
	}

	/**
	 * The capability split, written as literals, from the other side. `private`
	 * being absent from DRAFT_LIKE_STATUSES is a security property, not a
	 * detail, and this pins it where a reader of this operation will look.
	 */
	public function test_only_draft_and_pending_are_below_the_publish_line(): void {
		$this->assertSame( [ 'draft', 'pending' ], ContentFields::DRAFT_LIKE_STATUSES );
	}
}
