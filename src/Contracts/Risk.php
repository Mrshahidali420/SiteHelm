<?php
/**
 * Risk levels for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Risk levels for operations, in order.
 *
 * The distinction between the top two is precise, and it is about what can be
 * PROMISED rather than about how much is touched:
 *
 * - **High** — bounded effect, large blast radius. Deleting an element,
 *   rewriting the global colours. We know exactly what will change.
 * - **Extreme** — the payload is a program, so the effect cannot be bounded at
 *   write time by anyone. We can promise what was STORED, never what it will DO.
 *
 * ORDER IS PART OF THE CONTRACT. Gates used to be written as inequalities
 * against one case — `Risk::High !== $definition->risk` was the Edit permission
 * level — and an inequality gate WIDENS every time a case is added above it,
 * silently, because the new case is not the one named. Adding `Extreme` to that
 * expression would have admitted arbitrary code at the permission level whose
 * whole promise is that it stops short of the dangerous things.
 *
 * `atLeast()` is the fix and the reason this enum has methods at all: a gate
 * written ordinally refuses a new top tier by default, which is the only safe
 * direction for a mistake to fall in.
 */
enum Risk: string {
	case Low     = 'low';
	case Medium  = 'medium';
	case High    = 'high';
	case Extreme = 'extreme';

	/**
	 * The level's position in the order, lowest first.
	 *
	 * @return int The rank.
	 */
	public function rank(): int {
		return match ( $this ) {
			self::Low     => 0,
			self::Medium  => 1,
			self::High    => 2,
			self::Extreme => 3,
		};
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether this level is at or above another.
	 *
	 * @param self $floor The level to compare against.
	 *
	 * @return bool True when this level is at least as risky as the floor.
	 */
	public function atLeast( self $floor ): bool {
		return $this->rank() >= $floor->rank();
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
