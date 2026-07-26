<?php
/**
 * The contract every write operation implements.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * One write operation, expressed as the six phases the change engine drives.
 *
 * A read operation is a single callable because a read has one phase. A write
 * has six, and the engine owns everything between them: fingerprinting, plan
 * issue and consumption, snapshotting, verification, and auditing. An interface
 * rather than a bag of callables so PHP checks the shape, one file documents
 * it, and tests can substitute it.
 *
 * `planChange()` is called in BOTH phases — once to build the preview, and again
 * at apply with the payload recovered from the stored plan. That is what makes
 * apply execute exactly the previewed change, and it means any guard inside
 * planChange() (a conditional capability, a post-type check) runs in both
 * phases without being written twice.
 *
 * Implementations throw OperationException with a contract error code for every
 * failure. Messages must contain no filesystem path, no SQL, and no credential
 * vocabulary.
 *
 * @package SiteHelm
 */
interface WriteOperation {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Resolves the current state of the target the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved current state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent or invisible to the resolved user.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Builds the change this operation promises, deterministically.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                            ErrorCode::Forbidden when the payload cannot be
	 *                            planned for this target or this user.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Captures the minimum local state required to reverse this write.
	 *
	 * MUST be side-effect free and MUST be safe to call more than once: the
	 * change engine calls it once at preview to decide snapshot eligibility, and
	 * again at apply to capture for real.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state to capture.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Executes the planned change.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The concrete target key that was written.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when WordPress
	 *                            or the owning plugin reported a failure.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the concrete target-key vocabulary used across the change engine.
	/**
	 * Re-reads the target so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The concrete target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                            target cannot be re-read at all.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the recorded-state vocabulary used across the change engine.
	/**
	 * Restores a recorded snapshot.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The concrete target key that was restored.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when
	 *                            complete restoration is not possible, or
	 *                            ErrorCode::ExecutionFailed when it was attempted
	 *                            and failed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
