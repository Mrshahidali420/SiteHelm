<?php
/**
 * The change a write operation promises to make.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use InvalidArgumentException;

/**
 * What a write operation promises, derived deterministically from the current
 * target state and the request payload.
 *
 * `afterFields` is the promised SUBSET of the target's field map: an operation
 * only lists the fields it actually sets, and post-write verification compares
 * exactly those keys. `fieldOrder` lets the owning module choose the
 * presentation order without the change engine having to know anything about
 * that module's domain.
 *
 * @package SiteHelm
 */
final class PlannedChange {

	/**
	 * Constructs one planned change.
	 *
	 * @param array<string, mixed> $payload     The normalized bound payload.
	 * @param array<string, mixed> $afterFields The promised after-state subset.
	 * @param string[]             $fieldOrder  Presentation order; empty means alphabetical.
	 * @param string[]             $warnings    Safe non-fatal notices.
	 *
	 * @throws InvalidArgumentException When no field is promised.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function __construct(
		public readonly array $payload,
		public readonly array $afterFields,
		public readonly array $fieldOrder = [],
		public readonly array $warnings = [],
	) {
		if ( [] === $afterFields ) {
			throw new InvalidArgumentException( 'A planned change must promise at least one field.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
