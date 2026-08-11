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
