<?php
/**
 * Tests for ContentRollbackApply (REQ-0008).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentRollbackApply;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0008: reverse a supported write, re-checking the original operation's
 * capability and the module's compatibility at restore time.
 */
final class ContentRollbackApplyTest extends TestCase {

	private const REFERENCE = 'rb-0123456789abcdef01234567';

	private FakeWpdb $wpdb;
	private CapabilityRegistry $registry;
	private ContentRollbackApply $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->writes    = [];
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);

		$fields         = new ContentFields();
		$this->registry = new CapabilityRegistry();
		$this->registerOriginalOperation();
		$this->operation = new ContentRollbackApply(
			$fields,
			new ContentTarget( $fields ),
			new SnapshotStore(),
			$this->registry,
			new PolicyEngine()
		);

		$this->stubPost();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function registerOriginalOperation(): void {
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				// A write, as the real content-update is: a snapshot's origin is
				// always a write, and the restore-time re-check requires it.
				mode: Mode::Write,
				description: 'Stand-in definition standing for the original write operation.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Low,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			new StubWriteOperation()
		);
	}

	private function stubPost( string $title = 'Edited title' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = $title;
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Edited body.</p>';
		$post->post_excerpt      = 'Edited excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 11:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext( string $core_version = '6.8.1', string $health = 'active' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-3',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => $core_version,
					'health'  => $health,
				],
			],
			requestTime: 1_800_000_500,
		);
	}

	/**
	 * @param array<string, mixed> $overrides Snapshot-row fields to replace.
	 *
	 * @return array<string, mixed> The snapshot row.
	 */
	private function snapshotRow( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => 5,
				'rollback_ref'    => self::REFERENCE,
				'site_id'         => 'example.com',
				'user_id'         => 7,
				'operation_id'    => 'content-update',
				'module_id'       => 'core',
				'target_key'      => 'post:42',
				'restore_state'   => '{"post_content":"<p>Original body.<\/p>","post_excerpt":"Original excerpt.","post_id":42,"post_title":"Original title"}',
				'module_versions' => '{"core":{"health":"active","version":"6.8.1"}}',
				'created_at'      => 1_800_000_100,
				'restored_at'     => null,
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Snapshot-row fields to replace.
	 */
	private function queueSnapshot( array $overrides = [], int $times = 1 ): void {
		for ( $index = 0; $index < $times; $index++ ) {
			$this->wpdb->rowQueue[] = $this->snapshotRow( $overrides );
		}
	}

	public function test_resolve_target_is_the_post_the_snapshot_names(): void {
		$this->queueSnapshot();

		$state = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
	}

	public function test_an_unknown_reference_is_target_not_found(): void {
		try {
			$this->operation->resolveTarget( [ 'rollbackRef' => 'rb-missing' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_the_recorded_prior_state(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_title'   => 'Original title',
			],
			$planned->afterFields
		);
		$this->assertSame( self::REFERENCE, $planned->payload['rollbackRef'] );
	}

	public function test_the_original_operation_capability_is_rechecked_at_restore_time(): void {
		$checked = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, ...$extra ) use ( &$checked ): bool {
				$checked[] = [ $capability, $extra[0] ?? null ];

				return true;
			}
		);
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertContains( [ 'edit_post', 42 ], $checked );
	}

	/**
	 * A second-generation rollback reference — one whose ORIGIN was itself a
	 * rollback — must re-check the same target-bound capability every other
	 * write does. content-rollback-apply's own declared capability supplies
	 * that check when it is itself the origin, so it must be the target-bound
	 * meta capability edit_post, not the site-wide primitive edit_posts: a
	 * user holding blanket edit_posts but lacking edit_post on THIS post must
	 * be refused, exactly as they already are through a content-update-origin
	 * reference.
	 */
	public function test_a_second_generation_rollback_reference_rechecks_the_target_bound_capability(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$checked = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, ...$extra ) use ( &$checked ): bool {
				$checked[] = [ $capability, $extra[0] ?? null ];

				// Allowed only for the target-bound meta capability on THIS
				// post — never for the generic, target-less primitive.
				return 'edit_post' === $capability && 42 === ( $extra[0] ?? null );
			}
		);

		$fields    = new ContentFields();
		$operation = new ContentRollbackApply(
			$fields,
			new ContentTarget( $fields ),
			new SnapshotStore(),
			$registry,
			new PolicyEngine()
		);

		$this->queueSnapshot( [ 'operation_id' => 'content-rollback-apply' ], 2 );
		$current = $operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertContains( [ 'edit_post', 42 ], $checked );
		$this->assertSame( self::REFERENCE, $planned->payload['rollbackRef'] );
	}

	/**
	 * The exploit the CRITICAL finding named directly: a caller holding only
	 * the site-wide primitive edit_posts, without edit_post on this specific
	 * post, must be refused restoring through a chained (rollback-origin)
	 * reference — the same caller who is already refused through a
	 * content-update-origin reference for the identical post and state.
	 */
	public function test_a_second_generation_rollback_reference_refuses_a_caller_without_the_target_bound_capability(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		// Holds the generic site-wide primitive but not edit_post on this post.
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability, ...$extra ): bool => 'edit_posts' === $capability
		);

		$fields    = new ContentFields();
		$operation = new ContentRollbackApply(
			$fields,
			new ContentTarget( $fields ),
			new SnapshotStore(),
			$registry,
			new PolicyEngine()
		);

		$this->queueSnapshot( [ 'operation_id' => 'content-rollback-apply' ], 2 );
		$current = $operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * Registers a write operation whose declaration names only the site-wide
	 * primitive, standing in for a future write that is not target-bound.
	 *
	 * @param Mode $mode The declared mode.
	 */
	private function registerSitewideOrigin( Mode $mode = Mode::Write ): void {
		$register = Mode::Write === $mode
			? fn( OperationDefinition $d ): mixed => $this->registry->registerWrite( $d, new StubWriteOperation() )
			: fn( OperationDefinition $d ): mixed => $this->registry->register( $d, static fn(): array => [] );

		$register(
			new OperationDefinition(
				id: 'content-sitewide-touch',
				domain: Domain::Content,
				mode: $mode,
				description: 'Stand-in origin declaring only the site-wide primitive.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
				risk: Risk::Low,
				isReadOnly: Mode::Read === $mode,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: Mode::Write === $mode ? PreviewPolicy::Required : PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-sitewide-touch',
					'arguments' => [ 'id' => 42 ],
				],
			)
		);
	}

	/**
	 * The restore-time re-check must derive the capability it enforces from the
	 * resolved target, never from what the origin operation happens to declare.
	 *
	 * A reviewer probed the previous behaviour: a Contributor holding only the
	 * site-wide primitive edit_posts was allowed to overwrite post 42 whenever the
	 * origin's declaration named that primitive, because the re-check simply
	 * re-authorized the origin's own definition. It is unreachable today — reads
	 * cannot record snapshots and a creation's captureSnapshot() returns null —
	 * but it goes live the moment REQ-0018 ships. This is the same Critical as
	 * the chained-reference case through a different door: changing the declared
	 * capability closed one entrance, and this closes the other.
	 */
	public function test_an_origin_declaring_only_a_site_wide_primitive_cannot_weaken_the_recheck(): void {
		$this->registerSitewideOrigin();

		// Holds the site-wide primitive the origin declares, but not edit_post on
		// this post.
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability, ...$extra ): bool => 'edit_posts' === $capability
		);

		$this->queueSnapshot( [ 'operation_id' => 'content-sitewide-touch' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * The target-bound capability is checked against the resolved post, so a
	 * caller who does hold it is still allowed through even when the origin's own
	 * declaration is only the site-wide primitive.
	 */
	public function test_a_site_wide_origin_still_admits_a_caller_holding_the_target_bound_capability(): void {
		$this->registerSitewideOrigin();

		$checked = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, ...$extra ) use ( &$checked ): bool {
				$checked[] = [ $capability, $extra[0] ?? null ];

				return 'edit_post' === $capability && 42 === ( $extra[0] ?? null );
			}
		);

		$this->queueSnapshot( [ 'operation_id' => 'content-sitewide-touch' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'Original title', $planned->afterFields['post_title'] );
		$this->assertContains( [ 'edit_post', 42 ], $checked );
	}

	/**
	 * A snapshot whose origin is not a write is not something a restore may act
	 * on. Reads cannot record snapshots, so such a reference is malformed, and it
	 * must be refused rather than have its declaration consulted.
	 */
	public function test_an_origin_that_is_not_a_write_operation_is_target_not_found(): void {
		$this->registerSitewideOrigin( Mode::Read );
		$this->queueSnapshot( [ 'operation_id' => 'content-sitewide-touch' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_a_missing_original_capability_is_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_an_unregistered_original_operation_is_rollback_unavailable(): void {
		$this->queueSnapshot( [ 'operation_id' => 'content-retired' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_an_inactive_owning_module_is_rollback_unavailable(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[ 'rollbackRef' => self::REFERENCE ],
				$this->makeContext( '6.8.1', 'inactive' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_a_changed_module_version_is_rollback_unavailable(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[ 'rollbackRef' => self::REFERENCE ],
				$this->makeContext( '6.9.0' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_a_snapshot_from_another_site_is_target_not_found(): void {
		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The cross-site check must run before the capability re-check. If it
	 * ran later, a cross-site reference paired with a failing capability
	 * would answer `forbidden` instead of `target_not_found`, disclosing
	 * that the snapshot exists (just on another site) to a caller who
	 * should learn nothing about it at all.
	 */
	public function test_the_cross_site_check_runs_before_the_capability_recheck(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The cross-site check must also run before the module-compatibility
	 * check. If it ran later, a cross-site reference paired with an inactive
	 * owning module would answer `rollback_unavailable` instead of
	 * `target_not_found`, again disclosing that the snapshot exists.
	 */
	public function test_the_cross_site_check_runs_before_the_module_compatibility_check(): void {
		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[ 'rollbackRef' => self::REFERENCE ],
				$this->makeContext( '6.8.1', 'inactive' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The contract scopes a write dispatcher's rollback to its OWN domain. A
	 * snapshot recorded by another module can be perfectly healthy — the module
	 * compatibility check would pass it — and still not be this operation's to
	 * restore. Only core records snapshots today, so nothing else catches this.
	 */
	public function test_a_snapshot_recorded_by_another_module_is_target_not_found(): void {
		$this->queueSnapshot(
			[
				'module_id'       => 'elementor',
				'module_versions' => '{"elementor":{"health":"active","version":"3.25.0"}}',
			],
			2
		);
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * Module identity and the original operation's capability are different
	 * questions, and identity must be settled first. If the user_can() check ran
	 * before the module-identity check, this would fail with `forbidden` instead
	 * of `target_not_found`: the user genuinely lacks 'edit_post' here, but the
	 * snapshot belongs to a different module regardless of who is asking, so the
	 * refusal must never depend on capability.
	 */
	public function test_the_module_identity_check_runs_before_the_capability_recheck(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->queueSnapshot( [ 'module_id' => 'elementor' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A missing reference, a reference belonging to another site, a reference
	 * recorded by another module, and a reference whose origin is not a write
	 * must all be indistinguishable from the caller's side: the same code, the
	 * same message, AND the same remediation, or the response becomes a probe for
	 * which rollback references exist. remediation is a separate property
	 * surfaced in the same error envelope (OperationError::toArray()), so
	 * comparing message alone would still let a divergent remediation leak which
	 * case fired.
	 */
	public function test_missing_cross_site_and_cross_module_refusals_share_one_message(): void {
		try {
			$this->operation->resolveTarget( [ 'rollbackRef' => 'rb-missing' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $missing ) {
			$missing_message     = $missing->getMessage();
			$missing_remediation = $missing->remediation;
		}

		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$cross_site_current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		try {
			$this->operation->planChange( $cross_site_current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $cross_site ) {
			$cross_site_message     = $cross_site->getMessage();
			$cross_site_remediation = $cross_site->remediation;
		}

		$this->queueSnapshot( [ 'module_id' => 'elementor' ], 2 );
		$cross_module_current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		try {
			$this->operation->planChange( $cross_module_current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $cross_module ) {
			$cross_module_message     = $cross_module->getMessage();
			$cross_module_remediation = $cross_module->remediation;
		}

		$this->registerSitewideOrigin( Mode::Read );
		$this->queueSnapshot( [ 'operation_id' => 'content-sitewide-touch' ], 2 );
		$non_write_current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		try {
			$this->operation->planChange( $non_write_current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $non_write ) {
			$non_write_message     = $non_write->getMessage();
			$non_write_remediation = $non_write->remediation;
		}

		$this->assertSame( $missing_message, $cross_site_message );
		$this->assertSame( $missing_message, $cross_module_message );
		$this->assertSame( $missing_message, $non_write_message );
		$this->assertSame( $missing_remediation, $cross_site_remediation );
		$this->assertSame( $missing_remediation, $cross_module_remediation );
		$this->assertSame( $missing_remediation, $non_write_remediation );
	}

	/**
	 * The rollback's own snapshot is captured through the shared
	 * ContentTarget::snapshotOf(), so it records every column in
	 * RESTORABLE_FIELDS. post_status and post_name are asserted here because
	 * this operation is what un-does a rollback: if its capture were to lose
	 * either column, restore() would be unable to put a post back the way the
	 * rollback found it.
	 */
	public function test_capture_snapshot_records_the_pre_rollback_state(): void {
		$this->queueSnapshot();
		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame( 'Edited title', $snapshot['post_title'] );
		$this->assertSame( 42, $snapshot['post_id'] );
		$this->assertSame( 'draft', $snapshot['post_status'] );
		$this->assertSame( 'original-title', $snapshot['post_name'] );
	}

	public function test_apply_change_writes_the_prior_state_and_stamps_the_snapshot(): void {
		$this->queueSnapshot( [], 3 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
		$this->assertSame( [ 'id' => 5 ], $this->wpdb->updates[0]['where'] );
		$this->assertSame( 1_800_000_500, $this->wpdb->updates[0]['data']['restored_at'] );
	}

	/**
	 * markRestored()'s boolean return must be checked, not discarded: a
	 * snapshot that silently fails to be marked restored would go
	 * unnoticed. The restoration itself already succeeded by this point, so
	 * the failure is logged server-side rather than turning a successful
	 * write into a reported failure.
	 */
	public function test_a_failed_snapshot_stamp_is_logged_not_silently_dropped(): void {
		$this->queueSnapshot( [], 3 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$logged = [];
		Functions\when( 'error_log' )->alias(
			static function ( string $message ) use ( &$logged ): bool {
				$logged[] = $message;

				return true;
			}
		);
		$this->wpdb->failUpdate = true;

		$target = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'post:42', $target );
		$this->assertNotSame( [], $logged );
	}

	public function test_restore_undoes_a_failed_rollback(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Edited title',
					'post_content' => '<p>Edited body.</p>',
					'post_excerpt' => 'Edited excerpt.',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'Edited title', $this->writes[0]['post_title'] );
	}

	/**
	 * Interim mitigation for interpretation I6, as for every other registered
	 * operation: the apply-phase payload is assembled exactly as
	 * ChangeEngine::apply() builds it and checked against the schema the module
	 * actually registered.
	 *
	 * Deviation from the brief's draft: `$this->registry` already carries the
	 * `registerOriginalOperation()` stand-in for 'content-update' from setUp(),
	 * so registering the real CoreModule into that SAME registry throws
	 * (`registerWrite()` refuses a duplicate identifier). A fresh registry and a
	 * fresh operation instance built on it side-steps the collision while still
	 * reading the schema CoreModule actually ships, which is what this test
	 * exists to verify.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$fields   = new ContentFields();
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );
		$operation = new ContentRollbackApply(
			$fields,
			new ContentTarget( $fields ),
			new SnapshotStore(),
			$registry,
			new PolicyEngine()
		);

		$this->queueSnapshot( [], 3 );
		$context = $this->makeContext();
		$current = $operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $context );
		$planned = $operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $context );
		$target  = $operation->applyChange( $current, $planned, $context );
		$after   = $operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-rollback-apply' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the `oneOf` union: WriteOutputSchema::schema()'s
	 * plan branch, which the apply-phase test above never exercises.
	 * previewPolicy is Required, so the plan branch is the one every caller
	 * sees first.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-rollback-apply' )->outputSchema
		);
	}

	/**
	 * A snapshot recorded after post_status and post_name joined
	 * RESTORABLE_FIELDS promises both and writes both back. Without this the
	 * widening in ContentTarget would record two columns that no rollback ever
	 * restores.
	 */
	public function test_a_widened_snapshot_promises_and_restores_status_and_slug(): void {
		$restore_state = '{"post_content":"<p>Original body.<\/p>","post_excerpt":"Original excerpt.","post_id":42,"post_name":"original-title","post_status":"publish","post_title":"Original title"}';
		$this->queueSnapshot( [ 'restore_state' => $restore_state ], 3 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_name'    => 'original-title',
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
			],
			$planned->afterFields
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'publish', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
	}

	/**
	 * Backward compatibility with snapshot rows already in a live database. The
	 * default fixture row is deliberately the pre-widening shape: four keys, no
	 * post_status, no post_name.
	 *
	 * Such a row must promise and write only those four. A missing post_status
	 * defaulted to '' would reach wp_update_post(), which resolves an empty
	 * status to 'draft' — silently un-publishing a live post during a rollback
	 * that promised only to restore its text, and reporting success while doing
	 * it. This test is the only thing standing between that fixture shape and
	 * that outcome.
	 */
	public function test_a_snapshot_recorded_before_the_widening_restores_only_what_it_holds(): void {
		$this->queueSnapshot( [], 3 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertArrayNotHasKey( 'post_status', $planned->afterFields );
		$this->assertArrayNotHasKey( 'post_name', $planned->afterFields );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertArrayNotHasKey( 'post_status', $this->writes[0] );
		$this->assertArrayNotHasKey( 'post_name', $this->writes[0] );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_content', 'post_excerpt' ],
			array_keys( $this->writes[0] )
		);
	}
}
