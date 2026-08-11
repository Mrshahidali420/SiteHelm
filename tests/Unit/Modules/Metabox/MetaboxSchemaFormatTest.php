<?php
/**
 * Tests for MetaboxSchemaFormat, the module's field-definition projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Tests\TestCase;

/**
 * The projection's two jobs: report what a field declares, and stop descending.
 *
 * THE INVERSION IS THE FIRST THING UNDER TEST. A Meta Box field's `id` is the meta
 * key and its `name` is the human label (spec §5), which is backwards from what a
 * reader assumes, and a projection that swapped them would produce a response in
 * which every identifier a later operation takes as input is a display string. Every
 * assertion below names both members rather than one, so a swap fails rather than
 * shifting a value into a member nothing checks.
 *
 * THE DEPTH BOUND IS TESTED ON BOTH SIDES. A single assertion that "deep nesting
 * stops" passes just as happily for an off-by-one, and an off-by-one in a bound is
 * the defect a bound is least likely to be re-read for. So one test asserts that the
 * field at depth MAX_DEPTH is REPORTED and another that its children are NOT, from
 * the same fixture.
 *
 * TRUNCATION IS ASSERTED AS A REPORTED FACT rather than as an absence. A tree cut
 * off at the cap and a tree that was genuinely that shallow are the same shape, so
 * the only thing separating them is something the projection says out loud — which
 * is what `truncated` is, and what containsTruncation() lets a listing turn into a
 * notice.
 *
 * NO PROCESS ISOLATION AND NO DOUBLES. This class names no RWMB symbol and calls no
 * WordPress function; it is handed arrays and answers arrays, which is the property
 * that keeps the containment rule (spec §4) cheap to hold.
 */
final class MetaboxSchemaFormatTest extends TestCase {

	/**
	 * @return MetaboxSchemaFormat The projection under test.
	 */
	private function format(): MetaboxSchemaFormat {
		return new MetaboxSchemaFormat();
	}

	/**
	 * A chain of nested group fields, each holding the next.
	 *
	 * Built from the bottom up so the depth of the deepest field is exactly the
	 * argument, with the outermost field sitting at depth 0.
	 *
	 * @param int $levels How many fields the chain holds.
	 *
	 * @return array<string, mixed> The outermost field definition.
	 */
	private function chain( int $levels ): array {
		$field = [
			'id'   => 'level_' . ( $levels - 1 ),
			'name' => 'Level ' . ( $levels - 1 ),
			'type' => 'text',
		];

		for ( $index = $levels - 2; $index >= 0; $index-- ) {
			$field = [
				'id'     => 'level_' . $index,
				'name'   => 'Level ' . $index,
				'type'   => 'group',
				'fields' => [ $field ],
			];
		}

		return $field;
	}

	/**
	 * Walks a projected chain down to one depth.
	 *
	 * @param array<string, mixed> $projection The projected outermost field.
	 * @param int                  $depth      The depth to descend to.
	 *
	 * @return array<string, mixed>|null The projection at that depth, or null when it was not reported.
	 */
	private function descend( array $projection, int $depth ): ?array {
		for ( $index = 0; $index < $depth; $index++ ) {
			if ( ! isset( $projection['subFields'][0] ) || ! is_array( $projection['subFields'][0] ) ) {
				return null;
			}

			$projection = $projection['subFields'][0];
		}

		return $projection;
	}

	// ------------------------------------------------------------------ flat field

	public function test_a_flat_field_reports_the_meta_key_as_id_and_the_label_as_name(): void {
		$projected = $this->format()->formatField(
			[
				'id'   => 'reading_time',
				'name' => 'Reading time',
				'type' => 'number',
			]
		);

		$this->assertSame( 'reading_time', $projected['id'], 'A field id is the meta key.' );
		$this->assertSame( 'Reading time', $projected['name'], 'A field name is the human label.' );
		$this->assertSame( 'number', $projected['type'] );
		$this->assertFalse( $projected['required'] );
	}

