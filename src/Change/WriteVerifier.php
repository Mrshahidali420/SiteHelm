<?php
/**
 * Classifies the persisted state of a completed write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * The one place the three-way post-write rule lives.
 *
 * For each field the plan promised, the stored value is either what was
 * promised, still the prior value, or something else. Only the middle case means
 * the write did not take. The third means WordPress adjusted it, which is the
 * platform behaving normally and must not be reported as a failure.
 *
 * @package SiteHelm
 */
final class WriteVerifier {

	/**
	 * Constructs the verifier.
	 *
	 * @param PayloadNormalizer $normalizer Supplies the canonical fingerprint.
	 */
	public function __construct( private readonly PayloadNormalizer $normalizer ) {
	}

	/**
	 * Classifies one completed write.
	 *
	 * Only the promised keys are considered. Fields the plan never promised are
	 * handled separately by the engine, which warns rather than fails.
	 *
	 * The branch order is load-bearing: the promised value is tested BEFORE the
	 * prior value, so a field whose promise equals its prior value — a no-op
	 * field inside a larger change — is exact rather than not-applied.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $before  The state resolved immediately before the write.
	 * @param TargetState   $after   The state re-read after the write.
	 *
	 * @return VerificationOutcome The verdict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function classify( PlannedChange $planned, TargetState $before, TargetState $after ): VerificationOutcome {
		$adjusted = [];

		foreach ( array_keys( $planned->afterFields ) as $field ) {
			$stored = $this->digest( $after->fields[ $field ] ?? null );

			if ( $stored === $this->digest( $planned->afterFields[ $field ] ) ) {
				continue;
			}

			if ( $stored === $this->digest( $before->fields[ $field ] ?? null ) ) {
				return new VerificationOutcome( false, [] );
			}

			$adjusted[] = (string) $field;
		}

		return new VerificationOutcome( true, $adjusted );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The canonical fingerprint of one value.
	 *
	 * Field values are arrays as often as scalars — terms and meta both are — so
	 * comparison never uses ===.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The fingerprint.
	 */
	private function digest( mixed $value ): string {
		return $this->normalizer->fingerprint( $value );
	}
}
