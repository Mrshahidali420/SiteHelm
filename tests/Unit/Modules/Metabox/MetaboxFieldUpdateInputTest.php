<?php
/**
 * Tests for the request-shape half of a Meta Box write's validation (REQ-0051).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdateInput;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0051: what a caller may send, and what the schema promises about it.
 *
 * NO SITE IS INSTALLED IN THIS SUITE, AND THAT IS THE POINT. This validator has no
 * index, no presence gate and no post; it can answer every question below in a
 * process with no WordPress and no Meta Box at all. That is precisely what makes it
 * safe to run BEFORE the capability gate: a caller who may not edit the post learns
 * from these refusals only what they themselves typed (spec §6). A test here that
 * needed a site would be evidence the validator had started asking about one.
 *
 * THE SCHEMA IS ASSERTED AS WELL AS THE CODE, because the schema is the contract a
 * client generates requests from and the code is the last line before a caller's
 * string is copied into a refusal. Two declarations of one bound drift, and the one
 * the caller reads would be the one that is not enforced.
 */
final class MetaboxFieldUpdateInputTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var MetaboxFieldUpdateInput
	 */
	private MetaboxFieldUpdateInput $input;

	protected function setUp(): void {
		parent::setUp();

		$this->input = new MetaboxFieldUpdateInput();
	}

	// ------------------------------------------------------------ what parses

	/**
	 * The ordinary case, and the one that proves a value is passed through UNTOUCHED.
	 * A validator that normalized values here would silently rewrite what the operator
	 * approved between the preview and the write.
	 */
	public function test_it_returns_one_entry_per_field_in_the_callers_order(): void {
		$parsed = $this->input->parse(
			[
				'post'   => 42,
				'fields' => [
					[
						'field' => 'tagline',
						'value' => 0,
					],
					[
						'field' => 'subtitle',
						'value' => [ 'a' => [ 1, 2 ] ],
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'tagline',
					'value' => 0,
				],
				[
					'field' => 'subtitle',
					'value' => [ 'a' => [ 1, 2 ] ],
				],
			],
			$parsed,
			'Every entry is returned in the caller\'s order with its value exactly as sent.'
		);
	}

	/**
	 * `null` IS A VALUE THIS MODULE WRITES, not a gap, so an explicitly sent null must
	 * parse. A validator using `??` to read the member would refuse it as missing.
	 */
	public function test_an_explicit_null_value_is_a_value_and_not_a_missing_member(): void {
		$parsed = $this->input->parse(
			[
				'fields' => [
					[
						'field' => 'subtitle',
						'value' => null,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'subtitle',
					'value' => null,
				],
			],
			$parsed,
			'A caller who sent null asked for null to be stored.'
		);
	}

	// ---------------------------------------------------------- what refuses

	/**
	 * Every shape a `fields` argument can arrive in that names no field to write.
	 */
	public function test_a_fields_argument_naming_nothing_is_refused(): void {
		foreach ( [ [], [ 'fields' => [] ], [ 'fields' => 'subtitle' ], [ 'fields' => null ] ] as $input ) {
			$refusal = $this->refusalFrom(
				fn() => $this->input->parse( $input ),
				'A request naming no field to write must be refused.'
			);

			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode, 'The argument is the caller\'s own.' );
		}
	}

	/**
	 * THE LENGTH IS CHECKED BEFORE ANY MEMBER IS READ. A caller who sent a thousand
	 * entries must be told about the limit rather than about the shape of whichever
	 * one happened to be malformed first — otherwise they fix that entry, resend, and
	 * are refused again for a reason that was already true.
	 */
	public function test_too_many_fields_is_refused_before_a_malformed_entry_is_reported(): void {
		$members = array_fill(
			0,
			MetaboxFieldUpdateInput::MAX_FIELDS + 1,
			[ 'field' => 'not two members at all' ]
		);

		$refusal = $this->refusalFrom(
			fn() => $this->input->parse( [ 'fields' => $members ] ),
			'A request past the field limit must be refused.'
		);

		$this->assertStringContainsString(
			(string) MetaboxFieldUpdateInput::MAX_FIELDS,
			$refusal->getMessage(),
			'The refusal quotes the limit that was exceeded, not the shape of the first entry.'
		);
	}

	/**
	 * Two entries addressing one field have no defined outcome: the order is the
	 * caller's array order, the snapshot records ONE prior value, and a restore would
	 * put back a state that was never live.
	 */
	public function test_a_field_named_twice_is_refused_and_named(): void {
		$refusal = $this->refusalFrom(
			fn() => $this->input->parse(
				[
					'fields' => [
						[
							'field' => 'subtitle',
							'value' => 'first',
						],
						[
							'field' => 'subtitle',
							'value' => 'second',
						],
					],
				]
			),
			'One field named twice in one request must be refused.'
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode, 'The repetition is in the caller\'s request.' );

		$this->assertStringContainsString(
			'"subtitle"',
			$refusal->getMessage(),
			'The caller cannot find the repeated entry in a list of fifty from a refusal that will not name it.'
		);

		$this->assertStringNotContainsString(
			'second',
			$refusal->getMessage(),
			'A refusal names a field and never carries a value.'
		);
	}

	/**
	 * An extra member is a caller who believes they asked for something — an `append`,
	 * a `force` — and silently dropping it writes a value under a contract they did
	 * not agree to. A missing `value` is refused for the mirror reason.
	 */
	public function test_an_entry_that_is_not_exactly_a_field_and_a_value_is_refused(): void {
		$entries = [
			[ 'field' => 'subtitle' ],
			[ 'value' => 'x' ],
			[
				'field'  => 'subtitle',
				'value'  => 'x',
				'append' => true,
			],
			[
				'name'  => 'subtitle',
				'value' => 'x',
			],
			'subtitle',
		];

		foreach ( $entries as $entry ) {
			$refusal = $this->refusalFrom(
				fn() => $this->input->parse( [ 'fields' => [ $entry ] ] ),
				'An entry that is not exactly a field and a value must be refused.'
			);

			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode, 'The entry is the caller\'s own.' );
		}
	}

	/**
	 * NEVER A CAST. `(string)` on an array is a fatal and on an integer it produces an
	 * identifier no field on any site carries.
	 */
	public function test_a_field_id_that_is_not_a_usable_string_is_refused(): void {
		foreach ( [ '', 12, [ 'subtitle' ], null, true ] as $id ) {
			$refusal = $this->refusalFrom(
				fn() => $this->input->parse(
					[
						'fields' => [
							[
								'field' => $id,
								'value' => 'x',
							],
						],
					]
				),
				'A field member that is not a non-empty string must be refused.'
			);

			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode, 'The identifier is the caller\'s own.' );
		}
	}

	/**
	 * THE BOUND IS ENFORCED IN CODE AND NOT ONLY IN THE SCHEMA, and this is the test
	 * that would fail if the check moved out. An id that does not resolve is quoted
	 * back into a refusal, and from there into the response and the audit row; without
	 * a cap, a five-megabyte `field` is copied into all three.
	 */
	public function test_an_over_long_field_id_is_refused_without_being_echoed(): void {
		$id = str_repeat( 'z', MetaboxFieldUpdateInput::MAX_ID_LENGTH + 1 );

		$refusal = $this->refusalFrom(
			fn() => $this->input->parse(
				[
					'fields' => [
						[
							'field' => $id,
							'value' => 'x',
						],
					],
				]
			),
			'A field id past the length bound must be refused.'
		);

		$this->assertStringNotContainsString(
			$id,
			$refusal->getMessage() . (string) $refusal->remediation,
			'The unbounded identifier must not be copied into the refusal it caused.'
		);
	}

	// ------------------------------------------------------------ the schema

	/**
	 * THE SCHEMA IS STRICT AND ITS BOUNDS ARE THE ENFORCED ONES. A client generates
	 * requests from this, so a bound stated here and not applied in code — or applied
	 * and not stated — is a documented request the operation refuses.
	 */
	public function test_the_schema_is_closed_and_declares_the_bounds_that_are_enforced(): void {
		$schema = MetaboxFieldUpdateInput::schema();

		$this->assertFalse( $schema['additionalProperties'], 'An unrecognised argument is refused, not ignored.' );
		$this->assertSame( [ 'post', 'fields' ], $schema['required'], 'Both arguments are required.' );
		$this->assertSame( 1, $schema['properties']['post']['minimum'], 'Post 0 names no post and is refused.' );

		$fields = $schema['properties']['fields'];

		$this->assertSame( 1, $fields['minItems'], 'A write must name at least one field.' );
		$this->assertSame( MetaboxFieldUpdateInput::MAX_FIELDS, $fields['maxItems'], 'The declared cap is the enforced cap.' );
		$this->assertFalse( $fields['items']['additionalProperties'], 'An entry carries exactly a field and a value.' );
		$this->assertSame( [ 'field', 'value' ], $fields['items']['required'], 'Both members are required.' );

		$this->assertSame(
			MetaboxFieldUpdateInput::MAX_ID_LENGTH,
			$fields['items']['properties']['field']['maxLength'],
			'The declared id bound is the enforced id bound.'
		);
	}

	/**
	 * Meta Box's naming is inverted from what a reader assumes, and a request
	 * addressing the human label names nothing this module can write. THE SCHEMA SAYS
	 * WHICH IS WHICH IN WORDS rather than leaving it to be inferred from an example.
	 */
	public function test_the_schema_states_that_an_id_is_the_meta_key_and_a_name_is_the_label(): void {
		$description = MetaboxFieldUpdateInput::schema()['properties']['fields']['items']['properties']['field']['description'];

		$this->assertStringContainsString( 'meta key', $description, 'The id is the meta key, in words.' );
		$this->assertStringContainsString( 'label', $description, 'The name is the human label, in words.' );
	}

	/**
	 * An example that contradicted the shape this class accepts would be a documented
	 * request the operation refuses, so the example is parsed rather than eyeballed.
	 */
	public function test_the_published_example_is_a_request_this_validator_accepts(): void {
		$example = MetaboxFieldUpdateInput::example();

		$this->assertSame( 'metabox-field-update', $example['operation'], 'The example names this operation.' );

		$this->assertNotSame( [], $this->input->parse( $example['arguments'] ), 'The published example parses.' );
	}

	// ------------------------------------------------------------- the helper

	/**
	 * The exception one call raised, asserted to have been raised at all.
	 *
	 * A try/catch whose assertions live in the catch block passes silently when
	 * nothing is thrown.
	 *
	 * @param callable $run     The call expected to refuse.
	 * @param string   $message The assertion message.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalFrom( callable $run, string $message ): OperationException {
		$thrown = null;

		try {
			$run();
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( OperationException::class, $thrown, $message );

		return $thrown;
	}
}
