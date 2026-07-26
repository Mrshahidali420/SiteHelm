<?php
/**
 * Tests for the change engine's plan phase (REQ-0005).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\StateFingerprint;
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
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0005: a deterministic plan diff plus a short-lived opaque plan token,
 * with the target state left unchanged.
 */
final class ChangeEnginePlanTest extends TestCase {

	private FakeWpdb $wpdb;
	private ChangeEngine $engine;
	private StubWriteOperation $operation;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$normalizer      = new PayloadNormalizer();
		$this->engine    = new ChangeEngine(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer()
		);
		$this->operation = new StubWriteOperation();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(
		SnapshotPolicy $snapshot = SnapshotPolicy::Required,
		RollbackPolicy $rollback = RollbackPolicy::Supported
	): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'plan' => [ 'type' => 'object' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: $snapshot,
			rollbackPolicy: $rollback,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
		);
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
	 * @return array<string, mixed> The plan payload from a successful preview.
	 */
	private function runPreview(
		SnapshotPolicy $snapshot = SnapshotPolicy::Required,
		RollbackPolicy $rollback = RollbackPolicy::Supported
	): array {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$result                     = $this->engine->preview(
			$this->makeDefinition( $snapshot, $rollback ),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			$this->makeContext()
		);

		return $result->data['plan'];
	}

	public function test_preview_returns_an_opaque_token_and_a_short_lived_expiry(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['planToken'] ) );
		$this->assertSame( 1_800_000_000 + PlanStore::DEFAULT_TTL, $plan['expiresAt'] );
	}

	public function test_preview_stores_only_the_token_digest(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan   = $this->runPreview();
		$stored = $this->wpdb->inserts[0]['data'];

		$this->assertSame( hash( 'sha256', $plan['planToken'] ), $stored['token_hash'] );
		$this->assertStringNotContainsString( $plan['planToken'], (string) $stored['plan_body'] );
	}

	/**
	 * The server-side row is what apply() re-checks a submitted token against,
	 * so every binding the client is told about must actually be persisted, not
	 * just echoed back in the response.
	 */
	public function test_preview_persists_every_binding_on_the_stored_plan_row(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan   = $this->runPreview();
		$stored = $this->wpdb->inserts[0]['data'];

		$this->assertSame( 'example.com', $stored['site_id'] );
		$this->assertSame( 7, $stored['user_id'] );
		$this->assertSame( 'content-update', $stored['operation_id'] );
		$this->assertSame( 1, $stored['schema_version'] );
		$this->assertSame( 'post:42', $stored['target_key'] );
		$this->assertSame( $plan['bindings']['payloadHash'], $stored['payload_hash'] );
		$this->assertSame( $plan['stateFingerprint'], $stored['state_fingerprint'] );
		$this->assertSame( 1_800_000_000, $stored['created_at'] );
		$this->assertSame( $plan['expiresAt'], $stored['expires_at'] );
	}

	public function test_preview_binds_user_site_operation_schema_target_and_payload(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( 7, $plan['bindings']['user'] );
		$this->assertSame( 'example.com', $plan['bindings']['site'] );
		$this->assertSame( 'content-update', $plan['bindings']['operation'] );
		$this->assertSame( 1, $plan['bindings']['schemaVersion'] );
		$this->assertSame( 'post:42', $plan['bindings']['target'] );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['bindings']['payloadHash'] ) );
	}

	public function test_preview_carries_both_renderings_and_the_state_fingerprint(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertArrayHasKey( 'human', $plan['previewSummary'] );
		$this->assertArrayHasKey( 'machine', $plan['previewSummary'] );
		$this->assertSame(
			[
				[
					'field'  => 'post_title',
					'before' => 'Original title',
					'after'  => 'Edited title',
				],
			],
			$plan['previewSummary']['machine']['changes']
		);
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['stateFingerprint'] ) );
	}

	public function test_preview_never_executes_the_write(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$this->runPreview();

		$this->assertSame( 0, $this->operation->applyCalls );
		$this->assertSame( 0, $this->operation->restoreCalls );
		$this->assertSame( [], $this->wpdb->updates );
	}

	public function test_preview_declares_snapshot_and_rollback_eligibility(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( ChangeEngine::SNAPSHOT_WILL_CAPTURE, $plan['snapshotEligibility']['snapshot'] );
		$this->assertSame( ChangeEngine::ROLLBACK_WILL_OFFER, $plan['snapshotEligibility']['rollback'] );
	}

	public function test_a_creation_without_prior_state_offers_no_rollback(): void {
		$this->operation->target   = new TargetState( 'post:new', false, [] );
		$this->operation->planned  = new PlannedChange(
			[ 'title' => 'Brand new' ],
			[ 'post_title' => 'Brand new' ],
			[ 'post_title' ]
		);
		$this->operation->snapshot = null;

		$plan = $this->runPreview( SnapshotPolicy::Supported, RollbackPolicy::Supported );

		$this->assertSame( ChangeEngine::SNAPSHOT_NO_PRIOR_STATE, $plan['snapshotEligibility']['snapshot'] );
		$this->assertSame( ChangeEngine::ROLLBACK_NOT_OFFERED, $plan['snapshotEligibility']['rollback'] );
	}

	public function test_required_snapshot_that_cannot_be_captured_is_refused_before_execution(): void {
		$this->operation->snapshot = null;

		try {
			$this->runPreview( SnapshotPolicy::Required, RollbackPolicy::Supported );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_two_previews_of_the_same_state_and_payload_render_identically(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$first  = $this->runPreview();
		$second = $this->runPreview();

		$this->assertSame( $first['previewSummary'], $second['previewSummary'] );
		$this->assertSame( $first['stateFingerprint'], $second['stateFingerprint'] );
		$this->assertNotSame( $first['planToken'], $second['planToken'] );
	}

	public function test_unavailable_storage_degrades_to_integration_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->engine->preview(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->resolveCalls );
		}
	}

	public function test_a_refused_plan_insert_is_integration_unavailable(): void {
		$this->operation->snapshot  = [ 'post_title' => 'Original title' ];
		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->wpdb->failInsert     = true;

		try {
			$this->engine->preview(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_no_envelope_text_contains_a_filesystem_path_or_credential_word(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->engine->preview( $this->makeDefinition(), $this->operation, [], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;
			$this->assertSame( 0, preg_match( '/\\\\|\/var\/|\/home\/|wp-content|password|secret|authorization/i', $text ) );
		}
	}
}
