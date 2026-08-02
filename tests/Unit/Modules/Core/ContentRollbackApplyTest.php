<?php
/**
 * Tests for ContentRollbackApply (REQ-0008).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
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

	/** @var array<int, array<int, int|string>> */
	private array $thumbnailWrites = [];

	private int $thumbnailId = 0;

	/** @var string[] The taxonomies whose `sort` member is true. */
	private array $sortedTaxonomies = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'user_can' )->justReturn( true );
		// Untyped, as core's own wp_slash( $value ) is. It was declared
		// `array $v` while the only caller passed the wp_update_post() array;
		// ContentTarget::restore_custom_fields() now also slashes a single string,
		// and a fake narrower than the function it replaces would have turned that
		// into a TypeError that says nothing about the code under test.
		Functions\when( 'wp_slash' )->alias( static fn( $v ) => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$this->wpdb             = new FakeWpdb();
		$GLOBALS['wpdb']        = $this->wpdb;
		$this->writes           = [];
		$this->thumbnailWrites  = [];
		$this->thumbnailId      = 0;
		$this->sortedTaxonomies = [];
		// Always an object, unlike ContentTermsAssignTest's equivalent, and that is
		// a real difference rather than a weaker fake. There the payload names the
		// taxonomy, so an unregistered name reaches get_taxonomy() and `false` is a
		// live answer. Here the only names that reach it come from
		// ContentFields::terms(), which builds the map from get_object_taxonomies()
		// for the post type — so every key is registered by construction and a fake
		// answering false would be modelling a state this operation cannot observe.
		// The `! empty()` in the guard still tolerates it; nothing here proves it.
		Functions\when( 'get_taxonomy' )->alias(
			fn( string $tax ) => in_array( $tax, $this->sortedTaxonomies, true )
				? (object) [
					'name' => $tax,
					'sort' => true,
				]
				: (object) [
					'name' => $tax,
					'sort' => false,
				]
		);
		// Answers false rather than 0 when there is no thumbnail, exactly as
		// WordPress does. See the matching note in ContentUpdateTest.
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
	 * re-authorized the origin's own definition. It was unreachable when that was
	 * written — reads cannot record snapshots and a creation's captureSnapshot()
	 * returns null — and REQ-0018 was expected to make it live. REQ-0018 has since
	 * shipped and did not, only because `content-status-set` declares the
	 * target-bound edit_post rather than the site-wide primitive. That is a
	 * property of one declaration, which is exactly what this test exists to stop
	 * the re-check depending on. This is the same Critical as
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
	 * The rollback's own snapshot must record everything the rollback can WRITE,
	 * because this operation is what un-does a rollback: whatever the capture
	 * loses, restore() cannot put back, and the reversal would report verified
	 * having silently skipped it.
	 *
	 * That is wider than the shared ContentTarget::snapshotOf(). applyChange()
	 * writes RESTORABLE_MEDIA_FIELDS, RESTORABLE_CUSTOM_FIELDS and
	 * RESTORABLE_TAXONOMY_FIELDS as well as the five RESTORABLE_FIELDS columns,
	 * so captureSnapshot() adds those three on top of snapshotOf()'s result
	 * rather than delegating to it — see the round-trip test below.
	 *
	 * The whole array is asserted, not four keys of it. That pins the key SET
	 * (a dropped key fails on the assertion rather than on an undefined-index
	 * warning), the values, and the sorted order the canonical-JSON storage
	 * depends on. `featured_media` is 0 here, not absent and not null: a post
	 * with no featured image records a legal 0, since null would read to
	 * SnapshotLifecycle as nothing recoverable under a `required` policy.
	 *
	 * `meta` and `terms` are recorded as EMPTY maps for the same reason and not
	 * as absent keys. This fixture allowlists no meta key and registers no
	 * taxonomy, which is an ordinary post rather than a missing observation; an
	 * absent key would be read by ContentTarget::restoreFields() as "this
	 * snapshot predates the list", which is a different fact about a different
	 * row. The non-empty case is pinned by its own test below.
	 */
	public function test_capture_snapshot_records_the_pre_rollback_state(): void {
		$this->queueSnapshot();
		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 0,
				'meta'           => [],
				'post_content'   => '<p>Edited body.</p>',
				'post_excerpt'   => 'Edited excerpt.',
				'post_id'        => 42,
				'post_name'      => 'original-title',
				'post_status'    => 'draft',
				'post_title'     => 'Edited title',
				'terms'          => [],
			],
			$snapshot
		);
	}

	/**
	 * The defect this branch made reachable, stated as a round trip.
	 *
	 * Post 42 is live with featured image 200. The referenced snapshot records
	 * 100, so the rollback moves it to 100 — and the state it overwrote, 200, is
	 * only recoverable if this operation's own capture recorded it. Before the
	 * capture was widened, it recorded the five post columns and no media id, so
	 * reversing this rollback promised five unchanged columns, skipped the absent
	 * media key in restoreFields(), matched its promise on read-back and reported
	 * VERIFIED while the featured image stayed at 100. The 200 was gone.
	 *
	 * assertArrayHasKey runs before the value assertion on purpose: with
	 * failOnWarning="true" a missing key would otherwise fail the test on an
	 * undefined-index warning, which is an incidental failure and proves nothing
	 * about the capture.
	 */
	public function test_the_rollback_of_a_featured_media_change_can_itself_be_reversed(): void {
		$this->thumbnailId = 200;
		$this->queueSnapshot( [ 'restore_state' => '{"featured_media":100,"post_id":42}' ], 3 );

		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $context );
		$captured = $this->operation->captureSnapshot( $current, $context );

		$this->assertArrayHasKey(
			'featured_media',
			$captured,
			'The rollback must record the featured image it is about to overwrite, or reversing it cannot put that image back.'
		);
		$this->assertSame( 200, $captured['featured_media'] );

		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $context );
		$this->operation->applyChange( $current, $planned, $context );
		$this->assertSame( 100, $this->thumbnailId );

		$this->operation->restore( $captured, $context );
		$this->assertSame( 200, $this->thumbnailId );
	}

	/**
	 * The null guard is not decoration. Assigning a key to null auto-vivifies an
	 * array in PHP, so indexing snapshotOf()'s result blind would turn "there was
	 * no prior state" into a snapshot holding a media id and nothing else — a
	 * restore state naming no target, which restoreFields() then refuses as
	 * rollback_unavailable instead of the engine treating the capture as absent.
	 */
	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
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

	/**
	 * A featured-media snapshot records no post column, so intersecting it with
	 * RESTORABLE_FIELDS alone produced an empty promise — and PlannedChange
	 * rejects an empty promise with InvalidArgumentException, which escapes
	 * planChange() uncaught. RESTORABLE_MEDIA_FIELDS is promised separately, and
	 * as an integer: the read-back reports featured_media as an int, so a string
	 * promise would make a correct rollback verify as adjusted.
	 */
	public function test_a_featured_media_snapshot_is_promised_as_an_integer(): void {
		$this->queueSnapshot( [ 'restore_state' => '{"featured_media":108,"post_id":42}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'featured_media' => 108 ], $planned->afterFields );
	}

	/**
	 * The promised media field must reach ContentTarget::restoreFields() as well,
	 * or the rollback would report success having written nothing: a restore state
	 * holding only post_id issues no wp_update_post() call and no thumbnail write.
	 *
	 * Deviation from the brief's draft name (`..._as_an_integer`): the integer
	 * type of applyChange()'s own cast is not observable from outside.
	 * restore_featured_media() casts to int before any double can see the value,
	 * and both `int` and the string `'108'` produce the identical set write. The
	 * promise's integer type IS pinned, by the assertSame in the test above; what
	 * this test pins is that the loop exists at all.
	 */
	public function test_a_promised_featured_media_reaches_the_restore_state(): void {
		$this->queueSnapshot( [ 'restore_state' => '{"featured_media":108,"post_id":42}' ], 3 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ [ 'set', 42, 108 ] ], $this->thumbnailWrites );
		$this->assertSame( [], $this->writes );
	}

	/**
	 * A stored featured_media of JSON null must not be promised. `(int) null` is
	 * 0, and a recorded 0 MEANS "restore to no featured image", so promising the
	 * null as 0 would have the rollback offer — and then perform — the deletion of
	 * a live featured image, reporting it verified.
	 *
	 * The recorded column is still promised, which is what keeps this a skip of
	 * one unusable value rather than a refusal of the whole rollback.
	 */
	public function test_a_null_recorded_featured_media_is_not_promised(): void {
		$restore_state = '{"featured_media":null,"post_id":42,"post_title":"Original title"}';
		$this->queueSnapshot( [ 'restore_state' => $restore_state ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'post_title' => 'Original title' ], $planned->afterFields );
	}

	/**
	 * Shape one of the empty promise: the snapshot's only restorable key holds a
	 * value the media gate refuses, so nothing is promised.
	 *
	 * Without the explicit refusal this reaches PlannedChange::__construct(),
	 * which throws InvalidArgumentException — and because preview() calls
	 * planChange() outside any try block, that escape is answered as
	 * `execution_failed`, which ErrorCode declares RETRYABLE. The caller would be
	 * told to retry a rollback that can never succeed. `rollback_unavailable`
	 * exists for exactly this and is not retryable.
	 */
	public function test_a_snapshot_promising_nothing_is_rollback_unavailable(): void {
		$this->queueSnapshot( [ 'restore_state' => '{"featured_media":null,"post_id":42}' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			// The message matters, not only the code: four other
			// RollbackUnavailable throws sit earlier in planChange(), so an
			// errorCode assertion alone would pass if the empty-promise guard
			// were removed and an earlier gate refused for its own reason.
			$this->assertStringContainsString( 'holds no value', $e->getMessage() );
		}
	}

	/**
	 * Shape two, and the older of the two: a stored snapshot holding nothing but
	 * `post_id`. It reaches the same empty promise by a different route — no key
	 * to skip, simply nothing recorded — and one condition covers both.
	 */
	public function test_a_snapshot_holding_only_a_post_id_is_rollback_unavailable(): void {
		$this->queueSnapshot( [ 'restore_state' => '{"post_id":42}' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertStringContainsString( 'holds no value', $e->getMessage() );
		}
	}

	/**
	 * The refusal must not over-fire. A snapshot whose ONLY restorable value is a
	 * featured media id of 0 promises exactly that, and 0 is both falsy and the
	 * legal "no featured image" value — so a guard written as `! $promised` or
	 * `empty( $promised )` on the value rather than the array would refuse a
	 * perfectly restorable snapshot. A guard that refuses too much is worse than
	 * the escape it replaces.
	 */
	public function test_a_snapshot_promising_only_a_zero_featured_media_is_still_planned(): void {
		$this->queueSnapshot( [ 'restore_state' => '{"featured_media":0,"post_id":42}' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'featured_media' => 0 ], $planned->afterFields );
	}

	/**
	 * applyChange() carries its own is_numeric() gate rather than trusting
	 * planChange() to have stripped the value. The two phases are separated by a
	 * stored plan token, so applyChange() re-derives everything it writes; a
	 * promise reaching it with a null media value must still not be turned into
	 * the deletion that `(int) null` would make it.
	 */
	public function test_a_null_promised_featured_media_never_reaches_the_restore_state(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = new PlannedChange(
			[
				'rollbackRef' => self::REFERENCE,
				'restore'     => [ 'post_title' => 'Original title' ],
			],
			[
				'featured_media' => null,
				'post_title'     => 'Original title',
			],
			ContentFields::FIELD_ORDER
		);

		$this->thumbnailId = 108;

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [], $this->thumbnailWrites );
		$this->assertSame( 108, $this->thumbnailId );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
	}

	/**
	 * Makes the post carry allowlisted meta and a registered taxonomy, so
	 * ContentFields::read() projects non-empty `meta` and `terms` maps.
	 *
	 * @param array<string, string> $meta  Allowlisted key to stored value.
	 * @param array<string, int[]>  $terms Taxonomy name to stored term ids.
	 */
	private function stubMetaAndTerms( array $meta, array $terms ): void {
		Functions\when( 'get_option' )->justReturn( array_keys( $meta ) );
		// Honours $single as get_metadata_raw() does: row 0 alone for a single read,
		// the whole list otherwise. ContentFields::meta() takes the first and
		// ContentTarget's restore guard takes the second, so a fake answering one
		// shape to both would refuse every restore this class exercises.
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single
				? ( $meta[ $key ] ?? '' )
				: ( array_key_exists( $key, $meta ) ? [ $meta[ $key ] ] : [] )
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( array_keys( $terms ) );
		Functions\when( 'wp_get_object_terms' )->alias(
			static fn( $post_id, $taxonomy, $args = [] ) => $terms[ $taxonomy ] ?? []
		);
	}

	/**
	 * A recorded meta map is promised as the CURRENT complete map with the
	 * recorded values substituted in, not as the map that was recorded.
	 *
	 * `gone` is the allowlist-narrowed case: the administrator removed that key
	 * between capture and rollback. Promising it would promise a write the read
	 * projection can no longer see, so the restore would land and then report
	 * verification_failed. Dropping it is the honest answer. `subtitle` is
	 * promised as the recorded 'old' rather than the current 'new', which is what
	 * makes this a restore rather than a no-op.
	 */
	public function test_a_recorded_meta_map_is_promised_against_the_current_allowlist(): void {
		$this->stubMetaAndTerms( [ 'subtitle' => 'new' ], [] );
		$this->queueSnapshot( [ 'restore_state' => '{"meta":{"gone":"x","subtitle":"old"},"post_id":42}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'meta' => [ 'subtitle' => 'old' ] ], $planned->afterFields );
	}

	/**
	 * The same shape for terms, with `gone_tax` standing for a taxonomy
	 * unregistered from the post type since capture. `category` is promised as
	 * the recorded [3, 5] rather than the currently stored [11].
	 */
	public function test_a_recorded_term_map_is_promised_against_the_current_taxonomies(): void {
		$this->stubMetaAndTerms( [], [ 'category' => [ 11 ] ] );
		$this->queueSnapshot(
			[ 'restore_state' => '{"post_id":42,"terms":{"category":[3,5],"gone_tax":[9]}}' ],
			2
		);

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'terms' => [ 'category' => [ 3, 5 ] ] ], $planned->afterFields );
	}

	/**
	 * An item carrying a `sort => true` taxonomy is refused while planning, before
	 * anything is written and before any snapshot is captured.
	 *
	 * THE SNAPSHOT THIS OPERATION TAKES IS THE REASON, not the one it restores.
	 * The stored snapshot here promises `post_status` alone — the shape a
	 * content-status-set rollback has, naming no taxonomy at all — and the refusal
	 * still fires, because captureSnapshot() records the item's COMPLETE projected
	 * terms map whatever the promise holds. Without the refusal that map would
	 * record post_tag's curated sequence as a numerically sorted set, and reversing
	 * this rollback would rewrite term_order from it, read the same sorted
	 * projection back, and report `verified` with the order gone.
	 *
	 * `category` is unsorted and `post_tag` is the sorted one, so the assertion
	 * also pins that the scan does not stop at the first taxonomy it likes.
	 */
	public function test_an_order_sensitive_taxonomy_on_this_item_makes_the_rollback_unrecordable(): void {
		$this->stubMetaAndTerms(
			[],
			[
				'category' => [ 3 ],
				'post_tag' => [ 8 ],
			]
		);
		$this->sortedTaxonomies = [ 'post_tag' ];
		$this->queueSnapshot( [ 'restore_state' => '{"post_id":42,"post_status":"publish"}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( 'This content item carries a taxonomy that stores its terms in a curated order, which no snapshot can record, so nothing was restored.', $e->getMessage() );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * The unsorted case, asserted separately so the refusal above is known to be
	 * caused by `sort` rather than by the presence of taxonomies at all. Same
	 * fixture, same snapshot, only $sortedTaxonomies differs, and the plan
	 * succeeds.
	 */
	public function test_an_ordinary_taxonomy_on_this_item_does_not_block_the_rollback(): void {
		$this->stubMetaAndTerms(
			[],
			[
				'category' => [ 3 ],
				'post_tag' => [ 8 ],
			]
		);
		$this->queueSnapshot( [ 'restore_state' => '{"post_id":42,"post_status":"publish"}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'publish' ], $planned->afterFields );
	}

	/**
	 * The third shape of the empty promise. Every key the snapshot recorded has
	 * since left the allowlist, so the overlay comes back empty and there is
	 * nothing for the restore to put back.
	 *
	 * Promising `meta => []` instead would be a promise restoreFields() satisfies
	 * by doing nothing: the rollback would report VERIFIED having restored none of
	 * what was recorded — the exact "reference to a rollback that cannot run"
	 * these two field lists exist to prevent, one layer further in.
	 *
	 * The MESSAGE is asserted, not only the code. Four other RollbackUnavailable
	 * throws sit earlier in planChange(), so a code-only assertion would pass if
	 * this guard were removed and an earlier gate refused for its own reason.
	 */
	public function test_a_snapshot_holding_only_a_meta_map_that_overlays_onto_nothing_is_refused(): void {
		$this->stubMetaAndTerms( [], [] );
		$this->queueSnapshot( [ 'restore_state' => '{"meta":{"subtitle":"old"},"post_id":42}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame(
				'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.',
				$e->getMessage()
			);
		}
	}

	/**
	 * The same refusal when the current map is NOT empty, which emptiness of the
	 * overlay alone does not catch.
	 *
	 * The snapshot recorded only `gone`, which has since left the allowlist, while
	 * the post still carries `subtitle`. The overlay is therefore
	 * `['subtitle' => 'new']` — non-empty, and entirely the value already stored.
	 * Promising it would have the rollback verify having restored nothing at all,
	 * which is the identical outcome to the empty-map case and must be refused the
	 * same way. The test is whether any RECORDED key survived, not whether the
	 * result has anything in it.
	 *
	 * It matters beyond this operation: all three writes still to come record
	 * snapshots holding only `meta` or only `terms`, so this is the ordinary shape
	 * of their rollbacks rather than an edge case of this one.
	 */
	public function test_a_recorded_meta_map_whose_every_key_left_the_allowlist_is_refused(): void {
		$this->stubMetaAndTerms( [ 'subtitle' => 'new' ], [] );
		$this->queueSnapshot( [ 'restore_state' => '{"meta":{"gone":"x"},"post_id":42}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame(
				'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.',
				$e->getMessage()
			);
		}
	}

	/**
	 * The taxonomy loop carries the same condition and is deliberately a separate
	 * loop, so it needs its own proof: a mutation to one is invisible to a test
	 * that only exercises the other.
	 */
	public function test_a_recorded_term_map_whose_every_taxonomy_was_unregistered_is_refused(): void {
		$this->stubMetaAndTerms( [], [ 'category' => [ 11 ] ] );
		$this->queueSnapshot( [ 'restore_state' => '{"post_id":42,"terms":{"gone_tax":[9]}}' ], 2 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame(
				'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.',
				$e->getMessage()
			);
		}
	}

	/**
	 * The capture must record everything applyChange() can now write, which is
	 * two values wider than it was. Without this, reversing a rollback that
	 * changed meta or terms would promise the columns, skip the absent keys in
	 * restoreFields(), match its own promise on read-back, and report VERIFIED
	 * while the meta and the terms stayed where the rollback put them.
	 *
	 * The expected array is a LITERAL rather than derived from
	 * ContentTarget::RESTORABLE_*: deriving it would take its answer from the
	 * same constants the code under test loops over, and the test would agree
	 * with any widening of them including a wrong one.
	 *
	 * One assertSame on the whole array, not one per key, so the key SET and the
	 * canonical sort order are pinned along with the values.
	 */
	public function test_capture_snapshot_records_the_meta_and_term_maps_it_can_now_write(): void {
		$this->stubMetaAndTerms(
			[
				'subtitle' => 'Sub',
				'byline'   => 'Ada',
			],
			[ 'category' => [ 5, 3 ] ]
		);
		$this->queueSnapshot();

		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 0,
				'meta'           => [
					'byline'   => 'Ada',
					'subtitle' => 'Sub',
				],
				'post_content'   => '<p>Edited body.</p>',
				'post_excerpt'   => 'Edited excerpt.',
				'post_id'        => 42,
				'post_name'      => 'original-title',
				'post_status'    => 'draft',
				'post_title'     => 'Edited title',
				'terms'          => [ 'category' => [ 3, 5 ] ],
			],
			$snapshot
		);
	}

	/**
	 * The promised maps have to survive the plan-token boundary and reach the
	 * restore state applyChange() hands to restoreFields(). The two phases are
	 * separated by a stored token, so applyChange() re-derives everything it
	 * writes rather than trusting planChange() to have left it anywhere.
	 *
	 * A promise that reached restoreFields() without these keys would leave the
	 * meta and the terms exactly as the original write left them while the
	 * read-back — which compares against the promise — reported the rollback
	 * verified.
	 */
	public function test_a_promised_meta_and_term_map_reach_the_restore_state(): void {
		$meta_writes = [];
		$term_writes = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( $post_id, $key, $value, $prev_value = '' ) use ( &$meta_writes ) {
				$meta_writes[] = [ $key, $value ];

				return 1;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single
				? 'Recorded subtitle'
				: [ 'Recorded subtitle' ]
		);
		Functions\when( 'wp_set_object_terms' )->alias(
			static function ( $post_id, $ids, $taxonomy, $append = false ) use ( &$term_writes ) {
				$term_writes[] = [ $taxonomy, $ids ];

				// Term TAXONOMY ids, which is what core returns and is a different
				// id space from the term ids the restore compares.
				return [ 1003, 1005 ];
			}
		);
		Functions\when( 'wp_get_object_terms' )->justReturn( [ 3, 5 ] );

		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = new PlannedChange(
			[
				'rollbackRef' => self::REFERENCE,
				'restore'     => [ 'meta' => [ 'subtitle' => 'Recorded subtitle' ] ],
			],
			[
				'meta'  => [ 'subtitle' => 'Recorded subtitle' ],
				'terms' => [ 'category' => [ 3, 5 ] ],
			],
			ContentFields::FIELD_ORDER
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ [ 'subtitle', 'Recorded subtitle' ] ], $meta_writes );
		$this->assertSame( [ [ 'category', [ 3, 5 ] ] ], $term_writes );
	}
}
