<?php
/**
 * Tests for ElementorTreeDiff.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0035: the before-and-after element tree diff a preview carries.
 *
 * NO TEST DOUBLE APPEARS IN THIS FILE. The diff is pure, like the normalizer it
 * builds on, so there is nothing to fake and therefore nothing a fake could be
 * unfaithful about.
 *
 * THREE INVARIANTS CARRY MORE WEIGHT THAN THE REST:
 *
 *  1. A NODE WITH A NULL STORED `id` PRODUCES NO CHANGE ENTRY. `ElementorTree`
 *     reports `id: null` for an element that declares none — templates exported
 *     by older Elementor versions are full of them — because such an element
 *     cannot be addressed by any write. Keying it by `''` would match the wrong
 *     sibling, match all of them, or report a phantom no-op, which is an
 *     approved preview that does not describe the change applied.
 *  2. IT REFUSES RATHER THAN TRUNCATES. Both bounds are inherited by
 *     normalizing through `ElementorTree`, and a short tree that looks complete
 *     is exactly how a preview stops describing what it asks approval for.
 *  3. NO `settings` VALUE APPEARS ANYWHERE IN THE RESULT. The diff reports
 *     structure and identity; a whole-tree settings dump is arbitrary
 *     third-party data in a response.
 */
final class ElementorTreeDiffTest extends TestCase {

	private ElementorTreeDiff $diff;

	protected function setUp(): void {
		parent::setUp();
		$this->diff = new ElementorTreeDiff( new ElementorTree() );
	}

