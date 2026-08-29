<?php
/**
 * Tests for the two REQ-0103 primitives on ElementorTreeEdit.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Tests\TestCase;

/**
 * `childIds()` and `reorder()`, the two tree primitives REQ-0103 added.
 *
 * THE WHOLE-PERMUTATION RULE LIVES HERE, not in `ElementorElementsReorder`, so
 * this is where it has to be pinned. `ElementorGlobalClassesReorder` settled the
 * reasoning for the module and this file is that reasoning applied to a
 * document: a partial order has to invent a policy for the children the caller
 * did not mention, and a caller working from a stale read is better served by a
 * loud failure than by a silent rule about where its missing siblings went.
 *
 * NO WORDPRESS AT ALL. `ElementorTreeEdit` is a pure function over arrays, and
 * every case below is a literal tree in and a literal expectation out. There is
 * nothing to double, which is why the properties these cases claim are
 * properties of the code rather than of a stand-in.
 */
final class ElementorTreeEditReorderTest extends TestCase {

	private ElementorTreeEdit $edit;

	protected function setUp(): void {
		parent::setUp();

		$this->edit = new ElementorTreeEdit();
	}

	// ------------------------------------------------------- childIds

	/**
	 * The document's top level is addressed by a null parent.
	 */
	public function test_a_null_parent_names_the_documents_top_level(): void {
		$this->assertSame( [ 'c111111', 'c222222' ], $this->edit->childIds( $this->tree(), null ) );
	}

	/**
	 * One element's direct children, in stored order, and NOT its grandchildren.
	 */
	public function test_only_the_direct_children_are_reported_and_in_stored_order(): void {
		$this->assertSame(
			[ 'w111111', 'w222222', 'w333333' ],
			$this->edit->childIds( $this->tree(), 'c111111' )
		);
	}

	/**
	 * A parent that is not in the tree answers null, which is how the operation
	 * above tells "this element is not here" from "this element has no children".
	 */
	public function test_a_parent_that_is_not_in_the_tree_answers_null(): void {
		$this->assertNull( $this->edit->childIds( $this->tree(), 'c999999' ) );
	}

	/**
	 * A parent that stores no `elements` key answers an EMPTY LIST, not null.
	 *
	 * The distinction is the whole reason the return type is nullable: an
	 * element Elementor stored without a children key is present and empty, and
	 * reporting it as absent would make the reorder operation refuse a parent
	 * that is really there.
	 */
	public function test_a_parent_storing_no_children_key_answers_an_empty_list(): void {
		$tree = [
			[
				'id'     => 'c111111',
				'elType' => 'container',
			],
		];

		$this->assertSame( [], $this->edit->childIds( $tree, 'c111111' ) );
	}

