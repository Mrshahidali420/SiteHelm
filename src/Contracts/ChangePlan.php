<?php
/**
 * The change plan bridging a previewed write and its execution.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * The bridge between previewing a write and executing it. Phase 2 defines
 * the type; the change engine that issues and consumes plans is a later phase.
 *
 * @package SiteHelm
 */
final class ChangePlan {

	/**
	 * Minimum opaque plan-token length, in characters.
	 */
	private const MIN_TOKEN_LENGTH = 32;

	/**
	 * Constructs one validated change plan.
	 *
	 * PHPDoc uses array shorthand rather than generic shape or list syntax because
	 * WPCS's IncorrectTypeHint sniff does not understand them; the shapes are
	 * described in prose instead.
	 *
	 * @param string               $planToken           Opaque, non-guessable, single-use token.
	 * @param array<string, mixed> $bindings            User, site, operation, target, payload tuple.
	 * @param string               $stateFingerprint    Fingerprint of the resolved target state.
	 * @param array<string, mixed> $previewSummary      Both preview renderings, keyed 'human' and 'machine'.
	 * @param int                  $expiresAt           UTC instant after which the token is refused.
	 * @param array<string, mixed> $snapshotEligibility Declared recovery position, keyed 'snapshot' and 'rollback'.
	 *
	 * @throws InvalidArgumentException When any plan invariant is violated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		public readonly string $planToken,
		public readonly array $bindings,
		public readonly string $stateFingerprint,
		public readonly array $previewSummary,
		public readonly int $expiresAt,
		public readonly array $snapshotEligibility,
	) {
		if ( strlen( $planToken ) < self::MIN_TOKEN_LENGTH ) {
			throw new InvalidArgumentException( 'Plan tokens must be at least 32 characters of opaque randomness.' );
		}
		if ( ! isset( $previewSummary['human'], $previewSummary['machine'] ) ) {
			throw new InvalidArgumentException( 'A change plan requires human and machine preview renderings.' );
		}
		if ( $expiresAt <= 0 ) {
			throw new InvalidArgumentException( 'A change plan requires a server-side expiration instant.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
