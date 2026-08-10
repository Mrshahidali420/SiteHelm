<?php
/**
 * Tests for ElementorStyleRemap.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorStyleRemap;
use SiteHelm\Tests\TestCase;

/**
 * Issue #97: local style classes are bound to the element id that owns them.
 *
 * A local class is stored as `e-<element_id>-<hash>` under the element's own
 * `styles` key and referenced from `settings.classes.value`. Re-iding an element
 * without rewriting BOTH sides leaves the copy pointing at the original's class,
 * so editing either one silently restyles the other — style bleed, which is what
 * issue #97 reported.
 *
 * THE STRONGEST TEST IN THIS FILE IS
 * test_a_remapped_subtree_holds_no_reference_to_any_source_element_id. It is
 * written as a whole-subtree search rather than a per-key assertion on purpose:
 * a per-key assertion only catches the places someone thought to look, and the
 * defect is precisely a place nobody looked. It is also the mutation probe — if
 * the `settings.classes.value` rewrite is removed, this test must fail.
 *
 * GLOBAL CLASSES ARE OUT OF SCOPE BY DESIGN. `g-` prefixed classes live in
 * Elementor's own repository, not in `_elementor_data`, so no write in this
 * phase can touch one and no document snapshot captures one.
 */
final class ElementorStyleRemapTest extends TestCase {

	private ElementorStyleRemap $remap;

	protected function setUp(): void {
		parent::setUp();
		$this->remap = new ElementorStyleRemap();
	}

