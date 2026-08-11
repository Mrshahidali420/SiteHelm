<?php
/**
 * Tests for ElementorWriteFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\TestCase;

/**
 * The declaration half of the Elementor write block: the four verification
 * fields, the order the preview ranks them in, and the two input properties
 * every one of the six writes shares.
 *
 * EVERY EXPECTED VALUE HERE IS A FROZEN LITERAL, never a value recomputed by
 * calling the code under test. A test that rebuilds `FIELD_ORDER` from
 * `FIELD_DIGEST` and its siblings cannot detect a rename of any of them, which
 * is the whole class of drift these constants exist to prevent: the field names
 * are a wire contract shared with PreviewRenderer, the snapshot, and the six
 * operations' promised after-states.
 *
 * The one exception is the plan branch of the output schema, which is asserted
 * to be IDENTICAL to `WriteOutputSchema::schema()`'s. That is not a
 * recomputation of anything this class does — it is the claim that this class
 * refines the apply branch and leaves the shared plan branch alone.
 */
final class ElementorWriteFieldsTest extends TestCase {

	/**
	 * The four field names, frozen. A rename anywhere breaks this first.
	 */
	public function test_the_four_verification_field_names_are_frozen(): void {
		$this->assertSame( 'documentDigest', ElementorWriteFields::FIELD_DIGEST );
		$this->assertSame( 'elementCount', ElementorWriteFields::FIELD_COUNT );
		$this->assertSame( 'maxDepth', ElementorWriteFields::FIELD_DEPTH );
		$this->assertSame( 'widgetTypeCounts', ElementorWriteFields::FIELD_WIDGETS );
	}

	/**
	 * PreviewRenderer ranks a change's fields in this order, so the order is
	 * part of what an operator reads before approving a plan. Digest first
	 * because it is the one field that answers "did the stored document move at
	 * all"; the three shape totals after it, coarsest first.
	 */
	public function test_the_field_order_is_digest_then_count_then_depth_then_widgets(): void {
		$this->assertSame(
			[ 'documentDigest', 'elementCount', 'maxDepth', 'widgetTypeCounts' ],
			ElementorWriteFields::FIELD_ORDER
		);
	}

	/**
	 * The shared input properties are exactly two, and no more. A third
	 * property added here would silently appear on all six operations.
	 */
	public function test_the_document_input_declares_exactly_document_and_element_id(): void {
		$this->assertSame( [ 'document', 'elementId' ], array_keys( ElementorWriteFields::documentInput() ) );
	}

	/**
	 * `document` is a post identifier, and `minimum => 1` is the boundary that
	 * keeps a 0 out of `get_post_meta()`, where on some configurations it reads
	 * whatever `$post` happens to be global.
	 */
	public function test_the_document_property_is_a_positive_integer(): void {
		$document = ElementorWriteFields::documentInput()['document'];

		$this->assertSame( 'integer', $document['type'] );
		$this->assertSame( 1, $document['minimum'] );
	}

	/**
	 * `elementId` is a bounded string. The bound matters because the id is
	 * compared against every id in a stored tree, and an unbounded one is a
	 * caller-controlled scan of arbitrary length.
	 */
	public function test_the_element_id_property_is_a_bounded_pattern_matched_string(): void {
		$element = ElementorWriteFields::documentInput()['elementId'];

		$this->assertSame( 'string', $element['type'] );
		$this->assertSame( 1, $element['minLength'] );
		$this->assertSame( 64, $element['maxLength'] );
		$this->assertSame( '^[A-Za-z0-9_-]{1,64}$', $element['pattern'] );
	}

	/**
	 * The pattern is asserted against real values rather than only against its
	 * own text, because a pattern that compiles and matches nothing would pass
	 * a string comparison and refuse every request.
	 *
	 * `1a2b3c4` is the shape Elementor mints — seven characters of hex — and
	 * ElementorIdMint::ID_LENGTH is 7. The refusals are the two that would
	 * otherwise reach a tree walk: an empty id, which matches every node that
	 * carries no id at all, and an id carrying whitespace.
	 *
	 * @dataProvider elementIdCases
	 *
	 * @param string $candidate The id under test.
	 * @param bool   $accepted  Whether the pattern must accept it.
	 */
	public function test_the_element_id_pattern_accepts_stored_ids_and_refuses_the_rest( string $candidate, bool $accepted ): void {
		$pattern = '/' . ElementorWriteFields::documentInput()['elementId']['pattern'] . '/';

		$this->assertSame( $accepted, 1 === preg_match( $pattern, $candidate ) );
	}

