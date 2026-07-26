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
		$this->registry->register(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'Stand-in definition supplying the original operation capability.',
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
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			static fn(): array => []
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
	 * A missing reference, a reference belonging to another site, and a
	 * reference recorded by another module must be indistinguishable from the
	 * caller's side: the same code AND the same message, or the response
	 * becomes a probe for which rollback references exist.
	 */
	public function test_missing_cross_site_and_cross_module_refusals_share_one_message(): void {
		try {
			$this->operation->resolveTarget( [ 'rollbackRef' => 'rb-missing' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $missing ) {
			$missing_message = $missing->getMessage();
		}

		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$cross_site_current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		try {
			$this->operation->planChange( $cross_site_current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $cross_site ) {
			$cross_site_message = $cross_site->getMessage();
		}

		$this->queueSnapshot( [ 'module_id' => 'elementor' ], 2 );
		$cross_module_current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		try {
			$this->operation->planChange( $cross_module_current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $cross_module ) {
			$cross_module_message = $cross_module->getMessage();
		}

		$this->assertSame( $missing_message, $cross_site_message );
		$this->assertSame( $missing_message, $cross_module_message );
	}

	public function test_capture_snapshot_records_the_pre_rollback_state(): void {
		$this->queueSnapshot();
		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame( 'Edited title', $snapshot['post_title'] );
		$this->assertSame( 42, $snapshot['post_id'] );
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
}
