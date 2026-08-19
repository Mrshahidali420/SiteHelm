<?php
/**
 * What happens to a write after it lands.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Contracts\VerificationStatus;
use Throwable;

/**
 * Settles a write that has already been applied.
 *
 * `applyChange()` returning is the seam. Everything before it decides whether
 * the write may happen; everything after it is a single question with one
 * answer — what actually landed, and what does the caller need to be told about
 * it. That tail reads the target back, classifies the outcome, names every
 * adjusted and unpromised field, finalises the permanent audit row, and builds
 * the result.
 *
 * It lives here rather than inside `ChangeEngine::apply()` because it is the
 * half of that method with no authority over whether the write happens: by the
 * time anything here runs, the change is already stored, and the only remaining
 * choices are about description. Nothing in this class may prevent a write, and
 * nothing in it may leave the audit row unfinished.
 *
 * @package SiteHelm
 */
final class WriteSettlement {

	/**
	 * The one field that changes on every write regardless of what was planned.
	 * It is in the state fingerprint, because that is how a concurrent edit is
	 * detected, but it is never promised and never reported as an unpromised
	 * change — it would be reported on every single write.
	 */
	private const VOLATILE_FIELD = 'post_modified_gmt';

	/**
	 * Constructs the settlement over its collaborators.
	 *
	 * @param WriteVerifier     $verifier   Post-write state classification.
	 * @param AuditRecorder     $audit      Audit record lifecycle.
	 * @param PayloadNormalizer $normalizer Canonical form and hashing.
	 */
	public function __construct(
		private readonly WriteVerifier $verifier,
		private readonly AuditRecorder $audit,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	/**
	 * Reads the write back, records what it did, and reports it.
	 *
	 * The parameter list is long because settling a write genuinely needs the
	 * whole story of it: what was promised, what was there before, which audit
	 * row is open, and which snapshot that row already names. Every one of them
	 * is read; none is optional.
	 *
	 * @param OperationDefinition  $definition The operation being applied.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param TargetState          $current    The state resolved before the write.
	 * @param PlannedChange        $planned    The promised change.
	 * @param string               $targetKey  The concrete target the write returned.
	 * @param int                  $auditId    The open audit record.
	 * @param array<string, mixed> $snapshot   Keys 'id' and 'reference' from capture().
	 * @param string[]             $warnings   Warnings accumulated before the write.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The applied result.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                           write cannot be re-read, or when a promised
	 *                           field still holds its previous value.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function settle(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		PlannedChange $planned,
		string $targetKey,
		int $auditId,
		array $snapshot,
		array $warnings,
		OperationContext $context
	): OperationResult {
		$after = $this->read_back_or_fail( $operation, $targetKey, $current, $planned, $auditId, $snapshot, $context );

		$outcome = $this->verifier->classify( $planned, $current, $after );

		if ( ! $outcome->applied ) {
			// An after-state was read successfully here, so the row records what
			// is actually stored — measured exactly as the applied path measures
			// it. On a partial write, where one promised field landed and another
			// reverted, recording the promise would list both at promised sizes
			// and hide which one took: the one fact a recovery needs.
			throw $this->verification_failed(
				$auditId,
				$snapshot,
				$targetKey,
				$current,
				$this->measured_after( $planned, $after ),
				$context,
				'The write completed but a field the approved plan promised to change still holds its previous value, so the change did not take.'
			);
		}

		$warnings = array_merge(
			$warnings,
			$this->adjustment_warnings( $outcome->adjustedFields ),
			$this->unpromised_warnings( $planned, $current, $after )
		);

		// The audit record must state what was STORED, not what was promised.
		// This row carries outcome `applied` and it is permanent, so recording an
		// adjusted write against the promised value would assert a clean apply for
		// a value WordPress never stored. The response discloses the same thing in
		// 'state', but a response is ephemeral and this is what an administrator
		// reviews later. measured_after() carries the rest of that reasoning, and
		// the not-applied refusal path measures through the same helper.
		$finished = $this->audit->finish(
			$auditId,
			AuditRecorder::OUTCOME_APPLIED,
			$snapshot['id'],
			$snapshot['reference'],
			$targetKey,
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
				'target'  => $targetKey,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			verification: [] === $outcome->adjustedFields
				? VerificationStatus::Verified
				: VerificationStatus::VerifiedWithAdjustments,
			correlationId: $context->correlationId,
			auditRef: AuditRecorder::reference( $auditId ),
			rollbackRef: $snapshot['reference'],
			warnings: $warnings,
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the written target, or refuses in a way that closes the row.
	 *
	 * The re-read runs inside its own protection: the write already landed by
	 * this point, so ANY failure in readBack() — including an operation that
	 * throws its OWN error code, such as target_not_found, when the freshly
	 * written target cannot be re-read — must still finalize the audit row and
	 * surface as verification_failed. Without this, a write that lands but
	 * cannot be re-read escapes with the operation's own error code, the audit
	 * row is stranded at 'started' forever for a write that actually happened,
	 * and the caller gets no indication the change landed.
	 *
	 * @param WriteOperation       $operation The six-phase implementation.
	 * @param string               $targetKey The concrete target the write returned.
	 * @param TargetState          $current   The state resolved before the write.
	 * @param PlannedChange        $planned   The promised change.
	 * @param int                  $auditId   The open audit record.
	 * @param array<string, mixed> $snapshot  Keys 'id' and 'reference' from capture().
	 * @param OperationContext     $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function read_back_or_fail(
		WriteOperation $operation,
		string $targetKey,
		TargetState $current,
		PlannedChange $planned,
		int $auditId,
		array $snapshot,
		OperationContext $context
	): TargetState {
		try {
			return $operation->readBack( $targetKey, $context );
		} catch ( Throwable $unreadable ) {
			EngineLog::unexpected( $unreadable );

			// No after-state exists to record: the read that would have produced
			// one is what failed. The promise is all there is. See the helper's
			// docblock for why the other caller passes something else.
			throw $this->verification_failed(
				$auditId,
				$snapshot,
				$targetKey,
				$current,
				$planned->afterFields,
				$context,
				'The write completed but the change engine could not re-read the target to verify it.',
				[ 'applied' ]
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Names each field WordPress stored differently from the approved plan.
	 *
	 * WordPress transforms values as it stores them, and some of those
	 * transformations cannot be known before the write: a slug is uniquified
	 * against whatever else exists, a publish becomes a future when the post is
	 * dated ahead. The write landed, so this is not a failure — but the caller
	 * approved a different value, so each adjusted field is named. The value
	 * itself is disclosed in 'state', never in a warning.
	 *
	 * @param string[] $adjustedFields Fields WriteVerifier classified as adjusted.
	 *
	 * @return string[] One warning per adjusted field.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function adjustment_warnings( array $adjustedFields ): array {
		$warnings = [];

		foreach ( $adjustedFields as $field ) {
			$warnings[] = sprintf(
				'WordPress stored a different value for %s than the approved plan promised. The stored state is reported in this response.',
				$field
			);
		}

		return $warnings;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Names each field that changed although the plan never promised it.
	 *
	 * The operation kept every promise it made, but fields the preview never
	 * showed may still have changed — WordPress renames a slug when a post is
	 * trashed, and a save_post hook can rewrite content. The caller approved a
	 * preview that did not mention them, so each is named here. Names only,
	 * never values, exactly as in the audit summary. The engine cannot tell
	 * which actor changed the field, so it does not guess.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $before  The state resolved immediately before the write.
	 * @param TargetState   $after   The persisted state.
	 *
	 * @return string[] One warning per unpromised change.
	 */
	private function unpromised_warnings( PlannedChange $planned, TargetState $before, TargetState $after ): array {
		$warnings = [];

		foreach ( $this->unpromised_changes( $planned, $before, $after ) as $field ) {
			$warnings[] = sprintf(
				'The write also changed %s, which the approved plan did not promise. Compare the reported state against the preview you approved.',
				$field
			);
		}

		return $warnings;
	}

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
}