	public function test_a_field_declaring_nothing_still_reports_the_four_required_members(): void {
		$projected = $this->format()->formatField( [] );

		$this->assertSame( [ 'id', 'name', 'type', 'required' ], array_keys( $projected ) );
		$this->assertSame( '', $projected['id'] );
		$this->assertSame( '', $projected['name'] );
		$this->assertSame( '', $projected['type'] );
	}

	/**
	 * Meta Box's group settings pass through the public `rwmb_meta_boxes` filter, so
	 * an array in a scalar position is an ordinary outcome and `(string)` on one is a
	 * fatal in the middle of a read.
	 */
	public function test_a_member_of_the_wrong_shape_reports_empty_rather_than_crashing(): void {
		$projected = $this->format()->formatField(
			[
				'id'   => [ 'not', 'a', 'key' ],
				'name' => 'Subtitle',
				'type' => 'text',
			]
		);

		$this->assertSame( '', $projected['id'] );
	}

	// -------------------------------------------------------------- optional members

	public function test_the_optional_members_a_field_declares_are_reported_under_their_output_names(): void {
		$projected = $this->format()->formatField(
			[
				'id'        => 'layout',
				'name'      => 'Layout',
				'type'      => 'select',
				'desc'      => 'How the section is laid out.',
				'std'       => 'wide',
				'required'  => true,
				'multiple'  => true,
				'min'       => 0,
				'max'       => 5,
				'post_type' => 'page',
				'taxonomy'  => [ 'category' ],
			]
		);

		$this->assertSame( 'How the section is laid out.', $projected['description'] );
		$this->assertSame( 'wide', $projected['defaultValue'] );
		$this->assertTrue( $projected['required'] );
		$this->assertTrue( $projected['multiple'] );
		$this->assertSame( 0, $projected['min'], 'A bound of zero is a declared bound, not an absence.' );
		$this->assertSame( 5, $projected['max'] );
		$this->assertSame( [ 'page' ], $projected['postType'], 'Meta Box accepts a bare string here.' );
		$this->assertSame( [ 'category' ], $projected['taxonomy'] );
	}

	/**
	 * A member the field does not declare is OMITTED rather than emitted empty:
	 * `description: ''` asserts that the field declares a description of nothing,
	 * which is a claim the site never made.
	 */
	public function test_a_member_the_field_does_not_declare_is_omitted(): void {
		$projected = $this->format()->formatField(
			[
				'id'   => 'subtitle',
				'name' => 'Subtitle',
				'type' => 'text',
				'desc' => '',
			]
		);

		$this->assertArrayNotHasKey( 'description', $projected );
		$this->assertArrayNotHasKey( 'defaultValue', $projected );
		$this->assertArrayNotHasKey( 'options', $projected );
	}

	public function test_a_selectable_field_reports_its_options_as_value_and_label_pairs(): void {
		$projected = $this->format()->formatField(
			[
				'id'      => 'size',
				'name'    => 'Size',
				'type'    => 'select',
				'options' => [
					1 => 'Wide',
					2 => 'Narrow',
				],
			]
		);

		$this->assertSame(
			[
				[
					'value' => '1',
					'label' => 'Wide',
				],
				[
					'value' => '2',
					'label' => 'Narrow',
				],
			],
			$projected['options'],
			'A map keyed by a numeric-looking value cannot survive as a JSON object at all.'
		);
	}

	public function test_a_clonable_field_is_reported_as_clonable(): void {
		$projected = $this->format()->formatField(
			[
				'id'    => 'links',
				'name'  => 'Links',
				'type'  => 'url',
				'clone' => true,
			]
		);

		$this->assertTrue( $projected['clonable'], 'Meta Box spells repetition `clone`; the response spells it clonable.' );
	}

	public function test_a_field_that_is_not_clonable_omits_the_flag_rather_than_reporting_it_false(): void {
		$projected = $this->format()->formatField(
			[
				'id'    => 'links',
				'name'  => 'Links',
				'type'  => 'url',
				'clone' => false,
			]
		);

		$this->assertArrayNotHasKey( 'clonable', $projected );
	}

	// ------------------------------------------------------------------- recursion

