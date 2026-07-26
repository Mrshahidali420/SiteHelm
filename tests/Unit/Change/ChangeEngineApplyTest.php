<?php
/**
 * Tests for the change engine's apply phase (REQ-0006, REQ-0007).
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
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0006: a write executes only when the exact previewed plan is approved by
 * the same authenticated user. REQ-0007: the persisted state is verified.
 */
final class ChangeEngineApplyTest extends TestCase {

	private const TOKEN = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

	private FakeWpdb $wpdb;
	private ChangeEngine $engine;
	private StubWriteOperation $operation;
	private PayloadNormalizer $normalizer;

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
		$user             = new \stdClass();
		$user->user_login = 'operator';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->normalizer = new PayloadNormalizer();
		$this->engine     = new ChangeEngine(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$this->normalizer,
			new StateFingerprint( $this->normalizer ),
			new PreviewRenderer(),
			new Installer(),
			new WriteVerifier( $this->normalizer )
		);

		$this->operation           = new StubWriteOperation();
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(): OperationDefinition {
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
				'properties'           => [ 'target' => [ 'type' => 'string' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
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
		);
	}

	private function makeContext( int $user_id = 7, int $request_time = 1_800_000_100 ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: $user_id,
			clientId: 'demo-client',
			correlationId: 'corr-2',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: $request_time,
		);
	}

	/**
	 * The stored plan row matching the stub operation's default plan.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> The plan row.
	 */
	private function planRow( array $overrides = [] ): array {
		$current     = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
		$fingerprint = ( new StateFingerprint( $this->normalizer ) )->compute( $current, $this->makeContext() );

		return array_merge(
			[
				'id'                => 1,
				'token_hash'        => PlanStore::digest( self::TOKEN ),
				'site_id'           => 'example.com',
				'user_id'           => 7,
				'operation_id'      => 'content-update',
				'schema_version'    => 1,
				'target_key'        => 'post:42',
				'payload_hash'      => $this->normalizer->fingerprint( [ 'title' => 'Edited title' ] ),
				'state_fingerprint' => $fingerprint,
				'plan_body'         => '{}',
				'created_at'        => 1_800_000_000,
				'expires_at'        => 1_800_000_900,
				'consumed_at'       => null,
			],
			$overrides
		);
	}

	/**
	 * Runs apply with the given stored plan row and queued row counts.
	 *
	 * @param array<string, mixed> $overrides Plan-row fields to replace.
	 */
	private function apply( array $overrides = [], int $user_id = 7, int $request_time = 1_800_000_100 ): mixed {
		$this->wpdb->rowQueue       = [ $this->planRow( $overrides ) ];
		$this->wpdb->queryRowsQueue = [ 1 ];

		return $this->engine->apply(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext( $user_id, $request_time )
		);
	}

	public function test_a_matching_plan_executes_verifies_audits_and_offers_rollback(): void {
		$result = $this->apply();

		$this->assertSame( 1, $this->operation->applyCalls );
		$this->assertSame( VerificationStatus::Verified, $result->verification );
		$this->assertSame( 'post:42', $result->data['target'] );
		$this->assertSame( [ 'post_title' ], $result->data['changed'] );
		$this->assertSame( 'Edited title', $result->data['state']['post_title'] );
		// The snapshot row is inserted before the audit row and FakeWpdb shares one
		// insert_id counter, so the audit record is the second insert.
		$this->assertSame( 'audit-2', $result->auditRef );
		$this->assertSame( 1, preg_match( '/^rb-[0-9a-f]{24}$/', (string) $result->rollbackRef ) );
	}

	public function test_the_plan_token_is_consumed_before_execution(): void {
		$this->apply();

		// prepared[0] is the plan lookup; prepared[1] is the single-use claim.
		$consume = $this->wpdb->prepared[1];
		$this->assertStringContainsString( 'consumed_at IS NULL', $consume['query'] );
		$this->assertSame( PlanStore::digest( self::TOKEN ), $consume['args'][1] );
	}

	/**
	 * When the engine's own local tables are missing, apply() must refuse
	 * with integration_unavailable BEFORE even looking up the plan — not
	 * stale_plan, which would falsely tell the caller their approval expired
	 * or was already used when the real problem is that the plugin's storage
	 * is gone.
	 */
	public function test_unavailable_storage_refuses_before_any_plan_lookup(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		// No plan lookup was even attempted: require_storage() runs first.
		$this->assertSame( [], $this->wpdb->prepared );
		$this->assertSame( 0, $this->operation->resolveCalls );
	}

