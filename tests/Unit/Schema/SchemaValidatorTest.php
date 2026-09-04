<?php
/**
 * Unit tests for SchemaValidator.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * Test suite for SchemaValidator.
 */
final class SchemaValidatorTest extends TestCase {

	/**
	 * Validator instance.
	 *
	 * @var SchemaValidator
	 */
	private SchemaValidator $validator;

	/**
	 * Test schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema = [
		'type'                 => 'object',
		'properties'           => [
			'title'  => [
				'type'      => 'string',
				'maxLength' => 200,
			],
			'status' => [
				'type' => 'string',
				'enum' => [ 'draft', 'publish' ],
			],
			'count'  => [
				'type'    => 'integer',
				'minimum' => 1,
			],
		],
		'required'             => [ 'title' ],
		'additionalProperties' => false,
	];

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new SchemaValidator();
	}

	/**
	 * Valid input should pass through unchanged.
	 */
	public function test_valid_input_passes_through(): void {
		$input = [
			'title'  => 'Hello',
			'status' => 'draft',
			'count'  => 3,
		];
		$this->assertSame( $input, $this->validator->validate( $input, $this->schema ) );
	}

	/**
	 * Unknown properties should be rejected, not silently ignored.
	 */
	public function test_unknown_property_is_rejected_not_ignored(): void {
		try {
			$this->validator->validate(
				[
					'title'  => 'x',
					'sneaky' => true,
				],
				$this->schema
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'sneaky', $e->getMessage() );
		}
	}

