<?php
/**
 * Strict object-schema validation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Schema;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Strict object-schema validation. Unknown properties are violations,
 * never silently dropped. All violations are collected into one message.
 */
final class SchemaValidator {

	/**
	 * Validate input against a strict object schema.
	 *
	 * @param array<string, mixed> $input  Request arguments.
	 * @param array<string, mixed> $schema Operation input schema.
	 * @return array<string, mixed> The validated input, unchanged.
	 * @throws OperationException With ErrorCode::InvalidInput on any violation.
	 * @throws InvalidArgumentException If schema is not strict (lacks additionalProperties: false or type !== 'object').
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function validate( array $input, array $schema ): array {
		if ( ( $schema['type'] ?? '' ) !== 'object' || ( $schema['additionalProperties'] ?? null ) !== false ) {
			throw new InvalidArgumentException( 'Operation input schemas must be objects with additionalProperties: false.' );
		}

		$violations = $this->collect_violations( $input, $schema );

		if ( [] !== $violations ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Input validation failed: ' . implode( '; ', $violations ) . '.',
				'Correct the listed properties and retry. Identical input always fails identically.'
			);
		}

		return $input;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Collect all violations for input against schema without throwing.
	 *
	 * @param array<string, mixed> $input  Request arguments.
	 * @param array<string, mixed> $schema Operation input schema (must be strict).
	 * @return list<string> All violations found.
	 */
	private function collect_violations( array $input, array $schema ): array {
		$violations = [];
		$properties = $schema['properties'] ?? [];

		foreach ( array_keys( $input ) as $key ) {
			if ( ! array_key_exists( $key, $properties ) ) {
				// Unknown keys are attacker-controlled: report a sanitized form so
				// the message stays useful without being an injection vector.
				$violations[] = "unknown property '" . $this->safe_property_name( (string) $key ) . "'";
			}
		}
		foreach ( $schema['required'] ?? [] as $required ) {
			if ( ! array_key_exists( $required, $input ) ) {
				$violations[] = "missing required property '{$required}'";
			}
		}
		foreach ( $input as $key => $value ) {
			if ( array_key_exists( $key, $properties ) ) {
				$violations = array_merge( $violations, $this->check_value( $key, $value, $properties[ $key ] ) );
			}
		}

		return $violations;
	}

