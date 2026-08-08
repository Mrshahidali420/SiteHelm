<?php

declare(strict_types=1);

namespace SiteHelm\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use stdClass;

abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Clears the two pieces of process-global test state before every test:
	 * Brain Monkey's function fakes, and the WP_Query double's queued rows.
	 *
	 * The double is reset here rather than in the one class that uses it today,
	 * because its state is static and its alias is installed for the whole
	 * process by tests/bootstrap.php. Resetting per class would let a class that
	 * constructs WP_Query without knowing about the double inherit whatever rows
	 * the previous class queued.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		FakeWpQuery::reset();
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

		// A union such as [ 'integer', 'null' ] matches when the value matches
		// any one branch. Checked as a list of single types rather than folded
		// into the match() below, so a member the union does not declare — for
		// example a string arriving where only integer|null is declared — still
		// fails rather than falling through to the permissive default.
		if ( is_array( $type ) ) {
			foreach ( $type as $branch ) {
				if ( $this->matchesSingleType( $value, $branch ) ) {
					return true;
				}
			}

			return false;
		}

		$matches = $this->matchesSingleType( $value, $type );

		if ( ! $matches || 'array' !== $type || ! isset( $property['items']['type'] ) ) {
			return $matches;
		}

		foreach ( $value as $item ) {
			if ( ! $this->matchesDeclaredItem( $item, $property['items'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reports whether one value matches a single declared JSON Schema type name.
	 *
	 * Extracted from matchesDeclaredType() so a union type such as
	 * `[ 'integer', 'null' ]` can test the value against each branch in turn
	 * instead of falling through to a permissive default that would accept any
	 * value for a member the schema never actually declared.
	 *
	 * @param mixed       $value The member's value.
	 * @param string|null $type  One JSON Schema type name.
	 *
	 * @return bool True when the value matches this one type.
	 */
	private function matchesSingleType( mixed $value, ?string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => $value instanceof stdClass || is_array( $value ),
			'null'    => null === $value,
			default   => true,
		};
	}

	/**
	 * Reports whether one member of a declared array matches its item schema.
	 *
	 * An object item that declares its own `properties` is checked against that
	 * sub-schema by conformsToSchema(), so the per-item contract — no member the
	 * schema does not declare, every required member present, every member the
	 * declared type — is enforced by the same rules that govern a top-level
	 * payload rather than by a second copy of them.
	 *
	 * Before this delegation existed, a declared `object` item fell through to
	 * the permissive default and every item passed unconditionally, which meant
	 * the first operation to declare an array of objects had its per-item
	 * contract enforced by nothing. That matters beyond one operation: this
	 * assertion is the interim mitigation for interpretation I6, under which
	 * runtime outputSchema validation is deferred.
	 *
	 * An object item that declares no `properties` can only be checked for
	 * shape, because there is no per-item contract to check it against.
	 *
	 * Every other item type delegates to matchesDeclaredType() rather than
	 * restating the scalar rules here. A second copy would drift: the first one
	 * covered only `string` and `integer` and passed `number`, `boolean` and
	 * nested arrays unconditionally — the same permissive default this method
	 * exists to remove, one level down.
	 *
	 * @param mixed                $item  One member of the declared array.
	 * @param array<string, mixed> $items The declared item schema.
	 *
	 * @return bool True when the item matches the declaration.
	 */
	private function matchesDeclaredItem( mixed $item, array $items ): bool {
		if ( 'object' === $items['type'] ) {
			if ( ! isset( $items['properties'] ) ) {
				return $item instanceof stdClass || is_array( $item );
			}

			return is_array( $item ) && $this->conformsToSchema( $item, $items );
		}

		return $this->matchesDeclaredType( $item, $items );
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
			$declared_type = $properties[ $key ]['type'] ?? null;

			$this->assertTrue(
				$this->matchesDeclaredType( $value, $properties[ $key ] ),
				sprintf(
					"Member '%s' does not match its declared type '%s'.",
					$key,
					is_array( $declared_type ) ? implode( '|', $declared_type ) : (string) $declared_type
				)
			);
		}
	}
}