	public function test_an_unknown_token_is_stale_plan(): void {
		$this->wpdb->rowQueue = [];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_an_expired_token_is_stale_plan_and_does_not_execute(): void {
		try {
			$this->apply( [ 'expires_at' => 1_800_000_050 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}

		// consume() must never even be attempted here: the freshness check has
		// to run BEFORE consumption, not merely before execution. Only the
		// plan lookup's prepare() call may have happened; a second prepare()
		// would mean consume() ran and burned the token on a plan that was
		// already refused for being expired.
		$this->assertCount(
			1,
			$this->wpdb->prepared,
			'consume() must not run before the expiry check has passed.'
		);
	}

	/**
	 * The expiry check is `expires_at <= requestTime`, not `<`: a plan
	 * expiring in the SAME instant as the request must still be refused.
	 * Flipping the operator to `<` would leave a one-second window where an
	 * already-expired plan applies successfully.
	 */
	public function test_a_plan_expiring_exactly_at_the_request_time_is_stale_plan(): void {
		try {
			// makeContext()'s default requestTime is 1_800_000_100.
			$this->apply( [ 'expires_at' => 1_800_000_100 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_an_already_consumed_token_is_stale_plan(): void {
		try {
			$this->apply( [ 'consumed_at' => 1_800_000_060 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_a_different_authenticated_user_is_stale_plan(): void {
		try {
			$this->apply( [], 9 );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_a_different_site_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'site_id' => 'other.example' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_operation_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'operation_id' => 'content-create' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_schema_version_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'schema_version' => 2 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_target_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'target_key' => 'post:99' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	/**
	 * Exercises the path a real client actually takes: it resends genuinely
	 * DIFFERENT arguments on apply (simulated here via planChange()'s
	 * returned payload, since resolveTarget()'s current state — and so the
	 * fingerprint — is unchanged), rather than tampering with the stored
	 * hash directly. The stored plan's payload_hash was computed from a
	 * different payload than the one this call resolves to.
	 */
	public function test_a_different_payload_is_stale_plan(): void {
		$this->operation->planned = new PlannedChange(
			[ 'title' => 'A completely different edit' ],
			[ 'post_title' => 'A completely different edit' ],
			[ 'post_title' ]
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	/**
	 * Every stale-plan-triggering scenario must be indistinguishable to the
	 * caller in code, message, AND remediation — not merely share an error
	 * code. A test that only asserts the error code would still pass if one
	 * binding failure carried a different message revealing WHICH element of
	 * the binding was wrong, which is exactly the oracle the shared refusal
	 * exists to deny a caller.
	 */
	public function test_every_stale_plan_cause_is_indistinguishable_in_message_and_remediation(): void {
		$scenarios = [
			'unknown token'    => fn () => $this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			),
			'expired'          => fn () => $this->apply( [ 'expires_at' => 1_800_000_050 ] ),
			'already consumed' => fn () => $this->apply( [ 'consumed_at' => 1_800_000_060 ] ),
			'different user'   => fn () => $this->apply( [], 9 ),
			'different site'   => fn () => $this->apply( [ 'site_id' => 'other.example' ] ),
			'different op'     => fn () => $this->apply( [ 'operation_id' => 'content-create' ] ),
			'different schema' => fn () => $this->apply( [ 'schema_version' => 2 ] ),
			'different target' => fn () => $this->apply( [ 'target_key' => 'post:99' ] ),
			'different payload' => fn () => $this->apply( [ 'payload_hash' => str_repeat( '0', 64 ) ] ),
		];

		$messages     = [];
		$remediations = [];

		foreach ( $scenarios as $label => $scenario ) {
			// Each scenario needs its own fresh double: an already-consumed
			// wpdb row queue cannot be replayed across iterations.
			$this->wpdb = new FakeWpdb();
			$GLOBALS['wpdb'] = $this->wpdb;

			try {
				$scenario();
				$this->fail( "Expected OperationException for scenario: {$label}" );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::StalePlan, $e->errorCode, "scenario: {$label}" );
				$messages[ $label ]     = $e->getMessage();
				$remediations[ $label ] = $e->remediation;
			}
		}

		$this->assertCount( 1, array_unique( $messages ), 'Every stale_plan message must be identical: ' . implode( ' | ', array_unique( $messages ) ) );
		$this->assertCount( 1, array_unique( $remediations ), 'Every stale_plan remediation must be identical.' );
	}

	public function test_a_changed_target_state_is_conflict_with_state_untouched(): void {
		try {
			$this->apply( [ 'state_fingerprint' => str_repeat( '1', 64 ) ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
			$this->assertSame( 0, $this->operation->snapshotCalls );
		}

		// consume() must never run for a plan whose fingerprint no longer
		// matches: only the plan lookup's prepare() call may have happened.
		// If consume() ran ahead of the fingerprint check, a concurrent edit
		// would both answer `conflict` AND burn the token, leaving the caller
		// unable to re-preview and retry with a fresh plan.
		$this->assertCount(
			1,
			$this->wpdb->prepared,
			'consume() must not run before the fingerprint check has passed.'
		);
	}

	/**
	 * A genuine concurrent edit invalidates the state fingerprint AND the
	 * payload hash at once, because the payload is re-derived from the
	 * (now-changed) current target. The contract names that situation
	 * `conflict`, so the fingerprint check must be the one that fires even
	 * though the payload check would also fail on its own. A test that only
	 * ever mismatches one binding field at a time cannot distinguish "checks
	 * fingerprint first" from "checks payload first" — both orderings answer
	 * every single-field test the same way. Only a scenario where both checks
	 * would fail independently can tell the two orderings apart.
	 */
	public function test_a_concurrent_edit_that_fails_both_checks_reports_conflict_not_stale_plan(): void {
		$this->operation->target  = new TargetState( 'post:42', true, [ 'post_title' => 'Someone else already changed this' ] );
		$this->operation->planned = new PlannedChange(
			[ 'title' => 'A completely different edit' ],
			[ 'post_title' => 'A completely different edit' ],
			[ 'post_title' ]
		);

		try {
			// planRow()'s stored state_fingerprint and payload_hash are both
			// computed from the ORIGINAL target/payload, so both mismatch here.
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
			$this->assertSame( 0, $this->operation->snapshotCalls );
		}
	}

	public function test_losing_the_consumption_race_is_stale_plan(): void {
		$this->wpdb->rowQueue       = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue = [ 0 ];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	/**
	 * The stored value still being the prior value is the one divergence that
	 * means the write did not take.
	 */
	public function test_a_field_still_holding_its_prior_value_is_verification_failed(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-2', (string) $e->remediation );
		}

		$this->assertSame(
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	/**
	 * The defect this contract change exists to fix. WordPress adjusting a value
	 * on save — trimming a title, uniquifying a slug, turning a publish into a
	 * future — used to raise verification_failed with no rollbackRef and a
	 * remediation telling the operator to restore the snapshot, for a write that
	 * had landed perfectly. It must now succeed, disclose what was stored, and
	 * hand back the recovery handle.
	 */
	public function test_a_value_wordpress_adjusted_succeeds_with_adjustments(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Adjusted by WordPress' ] );

		$result = $this->apply();

		$this->assertSame( VerificationStatus::VerifiedWithAdjustments, $result->verification );
		$this->assertNotNull( $result->rollbackRef );
		$this->assertSame( 'Adjusted by WordPress', $result->data['state']['post_title'] );
		$this->assertSame(
			AuditRecorder::OUTCOME_APPLIED,
			$this->wpdb->updates[0]['data']['outcome']
		);

		$joined = implode( "\n", $result->warnings );
		$this->assertStringContainsString( 'post_title', $joined );
		$this->assertStringNotContainsString( 'Adjusted by WordPress', $joined );
	}

	/**
	 * The response's disclosure of an adjusted value is ephemeral; the audit
	 * record is permanent. Recording the PROMISED value against outcome
	 * `applied` would leave the audit asserting a clean apply for a value
	 * WordPress never stored — a false permanent record of exactly the kind
	 * auditability exists to prevent, and one that did not exist before this
	 * contract change, because the case used to record verification_failed.
	 *
	 * The summary carries names and sizes only, so this asserts the measured
	 * length: the stored 'Adjusted by WordPress' is 21 characters, the prior
	 * 'Original title' is 14, and the promised 'Edited title' — which must NOT
	 * be what is recorded — is 12.
	 */
	public function test_the_audit_record_measures_the_stored_value_not_the_promised_one(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Adjusted by WordPress' ] );

		$this->apply();

		$this->assertSame(
			AuditRecorder::OUTCOME_APPLIED,
			$this->wpdb->updates[0]['data']['outcome']
		);

		$summary = json_decode( (string) $this->wpdb->updates[0]['data']['summary'], true );

		$this->assertSame( [ 'post_title' ], $summary['changed'] );
		$this->assertSame( 14, $summary['metrics']['post_title']['before'] );
		$this->assertSame( 21, $summary['metrics']['post_title']['after'] );
	}

	/**
	 * The sibling above covers a promised field stored with a DIFFERENT value.
	 * This covers a promised field the after-state does not carry at all, which
	 * is the one shape where taking the intersection of the two field sets and
	 * falling back to the plan would quietly record the PROMISED value — the
	 * same false audit record, reached by a different route.
	 *
	 * The prior value is non-null, so the missing field matches neither the
	 * promise nor the prior value and the write still classifies as adjusted:
	 * the applied path runs and writes this row. The recorded after-state must
	 * measure the field as absent — size 0 — never as the promised 'Edited
	 * title', which would measure 12.
	 */
	public function test_the_audit_record_measures_a_promised_field_missing_from_the_after_state_as_absent(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [] );

		$result = $this->apply();

		$this->assertSame( VerificationStatus::VerifiedWithAdjustments, $result->verification );
		$this->assertSame(
			AuditRecorder::OUTCOME_APPLIED,
			$this->wpdb->updates[0]['data']['outcome']
		);

		$summary = json_decode( (string) $this->wpdb->updates[0]['data']['summary'], true );

		$this->assertSame( [ 'post_title' ], $summary['changed'] );
		$this->assertSame( 14, $summary['metrics']['post_title']['before'] );
		$this->assertSame( 0, $summary['metrics']['post_title']['after'] );
	}

	/**
	 * One promised field stored and another reverted leaves the target in neither
	 * its prior nor its promised state.
	 *
	 * The plan promises two fields, so the resolved before-state carries two
	 * fields as well — which means the stored row's state_fingerprint has to be
	 * recomputed from that state. planRow()'s default fingerprint is computed
	 * from the single-field fixture, and leaving it in place would refuse this
	 * plan as a `conflict` long before verification is reached. The stored
	 * payload_hash still matches: planChange()'s returned payload is unchanged.
	 */
	public function test_a_partial_write_is_verification_failed(): void {
		$this->operation->planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Edited excerpt',
			],
			[ 'post_title', 'post_excerpt' ]
		);
		$this->operation->target        = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_excerpt' => 'Original excerpt',
			]
		);
		$this->operation->readBackState = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Original excerpt',
			]
		);

		try {
			$this->apply(
				[
					'state_fingerprint' => ( new StateFingerprint( $this->normalizer ) )
						->compute( $this->operation->target, $this->makeContext() ),
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}

		$this->assertSame(
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	/**
	 * A write that lands but cannot be re-read must still surface as
	 * verification_failed with the audit row finalized — never as the
	 * underlying operation's own error code (a real readBack() might throw
	 * target_not_found rather than verification_failed), and never with the
	 * audit row stranded at 'started' for a write that actually happened.
	 */
	public function test_a_readback_failure_after_a_successful_write_is_verification_failed_with_audit_finalized(): void {
		$this->operation->readBackThrows = new OperationException(
			ErrorCode::TargetNotFound,
			'The content item could not be found.'
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-2', (string) $e->remediation );
		}

		// The write itself succeeded before readBack() failed.
		$this->assertSame( 1, $this->operation->applyCalls );
		$this->assertSame(
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	/**
	 * The audit row must carry the recovery handle from the moment it is
	 * created, not only once finish() runs, so that a fatal inside applyChange()
	 * cannot strand a real snapshot that nothing references. The audit row is
	 * the second insert: the snapshot is captured first and FakeWpdb shares one
	 * insert_id counter.
	 */
	public function test_the_opening_audit_row_already_carries_the_rollback_reference(): void {
		$result = $this->apply();

		$audit_row = $this->wpdb->inserts[1]['data'];
		$this->assertSame( AuditRecorder::OUTCOME_STARTED, $audit_row['outcome'] );
		$this->assertSame( 1, $audit_row['snapshot_id'] );
		$this->assertSame( $result->rollbackRef, $audit_row['rollback_ref'] );
	}

	/**
	 * REQ-0005 promises the operator inspects exactly what a write will change.
	 * A third-party save_post hook that rewrites an unpromised field breaks that
	 * promise silently if verification only ever looks at the promised keys. The
	 * operation kept its own promise, so this warns; it does not fail.
	 */
	public function test_an_unpromised_field_change_warns_without_failing_verification(): void {
		$current = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
			]
		);

		$this->operation->target        = $current;
		$this->operation->readBackState = new TargetState(
			'post:42',
			true,
			[
				'post_title'        => 'Edited title',
				'post_content'      => '<p>Rewritten by another plugin.</p>',
				'post_modified_gmt' => '2026-07-26 11:00:00',
			]
		);

		$this->wpdb->rowQueue       = [
			$this->planRow(
				[
					'state_fingerprint' => ( new StateFingerprint( $this->normalizer ) )
						->compute( $current, $this->makeContext() ),
				]
			),
		];
		$this->wpdb->queryRowsQueue = [ 1 ];

		$result = $this->engine->apply(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext()
		);

		$this->assertSame( VerificationStatus::Verified, $result->verification );

		$joined = implode( ' | ', $result->warnings );
		$this->assertStringContainsString( 'post_content', $joined );
		// Names only. A warning must never carry the value it is warning about.
		$this->assertStringNotContainsString( 'Rewritten by another plugin', $joined );
		// post_modified_gmt changes on every write and must never be reported.
		$this->assertStringNotContainsString( 'post_modified_gmt', $joined );
		// The engine cannot tell core from a third-party hook — trashing a post
		// renames the slug in core — so the warning must not name a culprit.
		$this->assertStringNotContainsString( 'Another plugin', $joined );
		$this->assertStringNotContainsString( 'plugin', $joined );
	}

	public function test_a_write_that_changes_only_what_it_promised_warns_about_nothing(): void {
		$this->assertSame( [], $this->apply()->warnings );
	}

	/**
	 * post_modified_gmt must never be reported even when it is present in
	 * BOTH the before-state and the after-state with genuinely different
	 * values — the realistic case for every single write. The prior
	 * regression only tested post_modified_gmt appearing in the after-state
	 * with no counterpart in the before-state, where the separate "present in
	 * both states" guard already excludes it regardless of VOLATILE_FIELD, so
	 * deleting the VOLATILE_FIELD check left that test green. This fixture
	 * removes that confound.
	 */
	public function test_the_volatile_modified_timestamp_is_never_reported_even_when_present_in_both_states(): void {
		$current = new TargetState(
			'post:42',
			true,
			[
				'post_title'        => 'Original title',
				'post_modified_gmt' => '2026-07-26 09:00:00',
			]
		);

		$this->operation->target        = $current;
		$this->operation->readBackState = new TargetState(
			'post:42',
			true,
			[
				'post_title'        => 'Edited title',
				'post_modified_gmt' => '2026-07-26 11:00:00',
			]
		);

		$this->wpdb->rowQueue       = [
			$this->planRow(
				[
					'state_fingerprint' => ( new StateFingerprint( $this->normalizer ) )
						->compute( $current, $this->makeContext() ),
				]
			),
		];
		$this->wpdb->queryRowsQueue = [ 1 ];

		$result = $this->engine->apply(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext()
		);

		$this->assertSame( [], $result->warnings );
	}

	/**
	 * `changed` in the response must reflect only what the plan promised, not
	 * every field the re-read after-state happens to carry. readBack() often
	 * returns the full record, so an after-state with an untouched field the
	 * plan never mentioned must not leak into `changed`.
	 */
	public function test_changed_reports_only_the_fields_the_plan_promised(): void {
		$this->operation->readBackState = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Untouched excerpt',
			]
		);

		$result = $this->apply();

		$this->assertSame( [ 'post_title' ], $result->data['changed'] );
	}

	public function test_a_failed_execution_restores_the_snapshot_and_reports_compensation(): void {
		$this->operation->applyThrows = new OperationException(
			ErrorCode::ExecutionFailed,
			'WordPress refused to save the content item.',
			'Retry with a fresh plan.',
			[ 'validated', 'snapshot captured' ]
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'restored', $e->compensation );
			$this->assertSame( [ 'validated', 'snapshot captured' ], $e->completedSteps );
		}

		$this->assertSame( 1, $this->operation->restoreCalls );
		$this->assertSame(
			AuditRecorder::OUTCOME_EXECUTION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	public function test_a_failed_restore_reports_compensation_failed(): void {
		$this->operation->applyThrows   = new OperationException(
			ErrorCode::ExecutionFailed,
			'WordPress refused to save the content item.'
		);
		$this->operation->restoreThrows = new OperationException(
			ErrorCode::ExecutionFailed,
			'The recorded snapshot could not be restored.'
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( 'failed', $e->compensation );
		}
	}

	/**
	 * The plan's snapshot policy is Required. When capturing fails, the change
	 * must be refused with rollback_unavailable and nothing may execute — but
	 * the plan token is already spent, because consume() runs before capture()
	 * is even attempted. Without this test, a mutation that lets a required
	 * snapshot policy silently proceed without a snapshot passes the whole
	 * suite: every other test supplies a capturable snapshot.
	 */
	public function test_a_required_snapshot_that_cannot_be_captured_refuses_after_the_token_is_spent(): void {
		$this->operation->snapshot = null;

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( 0, $this->operation->applyCalls );
		// Nothing was ever inserted: no snapshot row, no audit row.
		$this->assertSame( [], $this->wpdb->inserts );
		// But the token itself was already claimed before capture() ran.
		$consume = $this->wpdb->prepared[1];
		$this->assertStringContainsString( 'consumed_at IS NULL', $consume['query'] );
	}

	/**
	 * The plan's snapshot policy is Required and captureSnapshot() returns
	 * data (unlike the sibling test above), but the SNAPSHOT ROW ITSELF
	 * cannot be stored. That must still refuse with rollback_unavailable
	 * rather than let the write land with no snapshot, a null rollbackRef,
	 * and no warning to explain why.
	 */
	public function test_a_snapshot_row_that_cannot_be_stored_is_rollback_unavailable(): void {
		$this->wpdb->rowQueue         = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue   = [ 1 ];
		$this->wpdb->failInsertTables = [ Installer::tableName( Installer::TABLE_SNAPSHOTS ) ];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( 0, $this->operation->applyCalls );
	}

	/**
	 * The audit summary's `changed` list and metrics are direction-sensitive:
	 * summarize() diffs the promised after-state against the resolved
	 * before-state. This fixture is deliberately asymmetric — the before-state
	 * carries a field ('post_excerpt') the plan never touches — so that
	 * swapping the two arguments at the call site is observable: swapped, the
	 * untouched field would spuriously appear in `changed` (it exists only on
	 * one side, so a swap makes summarize() treat its disappearance as a
	 * change) and the direction of every reported metric would invert.
	 */
	public function test_the_audit_summary_reports_before_and_after_fields_in_the_correct_direction(): void {
		$current = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_excerpt' => 'Original excerpt',
			]
		);
		$this->operation->target = $current;

		$this->wpdb->rowQueue       = [
			$this->planRow(
				[
					'state_fingerprint' => ( new StateFingerprint( $this->normalizer ) )
						->compute( $current, $this->makeContext() ),
				]
			),
		];
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->engine->apply(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext()
		);

		$summary = json_decode( (string) $this->wpdb->updates[0]['data']['summary'], true );

		// Only the promised field (post_title) may be reported as changed. A
		// swap would additionally report post_excerpt — present only in the
		// "before" state — as a spurious change.
		$this->assertSame( [ 'post_title' ], $summary['changed'] );
	}

	public function test_a_refused_audit_record_refuses_to_execute(): void {
		$this->wpdb->rowQueue         = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue   = [ 1 ];
		// Only the audit insert fails: the snapshot must still succeed, or the
		// engine would refuse earlier with rollback_unavailable instead.
		$this->wpdb->failInsertTables = [ Installer::tableName( Installer::TABLE_AUDIT ) ];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_handle_previews_without_a_token_and_applies_with_one(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$previewed                  = $this->engine->handle(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			null,
			$this->makeContext()
		);

		$this->assertArrayHasKey( 'plan', $previewed->data );
		$this->assertSame( 0, $this->operation->applyCalls );

		$this->wpdb->rowQueue       = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue = [ 1 ];
		$applied                    = $this->engine->handle(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext()
		);

		$this->assertArrayHasKey( 'target', $applied->data );
		$this->assertSame( 1, $this->operation->applyCalls );
	}
}