	/**
	 * Reduce an untrusted property name to a form safe to interpolate.
	 *
	 * Keeps only letters, digits, underscores, and hyphens, truncates to 40
	 * characters, and falls back to a placeholder when nothing survives.
	 *
	 * @param string $name The raw property name from the request.
	 * @return string A safe, bounded rendering of the name.
	 */
	private function safe_property_name( string $name ): string {
		$safe = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $name ), 0, 40 );

		return '' === $safe ? '<unnamed>' : $safe;
	}

	/**
	 * Check a single property value against its schema spec.
	 *
	 * @param string               $key  Property name.
	 * @param mixed                $value Property value.
	 * @param array<string, mixed> $spec Property schema.
	 * @return list<string> Violations for this property.
	 */
	private function check_value( string $key, mixed $value, array $spec ): array {
		$violations = [];
		$type       = $spec['type'] ?? null;

		// An EMPTY array satisfies both `array` and `object`, and must. The
		// request is decoded associatively, so JSON `[]` and JSON `{}` arrive as
		// the same PHP value and nothing downstream can tell them apart. Judging
		// `[]` a list — which `array_is_list()` does — therefore refused every
		// empty object a client sent against a schema this validator itself
		// published, with a message about types rather than about content. Two
		// operations have already been shaped around that refusal.
		$type_ok = match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ),
			default   => true,
		};
		if ( ! $type_ok ) {
			return [ "property '{$key}' must be of type {$type}" ];
		}

		if ( isset( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
			$violations[] = "property '{$key}' must be one of: " . implode( ', ', $spec['enum'] );
		}
		if ( isset( $spec['minimum'] ) && is_numeric( $value ) && $value < $spec['minimum'] ) {
			$violations[] = "property '{$key}' must be >= {$spec['minimum']}";
		}
		if ( isset( $spec['maximum'] ) && is_numeric( $value ) && $value > $spec['maximum'] ) {
			$violations[] = "property '{$key}' must be <= {$spec['maximum']}";
		}
		if ( isset( $spec['minLength'] ) && is_string( $value ) && strlen( $value ) < $spec['minLength'] ) {
			$violations[] = "property '{$key}' is shorter than minimum length {$spec['minLength']}";
		}
		if ( isset( $spec['maxLength'] ) && is_string( $value ) && strlen( $value ) > $spec['maxLength'] ) {
			$violations[] = "property '{$key}' exceeds maximum length {$spec['maxLength']}";
		}
		// The pattern is stored unanchored, in JSON Schema's own form, and is
		// applied with JSON Schema's own semantics: a search, not a full match.
		// Every pattern the catalog declares anchors itself. A pattern that does
		// not compile is a defect in the schema rather than in the request, so it
		// is reported as such rather than silently passing every value; the
		// catalog's patterns are pinned as compilable by
		// SchemaKeywordCoverageTest.
		if ( isset( $spec['pattern'] ) && is_string( $value ) && is_string( $spec['pattern'] ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that does not compile is a defect in the schema, not in the request, so the compile warning would repeat on every request naming that property while telling the operator nothing the refusal below does not; the false return is handled on the line below.
			$matched = @preg_match( '#' . str_replace( '#', '\\#', $spec['pattern'] ) . '#D', $value );

			if ( false === $matched ) {
				$violations[] = "property '{$key}' is declared with a pattern this site cannot apply";
			} elseif ( 1 !== $matched ) {
				$violations[] = "property '{$key}' does not match the required format";
			}
		}
		if ( isset( $spec['minItems'] ) && is_array( $value ) && count( $value ) < $spec['minItems'] ) {
			$violations[] = "property '{$key}' must have at least {$spec['minItems']} entries";
		}
		// RETURNS RATHER THAN CONTINUES. The point of an upper bound on entries is
		// to stop the work, so an over-long array is refused whole instead of being
		// walked entry by entry to collect violations nobody will read.
		if ( isset( $spec['maxItems'] ) && is_array( $value ) && count( $value ) > $spec['maxItems'] ) {
			$violations[] = "property '{$key}' must have at most {$spec['maxItems']} entries";

			return $violations;
		}
		// COMPARED BY TYPE AND VALUE TOGETHER, so the string '1' and the integer 1
		// are two entries rather than one. A comparison by value alone is what
		// in_array() without the strict flag does, and it would let a list naming
		// the same child twice through whenever the two spellings differed only in
		// type. SCALARS AND NULL ONLY: no schema in the catalog declares uniqueness
		// over a list of objects, and inventing an equality for one here would be a
		// rule nothing exercises.
		if ( isset( $spec['uniqueItems'] ) && true === $spec['uniqueItems'] && is_array( $value ) ) {
			$tokens = [];

			foreach ( $value as $item ) {
				if ( is_scalar( $item ) || null === $item ) {
					$tokens[] = gettype( $item ) . ':' . ( is_bool( $item ) ? (string) (int) $item : (string) $item );
				}
			}

			if ( count( array_unique( $tokens ) ) !== count( $tokens ) ) {
				$violations[] = "property '{$key}' must not name the same entry twice";
			}
		}
		// REFUSED WHOLE, for the same reason as maxItems above: an object larger
		// than its declared bound is not a request to inspect member by member.
		if ( isset( $spec['maxProperties'] ) && 'object' === $type && is_array( $value )
			&& count( $value ) > $spec['maxProperties'] ) {
			$violations[] = "property '{$key}' must have at most {$spec['maxProperties']} members";

			return $violations;
		}
		if ( 'array' === $type && isset( $spec['items'] ) && is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$violations = array_merge( $violations, $this->check_value( "{$key}[{$index}]", $item, $spec['items'] ) );
			}
		}
		if ( 'object' === $type && isset( $spec['properties'] ) && is_array( $value ) ) {
			$nested            = array_merge(
				$spec,
				[
					'type'                 => 'object',
					'additionalProperties' => false,
				]
			);
			$nested_violations = $this->collect_violations( $value, $nested );
			foreach ( $nested_violations as $violation ) {
				$violations[] = "property '{$key}': " . $violation;
			}
		}

		return $violations;
	}
}