	/**
	 * One raw container node.
	 *
	 * @param string  $id       The element id.
	 * @param array[] $children The raw child nodes.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function container( string $id, array $children = [] ): array {
		return [
			'id'       => $id,
			'elType'   => 'container',
			'settings' => [ 'padding' => '20px' ],
			'elements' => $children,
		];
	}

	/**
	 * One raw widget node.
	 *
	 * @param string $id    The element id.
	 * @param string $type  The widget type.
	 * @param string $title The stored title setting.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function widget( string $id, string $type = 'heading', string $title = 'Some stored title' ): array {
		return [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => [ 'title' => $title ],
			'elements'   => [],
		];
	}

	public function test_an_unchanged_tree_reports_no_changes(): void {
		$tree = [ $this->container( 'c1', [ $this->widget( 'w1' ) ] ) ];

		$result = $this->diff->diff( $tree, $tree );

		$this->assertSame( [], $result['changes'] );
	}

	public function test_the_result_carries_normalized_before_and_after_node_lists(): void {
		$before = [ $this->container( 'c1' ) ];
		$after  = [ $this->container( 'c1', [ $this->widget( 'w1' ) ] ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'before', 'after', 'changes' ], array_keys( $result ) );
		$this->assertSame( 0, $result['before'][0]['childCount'] );
		$this->assertSame( 1, $result['after'][0]['childCount'] );
		$this->assertSame( 'w1', $result['after'][0]['children'][0]['id'] );
	}

	public function test_a_new_element_is_reported_as_added(): void {
		$before = [ $this->container( 'c1' ) ];
		$after  = [ $this->container( 'c1', [ $this->widget( 'w1' ) ] ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame(
			[
				[
					'op'        => 'added',
					'elementId' => 'w1',
					'fromPath'  => null,
					'toPath'    => '0.0',
				],
			],
			$result['changes']
		);
	}

	public function test_a_deleted_element_is_reported_as_removed(): void {
		$before = [ $this->container( 'c1', [ $this->widget( 'w1' ) ] ) ];
		$after  = [ $this->container( 'c1' ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame(
			[
				[
					'op'        => 'removed',
					'elementId' => 'w1',
					'fromPath'  => '0.0',
					'toPath'    => null,
				],
			],
			$result['changes']
		);
	}

	public function test_a_relocated_element_is_reported_as_moved(): void {
		$before = [
			$this->container( 'c1', [ $this->widget( 'w1' ) ] ),
			$this->container( 'c2' ),
		];
		$after  = [
			$this->container( 'c1' ),
			$this->container( 'c2', [ $this->widget( 'w1' ) ] ),
		];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame(
			[
				[
					'op'        => 'moved',
					'elementId' => 'w1',
					'fromPath'  => '0.0',
					'toPath'    => '1.0',
				],
			],
			$result['changes']
		);
	}

	/**
	 * INSERTING ONE CHILD MOVES NOBODY.
	 *
	 * A path is a chain of zero-based child positions, so a child inserted at
	 * position 0 renumbers every later sibling. Deciding `moved` by path equality
	 * therefore rendered a single insertion into a many-child section as "1
	 * added, N moved" — every one of those entries true about the path and false
	 * about the event, and an operator reads them as N relocations.
	 */
	public function test_inserting_a_child_at_the_front_moves_no_sibling(): void {
		$existing = [];
		for ( $index = 0; $index < 6; $index++ ) {
			$existing[] = $this->widget( 'w' . $index );
		}

		$before = [ $this->container( 'c1', $existing ) ];
		$after  = [ $this->container( 'c1', array_merge( [ $this->widget( 'new' ) ], $existing ) ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'added' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( 'new', $result['changes'][0]['elementId'] );
		$this->assertSame( '0.0', $result['changes'][0]['toPath'] );
	}

	/**
	 * Removing one child likewise moves nobody.
	 */
	public function test_removing_a_child_from_the_front_moves_no_sibling(): void {
		$existing = [];
		for ( $index = 0; $index < 6; $index++ ) {
			$existing[] = $this->widget( 'w' . $index );
		}

		$before = [ $this->container( 'c1', $existing ) ];
		$after  = [ $this->container( 'c1', array_slice( $existing, 1 ) ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'removed' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( 'w0', $result['changes'][0]['elementId'] );
	}

	/**
	 * A genuine reorder of two siblings still reports `moved` — for both.
	 *
	 * The narrowing must not cost the diff the event it exists to report.
	 */
	public function test_a_genuine_sibling_reorder_is_still_reported_as_moved(): void {
		$before = [ $this->container( 'c1', [ $this->widget( 'w1' ), $this->widget( 'w2' ) ] ) ];
		$after  = [ $this->container( 'c1', [ $this->widget( 'w2' ), $this->widget( 'w1' ) ] ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'moved', 'moved' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( [ 'w1', 'w2' ], array_column( $result['changes'], 'elementId' ) );
		$this->assertSame(
			[
				[
					'op'        => 'moved',
					'elementId' => 'w1',
					'fromPath'  => '0.0',
					'toPath'    => '0.1',
				],
				[
					'op'        => 'moved',
					'elementId' => 'w2',
					'fromPath'  => '0.1',
					'toPath'    => '0.0',
				],
			],
			$result['changes']
		);
	}

	/**
	 * A parent renumbered by an insertion above it moves none of its children.
	 *
	 * Every descendant path changes here, and none of it is movement.
	 */
	public function test_a_renumbered_parent_moves_none_of_its_children(): void {
		$section = $this->container( 'c1', [ $this->widget( 'w1' ), $this->widget( 'w2' ) ] );

		$before = [ $section ];
		$after  = [ $this->container( 'c0' ), $section ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'added' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( 'c0', $result['changes'][0]['elementId'] );
	}

	/**
	 * The known bound, recorded as a test rather than only as prose.
	 *
	 * A parent with no stored id cannot be keyed by one, so its children fall
	 * back to its raw path — and a renumbering of that IDLESS parent does still
	 * produce a `moved` nobody performed. No synthetic id is invented to hide
	 * it. This test exists so the limitation is visible if anyone changes the
	 * rule.
	 */
	public function test_a_renumbered_idless_parent_still_moves_its_children(): void {
		$wrapper = [ 'elements' => [ $this->widget( 'w1' ) ] ];

		$before = [ $wrapper ];
		$after  = [ $this->container( 'c0' ), $wrapper ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'moved', 'added' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( [ 'w1', 'c0' ], array_column( $result['changes'], 'elementId' ) );
	}

	/**
	 * Stored content nested past MAX_SETTINGS_DEPTH is refused, not truncated.
	 *
	 * `ElementorTree` bounds ELEMENT nesting and never walks `settings` — it
	 * drops them — so the depth of a settings array is caller-influenced input
	 * this class must bound itself. Truncating instead would compare a shortened
	 * value and report no change for a change.
	 */
	public function test_settings_nested_past_the_bound_are_refused_rather_than_truncated(): void {
		$settings = [ 'leaf' => 'value' ];
		for ( $index = 0; $index <= ElementorTreeDiff::MAX_SETTINGS_DEPTH; $index++ ) {
			$settings = [ 'nested' => $settings ];
		}

		$element             = $this->widget( 'w1' );
		$element['settings'] = $settings;

		try {
			$this->diff->diff( [ $element ], [] );
			$this->fail( 'Over-deep stored settings should have been refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	/**
	 * That refusal names no part of the stored tree either.
	 */
	public function test_a_settings_depth_refusal_names_no_part_of_the_stored_tree(): void {
		$settings = [ 'leaf' => 'Confidential note' ];
		for ( $index = 0; $index <= ElementorTreeDiff::MAX_SETTINGS_DEPTH; $index++ ) {
			$settings = [ 'nested' => $settings ];
		}

		$element             = $this->widget( 'w1' );
		$element['settings'] = $settings;

		try {
			$this->diff->diff( [ $element ], [] );
			$this->fail( 'Over-deep stored settings should have been refused.' );
		} catch ( OperationException $exception ) {
			$text = $exception->getMessage() . ' ' . (string) $exception->remediation;
			$this->assertStringNotContainsString( 'Confidential note', $text );
			$this->assertSame( 0, preg_match( '/\\\\|\/var\/|\/home\/|wp-content|password|secret/i', $text ) );
		}
	}

	/**
	 * Legitimately nested settings, well inside the bound, are compared normally.
	 */
	public function test_deeply_but_legitimately_nested_settings_are_still_compared(): void {
		$before_element             = $this->widget( 'w1' );
		$before_element['settings'] = [ 'a' => [ 'b' => [ 'c' => [ 'd' => 'Before' ] ] ] ];

		$after_element             = $this->widget( 'w1' );
		$after_element['settings'] = [ 'a' => [ 'b' => [ 'c' => [ 'd' => 'After' ] ] ] ];

		$result = $this->diff->diff( [ $before_element ], [ $after_element ] );

		$this->assertSame( [ 'updated' ], array_column( $result['changes'], 'op' ) );
	}

	/**
	 * A settings-only edit is `updated`.
	 *
	 * `ElementorTree` drops `settings`, so a diff computed from the normalized
	 * nodes alone would see nothing here and report `changes: []` for a widget
	 * settings write — a preview that describes no change for a change that is
	 * about to happen. The comparison is therefore made against the raw stored
	 * element, minus its children.
	 */
	public function test_a_settings_only_edit_is_reported_as_updated(): void {
		$before = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', 'Before' ) ] ) ];
		$after  = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', 'After' ) ] ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame(
			[
				[
					'op'        => 'updated',
					'elementId' => 'w1',
					'fromPath'  => '0.0',
					'toPath'    => '0.0',
				],
			],
			$result['changes']
		);
	}

	/**
	 * Key order in the stored element is not a change.
	 *
	 * `_elementor_data` is decoded JSON written by whatever last saved it, so
	 * two encoders can store the same element with its keys in a different
	 * order. Reporting that as `updated` would put a phantom change in front of
	 * an operator.
	 */
	public function test_reordered_stored_keys_are_not_a_change(): void {
		$before = [ $this->widget( 'w1' ) ];
		$after  = [
			[
				'settings'   => [ 'title' => 'Some stored title' ],
				'elements'   => [],
				'widgetType' => 'heading',
				'elType'     => 'widget',
				'id'         => 'w1',
			],
		];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [], $result['changes'] );
	}

	/**
	 * A move that also edits reports both, and loses neither.
	 */
	public function test_an_element_both_moved_and_edited_reports_both(): void {
		$before = [
			$this->container( 'c1', [ $this->widget( 'w1', 'heading', 'Before' ) ] ),
			$this->container( 'c2' ),
		];
		$after  = [
			$this->container( 'c1' ),
			$this->container( 'c2', [ $this->widget( 'w1', 'heading', 'After' ) ] ),
		];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [ 'moved', 'updated' ], array_column( $result['changes'], 'op' ) );
		$this->assertSame( [ 'w1', 'w1' ], array_column( $result['changes'], 'elementId' ) );
	}

	/**
	 * The old-template fixture: elements carrying neither `id` nor `elType`.
	 *
	 * Both sides differ in stored content, and the diff still reports nothing,
	 * because nothing here can be addressed by a write. A phantom entry naming
	 * an unaddressable element is a preview promising a change no operation
	 * could make.
	 */
	public function test_elements_with_no_stored_id_produce_no_change_entry(): void {
		$before = [
			[
				'settings' => [ 'title' => 'Before' ],
				'elements' => [ [ 'settings' => [ 'title' => 'Nested before' ] ] ],
			],
		];
		$after  = [
			[
				'settings' => [ 'title' => 'After' ],
				'elements' => [ [ 'settings' => [ 'title' => 'Nested after' ] ] ],
			],
		];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [], $result['changes'] );
		$this->assertNull( $result['before'][0]['id'] );
		$this->assertNull( $result['after'][0]['id'] );
	}

	/**
	 * An identifiable child of an unidentifiable parent is still reported.
	 *
	 * Skipping the whole branch would hide a real, addressable change behind an
	 * old template's untyped wrapper.
	 */
	public function test_an_addressable_child_of_an_unaddressable_parent_is_reported(): void {
		$before = [ [ 'elements' => [] ] ];
		$after  = [ [ 'elements' => [ $this->widget( 'w1' ) ] ] ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame(
			[
				[
					'op'        => 'added',
					'elementId' => 'w1',
					'fromPath'  => null,
					'toPath'    => '0.0',
				],
			],
			$result['changes']
		);
	}

	/**
	 * A repeated id is unaddressable too, and reports nothing.
	 *
	 * Elementor mints unique ids, but `_elementor_data` is writable by any
	 * plugin that can write post meta, and a duplicated id keyed naively would
	 * match the wrong sibling. Silence is the only honest answer.
	 */
	public function test_a_repeated_id_produces_no_change_entry(): void {
		$before = [ $this->widget( 'w1', 'heading', 'A' ), $this->widget( 'w1', 'heading', 'B' ) ];
		$after  = [ $this->widget( 'w1', 'heading', 'C' ), $this->widget( 'w1', 'heading', 'D' ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [], $result['changes'] );
	}

	/**
	 * An id that becomes ambiguous only on the other side reports nothing —
	 * and specifically does NOT report a removal.
	 *
	 * The element is plainly still there; it is merely no longer addressable,
	 * because something duplicated its id. `removed` would be the worst
	 * available answer: an operator would approve a preview describing a
	 * deletion that is not going to happen.
	 */
	public function test_an_id_duplicated_only_in_the_after_tree_reports_nothing(): void {
		$before = [ $this->widget( 'w1', 'heading', 'A' ) ];
		$after  = [ $this->widget( 'w1', 'heading', 'A' ), $this->widget( 'w1', 'heading', 'B' ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( [], $result['changes'] );
	}

	/**
	 * A non-array member shifts no path.
	 *
	 * `ElementorTree` skips one rather than refusing, so the diff must index
	 * positions the same way or every path after the stray value would name a
	 * different element than the normalized list holds.
	 */
	public function test_a_non_array_member_is_skipped_without_shifting_paths(): void {
		$before = [ $this->container( 'c1' ) ];
		$after  = [ 'not-an-element', $this->container( 'c1', [ $this->widget( 'w1' ) ] ) ];

		$result = $this->diff->diff( $before, $after );

		$this->assertSame( 'c1', $result['after'][0]['id'] );
		$this->assertSame( '0.0', $result['changes'][0]['toPath'] );
	}

	public function test_no_settings_value_appears_anywhere_in_the_result(): void {
		$before = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', 'Confidential note' ) ] ) ];
		$after  = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', 'Another note' ) ] ) ];

		$encoded = (string) json_encode( $this->diff->diff( $before, $after ) );

		$this->assertStringNotContainsString( 'Confidential note', $encoded );
		$this->assertStringNotContainsString( 'Another note', $encoded );
		$this->assertStringNotContainsString( 'settings', $encoded );
	}

	public function test_a_tree_over_the_node_bound_is_refused_rather_than_truncated(): void {
		$oversized = [];
		for ( $index = 0; $index <= ElementorTree::MAX_NODES; $index++ ) {
			$oversized[] = $this->widget( 'w' . $index );
		}

		try {
			$this->diff->diff( [], $oversized );
			$this->fail( 'An oversized tree should have been refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_a_tree_over_the_depth_bound_is_refused_rather_than_truncated(): void {
		$deep = $this->widget( 'leaf' );
		for ( $index = 0; $index <= ElementorTree::MAX_DEPTH; $index++ ) {
			$deep = $this->container( 'c' . $index, [ $deep ] );
		}

		try {
			$this->diff->diff( [ $deep ], [] );
			$this->fail( 'An over-deep tree should have been refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_a_refusal_names_no_part_of_the_stored_tree(): void {
		$oversized = [];
		for ( $index = 0; $index <= ElementorTree::MAX_NODES; $index++ ) {
			$oversized[] = $this->widget( 'w' . $index, 'heading', 'Confidential note' );
		}

		try {
			$this->diff->diff( [], $oversized );
			$this->fail( 'An oversized tree should have been refused.' );
		} catch ( OperationException $exception ) {
			$text = $exception->getMessage() . ' ' . (string) $exception->remediation;
			$this->assertStringNotContainsString( 'Confidential note', $text );
			$this->assertSame( 0, preg_match( '/\\\\|\/var\/|\/home\/|wp-content|password|secret/i', $text ) );
		}
	}
}
