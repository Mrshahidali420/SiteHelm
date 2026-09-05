<?php
/**
 * Reduces a change to identifiers and sizes for the audit log.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Audit;

use stdClass;

/**
 * Produces the audit summary.
 *
 * The design requires audit events to store identifiers and redacted summaries
 * rather than field values, so this class is deliberately incapable of emitting
 * a value: it only ever writes field NAMES and SIZES. Title text, body content,
 * meta values, and term names never reach the audit table through this path,
 * no matter how deeply they are nested — measure() reduces every value to an
 * integer before it is ever encoded.
 *
 * @package SiteHelm
 */
final class AuditRedactor {

	/**
	 * Encoding flags; the summary is stored as JSON in one text column.
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Summarizes one change as JSON carrying only names and sizes.
	 *
	 * A failure note, when one is given, is carried through untouched. It is
	 * formed by EngineLog rather than here because it describes a throwable and
	 * not a field, and it is already free of values and of paths by the time it
	 * arrives. Adding it here rather than in a column of its own keeps the audit
	 * table's shape unchanged, so an existing install needs no migration.
	 *
	 * @param array<string, mixed> $beforeFields The resolved before-state.
	 * @param array<string, mixed> $afterFields  The promised after-state.
	 * @param string|null          $failure      What went wrong, when the write failed
	 *                                           in a way nothing predicted.
	 *
	 * @return string The redacted summary as JSON.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function summarize( array $beforeFields, array $afterFields, ?string $failure = null ): string {
		$changed = [];
		$metrics = [];

		foreach ( $afterFields as $field => $after ) {
			$name   = (string) $field;
			$before = $beforeFields[ $name ] ?? null;
			if ( $before === $after ) {
				continue;
			}
			$changed[]        = $name;
			$metrics[ $name ] = [
				'before' => $this->measure( $before ),
				'after'  => $this->measure( $after ),
			];
		}

		sort( $changed, SORT_STRING );
		ksort( $metrics, SORT_STRING );

		$summary = [
			'changed' => $changed,
			'metrics' => [] === $metrics ? new stdClass() : $metrics,
		];

		if ( null !== $failure && '' !== $failure ) {
			$summary['failure'] = $failure;
		}

		return (string) wp_json_encode( $summary, self::JSON_FLAGS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The size of a value: item count for arrays, character count otherwise.
	 *
	 * @param mixed $value The value to measure.
	 *
	 * @return int The size.
	 */
	private function measure( mixed $value ): int {
		// THE NULL BRANCH IS AN EQUIVALENT MUTANT and a deletion sweep is expected
		// to survive it rather than pin it: `(string) null` is the empty string,
		// so the fallthrough measures 0 by a longer road. It stays because it
		// says what the zero MEANS — an absent value has no size — where the
		// arithmetic below only happens to agree. There is no test to write for
		// a branch that changes no answer; this paragraph is the record instead.
		// Its sibling below is NOT equivalent: see the boolean test.
		if ( null === $value ) {
			return 0;
		}
		if ( is_array( $value ) ) {
			return count( $value );
		}
		if ( is_bool( $value ) ) {
			return 1;
		}

		return mb_strlen( (string) $value );
	}
}
