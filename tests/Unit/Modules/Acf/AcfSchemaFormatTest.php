<?php
/**
 * Tests for AcfSchemaFormat.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfSchemaFormat;
use SiteHelm\Tests\TestCase;

/**
 * The recursive field-definition projection REQ-0044 emits.
 *
 * TWO RULES THIS FILE EXISTS TO PIN.
 *
 * FIRST, THE OPTIONAL KEYS ARE OMITTED RATHER THAN EMITTED EMPTY. A field
 * definition in ACF is a sparse array — a text field carries no `choices`, a
 * select carries no `return_format` in the sense an image field means it — and
 * emitting every key with a null or `''` beside it would tell a client the field
 * declares a return format of nothing, which is a different claim from
 * declaring none. The pair of tests below holds both directions, and the pair is
 * what makes either meaningful: mutate the omission into an unconditional emit
 * and the first fails while the second keeps passing.
 *
 * SECOND, THE DEPTH CAP TRUNCATES BY OMITTING `subFields` ENTIRELY. A repeater
 * inside a group inside a flexible-content layout nests as deep as an
 * administrator cares to build, and an uncapped recursion here is an uncapped
 * response. At the cap the projection carries no `subFields` key at all rather
 * than an empty list, because an empty list is a claim — "this field has no
 * sub-fields" — and at the cap that claim is false.
 *
 * NO ACF FUNCTION IS STUBBED HERE, and that is not an oversight. AcfSchemaFormat
 * is handed field definition arrays and reads nothing else; an ACF call
 * appearing on this path would surface as an undefined-function error in this
 * test, which is the earliest place it can be caught and the enforcement of the
 * containment rule that says only AcfPresence and AcfApi may name an ACF symbol.
 */
final class AcfSchemaFormatTest extends TestCase {

	/**
	 * The projection under test.
	 */
	private AcfSchemaFormat $format;

	protected function setUp(): void {
		parent::setUp();
		$this->format = new AcfSchemaFormat();
	}

	/**
	 * One ACF field definition array, in the sparse shape ACF really stores.
	 *
	 * @param array<string, mixed> $overrides Members to add or replace.
	 *
	 * @return array<string, mixed> The field definition.
	 */
	private function fieldDefinition( array $overrides = [] ): array {
		return array_merge(
			[
				'key'   => 'field_subtitle',
				'name'  => 'subtitle',
				'label' => 'Subtitle',
				'type'  => 'text',
			],
			$overrides
		);
	}

	/**
	 * A chain of nested single-sub-field groups, `$levels` deep in total.
	 *
	 * Level 0 is the outermost field; the deepest level carries no `sub_fields`.
	 *
	 * @param int $levels How many levels the chain has.
	 *
	 * @return array<string, mixed> The outermost field definition.
	 */
	private function nestedChain( int $levels ): array {
		$field = $this->fieldDefinition(
			[
				'key'   => 'field_level_' . ( $levels - 1 ),
				'name'  => 'level_' . ( $levels - 1 ),
				'label' => 'Level ' . ( $levels - 1 ),
			]
		);

		for ( $level = $levels - 2; $level >= 0; $level-- ) {
			$field = $this->fieldDefinition(
				[
					'key'        => 'field_level_' . $level,
					'name'       => 'level_' . $level,
					'label'      => 'Level ' . $level,
					'type'       => 'group',
					'sub_fields' => [ $field ],
				]
			);
		}

		return $field;
	}

	// ------------------------------------------------------ the always-present five

	public function test_a_minimal_field_projects_exactly_the_five_always_present_keys(): void {
		$projection = $this->format->field( $this->fieldDefinition() );

		$this->assertSame(
			[ 'key', 'name', 'label', 'type', 'required' ],
			array_keys( $projection ),
			'The five always-present members are emitted in their declared order, and nothing a sparse field did not declare joins them.'
		);
		$this->assertSame( 'field_subtitle', $projection['key'] );
		$this->assertSame( 'subtitle', $projection['name'] );
		$this->assertSame( 'Subtitle', $projection['label'] );
		$this->assertSame( 'text', $projection['type'] );
		$this->assertFalse( $projection['required'] );
	}

	/**
	 * ACF stores `required` as `0` or `1`, never as a PHP boolean, so the
	 * projection is where the two spellings become one. A client comparing
	 * `required === true` against a stored `1` would find every required field
	 * optional.
	 */
	public function test_acfs_numeric_required_flag_becomes_a_real_boolean(): void {
		$this->assertTrue( $this->format->field( $this->fieldDefinition( [ 'required' => 1 ] ) )['required'] );
		$this->assertFalse( $this->format->field( $this->fieldDefinition( [ 'required' => 0 ] ) )['required'] );
	}

