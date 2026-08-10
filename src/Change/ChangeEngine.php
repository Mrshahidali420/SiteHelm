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
use Throwable;

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
	 * The one field that changes on every write regardless of what was planned.
	 * It is in the state fingerprint, because that is how a concurrent edit is
	 * detected, but it is never promised and never reported as an unpromised
	 * change — it would be reported on every single write.
	 */
	private const VOLATILE_FIELD = 'post_modified_gmt';

	/**
	 * Constructs the engine over its collaborators.
	 *
	 * @param PlanStore         $plans       Pending plan storage.
	 * @param AuditRecorder     $audit       Audit record lifecycle.
	 * @param PayloadNormalizer $normalizer  Canonical form and hashing.
	 * @param StateFingerprint  $fingerprint Target-state fingerprinting.
	 * @param PreviewRenderer   $preview     Both preview renderings.
	 * @param Installer         $installer   Storage availability probe.
	 * @param WriteVerifier     $verifier    Post-write state classification.
	 * @param SnapshotLifecycle $lifecycle   Snapshot eligibility, capture, and compensation.
	 * @param PlanAdmission     $admission   Whether a stored plan may be applied.
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly AuditRecorder $audit,
		private readonly PayloadNormalizer $normalizer,
		private readonly StateFingerprint $fingerprint,
		private readonly PreviewRenderer $preview,
		private readonly Installer $installer,
		private readonly WriteVerifier $verifier,
		private readonly SnapshotLifecycle $lifecycle,
		private readonly PlanAdmission $admission,
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
		$normalizer  = new PayloadNormalizer();
		$plans       = new PlanStore();
		$fingerprint = new StateFingerprint( $normalizer );

		return new self(
			$plans,
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			$fingerprint,
			new PreviewRenderer(),
			new Installer(),
			new WriteVerifier( $normalizer ),
			new SnapshotLifecycle( new SnapshotStore(), $normalizer ),
			new PlanAdmission( $plans, $normalizer, $fingerprint )
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
		$eligibility = $this->lifecycle->eligibility( $definition, $operation, $current, $context );
		$rendering   = $this->preview->render( $definition->id, $current, $planned );

		// The machine-only REQ-0035 channel, copied verbatim. It is added HERE
		// rather than inside PreviewRenderer because the renderer's job is a
		// before/after field diff and a structural detail is not one; and it is
		// added to $rendering rather than to the response alone because the same
		// value must reach plan_body below, which is what binds the operator's
		// approval to the structure they were shown. An empty detail adds no key
		// at all, so every other module's wire shape is unchanged.
		if ( [] !== $planned->previewDetail ) {
			$rendering['machine']['detail'] = $planned->previewDetail;
		}

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
	 * Routes one write call: no token previews, a token applies.
	 *
	 * There is no third branch. Trusted-write enrollment would be that branch,
	 * and it is deliberately absent: no operation in this phase is risk `low`,
	 * so nothing is eligible for single-call execution.
	 *
	 * @param OperationDefinition  $definition The operation being invoked.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload    The validated arguments.
	 * @param string|null          $planToken  The approval token, when supplied.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The plan or the applied result.
	 *
	 * @throws OperationException On any failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function handle(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		?string $planToken,
		OperationContext $context
	): OperationResult {
		return null === $planToken
			? $this->preview( $definition, $operation, $payload, $context )
			: $this->apply( $definition, $operation, $payload, $planToken, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The apply phase: execute exactly the previewed change.
	 *
	 * Order matters and is deliberate:
	 *
	 * - Every binding and freshness check runs BEFORE the plan is consumed, so a
	 *   plan rejected for a reason the caller can fix costs nothing but a retry.
	 * - The state fingerprint is checked BEFORE the payload hash. Both refuse a
	 *   plan that no longer matches, but they refuse it with different codes, and
	 *   a concurrent edit can invalidate both at once: the target changed, so the
	 *   fingerprint differs, and the normalized payload derived from that target
	 *   differs too. The contract names that situation `conflict`, so the
	 *   fingerprint must be the check that fires. Reversing them would report
	 *   `stale_plan` for a concurrent edit.
	 * - Consumption is the last thing that happens before the audit record opens,
	 *   and the audit record opens before anything executes.
	 *
	 * Two refusals deliberately occur AFTER consumption, because neither is
	 * something the caller can correct by resubmitting the same plan: capture()
	 * can raise `rollback_unavailable` when a required snapshot cannot be
	 * recorded, and start() raises `integration_unavailable` when the audit row
	 * cannot be opened. Both leave the target untouched — nothing has executed
	 * yet — but the plan token is already spent, so the caller must generate a
	 * fresh preview. This is stated rather than glossed: burning a token on a
	 * storage failure is a real cost, and the alternative (executing without a
	 * snapshot or without an audit record) would break a contract guarantee.
	 *
	 * @param OperationDefinition  $definition The operation being applied.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload    The validated arguments.
	 * @param string               $planToken  The approval token.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The verified result.
	 *
	 * @throws OperationException On any failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function apply(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		string $planToken,
		OperationContext $context
	): OperationResult {
		$this->require_storage();

		$digest = PlanStore::digest( $planToken );

		$row     = $this->admission->findValidPlan( $digest, $definition, $context );
		$current = $operation->resolveTarget( $payload, $context );
		$this->admission->assertTargetMatches( $row, $current );

		// planChange() runs again here so the apply executes exactly what was
		// previewed, and so every guard inside it is re-evaluated. The payload is
		// the one the CLIENT re-supplied on this call; the digest comparison in
		// assertPayloadMatches() below is what proves it is the payload the
		// preview was generated from.
		$planned = $operation->planChange( $current, $payload, $context );

		// The fingerprint is checked first, and deliberately. A concurrent edit
		// invalidates both this and the payload hash at once, and the contract
		// names that case `conflict`; checking the payload first would answer
		// `stale_plan` for a situation the contract has a different code for.
		$this->admission->assertStateUnchanged( $row, $current, $context );
		$this->admission->assertPayloadMatches( $row, $planned );
		$this->admission->consumeOrFail( $digest, $context->requestTime );

		$warnings = $planned->warnings;
		$snapshot = $this->lifecycle->capture( $definition, $operation, $current, $context );
		$restore  = $snapshot['restore'];

		// The snapshot handle goes onto the OPENING audit row. Both values are
		// already known here, and deferring them to finish() would let a fatal
		// inside applyChange() strand a captured snapshot no audit row names.
		$audit_id = $this->audit->start(
			$definition,
			$context,
			$current->targetKey,
			(string) $row['state_fingerprint'],
			$snapshot['id'],
			$snapshot['reference']
		);
		if ( 0 === $audit_id ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The change engine could not open an audit record, so the change was not applied.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		try {
			$target_key = $operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $failure ) {
			// The operation named its own failure, so its code, message,
			// remediation and completed steps are re-raised verbatim.
			$compensation = $this->compensate_and_finalize(
				$operation,
				$restore,
				$audit_id,
				$snapshot,
				$current,
				$planned,
				$context
			);

			throw new OperationException(
				$failure->errorCode,
				$failure->getMessage(),
				$failure->remediation,
				$failure->completedSteps,
				$compensation
			);
		} catch ( Throwable $unexpected ) {
			// An unexpected throwable carries nothing safe to disclose, so it is
			// logged server-side and surfaces as a generic execution_failed.
			EngineLog::unexpected( $unexpected );

			$compensation = $this->compensate_and_finalize(
				$operation,
				$restore,
				$audit_id,
				$snapshot,
				$current,
				$planned,
				$context
			);

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The write failed unexpectedly. The details were logged on the server.',
				'Generate a fresh preview and retry; check SiteHelm diagnostics if it recurs.',
				[],
				$compensation
			);
		}

		// readBack() runs inside its own protection: the write already landed
		// by this point, so ANY failure re-reading it — including an operation
		// that throws its OWN error code, such as target_not_found, when the
		// freshly-written target cannot be re-read — must still finalize the
		// audit row and surface as verification_failed. Without this, a write
		// that lands but cannot be re-read escapes with the operation's own
		// error code, the audit row is stranded at 'started' forever for a
		// write that actually happened, and the caller gets no indication the
		// change landed.
		try {
			$after = $operation->readBack( $target_key, $context );
		} catch ( Throwable $unreadable ) {
			EngineLog::unexpected( $unreadable );

			// No after-state exists to record: the read that would have produced
			// one is what failed. The promise is all there is. See the helper's
			// docblock for why the other caller passes something else.
			throw $this->verification_failed(
				$audit_id,
				$snapshot,
				$target_key,
				$current,
				$planned->afterFields,
				$context,
				'The write completed but the change engine could not re-read the target to verify it.',
				[ 'applied' ]
			);
		}

		$outcome = $this->verifier->classify( $planned, $current, $after );

		if ( ! $outcome->applied ) {
			// An after-state was read successfully here, so the row records what
			// is actually stored — measured exactly as the applied path measures
			// it. On a partial write, where one promised field landed and another
			// reverted, recording the promise would list both at promised sizes
			// and hide which one took: the one fact a recovery needs.
			throw $this->verification_failed(
				$audit_id,
				$snapshot,
				$target_key,
				$current,
				$this->measured_after( $planned, $after ),
				$context,
				'The write completed but a field the approved plan promised to change still holds its previous value, so the change did not take.'
			);
		}

		// WordPress transforms values as it stores them, and some of those
		// transformations cannot be known before the write: a slug is uniquified
		// against whatever else exists, a publish becomes a future when the post
		// is dated ahead. The write landed, so this is not a failure — but the
		// caller approved a different value, so each adjusted field is named. The
		// value itself is disclosed in 'state' below, never in a warning.
		foreach ( $outcome->adjustedFields as $field ) {
			$warnings[] = sprintf(
				'WordPress stored a different value for %s than the approved plan promised. The stored state is reported in this response.',
				$field
			);
		}

		// The operation kept every promise it made, but fields the preview never
		// showed may still have changed — WordPress renames a slug when a post is
		// trashed, and a save_post hook can rewrite content. The caller approved a
		// preview that did not mention them, so each is named here. Names only,
		// never values, exactly as in the audit summary. The engine cannot tell
		// which actor changed the field, so it does not guess.
		foreach ( $this->unpromised_changes( $planned, $current, $after ) as $field ) {
			$warnings[] = sprintf(
				'The write also changed %s, which the approved plan did not promise. Compare the reported state against the preview you approved.',
				$field
			);
		}

		// The audit record must state what was STORED, not what was promised.
		// This row carries outcome `applied` and it is permanent, so recording an
		// adjusted write against the promised value would assert a clean apply for
		// a value WordPress never stored. The response discloses the same thing in
		// 'state', but a response is ephemeral and this is what an administrator
		// reviews later. measured_after() carries the rest of that reasoning, and
		// the not-applied refusal path measures through the same helper.
		$finished = $this->audit->finish(
			$audit_id,
			AuditRecorder::OUTCOME_APPLIED,
			$snapshot['id'],
			$snapshot['reference'],
			$target_key,
			$current->fields,
			$this->measured_after( $planned, $after )
		);
		if ( ! $finished ) {
			$warnings[] = 'The audit record was created but its outcome could not be updated.';
		}
		if ( null === $snapshot['reference'] && SnapshotPolicy::Supported === $definition->snapshotPolicy ) {
			$warnings[] = 'No snapshot was captured for this change, so no rollback reference is offered.';
		}

		return new OperationResult(
			operationId: $definition->id,
			data: [
				'target'  => $target_key,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			verification: [] === $outcome->adjustedFields
				? VerificationStatus::Verified
				: VerificationStatus::VerifiedWithAdjustments,
			correlationId: $context->correlationId,
			auditRef: AuditRecorder::reference( $audit_id ),
			rollbackRef: $snapshot['reference'],
			warnings: $warnings,
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The failure tail both applyChange() catch blocks share: compensate the
	 * partial write, then finalise the audit row as execution_failed, returning
	 * what compensation achieved so the caller can report it.
	 *
	 * The two callers differ only in the exception they raise afterwards — the
	 * operation's own code, message, remediation and completed steps in one
	 * case, a generic execution_failed in the other — which is exactly why the
	 * throw is deliberately NOT part of this method.
	 *
	 * The recorded after-state is the PROMISE, not a measurement. applyChange()
	 * threw, so no after-state was ever read; unlike verification_failed(),
	 * this path has nothing to measure and never can.
	 *
	 * @param WriteOperation            $operation The six-phase implementation.
	 * @param array<string, mixed>|null $restore   The captured restore state.
	 * @param int                       $auditId   The open audit record.
	 * @param array<string, mixed>      $snapshot  Keys 'id' and 'reference' from capture().
	 * @param TargetState               $current   The state before the write.
	 * @param PlannedChange             $planned   The promised change.
	 * @param OperationContext          $context   The request context.
	 *
	 * @return string One of 'restored', 'failed', or 'not-attempted'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function compensate_and_finalize(
		WriteOperation $operation,
		?array $restore,
		int $auditId,
		array $snapshot,
		TargetState $current,
		PlannedChange $planned,
		OperationContext $context
	): string {
		$compensation = $this->lifecycle->compensate( $operation, $restore, $context );

		$this->audit->finish(
			$auditId,
			AuditRecorder::OUTCOME_EXECUTION_FAILED,
			$snapshot['id'],
			$snapshot['reference'],
			$current->targetKey,
			$current->fields,
			$planned->afterFields
		);

		return $compensation;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Finalizes the audit record and builds the shared verification_failed
	 * refusal.
	 *
	 * Used both when the post-write re-read itself fails — the write landed,
	 * but nothing can confirm what it left behind, and that must never surface
	 * as the underlying operation's own error code, since a real WriteOperation
	 * might throw target_not_found from readBack() rather than
	 * verification_failed — and when a promised field still holds its prior
	 * value, which means the write did not take.
	 *
	 * A promised field holding some THIRD value is not a failure and never
	 * reaches here: WordPress adjusting a value as it stores it is the platform
	 * behaving normally, and WriteVerifier classifies it as applied. That
	 * distinction is the whole reason this refusal is narrower than a
	 * whole-state comparison.
	 *
	 * Per interpretation I4 the envelope carries no recovery handle: the
	 * remediation directs an administrator to the audit entry by
	 * correlationId rather than exposing the rollback reference here, even
	 * though a snapshot may exist and the audit row already references it.
	 *
	 * The two callers pass DIFFERENT after-states, deliberately, and unifying
	 * them would break one of them:
	 *
	 * - The not-applied classification has an after-state, read successfully.
	 *   It passes measured_after() — the same mapping the applied path records
	 *   — so the permanent row states what is actually stored. On a partial
	 *   write that is the difference between naming the field that landed and
	 *   claiming both did.
	 * - The readBack failure has NO after-state: re-reading the write is
	 *   exactly what threw. It passes the promise because nothing else exists
	 *   to pass. That is a limit of what is knowable, not an oversight.
	 *
	 * @param int                  $auditId        The opened audit row.
	 * @param array<string, mixed> $snapshot       Keys 'id' and 'reference' from capture().
	 * @param string               $targetKey      The concrete target key.
	 * @param TargetState          $current        The resolved pre-write state.
	 * @param array<string, mixed> $recordedAfter  The after-state to record, per the split above.
	 * @param OperationContext     $context        The request context.
	 * @param string               $message        The safe, human-readable explanation.
	 * @param string[]             $completedSteps Steps completed before this failure.
	 *
	 * @return OperationException The failure to throw.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function verification_failed(
		int $auditId,
		array $snapshot,
		string $targetKey,
		TargetState $current,
		array $recordedAfter,
		OperationContext $context,
		string $message,
		array $completedSteps = []
	): OperationException {
		$this->audit->finish(
			$auditId,
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$snapshot['id'],
			$snapshot['reference'],
			$targetKey,
			$current->fields,
			$recordedAfter
		);

		return new OperationException(
			ErrorCode::VerificationFailed,
			$message,
			sprintf(
				'Ask a site administrator to review the audit entry for correlation %s and restore the recorded snapshot.',
				$context->correlationId
			),
			$completedSteps
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Maps every promised field to the value the after-state actually holds.
	 *
	 * Every promised key is read from the after-state individually, defaulting
	 * to null rather than to the promise. Intersecting the two field sets and
	 * letting the plan fill the gaps would look equivalent, but it records the
	 * PROMISED value for a promised field the after-state does not carry at
	 * all — the same false record, by a narrower route. null measures as size
	 * 0, which is honest and visibly not the promise.
	 *
	 * Only promised keys are read, so unpromised fields stay out of the
	 * summary and remain warnings. When nothing was adjusted every stored
	 * value equals its promise, making this identical to $planned->afterFields
	 * on the unadjusted path.
	 *
	 * Both audit paths that HAVE an after-state — the applied path and the
	 * not-applied refusal — measure through this one mapping. A second copy
	 * would be free to drift into recording the promise again on one path
	 * only, which is the defect this method exists to prevent.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $after   The state read back after the write.
	 *
	 * @return array<string, mixed> The stored value of every promised field.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function measured_after( PlannedChange $planned, TargetState $after ): array {
		$measured = [];
		foreach ( array_keys( $planned->afterFields ) as $field ) {
			$measured[ $field ] = $after->fields[ $field ] ?? null;
		}

		return $measured;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Field names that changed although the approved plan never promised them.
	 *
	 * Verifying only the promised keys would let a third-party save_post hook
	 * rewrite post_content, retag terms, or regenerate the slug during the write
	 * and pass silently — a change the operator approved a preview that never
	 * showed it, which is the opposite of REQ-0005's "inspects exactly what a
	 * proposed write will change". The engine already holds both the full
	 * before-state and the full after-state, so the comparison is free.
	 *
	 * Only fields present in BOTH states are considered, so a creation (whose
	 * before-state is empty) produces no warnings, and a field the after-state
	 * newly exposes is not reported as a change. post_modified_gmt is excluded
	 * because every write changes it, exactly as D3 says.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $before  The state resolved immediately before the write.
	 * @param TargetState   $after   The persisted state.
	 *
	 * @return string[] The unpromised field names that changed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function unpromised_changes( PlannedChange $planned, TargetState $before, TargetState $after ): array {
		$changed = [];

		foreach ( $after->fields as $field => $value ) {
			if ( self::VOLATILE_FIELD === $field
				|| array_key_exists( $field, $planned->afterFields )
				|| ! array_key_exists( $field, $before->fields ) ) {
				continue;
			}

			if ( $this->normalizer->fingerprint( [ $field => $value ] )
				!== $this->normalizer->fingerprint( [ $field => $before->fields[ $field ] ] ) ) {
				$changed[] = (string) $field;
			}
		}

		return $changed;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
