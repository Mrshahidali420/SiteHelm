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
				$violations[] = "unknown property '{$key}'";
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

		$type_ok = match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ! array_is_list( $value ),
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
		if ( isset( $spec['maxLength'] ) && is_string( $value ) && strlen( $value ) > $spec['maxLength'] ) {
			$violations[] = "property '{$key}' exceeds maximum length {$spec['maxLength']}";
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
