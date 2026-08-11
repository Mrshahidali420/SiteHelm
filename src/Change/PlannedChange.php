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
 * `previewDetail` is a MACHINE-ONLY channel, added additively in Phase 6b for
 * REQ-0035. A structural description of a write — an element tree diff — cannot
 * ride `afterFields`, because everything promised there is compared against the
 * target AFTER the write, and a before-and-after diff is meaningless once the
 * write has landed. It is therefore carried separately: `ChangeEngine` copies it
 * verbatim into the machine preview and into the stored plan body, so the
 * operator's approval is bound to it, and NOTHING ELSE READS IT. `WriteVerifier`
 * does not; `PreviewRenderer` does not, so the human confirmation line is
 * unchanged.
 *
 * It is the LAST constructor value and defaults to `[]`, which is what makes the
 * change additive: every construction site in the four shipped modules keeps
 * working untouched, and an empty detail produces no `detail` key at all.
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
	 * @param array<string, mixed> $previewDetail Machine-only preview structure.
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
		public readonly array $previewDetail = [],
	) {
		// A rich previewDetail does NOT satisfy this. Nothing verifies the
		// detail after the write, so a change promising only a description
		// would apply and then be classified against an empty promise.
		if ( [] === $afterFields ) {
			throw new InvalidArgumentException( 'A planned change must promise at least one field.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
