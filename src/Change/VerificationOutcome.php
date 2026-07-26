<?php
/**
 * The verdict on one completed write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * What the persisted state says about a write that already happened.
 *
 * Separating "did the write land" from "is every value exactly what we promised"
 * is the whole point. WordPress transforms values on save — it trims titles,
 * uniquifies slugs, turns a publish into a future on a scheduled post — and
 * treating those as failures told the operator to undo a write that succeeded.
 *
 * @package SiteHelm
 */
final class VerificationOutcome {

	/**
	 * Constructs one verdict.
	 *
	 * @param bool     $applied        Whether the write landed at all.
	 * @param string[] $adjustedFields Promised fields WordPress stored differently.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly array $adjustedFields
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
