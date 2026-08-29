<?php
/**
 * Tests for ElementorGlobalClassFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorGlobalClassCreate;
use SiteHelm\Modules\Elementor\ElementorGlobalClassFields;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Tests\TestCase;

/**
 * The style check both the create and the update run.
 *
 * THIS FILE EXISTS BECAUSE THE TWO OPERATIONS MUST NOT DISAGREE. If the create
 * and the update each carried their own copy of this check, a caller could store
 * through one a value the other would have refused, and the refusal would then
 * appear the next time somebody edited the class rather than when the value was
 * first sent.
 */
final class ElementorGlobalClassFieldsTest extends TestCase {

	private PayloadNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): mixed => json_encode( $data ) );

		$this->normalizer = new PayloadNormalizer();
	}

	/**
	 * Runs the shared check with the bound the two operations use.
	 *
	 * @param mixed $value The requested styles.
	 *
	 * @return array<string, mixed> The accepted styles.
	 */
	private function styles( mixed $value ): array {
		return ElementorGlobalClassFields::styles(
			$value,
			$this->normalizer,
			ElementorGlobalClassCreate::MAX_STYLES_BYTES
		);
	}

	public function test_a_request_sending_no_styles_is_not_a_request_sending_empty_ones(): void {
		$this->assertSame( [], $this->styles( null ) );
	}

	public function test_values_are_passed_through_untouched(): void {
		$styles = [
			'padding'    => [
				'$$type' => 'dimensions',
				'value'  => [ 'top' => 8 ],
			],
			'font-size'  => 16,
			'background' => null,
		];

		$this->assertSame( $styles, $this->styles( $styles ), 'The prop vocabulary is Elementor\'s, and this check does not interpret it.' );
	}

	public function test_styles_that_are_not_an_object_are_refused(): void {
		try {
			$this->styles( 'color: red' );
			$this->fail( 'A style map is an object, not a stylesheet.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_a_property_name_elementor_would_not_store_is_refused(): void {
		try {
			$this->styles( [ 'not a prop name!' => 1 ] );
			$this->fail( 'A key outside the prop-name form must not be stored.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_a_numeric_property_name_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->styles( [ 0 => 'red' ] );
	}

	public function test_more_properties_than_one_class_may_carry_are_refused(): void {
		$styles = [];

		for ( $index = 0; $index <= ElementorGlobalClassWrite::MAX_STYLE_PROPERTIES; $index++ ) {
			$styles[ 'prop-' . $index ] = $index;
		}

		try {
			$this->styles( $styles );
			$this->fail( 'The property bound is advertised in the schema and must also be enforced here.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * The byte bound is what keeps the snapshot recordable.
	 */
	public function test_styles_larger_than_one_class_will_store_are_refused(): void {
		try {
			$this->styles( [ 'content' => str_repeat( 'x', ElementorGlobalClassCreate::MAX_STYLES_BYTES + 1 ) ] );
			$this->fail( 'An unbounded style map is an unbounded snapshot, and the refusal would arrive after the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_the_declared_output_names_both_verification_fields_and_nothing_else(): void {
		$schema = ElementorGlobalClassFields::writeOutput( 'How many classes this site holds.' );

		$this->assertSame( ElementorGlobalClassWrite::FIELD_ORDER, array_keys( $schema['properties'] ) );
		$this->assertSame( ElementorGlobalClassWrite::FIELD_ORDER, $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			'How many classes this site holds.',
			$schema['properties'][ ElementorGlobalClassWrite::FIELD_COUNT ]['description'],
			'Each operation says what it does to the count in its own words.'
		);
	}
}
