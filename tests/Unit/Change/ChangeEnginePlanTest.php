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
use SiteHelm\Change\PlanAdmission;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\SnapshotLifecycle;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteVerifier;
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
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer(),
			new WriteVerifier( $normalizer ),
			new SnapshotLifecycle( new SnapshotStore(), $normalizer ),
			new PlanAdmission( new PlanStore(), $normalizer, new StateFingerprint( $normalizer ) )
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
	/**
	 * The stored fingerprint covers the resolved state's actual contents.
	 *
	 * A review found nothing pinned *which* state was fingerprinted: computing
	 * it over an empty field map left the whole suite green. That makes the
	 * fingerprint content-independent, so an edit between preview and apply is
	 * never detected and REQ-0006's staleness check silently passes for a target
	 * somebody else changed. Comparing against StateFingerprint's own output for
	 * the resolved state pins the input, not just the presence of a hash.
	 */
	public function test_the_stored_fingerprint_covers_the_resolved_state(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
		$state                   = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
		$this->operation->target = $state;
		$context                 = $this->makeContext();

		$this->engine->preview( $this->makeDefinition(), $this->operation, [ 'id' => 42 ], $context );

		$expected = ( new StateFingerprint( new PayloadNormalizer() ) )->compute( $state, $context );
		$this->assertSame( $expected, $this->wpdb->inserts[0]['data']['state_fingerprint'] );
	}

	/**
	 * Two different current states fingerprint differently.
	 *
	 * The assertion above would still pass if compute() ignored the fields and
	 * hashed only the target key, because the expectation is computed the same
	 * way. This one pins that the field contents actually participate.
	 */
	public function test_a_different_current_state_stores_a_different_fingerprint(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
		$this->operation->target = new TargetState( 'post:42', true, [ 'post_title' => 'First' ] );
		$this->engine->preview( $this->makeDefinition(), $this->operation, [ 'id' => 42 ], $this->makeContext() );

		$this->operation->target = new TargetState( 'post:42', true, [ 'post_title' => 'Second' ] );
		$this->engine->preview( $this->makeDefinition(), $this->operation, [ 'id' => 42 ], $this->makeContext() );

		$this->assertNotSame(
			$this->wpdb->inserts[0]['data']['state_fingerprint'],
			$this->wpdb->inserts[1]['data']['state_fingerprint']
		);
	}

	/**
	 * The approved preview is persisted, not just returned.
	 *
	 * A review found plan_body's contents unasserted: dropping previewSummary
	 * from the stored body entirely left the suite green. The stored body is
	 * what an apply re-reads to prove the operator approved this exact diff, so
	 * losing it would surface later as an unexplained Task 12 failure rather
	 * than as a plan-phase defect.
	 */
	public function test_the_stored_plan_body_carries_the_preview_and_the_eligibility(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
		$this->operation->target  = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
		$this->operation->planned = new PlannedChange(
			[ 'id' => 42, 'title' => 'Revised title' ],
			[ 'post_title' => 'Revised title' ],
			[ 'post_title' ]
		);

		$result = $this->engine->preview( $this->makeDefinition(), $this->operation, [ 'id' => 42 ], $this->makeContext() );

		$body = json_decode( $this->wpdb->inserts[0]['data']['plan_body'], true );
		$this->assertSame( [ 'previewSummary', 'snapshotEligibility' ], array_keys( $body ) );
		// assertEquals, not assertSame: the stored body goes through
		// canonicalJson, which ksorts every level so two identical plans store
		// byte-identically. The wire response keeps declaration order. Same
		// content, different key order — comparing order-sensitively would be
		// asserting the canonicalization away.
		$this->assertEquals( $result->data['plan']['previewSummary'], $body['previewSummary'] );
		$this->assertStringContainsString( 'post_title', $body['previewSummary']['human'] );
		$this->assertSame( ChangeEngine::SNAPSHOT_WILL_CAPTURE, $body['snapshotEligibility']['snapshot'] );
	}

	/**
	 * The payload hash binds the normalized planned payload, not the raw input.
	 *
	 * A review found this source unpinned: hashing the caller's raw arguments
	 * instead of the planned payload survived the suite, and would then fail
	 * every apply, because apply recomputes the hash from the planned payload.
	 * The two are deliberately different here so only the right source matches.
	 */
	public function test_the_payload_hash_binds_the_planned_payload(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
		$planned_payload          = [ 'id' => 42, 'title' => 'Revised title' ];
		$raw_payload              = [ 'id' => 42 ];
		$this->operation->planned = new PlannedChange(
			$planned_payload,
			[ 'post_title' => 'Revised title' ],
			[ 'post_title' ]
		);

		$this->engine->preview( $this->makeDefinition(), $this->operation, $raw_payload, $this->makeContext() );

		$normalizer = new PayloadNormalizer();
		$this->assertSame(
			$normalizer->fingerprint( $planned_payload ),
			$this->wpdb->inserts[0]['data']['payload_hash']
		);
		$this->assertNotSame(
			$normalizer->fingerprint( $raw_payload ),
			$this->wpdb->inserts[0]['data']['payload_hash']
		);
	}

	/**
	 * A refusal after a token was minted still says nothing about the token.
	 *
	 * The existing hygiene test only covers the path where no token exists yet.
	 * The failed-store path runs after issueToken(), so it is the one place a
	 * raw token could plausibly reach an operator-visible message.
	 */
	public function test_a_failed_store_does_not_leak_the_minted_token(): void {
		// The snapshot fixture matters: without it the eligibility check refuses
		// before a token is ever minted, so the path under test is unreachable.
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
		$this->wpdb->failInsert = true;

		try {
			$this->engine->preview( $this->makeDefinition(), $this->operation, [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'A refused plan store should have thrown.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
			$this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{64}/', $exception->getMessage() );
			$this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{64}/', $exception->remediation );
		}
	}
}
