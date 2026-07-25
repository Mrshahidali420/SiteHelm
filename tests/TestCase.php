<?php

declare(strict_types=1);

namespace SiteHelm\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use stdClass;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reports whether one value matches a declared property, type and all.
	 *
	 * This is the single source of truth for the type rules, shared by the
	 * branch-selection predicate and by the assertions that report failures.
	 * It mirrors SchemaValidator's rules with one deliberate difference: an
	 * empty JSON object has no distinct PHP array form, so a declared `object`
	 * member is satisfied by a stdClass placeholder or by an empty array as
	 * well as by an associative array. SchemaValidator itself is not reused,
	 * because it is an INPUT validator and rejects both of those.
	 *
	 * @param mixed                $value    The member's value.
	 * @param array<string, mixed> $property The member's declared schema.
	 *
	 * @return bool True when the value matches the declaration.
	 */
	private function matchesDeclaredType( mixed $value, array $property ): bool {
		$type = $property['type'] ?? null;

		$matches = match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => $value instanceof stdClass || is_array( $value ),
			default   => true,
		};

		if ( ! $matches || 'array' !== $type || ! isset( $property['items']['type'] ) ) {
			return $matches;
		}

		foreach ( $value as $item ) {
			$item_matches = match ( $property['items']['type'] ) {
				'string'  => is_string( $item ),
				'integer' => is_int( $item ),
				default   => true,
			};

			if ( ! $item_matches ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reports whether a payload conforms to one schema, without asserting.
	 *
	 * A predicate rather than an assertion because selecting which branch of a
	 * `oneOf` union a payload matches requires testing branches that are
	 * expected to fail.
	 *
	 * @param array<string, mixed> $data   The returned data payload.
	 * @param array<string, mixed> $schema One schema, or one branch of a union.
	 *
	 * @return bool True when the payload conforms.
	 */
	private function conformsToSchema( array $data, array $schema ): bool {
		$properties = $schema['properties'] ?? [];

		if ( [] !== array_diff( array_keys( $data ), array_keys( $properties ) ) ) {
			return false;
		}

		if ( [] !== array_diff( $schema['required'] ?? [], array_keys( $data ) ) ) {
			return false;
		}

		foreach ( $data as $key => $value ) {
			if ( ! $this->matchesDeclaredType( $value, $properties[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Asserts a returned `data` payload conforms to its operation's declared
	 * outputSchema.
	 *
	 * Interpretation I6 accepts that nothing validates output at runtime and
	 * mandates this per-operation test as the interim mitigation, so drift is
	 * caught where it originates rather than by a client.
	 *
	 * A write's schema is a `oneOf` union of its plan-phase and apply-phase
	 * shapes (interpretation I2). The union is satisfied only by matching
	 * exactly one branch: zero means the payload matches neither shape, and
	 * more than one would mean the branches are not actually exclusive, which
	 * is the defect the union exists to prevent. Once a single branch is
	 * selected, the member-level assertions run against it so a failure names
	 * the member rather than the whole payload.
	 *
	 * @param array<string, mixed> $data   The returned data payload.
	 * @param array<string, mixed> $schema The operation's declared outputSchema.
	 */
	protected function assertConformsToOutputSchema( array $data, array $schema ): void {
		if ( isset( $schema['oneOf'] ) ) {
			$matched = array_values(
				array_filter(
					$schema['oneOf'],
					fn( array $branch ): bool => $this->conformsToSchema( $data, $branch )
				)
			);

			$this->assertCount(
				1,
				$matched,
				sprintf(
					'The payload [%s] must match exactly one branch of the declared union, and matched %d.',
					implode( ', ', array_keys( $data ) ),
					count( $matched )
				)
			);

			$schema = $matched[0];
		}

		$properties = $schema['properties'] ?? [];

		$this->assertSame(
			[],
			array_values( array_diff( array_keys( $data ), array_keys( $properties ) ) ),
			'The payload carries a member the declared outputSchema does not.'
		);

		$this->assertSame(
			[],
			array_values( array_diff( $schema['required'] ?? [], array_keys( $data ) ) ),
			'The payload omits a member the declared outputSchema requires.'
		);

		foreach ( $data as $key => $value ) {
			$this->assertTrue(
				$this->matchesDeclaredType( $value, $properties[ $key ] ),
				sprintf(
					"Member '%s' does not match its declared type '%s'.",
					$key,
					(string) ( $properties[ $key ]['type'] ?? null )
				)
			);
		}
	}
}
