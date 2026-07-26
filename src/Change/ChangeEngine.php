<?php
/**
 * The two-phase change engine.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Contracts\ChangePlan;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;

/**
 * The shared change engine every write dispatcher routes through.
 *
 * Phase one previews: it validates, resolves the target, builds a deterministic
 * plan, and hands back an opaque single-use token without mutating anything.
 * Phase two applies: it verifies the token bindings, confirms the state
 * fingerprint still matches, captures the promised snapshot, executes, verifies
 * the resulting WordPress state, records the audit event, and returns a result.
 *
 * Trusted-write mode is deliberately NOT implemented here. The contract allows
 * an administrator to enroll risk-`low` operations for single-call execution,
 * but no operation in this phase is risk `low`, so nothing is eligible. In
 * trusted-write mode this engine behaves exactly as in safe-write mode: the
 * two-phase flow is mandatory for every preview-required operation.
 *
 * @package SiteHelm
 */
final class ChangeEngine {

	public const SNAPSHOT_WILL_CAPTURE   = 'will-capture';
	public const SNAPSHOT_NO_PRIOR_STATE = 'no-prior-state';
	public const SNAPSHOT_NOT_APPLICABLE = 'not-applicable';
	public const ROLLBACK_WILL_OFFER     = 'will-offer';
	public const ROLLBACK_NOT_OFFERED    = 'not-offered';

	/**
	 * Constructs the engine over its collaborators.
	 *
	 * @param PlanStore         $plans       Pending plan storage.
	 * @param SnapshotStore     $snapshots   Rollback snapshot storage.
	 * @param AuditRecorder     $audit       Audit record lifecycle.
	 * @param PayloadNormalizer $normalizer  Canonical form and hashing.
	 * @param StateFingerprint  $fingerprint Target-state fingerprinting.
	 * @param PreviewRenderer   $preview     Both preview renderings.
	 * @param Installer         $installer   Storage availability probe.
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly SnapshotStore $snapshots,
		private readonly AuditRecorder $audit,
		private readonly PayloadNormalizer $normalizer,
		private readonly StateFingerprint $fingerprint,
		private readonly PreviewRenderer $preview,
		private readonly Installer $installer,
	) {
	}

	/**
	 * Builds the engine with its default collaborators.
	 *
	 * Every collaborator is a stateless wrapper over $wpdb or the options API,
	 * so constructing them here costs nothing and keeps the bootstrap short.
	 *
	 * @return self The default engine.
	 */
	public static function create(): self {
		$normalizer = new PayloadNormalizer();

		return new self(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer()
		);
	}