	public function test_a_group_field_reports_its_children(): void {
		$projected = $this->format()->formatField(
			[
				'id'     => 'hero',
				'name'   => 'Hero',
				'type'   => 'group',
				'fields' => [
					[
						'id'   => 'hero_title',
						'name' => 'Hero title',
						'type' => 'text',
					],
				],
			]
		);

		$this->assertSame( 'hero_title', $projected['subFields'][0]['id'] );
		$this->assertSame( 'Hero title', $projected['subFields'][0]['name'] );
		$this->assertArrayNotHasKey( 'truncated', $projected, 'Nothing was cut off here.' );
	}

	public function test_a_child_that_is_not_a_definition_is_dropped_rather_than_recursed_into(): void {
		$projected = $this->format()->formatField(
			[
				'id'     => 'hero',
				'name'   => 'Hero',
				'type'   => 'group',
				'fields' => [
					'not a field',
					[
						'id'   => 'hero_title',
						'name' => 'Hero title',
						'type' => 'text',
					],
				],
			]
		);

		$this->assertCount( 1, $projected['subFields'] );
		$this->assertSame( 'hero_title', $projected['subFields'][0]['id'] );
	}

	// --------------------------------------------------------------- the depth bound

	/**
	 * THE INCLUDED SIDE OF THE BOUNDARY. The field sitting at exactly MAX_DEPTH is
	 * reported, and asserted from the same fixture as the excluded side below so the
	 * two cannot drift apart.
	 */
	public function test_the_field_at_exactly_the_depth_cap_is_reported(): void {
		$projected = $this->format()->formatField( $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 3 ) );

		$deepest = $this->descend( $projected, MetaboxSchemaFormat::MAX_DEPTH );

		$this->assertIsArray( $deepest, 'The field at the cap is inside the bound, not outside it.' );
		$this->assertSame( 'level_' . MetaboxSchemaFormat::MAX_DEPTH, $deepest['id'] );
	}

	/**
	 * THE EXCLUDED SIDE. One deeper than the cap is not reported at all — and the
	 * pair of assertions is what makes an off-by-one fail rather than pass.
	 */
	public function test_the_field_one_past_the_depth_cap_is_not_reported(): void {
		$projected = $this->format()->formatField( $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 3 ) );

		$this->assertNull(
			$this->descend( $projected, MetaboxSchemaFormat::MAX_DEPTH + 1 ),
			'Recursion stops at the cap; one level deeper must not appear.'
		);
	}

	/**
	 * AND IT SAYS SO. A tree cut off at the cap is the same shape as a tree that was
	 * genuinely that shallow, so the cut has to be reported rather than inferred.
	 * `subFields` is ABSENT rather than empty at the cut, because an empty list is
	 * the claim "this field has no children" and at the cap that claim is false.
	 */
	public function test_a_tree_cut_off_at_the_cap_reports_the_truncation(): void {
		$projected = $this->format()->formatField( $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 3 ) );

		$deepest = $this->descend( $projected, MetaboxSchemaFormat::MAX_DEPTH );

		$this->assertIsArray( $deepest );
		$this->assertTrue( $deepest['truncated'] );
		$this->assertArrayNotHasKey( 'subFields', $deepest, 'An empty child list would claim the field has no children.' );
	}

	/**
	 * A field AT the cap that genuinely has no children reports no truncation, which
	 * is what keeps the flag a statement about the cut rather than about the depth.
	 */
	public function test_a_childless_field_at_the_cap_reports_no_truncation(): void {
		$projected = $this->format()->formatField( $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 1 ) );

		$deepest = $this->descend( $projected, MetaboxSchemaFormat::MAX_DEPTH );

		$this->assertIsArray( $deepest );
		$this->assertArrayNotHasKey( 'truncated', $deepest );
	}

	public function test_the_truncation_of_a_nested_field_is_findable_from_the_top(): void {
		$format = $this->format();

		$deep    = $format->formatField( $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 3 ) );
		$shallow = $format->formatField( $this->chain( 2 ) );

		$this->assertTrue( MetaboxSchemaFormat::containsTruncation( [ $shallow, $deep ] ) );
		$this->assertFalse( MetaboxSchemaFormat::containsTruncation( [ $shallow ] ) );
	}
}
