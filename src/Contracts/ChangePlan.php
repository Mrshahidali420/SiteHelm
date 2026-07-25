<?php

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
	 * @param array<string, mixed>                  $bindings            User, site, operation, target, payload tuple.
	 * @param array{human: string, machine: array}  $previewSummary Both preview renderings.
	 * @param array{snapshot: bool, rollback: bool} $snapshotEligibility Declared recovery position.
	 */
	public function __construct(
		public readonly string $planToken,
		public readonly array $bindings,
		public readonly string $stateFingerprint,
		public readonly array $previewSummary,
		public readonly int $expiresAt,
		public readonly array $snapshotEligibility,
	) {
		if ( strlen( $planToken ) < 32 ) {
			throw new InvalidArgumentException( 'Plan tokens must be at least 32 characters of opaque randomness.' );
		}
		if ( ! isset( $previewSummary['human'], $previewSummary['machine'] ) ) {
			throw new InvalidArgumentException( 'A change plan requires human and machine preview renderings.' );
		}
		if ( $expiresAt <= 0 ) {
			throw new InvalidArgumentException( 'A change plan requires a server-side expiration instant.' );
		}
	}
}