	/**
	 * The element id cases.
	 *
	 * @return array<string, array{0: string, 1: bool}> The cases.
	 */
	public static function elementIdCases(): array {
		return [
			'a minted seven character id' => [ '1a2b3c4', true ],
			'a legacy alphanumeric id'    => [ 'abc123XYZ', true ],
			'an id with an underscore'    => [ 'a_b-c', true ],
			'an empty id'                 => [ '', false ],
			'an id carrying a space'      => [ 'a b', false ],
			'an id carrying a newline'    => [ "abc\ndef", false ],
			'an id past the length bound' => [ str_repeat( 'a', 65 ), false ],
		];
	}

	/**
	 * The union still has exactly the two branches every write shares, so this
	 * class refines the shared schema rather than replacing it.
	 */
	public function test_the_output_schema_is_the_shared_two_branch_union(): void {
		$schema = ElementorWriteFields::outputSchema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertCount( 2, $schema['oneOf'] );
	}

	/**
	 * The plan branch is untouched. A module that re-declared it would let the
	 * two phases' shapes drift apart per module, which is exactly what one
	 * shared `oneOf` exists to prevent.
	 */
	public function test_the_plan_branch_is_the_shared_one_unchanged(): void {
		$shared = WriteOutputSchema::schema();

		$this->assertSame( $shared['oneOf'][0], ElementorWriteFields::outputSchema()['oneOf'][0] );
	}

	/**
	 * The apply branch's `state` is TYPED, and closed. The shared schema
	 * describes `state` as an untyped object because it has to cover every
	 * module; here the verified persisted state is exactly what `fieldsFor()`
	 * returns, so a client can be told the four members by name.
	 *
	 * Frozen as a literal. Rebuilding it from ElementorWriteFields' own
	 * constants would make this assertion true for any rename.
	 */
	public function test_the_apply_branch_types_the_state_as_the_four_fields(): void {
		$state = ElementorWriteFields::outputSchema()['oneOf'][1]['properties']['state'];

		$this->assertSame(
			[ 'documentDigest', 'elementCount', 'maxDepth', 'widgetTypeCounts' ],
			array_keys( $state['properties'] )
		);
		$this->assertSame(
			[ 'documentDigest', 'elementCount', 'maxDepth', 'widgetTypeCounts' ],
			$state['required']
		);
		$this->assertFalse( $state['additionalProperties'] );
		$this->assertSame( 'string', $state['properties']['documentDigest']['type'] );
		$this->assertSame( 'integer', $state['properties']['elementCount']['type'] );
		$this->assertSame( 'integer', $state['properties']['maxDepth']['type'] );
		$this->assertSame( 'object', $state['properties']['widgetTypeCounts']['type'] );
	}

	/**
	 * The apply branch keeps its other two members and stays closed, so a
	 * response carrying `plan` beside `target` still fails both branches.
	 */
	public function test_the_apply_branch_stays_closed_around_its_three_members(): void {
		$branch = ElementorWriteFields::outputSchema()['oneOf'][1];

		$this->assertSame( [ 'target', 'changed', 'state' ], array_keys( $branch['properties'] ) );
		$this->assertSame( [ 'target', 'changed', 'state' ], $branch['required'] );
		$this->assertFalse( $branch['additionalProperties'] );
	}

	/**
	 * A real apply payload selects exactly one branch of the union, so the
	 * refinement above is a claim about a response rather than about an array.
	 *
	 * The second half is the snapshot invariant in its declaration form: no
	 * DERIVED DISPLAY VALUE is declared here. `label`, `kind`, `depth` and
	 * `childCount` are the four the read side computes for display, none of
	 * them is recoverable from a stored row, and a write that promised one
	 * would be promising something a restore cannot put back.
	 */
	public function test_a_real_apply_payload_conforms_and_no_derived_display_field_is_declared(): void {
		$schema = ElementorWriteFields::outputSchema();

		$this->assertConformsToOutputSchema(
			[
				'target'  => 'elementor-document:42',
				'changed' => [ 'documentDigest' ],
				'state'   => [
					'documentDigest'   => str_repeat( 'a', 64 ),
					'elementCount'     => 3,
					'maxDepth'         => 2,
					'widgetTypeCounts' => [ 'e-heading' => 1 ],
				],
			],
			$schema
		);

		$declared = $schema['oneOf'][1]['properties']['state']['properties'];

		foreach ( [ 'label', 'kind', 'depth', 'childCount' ] as $derived ) {
			$this->assertArrayNotHasKey( $derived, $declared );
		}
	}
}
