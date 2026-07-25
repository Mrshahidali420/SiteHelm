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
}