	/**
	 * One raw element owning one local class and referencing it, plus a global
	 * class and a key no part of this codebase knows about.
	 *
	 * @param string  $id       The stored element id.
	 * @param array[] $children The raw child nodes.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function styled( string $id, array $children = [] ): array {
		$class = 'e-' . $id . '-9f3a10';

		return [
			'id'                     => $id,
			'elType'                 => 'container',
			'styles'                 => [
				$class => [
					'id'       => $class,
					'label'    => 'local',
					'type'     => 'class',
					'variants' => [ [ 'props' => [ 'color' => 'red' ] ] ],
				],
			],
			'settings'               => [
				'classes' => [
					'$$type' => 'classes',
					'value'  => [ $class, 'g-shared-brand' ],
				],
			],
			'zzz_unknown_vendor_key' => [ 'written by' => 'some third-party plugin' ],
			'elements'               => $children,
		];
	}

	public function test_a_styles_key_is_rewritten_to_the_new_element_id(): void {
		$result = $this->remap->remap( $this->styled( 'root111' ), [ 'root111' => 'new1111' ] );

		$this->assertSame( [ 'e-new1111-9f3a10' ], array_keys( $result['styles'] ) );
	}

	public function test_the_style_object_s_own_id_is_rewritten_with_its_key(): void {
		$result = $this->remap->remap( $this->styled( 'root111' ), [ 'root111' => 'new1111' ] );

		$this->assertSame( 'e-new1111-9f3a10', $result['styles']['e-new1111-9f3a10']['id'] );
	}

	public function test_a_settings_classes_reference_is_rewritten(): void {
		$result = $this->remap->remap( $this->styled( 'root111' ), [ 'root111' => 'new1111' ] );

		$this->assertSame(
			[ 'e-new1111-9f3a10', 'g-shared-brand' ],
			$result['settings']['classes']['value']
		);
	}

	public function test_a_remapped_subtree_holds_no_reference_to_any_source_element_id(): void {
		// The real duplicate pipeline: re-id the subtree, then remap its local
		// classes with the map that re-iding produced. Asserted over the whole
		// serialized copy rather than key by key, because the defect issue #97
		// reported is a reference in a place nobody thought to assert on.
		$subtree    = $this->styled( 'root111', [ $this->styled( 'kid2222' ) ] );
		$reassigned = ( new ElementorIdMint() )->reassign( $subtree, "op\0" . "7\0" . "fp\0" . 'payload', [] );

		$result = $this->remap->remap( $reassigned['tree'], $reassigned['map'] );

		$serialized = serialize( $result );
		$this->assertStringNotContainsString( 'root111', $serialized );
		$this->assertStringNotContainsString( 'kid2222', $serialized );
	}

	public function test_a_global_class_is_left_alone(): void {
		$result = $this->remap->remap( $this->styled( 'root111' ), [ 'root111' => 'new1111' ] );

		$this->assertContains( 'g-shared-brand', $result['settings']['classes']['value'] );
	}

	public function test_a_class_owned_by_an_element_outside_the_map_is_left_alone(): void {
		$subtree                                   = $this->styled( 'root111' );
		$subtree['settings']['classes']['value'][] = 'e-elsewhere-77bb';

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertContains( 'e-elsewhere-77bb', $result['settings']['classes']['value'] );
	}

	public function test_a_descendant_s_styles_and_references_are_remapped_too(): void {
		$subtree = $this->styled( 'root111', [ $this->styled( 'kid2222' ) ] );

		$result = $this->remap->remap(
			$subtree,
			[
				'root111' => 'new1111',
				'kid2222' => 'new2222',
			]
		);

		$child = $result['elements'][0];
		$this->assertSame( [ 'e-new2222-9f3a10' ], array_keys( $child['styles'] ) );
		$this->assertSame( [ 'e-new2222-9f3a10', 'g-shared-brand' ], $child['settings']['classes']['value'] );
	}

	public function test_a_space_separated_class_string_is_rewritten(): void {
		$subtree                                 = $this->styled( 'root111' );
		$subtree['settings']['classes']['value'] = 'e-root111-9f3a10 g-shared-brand';

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( 'e-new1111-9f3a10 g-shared-brand', $result['settings']['classes']['value'] );
	}

	public function test_every_key_it_does_not_touch_survives(): void {
		$result = $this->remap->remap( $this->styled( 'root111' ), [ 'root111' => 'new1111' ] );

		$this->assertSame( [ 'written by' => 'some third-party plugin' ], $result['zzz_unknown_vendor_key'] );
		$this->assertSame( 'container', $result['elType'] );
		$this->assertSame( 'class', $result['styles']['e-new1111-9f3a10']['type'] );
		$this->assertSame(
			[ [ 'props' => [ 'color' => 'red' ] ] ],
			$result['styles']['e-new1111-9f3a10']['variants']
		);
		$this->assertSame( 'classes', $result['settings']['classes']['$$type'] );
	}

	public function test_an_element_with_no_styles_and_no_classes_is_returned_unchanged(): void {
		$plain = [
			'id'       => 'root111',
			'elType'   => 'widget',
			'settings' => [ 'title' => 'A stored title' ],
		];

		$this->assertSame( $plain, $this->remap->remap( $plain, [ 'root111' => 'new1111' ] ) );
	}

	public function test_an_empty_map_changes_nothing(): void {
		$subtree = $this->styled( 'root111', [ $this->styled( 'kid2222' ) ] );

		$this->assertSame( $subtree, $this->remap->remap( $subtree, [] ) );
	}

	public function test_a_non_array_styles_value_is_left_alone(): void {
		$subtree           = $this->styled( 'root111' );
		$subtree['styles'] = 'not-a-style-map';

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( 'not-a-style-map', $result['styles'] );
	}

	public function test_a_non_string_class_reference_is_left_alone(): void {
		$subtree                                 = $this->styled( 'root111' );
		$subtree['settings']['classes']['value'] = [ 17, 'e-root111-9f3a10' ];

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( [ 17, 'e-new1111-9f3a10' ], $result['settings']['classes']['value'] );
	}

	public function test_a_class_binding_that_is_neither_a_list_nor_a_string_is_left_alone(): void {
		// A reference this class does not understand is not one it may rewrite.
		$subtree                                 = $this->styled( 'root111' );
		$subtree['settings']['classes']['value'] = 17;

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( 17, $result['settings']['classes']['value'] );
	}

	public function test_a_local_class_name_with_no_owner_segment_is_left_alone(): void {
		$subtree                                 = $this->styled( 'root111' );
		$subtree['settings']['classes']['value'] = [ 'e-nohash' ];

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( [ 'e-nohash' ], $result['settings']['classes']['value'] );
	}

	public function test_a_non_array_child_list_member_is_left_alone(): void {
		$subtree             = $this->styled( 'root111' );
		$subtree['elements'] = [ 'not-an-element' ];

		$result = $this->remap->remap( $subtree, [ 'root111' => 'new1111' ] );

		$this->assertSame( [ 'not-an-element' ], $result['elements'] );
	}
}