	/**
	 * AN IDLESS CHILD IS REPORTED AS NULL RATHER THAN SKIPPED.
	 *
	 * This is the case the whole nullable element type exists for. A list that
	 * quietly dropped the children carrying no identifier would let a caller
	 * name every child it can see, pass the completeness check in `reorder()`,
	 * and permute a list that is missing a sibling the document still holds —
	 * which would move that sibling somewhere nobody asked for.
	 *
	 * Mutation-proved: `continue`-ing past an idless child instead of appending
	 * null turns this case red and leaves every other case in this file green.
	 */
	public function test_a_child_storing_no_identifier_is_reported_as_null_not_dropped(): void {
		$tree = [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					[
						'id'     => 'w111111',
						'elType' => 'widget',
					],
					[ 'elType' => 'widget' ],
					[
						'id'     => 'w222222',
						'elType' => 'widget',
					],
				],
			],
		];

		$this->assertSame( [ 'w111111', null, 'w222222' ], $this->edit->childIds( $tree, 'c111111' ) );
	}

	/**
	 * A member of the child list that is not an element at all is not counted as
	 * a child, because it is not one — it is whatever else the row holds.
	 */
	public function test_a_member_that_is_not_an_element_is_not_reported_as_a_child(): void {
		$tree = [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					'stray',
					[
						'id'     => 'w111111',
						'elType' => 'widget',
					],
				],
			],
		];

		$this->assertSame( [ 'w111111' ], $this->edit->childIds( $tree, 'c111111' ) );
	}

	// ------------------------------------------------------- reorder

	/**
	 * A whole permutation is applied, and every element keeps everything it held.
	 *
	 * The expectation is a LITERAL LIST rather than a recomputation of what the
	 * reorder should have produced, so the test cannot drift together with the
	 * code it checks.
	 */
	public function test_a_whole_permutation_puts_the_children_in_the_named_order(): void {
		$reordered = $this->edit->reorder( $this->tree(), 'c111111', [ 'w333333', 'w111111', 'w222222' ] );

		$this->assertSame( [ 'w333333', 'w111111', 'w222222' ], $this->edit->childIds( $reordered, 'c111111' ) );
		$this->assertSame(
			'Second heading',
			$reordered[0]['elements'][2]['settings']['title'],
			'A reordered element must keep everything it held.'
		);
	}

	/**
	 * The top level is reorderable by naming a null parent.
	 */
	public function test_the_documents_top_level_is_reorderable(): void {
		$reordered = $this->edit->reorder( $this->tree(), null, [ 'c222222', 'c111111' ] );

		$this->assertSame( [ 'c222222', 'c111111' ], $this->edit->childIds( $reordered, null ) );
	}

	/**
	 * Nothing outside the named parent moves.
	 */
	public function test_the_rest_of_the_document_is_left_exactly_as_it_was(): void {
		$tree      = $this->tree();
		$reordered = $this->edit->reorder( $tree, 'c111111', [ 'w333333', 'w111111', 'w222222' ] );

		$this->assertSame( $tree[1], $reordered[1] );
		$this->assertSame( [ 'w444444' ], $this->edit->childIds( $reordered, 'c222222' ) );
	}

	/**
	 * A PARTIAL ORDER IS REFUSED. This is REQ-0103's central ruling.
	 *
	 * The remedy is asserted alongside the code, because the fix for this
	 * refusal is "read the children again and send all of them" and an operator
	 * told only that their input was invalid would retry the same partial list.
	 */
	public function test_an_order_missing_one_child_is_refused(): void {
		try {
			$this->edit->reorder( $this->tree(), 'c111111', [ 'w333333', 'w111111' ] );
			$this->fail( 'A partial order must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'exactly once', $exception->getMessage() );
			$this->assertStringContainsString( 'elementor-document-get', $exception->remediation );
		}
	}

	/**
	 * An order naming a child twice is refused rather than deduplicated.
	 *
	 * Deduplicating would turn a request that names three children into one that
	 * names two, which is the partial order above wearing a different hat.
	 */
	public function test_an_order_naming_one_child_twice_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->edit->reorder( $this->tree(), 'c111111', [ 'w111111', 'w111111', 'w222222' ] );
	}

	/**
	 * An order naming an element that is not one of these children is refused,
	 * even when the count happens to match.
	 *
	 * THE COUNT ALONE IS NOT ENOUGH, and this case is what pins that: with the
	 * membership half of the guard removed the list below is still three long
	 * and the refusal would not fire.
	 */
	public function test_an_order_naming_an_element_that_is_not_a_child_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->edit->reorder( $this->tree(), 'c111111', [ 'w111111', 'w222222', 'w444444' ] );
	}

	/**
	 * A parent that is not in the tree is refused rather than silently ignored.
	 */
	public function test_a_parent_that_is_not_in_the_tree_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->edit->reorder( $this->tree(), 'c999999', [ 'w111111' ] );
	}

	/**
	 * AN IDLESS CHILD IS INVISIBLE TO THIS PRIMITIVE, and that is why the
	 * operation above it has a guard of its own.
	 *
	 * `reorder()` counts only the children it can name, so a parent holding one
	 * nameable child and one idless one accepts a one-element order and permutes
	 * the nameable child alone. Nothing here is wrong — the primitive cannot act
	 * on an element it cannot address — but it means the completeness rule this
	 * class enforces does NOT by itself stop a caller from rearranging a list that
	 * is missing a sibling the document still holds. `ElementorElementsReorder`
	 * refuses that case outright, off `childIds()` reporting the null this file
	 * pins above, and this case is the record of why that second guard is not
	 * redundant.
	 */
	public function test_an_idless_child_is_not_counted_by_the_completeness_rule(): void {
		$tree = [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					[
						'id'     => 'w111111',
						'elType' => 'widget',
					],
					[ 'elType' => 'widget' ],
				],
			],
		];

		$reordered = $this->edit->reorder( $tree, 'c111111', [ 'w111111' ] );

		$this->assertSame( [ 'w111111', null ], $this->edit->childIds( $reordered, 'c111111' ) );
	}

	/**
	 * NON-ARRAY MEMBERS DO NOT MOVE.
	 *
	 * The elements are permuted among the raw offsets the elements already
	 * occupied, so anything else the row holds stays exactly where the document
	 * put it. A reorder that rebuilt the list from the wanted order alone would
	 * either drop the stray member or shuffle it to the end, and both are
	 * changes to the document nobody approved.
	 */
	public function test_a_member_that_is_not_an_element_keeps_its_offset(): void {
		$tree = [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					[
						'id'     => 'w111111',
						'elType' => 'widget',
					],
					'stray',
					[
						'id'     => 'w222222',
						'elType' => 'widget',
					],
				],
			],
		];

		$reordered = $this->edit->reorder( $tree, 'c111111', [ 'w222222', 'w111111' ] );

		$this->assertSame( 'w222222', $reordered[0]['elements'][0]['id'] );
		$this->assertSame( 'stray', $reordered[0]['elements'][1] );
		$this->assertSame( 'w111111', $reordered[0]['elements'][2]['id'] );
	}

	/**
	 * Reordering into the order the children are already in is a no-op that
	 * produces the identical tree, which is what makes the operation idempotent.
	 */
	public function test_reordering_into_the_current_order_produces_the_identical_tree(): void {
		$tree = $this->tree();

		$this->assertSame( $tree, $this->edit->reorder( $tree, 'c111111', [ 'w111111', 'w222222', 'w333333' ] ) );
	}

	/**
	 * The fixture: two top-level containers, the first holding three widgets and
	 * the second holding one.
	 *
	 * THREE SIBLINGS IN THE FIRST, not two, because a two-child container cannot
	 * tell a permutation from a reversal.
	 *
	 * @return array[] The raw tree.
	 */
	private function tree(): array {
		return [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'settings' => [ 'content_width' => 'boxed' ],
				'elements' => [
					[
						'id'         => 'w111111',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'First heading' ],
						'elements'   => [],
					],
					[
						'id'         => 'w222222',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Second heading' ],
						'elements'   => [],
					],
					[
						'id'         => 'w333333',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Third heading' ],
						'elements'   => [],
					],
				],
			],
			[
				'id'       => 'c222222',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w444444',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'elements'   => [],
					],
				],
			],
		];
	}
}
