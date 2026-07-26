<?php
/**
 * The snapshot side of a change: eligibility, capture, and compensation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Storage\SnapshotStore;
use Throwable;

/**
 * The recovery position of a write, from preview through to failure.
 *
 * Declaring what can be recovered, recording it before anything executes, and
 * putting it back when the write fails are one concern read three ways. They
 * share the snapshot store, the same canonical encoding, and the same snapshot
 * policy, so they live together rather than beside the engine's plan-token and
 * verification logic.
 *
 * @package SiteHelm
 */
final class SnapshotLifecycle {

	/**
	 * Constructs the lifecycle over its collaborators.
	 *
	 * @param SnapshotStore     $snapshots  Rollback snapshot storage.
	 * @param PayloadNormalizer $normalizer Canonical form and hashing.
	 */
	public function __construct(
		private readonly SnapshotStore $snapshots,
		private readonly PayloadNormalizer $normalizer
	) {
	}

	/**
	 * Captures the snapshot the plan promised.
	 *
	 * @param OperationDefinition $definition The operation being applied.
	 * @param WriteOperation      $operation  The six-phase implementation.
	 * @param TargetState         $current    The resolved current state.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, mixed> Keys 'restore', 'id', and 'reference'.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when a
	 *                           required snapshot could not be recorded.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function capture(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		OperationContext $context
	): array {
		$empty = [
			'restore'   => null,
			'id'        => null,
			'reference' => null,
		];

		if ( SnapshotPolicy::NotApplicable === $definition->snapshotPolicy ) {
			return $empty;
		}

		$restore = $operation->captureSnapshot( $current, $context );
		if ( null === $restore ) {
			if ( SnapshotPolicy::Required === $definition->snapshotPolicy ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'No recoverable snapshot can be captured for this target, so the change was not applied.',
					'Recover through WordPress revisions or the trash instead.'
				);
			}

			return $empty;
		}

		$captured = $this->snapshots->capture(
			[
				'site_id'         => $context->siteId,
				'user_id'         => $context->userId,
				'operation_id'    => $definition->id,
				'module_id'       => $definition->module->value,
				'target_key'      => $current->targetKey,
				'restore_state'   => $this->normalizer->canonicalJson( $restore ),
				'module_versions' => $this->normalizer->canonicalJson( $context->moduleVersions ),
				'created_at'      => $context->requestTime,
			]
		);

		if ( null === $captured ) {
			if ( SnapshotPolicy::Required === $definition->snapshotPolicy ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'The snapshot this change requires could not be recorded, so the change was not applied.',
					'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
				);
			}

			return [
				'restore'   => $restore,
				'id'        => null,
				'reference' => null,
			];
		}

		return [
			'restore'   => $restore,
			'id'        => $captured['id'],
			'reference' => $captured['reference'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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
	public function eligibility(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		OperationContext $context
	): array {
		if ( SnapshotPolicy::NotApplicable === $definition->snapshotPolicy ) {
			return [
				'snapshot' => ChangeEngine::SNAPSHOT_NOT_APPLICABLE,
				'rollback' => ChangeEngine::ROLLBACK_NOT_OFFERED,
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
			'snapshot' => $capturable ? ChangeEngine::SNAPSHOT_WILL_CAPTURE : ChangeEngine::SNAPSHOT_NO_PRIOR_STATE,
			'rollback' => $capturable ? ChangeEngine::ROLLBACK_WILL_OFFER : ChangeEngine::ROLLBACK_NOT_OFFERED,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Attempts to restore the captured snapshot after a failed write.
	 *
	 * The restore attempt has its own containment, because an exception thrown
	 * inside a catch block is not caught by a sibling catch on the same try.
	 *
	 * @param WriteOperation            $operation The six-phase implementation.
	 * @param array<string, mixed>|null $restore   The captured restore state.
	 * @param OperationContext          $context   The request context.
	 *
	 * @return string One of 'restored', 'failed', or 'not-attempted'.
	 */
	public function compensate( WriteOperation $operation, ?array $restore, OperationContext $context ): string {
		if ( null === $restore ) {
			return 'not-attempted';
		}

		try {
			$operation->restore( $restore, $context );

			return 'restored';
		} catch ( Throwable $failure ) {
			EngineLog::unexpected( $failure );

			return 'failed';
		}
	}
}
