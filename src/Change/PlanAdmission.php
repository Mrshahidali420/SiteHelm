<?php
/**
 * The gate a stored plan must pass before it may be applied.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Storage\PlanStore;

/**
 * Whether a stored plan may be applied, decided one binding at a time.
 *
 * Every write passes through here. Six bindings tie the plan to the site, the
 * authenticated user, the operation, its schema version, the concrete target,
 * and the full normalized payload; the state fingerprint proves the target has
 * not moved since the preview; and consumption is what makes the token
 * single-use.
 *
 * These are five methods rather than one admit() call because the ORDER is the
 * security property, and a call site that spells the order out keeps a future
 * reordering visible. Consumption must come last: every refusal a caller can
 * correct has to cost nothing but a retry, and spending the token before the
 * bindings are verified would burn it on a fixable mistake.
 *
 * @package SiteHelm
 */
final class PlanAdmission {

	/**
	 * Constructs the gate over its collaborators.
	 *
	 * @param PlanStore         $plans       Pending plan storage.
	 * @param PayloadNormalizer $normalizer  Canonical form and hashing.
	 * @param StateFingerprint  $fingerprint Target-state fingerprinting.
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly PayloadNormalizer $normalizer,
		private readonly StateFingerprint $fingerprint
	) {
	}

	/**
	 * Resolves the stored plan and verifies every binding that is knowable
	 * before the target is resolved.
	 *
	 * The condition is deliberately compound and must stay that way. Split into
	 * one check per binding, the checks would become individually observable —
	 * by timing, by ordering, or by a future maintainer giving one of them its
	 * own message — and a caller would learn WHICH element of the binding was
	 * wrong. As one condition every cause refuses identically.
	 *
	 * @param string              $digest     The stored digest of the client's token.
	 * @param OperationDefinition $definition The operation being applied.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, mixed> The stored plan row.
	 *
	 * @throws OperationException With ErrorCode::StalePlan when the plan is
	 *                           unknown, spent, expired, or bound elsewhere.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function findValidPlan( string $digest, OperationDefinition $definition, OperationContext $context ): array {
		$row = $this->plans->find( $digest );

		if ( null === $row
			|| null !== $row['consumed_at']
			|| (int) $row['expires_at'] <= $context->requestTime
			|| (string) $row['site_id'] !== $context->siteId
			|| (int) $row['user_id'] !== $context->userId
			|| (string) $row['operation_id'] !== $definition->id
			|| (int) $row['schema_version'] !== $definition->schemaVersion ) {
			throw $this->stale_plan();
		}

		return $row;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Refuses a plan whose approved target is not the one this call resolved.
	 *
	 * @param array<string, mixed> $row     The stored plan row.
	 * @param TargetState          $current The target resolved on this call.
	 *
	 * @throws OperationException With ErrorCode::StalePlan.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function assertTargetMatches( array $row, TargetState $current ): void {
		if ( (string) $row['target_key'] !== $current->targetKey ) {
			throw $this->stale_plan();
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Refuses a plan whose target moved between the preview and this approval.
	 *
	 * This is the one refusal in the gate that is NOT `stale_plan`, and the
	 * difference is behaviour rather than wording. A spent or misbound token is
	 * the caller's own request failing to line up; a changed fingerprint means
	 * the target moved underneath an operator who approved a preview of how it
	 * used to look. The contract names that `conflict`, and collapsing it into
	 * the shared refusal would tell an operator their token was stale when in
	 * fact somebody else edited the post.
	 *
	 * @param array<string, mixed> $row     The stored plan row.
	 * @param TargetState          $current The target resolved on this call.
	 * @param OperationContext     $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assertStateUnchanged( array $row, TargetState $current, OperationContext $context ): void {
		if ( (string) $row['state_fingerprint'] !== $this->fingerprint->compute( $current, $context ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The target changed between the preview and this approval, so nothing was applied.',
				'Read the target again and generate a fresh preview.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses a plan whose approved payload is not the one re-supplied here.
	 *
	 * The client sends the payload again on the apply call; this hash is what
	 * proves it is the payload the preview was generated from.
	 *
	 * @param array<string, mixed> $row     The stored plan row.
	 * @param PlannedChange        $planned The change planned from this call's payload.
	 *
	 * @throws OperationException With ErrorCode::StalePlan.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function assertPayloadMatches( array $row, PlannedChange $planned ): void {
		if ( (string) $row['payload_hash'] !== $this->normalizer->fingerprint( $planned->payload ) ) {
			throw $this->stale_plan();
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Claims the plan for single use, refusing when the claim does not land.
	 *
	 * The claim is the last thing the gate does. It is also the only step whose
	 * failure is not the caller's fault: of two concurrent applies presenting
	 * the same token exactly one claims it, and the loser is refused here.
	 *
	 * @param string $digest      The stored digest of the client's token.
	 * @param int    $requestTime The server-side request time.
	 *
	 * @throws OperationException With ErrorCode::StalePlan.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function consumeOrFail( string $digest, int $requestTime ): void {
		if ( ! $this->plans->consume( $digest, $requestTime ) ) {
			throw $this->stale_plan();
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The single stale-plan failure, used for every binding refusal so a caller
	 * cannot learn which element of the binding was wrong.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function stale_plan(): OperationException {
		return new OperationException(
			ErrorCode::StalePlan,
			'This plan token is expired, already used, or bound to a different request.',
			'Generate a fresh preview and approve that plan token instead.'
		);
	}
}