	/**
	 * Missing required property should be rejected.
	 */
	public function test_missing_required_property_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'status' => 'draft' ], $this->schema );
	}

	/**
	 * Wrong type should be rejected.
	 */
	public function test_wrong_type_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'title' => 42 ], $this->schema );
	}

	/**
	 * Enum violation should be rejected.
	 */
	public function test_enum_violation_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate(
			[
				'title'  => 'x',
				'status' => 'trashed',
			],
			$this->schema
		);
	}

	/**
	 * Minimum constraint violation should be rejected.
	 */
	public function test_minimum_violation_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate(
			[
				'title' => 'x',
				'count' => 0,
			],
			$this->schema
		);
	}

	/**
	 * All violations should be reported in one message.
	 */
	public function test_all_violations_reported_in_one_message(): void {
		try {
			$this->validator->validate(
				[
					'status' => 'nope',
					'extra'  => 1,
				],
				$this->schema
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'title', $e->getMessage() );  // Missing required property.
			$this->assertStringContainsString( 'status', $e->getMessage() ); // Enum violation.
			$this->assertStringContainsString( 'extra', $e->getMessage() );  // Unknown property.
		}
	}

	/**
	 * Schema without additionalProperties: false should throw InvalidArgumentException.
	 */
	public function test_schema_without_additional_properties_false_is_a_programming_error(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->validator->validate(
			[],
			[
				'type'       => 'object',
				'properties' => [],
			]
		);
	}

	/**
	 * Boolean and number types should be validated correctly.
	 */
	public function test_boolean_and_number_types_validated(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'flag'  => [ 'type' => 'boolean' ],
				'ratio' => [ 'type' => 'number' ],
			],
			'required'             => [],
			'additionalProperties' => false,
		];

		// Valid input should pass.
		$input = [
			'flag'  => true,
			'ratio' => 3.14,
		];
		$this->assertSame( $input, $this->validator->validate( $input, $schema ) );

		// Invalid type for boolean should fail.
		try {
			$this->validator->validate( [ 'flag' => 'yes' ], $schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'flag', $e->getMessage() );
		}
	}

	/**
	 * An empty object is accepted, and so is an empty array.
	 *
	 * The request body is decoded associatively, so JSON `{}` and JSON `[]` both
	 * arrive as PHP `[]`. A validator that accepted one and refused the other
	 * would be refusing a value it cannot actually distinguish from the one it
	 * accepts — and it refused the object, which is the shape every settings map
	 * in this plugin declares.
	 */
	public function test_an_empty_object_is_accepted_for_an_object_typed_property(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'settings' => [ 'type' => 'object' ],
				'tags'     => [ 'type' => 'array' ],
			],
			'required'             => [],
			'additionalProperties' => false,
		];

		$input = [
			'settings' => [],
			'tags'     => [],
		];

		$this->assertSame( $input, $this->validator->validate( $input, $schema ) );
	}

	/**
	 * A NON-empty list is still refused where an object is declared.
	 *
	 * The empty case is a decoding ambiguity; this one is not. Without this test
	 * the fix above could be widened to accept any array at all and nothing would
	 * fail.
	 */
	public function test_a_populated_list_is_still_refused_for_an_object_typed_property(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [ 'settings' => [ 'type' => 'object' ] ],
			'required'             => [],
			'additionalProperties' => false,
		];

		try {
			$this->validator->validate( [ 'settings' => [ 'a', 'b' ] ], $schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'settings', $e->getMessage() );
			$this->assertStringContainsString( 'object', $e->getMessage() );
		}
	}

	/**
	 * A populated map is still refused where a list is declared.
	 *
	 * The mirror of the test above, so the two arms cannot collapse into one.
	 */
	public function test_a_populated_map_is_still_refused_for_an_array_typed_property(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [ 'tags' => [ 'type' => 'array' ] ],
			'required'             => [],
			'additionalProperties' => false,
		];

		try {
			$this->validator->validate( [ 'tags' => [ 'k' => 'v' ] ], $schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'tags', $e->getMessage() );
			$this->assertStringContainsString( 'array', $e->getMessage() );
		}
	}

	/**
	 * Array items should be validated recursively.
	 */
	public function test_array_items_are_validated_recursively(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'tags' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'required'             => [],
			'additionalProperties' => false,
		];

		// Valid array should pass.
		$input = [ 'tags' => [ 'a', 'b' ] ];
		$this->assertSame( $input, $this->validator->validate( $input, $schema ) );

		// Invalid item type should fail with indexed path.
		try {
			$this->validator->validate( [ 'tags' => [ 'a', 5 ] ], $schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'tags[1]', $e->getMessage() );
		}
	}

	/**
	 * Nested object violations should be reported once, not doubled.
	 */
	public function test_nested_object_violations_are_reported_once(): void {
		$schema = [
			'type'                 => 'object',
			'properties'           => [
				'meta' => [
					'type'                 => 'object',
					'properties'           => [
						'key' => [ 'type' => 'string' ],
					],
					'additionalProperties' => false,
				],
			],
			'required'             => [],
			'additionalProperties' => false,
		];

		try {
			$this->validator->validate( [ 'meta' => [ 'key' => 7 ] ], $schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( "property 'meta'", $e->getMessage() );
			// Ensure no doubled "Input validation failed" messages.
			$count = substr_count( $e->getMessage(), 'Input validation failed' );
			$this->assertSame( 1, $count );
		}
	}

	/**
	 * A list declared unique is refused when it names one entry twice.
	 *
	 * The catalog declares `uniqueItems` on `elementor-elements-reorder`'s order,
	 * where a repeated child is a request the operation cannot satisfy at all.
	 */
	public function test_a_list_declared_unique_refuses_a_repeated_entry(): void {
		try {
			$this->validator->validate( [ 'ids' => [ 'a', 'b', 'a' ] ], $this->uniqueListSchema() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'must not name the same entry twice', $e->getMessage() );
		}
	}

	/**
	 * The same list without a repeat passes, so the check above is a constraint
	 * rather than a refusal of every list.
	 */
	public function test_a_list_declared_unique_accepts_distinct_entries(): void {
		$this->assertSame(
			[ 'ids' => [ 'a', 'b', 'c' ] ],
			$this->validator->validate( [ 'ids' => [ 'a', 'b', 'c' ] ], $this->uniqueListSchema() )
		);
	}

	/**
	 * TYPE IS PART OF THE COMPARISON: the string '1' and the integer 1 are two
	 * entries, not one. A comparison by value alone would call them the same and
	 * refuse a request that names two different things.
	 */
	public function test_two_spellings_of_the_same_number_are_two_entries(): void {
		$schema                             = $this->uniqueListSchema();
		$schema['properties']['ids']['items'] = [];

		$this->assertSame(
			[ 'ids' => [ '1', 1 ] ],
			$this->validator->validate( [ 'ids' => [ '1', 1 ] ], $schema )
		);
	}

	/**
	 * A list of unique identifiers, for the three cases above.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function uniqueListSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'maxItems'    => 10,
					'uniqueItems' => true,
				],
			],
			'required'             => [],
			'additionalProperties' => false,
		];
	}

	/**
	 * A property that really does take more than one kind of value has to be
	 * able to say so. Before it could, the only honest schema was the narrow
	 * one, and callers found out about the other kinds by being refused.
	 *
	 * @dataProvider unionValues
	 *
	 * @param mixed $value A value the union admits.
	 */
	public function test_a_property_naming_several_types_accepts_any_of_them( mixed $value ): void {
		$this->validator->validate( [ 'field' => $value ], $this->unionSchema() );

		$this->assertTrue( true, 'Validation passed without throwing.' );
	}

	/**
	 * Values the union in unionSchema() admits.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unionValues(): array {
		return [
			'text'    => [ 'a string' ],
			'integer' => [ 321 ],
			'float'   => [ 1.5 ],
			'true'    => [ true ],
			'false'   => [ false ],
		];
	}

	/**
	 * A UNION IS NOT AN ABSENCE OF RULES. A spec naming several types once fell
	 * through to no type check at all, which let an array reach an operation
	 * that had been promised a scalar.
	 */
	public function test_a_property_naming_several_types_still_refuses_the_others(): void {
		try {
			$this->validator->validate( [ 'field' => [ 'nested' ] ], $this->unionSchema() );
			$this->fail( 'Expected a value outside the union to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::InvalidInput, $refused->errorCode );
			$this->assertStringContainsString( 'string or number or boolean', $refused->getMessage() );
		}
	}

	/**
	 * One schema with a property that takes text, a number, or true or false.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function unionSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'field' => [ 'type' => [ 'string', 'number', 'boolean' ] ],
			],
			'required'             => [ 'field' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Non-object schema should throw InvalidArgumentException.
	 */
	public function test_non_object_schema_is_a_programming_error(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->validator->validate(
			[],
			[
				'type'                 => 'string',
				'additionalProperties' => false,
			]
		);
	}
}