	/**
	 * The plan phase: preview a write and issue its approval token.
	 *
	 * Nothing is mutated. The returned token is opaque, single-use, and bound to
	 * the authenticated user, the site, the operation with its schema version,
	 * the concrete target, and the full normalized payload.
	 *
	 * @param OperationDefinition  $definition The operation being previewed.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload    The validated arguments.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The plan, wrapped in a success envelope.
	 *
	 * @throws OperationException On any failure; state is left untouched.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function preview(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		OperationContext $context
	): OperationResult {
		$this->require_storage();

		$current     = $operation->resolveTarget( $payload, $context );
		$planned     = $operation->planChange( $current, $payload, $context );
		$fingerprint = $this->fingerprint->compute( $current, $context );
		$eligibility = $this->eligibility( $definition, $operation, $current, $context );
		$rendering   = $this->preview->render( $definition->id, $current, $planned );

		$token        = PlanStore::issueToken();
		$expires_at   = $context->requestTime + $this->plans->ttl();
		$payload_hash = $this->normalizer->fingerprint( $planned->payload );

		$plan = new ChangePlan(
			planToken: $token,
			bindings: [
				'user'          => $context->userId,
				'site'          => $context->siteId,
				'operation'     => $definition->id,
				'schemaVersion' => $definition->schemaVersion,
				'target'        => $current->targetKey,
				'payloadHash'   => $payload_hash,
			],
			stateFingerprint: $fingerprint,
			previewSummary: $rendering,
			expiresAt: $expires_at,
			snapshotEligibility: $eligibility,
		);

		$stored = $this->plans->store(
			[
				'token_hash'        => PlanStore::digest( $token ),
				'site_id'           => $context->siteId,
				'user_id'           => $context->userId,
				'operation_id'      => $definition->id,
				'schema_version'    => $definition->schemaVersion,
				'target_key'        => $current->targetKey,
				'payload_hash'      => $payload_hash,
				'state_fingerprint' => $fingerprint,
				'plan_body'         => $this->normalizer->canonicalJson(
					[
						'previewSummary'      => $rendering,
						'snapshotEligibility' => $eligibility,
					]
				),
				'created_at'        => $context->requestTime,
				'expires_at'        => $expires_at,
			]
		);

		if ( ! $stored ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The change engine could not record this plan, so no preview can be approved.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		return new OperationResult(
			operationId: $definition->id,
			data: [ 'plan' => $this->plan_payload( $plan ) ],
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
			warnings: $planned->warnings,
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Serializes a change plan for the wire.
	 *
	 * The bindings are returned because the contract makes them a field of
	 * `ChangePlan`. The guarantee that "all bindings live server-side" is about
	 * the TOKEN carrying no data, which holds: the token is 32 bytes of
	 * randomness and reveals nothing on its own.
	 *
	 * @param ChangePlan $plan The plan to serialize.
	 *
	 * @return array<string, mixed> The wire shape.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function plan_payload( ChangePlan $plan ): array {
		return [
			'planToken'           => $plan->planToken,
			'bindings'            => $plan->bindings,
			'stateFingerprint'    => $plan->stateFingerprint,
			'previewSummary'      => $plan->previewSummary,
			'expiresAt'           => $plan->expiresAt,
			'snapshotEligibility' => $plan->snapshotEligibility,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Declares the recovery position before anything executes.
	 *
	 * `captureSnapshot()` is contractually side-effect free, so probing it here
	 * is safe. When the snapshot policy is `required` and nothing can be
	 * captured, the plan is refused with `rollback_unavailable` rather than
	 * offering a preview that could never safely execute. A `required` rollback
	 * policy needs no separate branch: the registry already forces a `required`
	 * snapshot policy alongside it.
	 *
	 * @param OperationDefinition $definition The operation being previewed.
	 * @param WriteOperation      $operation  The six-phase implementation.
	 * @param TargetState         $current    The resolved current state.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, string> Keys 'snapshot' and 'rollback'.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function eligibility(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		OperationContext $context
	): array {
		if ( SnapshotPolicy::NotApplicable === $definition->snapshotPolicy ) {
			return [
				'snapshot' => self::SNAPSHOT_NOT_APPLICABLE,
				'rollback' => self::ROLLBACK_NOT_OFFERED,
			];
		}

		$capturable = null !== $operation->captureSnapshot( $current, $context );

		if ( ! $capturable && SnapshotPolicy::Required === $definition->snapshotPolicy ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'No recoverable snapshot can be captured for this target, so the change is refused before it executes.',
				'Recover through WordPress revisions or the trash instead, or choose a target that supports snapshots.'
			);
		}

		return [
			'snapshot' => $capturable ? self::SNAPSHOT_WILL_CAPTURE : self::SNAPSHOT_NO_PRIOR_STATE,
			'rollback' => $capturable ? self::ROLLBACK_WILL_OFFER : self::ROLLBACK_NOT_OFFERED,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses to proceed when the engine's own local tables are missing.
	 *
	 * Mapped to `integration_unavailable` because the core module's change,
	 * audit, and snapshot engines depend on those tables, so their absence means
	 * the module serving the operation genuinely is not installed.
	 * `execution_failed` would falsely assert that a write had started.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function require_storage(): void {
		if ( $this->installer->isAvailable() ) {
			return;
		}

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			'The change engine is unavailable because its local storage was not created.',
			'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