	/**
	 * A field definition is third-party writable through `acf/load_field`, so a
	 * non-scalar `label` is a real outcome rather than a theoretical one.
	 * `(string)` on an array is a fatal.
	 */
	public function test_a_non_scalar_always_present_member_becomes_an_empty_string_rather_than_being_cast(): void {
		$projection = $this->format->field( $this->fieldDefinition( [ 'label' => [ 'Subtitle' ] ] ) );

		$this->assertSame( '', $projection['label'] );
	}

	// --------------------------------------------------------- the optional keys

	public function test_an_optional_key_the_field_does_not_declare_is_omitted(): void {
		$projection = $this->format->field( $this->fieldDefinition() );

		$this->assertArrayNotHasKey( 'returnFormat', $projection );
		$this->assertArrayNotHasKey( 'choices', $projection );
		$this->assertArrayNotHasKey( 'instructions', $projection );
	}

	/**
	 * The mirror of the omission test, and the reason it can fail. If the
	 * projection emitted no optional key under any circumstance the assertion
	 * above would pass while describing nothing.
	 */
	public function test_an_optional_key_the_field_does_declare_is_present_under_its_camel_case_name(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'          => 'image',
					'instructions'  => 'Upload a wide image.',
					'return_format' => 'array',
					'default_value' => 'placeholder',
					'min'           => 200,
					'max'           => '1200',
					'multiple'      => 1,
					'allow_null'    => 1,
				]
			)
		);

		$this->assertSame( 'Upload a wide image.', $projection['instructions'] );
		$this->assertSame( 'array', $projection['returnFormat'] );
		$this->assertSame( 'placeholder', $projection['defaultValue'] );
		$this->assertSame( 200, $projection['min'] );
		$this->assertSame( '1200', $projection['max'] );
		$this->assertTrue( $projection['multiple'] );
		$this->assertTrue( $projection['allowNull'] );

		// The snake_case source spellings must not leak into the response beside
		// their camelCase projections.
		$this->assertArrayNotHasKey( 'return_format', $projection );
		$this->assertArrayNotHasKey( 'allow_null', $projection );
	}

	public function test_declared_choices_project_as_value_label_pairs_and_drop_unreadable_members(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'    => 'select',
					'choices' => [
						'red'  => 'Red',
						'blue' => 'Blue',
						'bad'  => [ 'not a label' ],
					],
				]
			)
		);

		$this->assertSame(
			[
				[
					'value' => 'red',
					'label' => 'Red',
				],
				[
					'value' => 'blue',
					'label' => 'Blue',
				],
			],
			$projection['choices'],
			'A choice whose label is not scalar is dropped rather than cast; (string) on an array is a fatal.'
		);
	}

	/**
	 * THE REASON choices IS A LIST RATHER THAN AN OBJECT, pinned as behaviour.
	 *
	 * `[ 1 => 'Yes', 2 => 'No' ]` is the commonest choice set ACF stores, and a
	 * projection keyed by the stored value cannot survive it: PHP re-casts a
	 * numeric-string key back to an integer, the array becomes a list, and
	 * json_encode emits `["Yes","No"]` — an array where the schema published an
	 * object, with the stored values gone entirely. The pair form keeps the value
	 * as data instead of as a key, so there is no key for PHP to re-type.
	 */
	public function test_numeric_choice_keys_keep_their_values_and_encode_as_objects(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'    => 'select',
					'choices' => [
						1 => 'Yes',
						2 => 'No',
					],
				]
			)
		);

		$this->assertSame(
			[
				[
					'value' => '1',
					'label' => 'Yes',
				],
				[
					'value' => '2',
					'label' => 'No',
				],
			],
			$projection['choices']
		);

		$this->assertSame(
			'[{"value":"1","label":"Yes"},{"value":"2","label":"No"}]',
			(string) json_encode( $projection['choices'] ),
			'The encoded form is what the client sees, and it must carry the stored values.'
		);
	}

	/**
	 * A choices map whose every label is unreadable omits the member rather than
	 * emitting an empty list, which would claim the field offers no choices at all.
	 */
	public function test_choices_whose_labels_are_all_unreadable_omit_the_member(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'    => 'select',
					'choices' => [ 'bad' => [ 'not a label' ] ],
				]
			)
		);

		$this->assertArrayNotHasKey( 'choices', $projection );
	}

	/**
	 * ZERO AND FALSE ARE DECLARATIONS. A number field really does declare
	 * `min => 0`, and a true_false field really does default to `false`. Reading
	 * those with emptiness dropped a stated bound and a stated default out of the
	 * response, and made the projection report that the field declared neither.
	 */
	public function test_a_zero_bound_and_a_false_default_are_reported_rather_than_dropped(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'          => 'number',
					'min'           => 0,
					'max'           => '0',
					'default_value' => 0,
				]
			)
		);

		$this->assertSame( 0, $projection['min'] );
		$this->assertSame( '0', $projection['max'] );
		$this->assertSame( 0, $projection['defaultValue'] );

		$false_default = $this->format->field(
			$this->fieldDefinition(
				[
					'type'          => 'true_false',
					'default_value' => false,
				]
			)
		);

		$this->assertArrayHasKey( 'defaultValue', $false_default );
		$this->assertFalse( $false_default['defaultValue'] );
	}

	/**
	 * The boundary of the rule above: a stored null is still no declaration, and a
	 * falsy FLAG is still no declaration, because 0 is not a raised flag.
	 */
	public function test_a_null_bound_and_a_falsy_flag_remain_undeclared(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'min'           => null,
					'default_value' => null,
					'multiple'      => 0,
					'allow_null'    => false,
				]
			)
		);

		$this->assertSame( [ 'key', 'name', 'label', 'type', 'required' ], array_keys( $projection ) );
	}

	/**
	 * ACF stores `post_type` and `taxonomy` as lists, but a filter may leave a
	 * bare string, so both shapes project to the one shape the schema declares.
	 */
	public function test_post_type_and_taxonomy_project_as_lists_whichever_shape_acf_stored(): void {
		$from_list = $this->format->field(
			$this->fieldDefinition(
				[
					'type'      => 'relationship',
					'post_type' => [ 'page', 'post' ],
					'taxonomy'  => [ 'category' ],
				]
			)
		);

		$from_scalar = $this->format->field(
			$this->fieldDefinition(
				[
					'type'      => 'relationship',
					'post_type' => 'page',
				]
			)
		);

		$this->assertSame( [ 'page', 'post' ], $from_list['postType'] );
		$this->assertSame( [ 'category' ], $from_list['taxonomy'] );
		$this->assertSame( [ 'page' ], $from_scalar['postType'] );
	}

	/**
	 * An empty declaration is not a declaration.
	 *
	 * ACF writes `'choices' => []`, `'instructions' => ''` and `'multiple' => 0`
	 * into field definitions it saves from the editor whether the administrator
	 * filled them in or not, so treating "present in the array" as "declared"
	 * would put an empty key on nearly every field in the response.
	 */
	public function test_an_optional_key_declared_empty_is_treated_as_not_declared(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'instructions'  => '',
					'choices'       => [],
					'multiple'      => 0,
					'return_format' => null,
				]
			)
		);

		$this->assertSame( [ 'key', 'name', 'label', 'type', 'required' ], array_keys( $projection ) );
	}

	// ------------------------------------------------------------- the recursion

	public function test_sub_fields_recurse_two_levels_deep_under_their_camel_case_name(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'key'        => 'field_slides',
					'name'       => 'slides',
					'label'      => 'Slides',
					'type'       => 'repeater',
					'sub_fields' => [
						$this->fieldDefinition(
							[
								'key'        => 'field_slide',
								'name'       => 'slide',
								'label'      => 'Slide',
								'type'       => 'group',
								'sub_fields' => [
									$this->fieldDefinition(
										[
											'key'   => 'field_caption',
											'name'  => 'caption',
											'label' => 'Caption',
										]
									),
								],
							]
						),
					],
				]
			)
		);

		$this->assertArrayNotHasKey( 'sub_fields', $projection );
		$this->assertCount( 1, $projection['subFields'] );
		$this->assertSame( 'field_slide', $projection['subFields'][0]['key'] );
		$this->assertSame( 'field_caption', $projection['subFields'][0]['subFields'][0]['key'] );
		$this->assertArrayNotHasKey(
			'subFields',
			$projection['subFields'][0]['subFields'][0],
			'A leaf declares no sub-fields, so it carries no subFields key.'
		);
	}

	/**
	 * A `sub_fields` member that is not an array at all.
	 *
	 * Reachable through `acf/load_field`, which any plugin may hook. Dropping the
	 * member keeps the rest of the group readable; recursing into it would be a
	 * TypeError in the middle of a read.
	 */
	public function test_a_sub_field_that_is_not_an_array_is_dropped_rather_than_crashing(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'       => 'group',
					'sub_fields' => [ 'not a field', $this->fieldDefinition( [ 'key' => 'field_ok' ] ) ],
				]
			)
		);

		$this->assertCount( 1, $projection['subFields'] );
		$this->assertSame( 'field_ok', $projection['subFields'][0]['key'] );
	}

	public function test_flexible_content_layouts_project_with_their_own_sub_fields(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'key'     => 'field_sections',
					'name'    => 'sections',
					'label'   => 'Sections',
					'type'    => 'flexible_content',
					'layouts' => [
						'layout_hero' => [
							'key'        => 'layout_hero',
							'name'       => 'hero',
							'label'      => 'Hero',
							'sub_fields' => [
								$this->fieldDefinition(
									[
										'key'   => 'field_headline',
										'name'  => 'headline',
										'label' => 'Headline',
									]
								),
							],
						],
					],
				]
			)
		);

		$this->assertArrayNotHasKey( 'layouts_source', $projection );
		$this->assertCount( 1, $projection['layouts'] );

		$layout = $projection['layouts'][0];

		// ACF keys the layouts map by layout key; the projection is a LIST, because
		// a map keyed by administrator-chosen text cannot be described by a schema
		// that sets additionalProperties false.
		$this->assertSame( [ 'key', 'name', 'label', 'subFields' ], array_keys( $layout ) );
		$this->assertSame( 'layout_hero', $layout['key'] );
		$this->assertSame( 'hero', $layout['name'] );
		$this->assertSame( 'Hero', $layout['label'] );
		$this->assertSame( 'field_headline', $layout['subFields'][0]['key'] );
	}

	/**
	 * A layout declaring no sub-fields still projects, with an empty list.
	 *
	 * Unlike `subFields` on a field, `subFields` on a layout is always emitted:
	 * the layout schema requires it, and a layout genuinely has a sub-field list
	 * that happens to be empty rather than no such concept.
	 */
	public function test_a_layout_without_sub_fields_projects_an_empty_list(): void {
		$projection = $this->format->field(
			$this->fieldDefinition(
				[
					'type'    => 'flexible_content',
					'layouts' => [ [ 'key' => 'layout_blank' ], 'not a layout' ],
				]
			)
		);

		$this->assertCount( 1, $projection['layouts'], 'A layout that is not an array is dropped.' );
		$this->assertSame( [], $projection['layouts'][0]['subFields'] );
	}

	// -------------------------------------------------------------- the depth cap

	/**
	 * THE DEPTH-CAP TEST. A chain twelve levels deep is emitted down to the cap
	 * and no further, and the projection AT the cap carries no `subFields` key at
	 * all — not an empty one, which would claim the field has no sub-fields when
	 * it has several.
	 *
	 * Depth is zero-based, so the outermost field sits at depth 0 and the
	 * deepest projected field sits at depth MAX_DEPTH. Raise the cap check from
	 * `>=` to `>` and this test finds one level too many; remove the check
	 * entirely and it finds twelve.
	 */
	public function test_a_chain_deeper_than_the_cap_stops_at_the_cap_and_omits_sub_fields_there(): void {
		$projection = $this->format->field( $this->nestedChain( 12 ) );

		$node  = $projection;
		$depth = 0;

		while ( isset( $node['subFields'][0] ) ) {
			$node = $node['subFields'][0];
			++$depth;
		}

		$this->assertSame(
			AcfFields::MAX_DEPTH,
			$depth,
			'The deepest projected field must sit at exactly MAX_DEPTH; the source chain runs deeper.'
		);
		$this->assertSame( 'field_level_' . AcfFields::MAX_DEPTH, $node['key'] );
		$this->assertArrayNotHasKey(
			'subFields',
			$node,
			'At the cap the projection carries no subFields key; an empty list would claim the field has none.'
		);
	}

	/**
	 * The mirror of the cap test. A chain that fits emits every level, so the cap
	 * is a bound that is really reached rather than an unconditional truncation.
	 */
	public function test_a_chain_that_fits_within_the_cap_is_emitted_whole(): void {
		$projection = $this->format->field( $this->nestedChain( 3 ) );

		$this->assertSame( 'field_level_1', $projection['subFields'][0]['key'] );
		$this->assertSame( 'field_level_2', $projection['subFields'][0]['subFields'][0]['key'] );
		$this->assertArrayNotHasKey( 'subFields', $projection['subFields'][0]['subFields'][0] );
	}

	/**
	 * The cap bounds layouts too, and by the same count.
	 *
	 * Flexible content is the field type most likely to reach the cap, because
	 * every layout is a nesting level an administrator adds without thinking of
	 * it as one.
	 */
	public function test_the_cap_bounds_layout_sub_fields_as_well(): void {
		$deep = $this->format->field( $this->nestedChain( 12 ), AcfFields::MAX_DEPTH );

		$this->assertArrayNotHasKey( 'subFields', $deep );
		$this->assertArrayNotHasKey( 'layouts', $this->format->field(
			$this->fieldDefinition(
				[
					'type'    => 'flexible_content',
					'layouts' => [ [ 'key' => 'layout_hero' ] ],
				]
			),
			AcfFields::MAX_DEPTH
		) );
	}
}
