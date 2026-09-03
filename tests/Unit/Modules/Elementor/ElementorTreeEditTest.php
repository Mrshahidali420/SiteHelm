<?php
/**
 * Tests for ElementorTreeEdit.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorStyleRemap;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Tests\TestCase;

/**
 * Structural surgery on the RAW stored element tree.
 *
 * THESE TESTS OPERATE ON THE RAW TREE, NEVER ON `ElementorTree`'s normalized
 * output, and that is the point of the vendor-key assertions. The normalizer
 * emits nine keys and drops everything else — `settings`, `styles`,
 * `editor_settings`, and whatever a third-party plugin stored. A write that
 * round-tripped a document through it would report success while deleting all of
 * that. Every node in this file therefore carries `zzz_unknown_vendor_key`, and
 * every operation is asserted to return it untouched.
 *
 * TWO OTHER INVARIANTS CARRY WEIGHT:
 *
 *  1. NO PARTIAL STATE IS PRODUCIBLE. `move()` validates everything before it
 *     mutates anything, so a refused move leaves the caller's tree byte-identical
 *     rather than removed-but-not-inserted — a state that would silently delete
 *     an element and report a failure.
 *  2. AN IDLESS NODE IS UNMATCHABLE. `find()` matches on the STORED id. Old
 *     exported templates are full of elements that declare none, and matching
 *     the first of them would let a write edit an element the operator never
 *     named. Its children are still reachable, but `find()` hands back no parent
 *     id for them at all — `destinationParent()` refuses instead, so a copy
 *     cannot be silently promoted out of an idless container to the top level.
 *  3. ONE INDEX SPACE. `find()`'s `index` and the `$index` `insert()` and
 *     `move()` take both count ELEMENTS, skipping non-array junk without
 *     counting it. The tests that pin this put the junk in the SAME list the
 *     write edits and assert the resulting ORDER, because the two spaces only
 *     disagree when a damaged member sits in front of the target.
 */
final class ElementorTreeEditTest extends TestCase {

	private ElementorTreeEdit $edit;

	protected function setUp(): void {
		parent::setUp();
		$this->edit = new ElementorTreeEdit();
	}

	/**
	 * One raw element carrying a key no part of this codebase knows about.
	 *
	 * @param string  $id       The stored element id.
	 * @param array[] $children The raw child nodes.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function node( string $id, array $children = [] ): array {
		return [
			'id'                     => $id,
			'elType'                 => 'container',
			'settings'               => [ 'padding' => '20px' ],
			'editor_settings'        => [ 'title' => 'Section' ],
			'zzz_unknown_vendor_key' => [ 'written by' => 'some third-party plugin' ],
			'elements'               => $children,
		];
	}

	/**
	 * A two-level document: one root holding three children, plus a second root.
	 *
	 * @return array[] The raw tree.
	 */
	private function tree(): array {
		return [
			$this->node( 'root111', [ $this->node( 'kidaaaa' ), $this->node( 'kidbbbb' ), $this->node( 'kidcccc' ) ] ),
			$this->node( 'root222' ),
		];
	}

	/**
	 * Every stored id in a raw tree, in document order.
	 *
	 * @param array[] $tree The raw tree.
	 *
	 * @return string[] The ids.
	 */
	private function ids( array $tree ): array {
		return $this->edit->collectIds( $tree );
	}

	public function test_find_returns_the_node_and_its_position_for_a_root_element(): void {
		$found = $this->edit->find( $this->tree(), 'root222' );

		$this->assertSame( 'root222', $found['node']['id'] );
		$this->assertNull( $this->edit->destinationParent( $found ) );
		$this->assertSame( 1, $found['index'] );
	}

	public function test_find_hands_back_no_parent_id_key_at_all(): void {
		// The key does not exist, so `insert( $tree, $found['parentId'], ... )`
		// — the call that silently promotes a copy to the document root when the
		// parent cannot be named — is not a call anybody can write.
		$this->assertArrayNotHasKey( 'parentId', $this->edit->find( $this->tree(), 'kidbbbb' ) );
	}

	public function test_find_returns_the_parent_and_index_for_a_nested_element(): void {
		$found = $this->edit->find( $this->tree(), 'kidbbbb' );

		$this->assertSame( 'root111', $this->edit->destinationParent( $found ) );
		$this->assertSame( 1, $found['index'] );
		$this->assertSame( 'kidbbbb', $found['node']['id'] );
	}

	public function test_find_returns_the_whole_stored_node_including_unknown_keys(): void {
		$found = $this->edit->find( $this->tree(), 'kidaaaa' );

		$this->assertSame( [ 'written by' => 'some third-party plugin' ], $found['node']['zzz_unknown_vendor_key'] );
		$this->assertSame( [ 'title' => 'Section' ], $found['node']['editor_settings'] );
	}

	public function test_find_returns_null_for_an_element_the_document_does_not_hold(): void {
		$this->assertNull( $this->edit->find( $this->tree(), 'nosuch1' ) );
	}

	public function test_find_never_matches_a_node_that_stores_no_id(): void {
		$idless = [ 'elType' => 'container', 'elements' => [] ];
		$tree   = [ $idless, $this->node( 'root222' ) ];

		// Neither the empty string nor any other id may reach the idless node
		// sitting in front of the element the caller actually named.
		$this->assertNull( $this->edit->find( $tree, '' ) );
		$this->assertSame( 1, $this->edit->find( $tree, 'root222' )['index'] );
	}

	public function test_find_reports_the_document_root_as_an_addressable_destination(): void {
		$found = $this->edit->find( $this->tree(), 'root222' );

		$this->assertTrue( $found['parentAddressable'] );
		$this->assertNull( $this->edit->destinationParent( $found ) );
	}

	public function test_find_reports_a_child_of_an_idless_container_as_having_no_nameable_parent(): void {
		// Without this flag a caller could not tell "sits at the document root"
		// from "sits inside an element nothing can name", and re-inserting a
		// copy beside its original would silently promote it to the top level.
		$tree = [ [ 'elType' => 'container', 'elements' => [ $this->node( 'kidaaaa' ) ] ] ];

		$found = $this->edit->find( $tree, 'kidaaaa' );

		$this->assertFalse( $found['parentAddressable'] );
	}

	public function test_destination_parent_refuses_a_child_of_an_idless_container(): void {
		// The refusal is the whole safeguard: answering null here would mean the
		// document root, and the copy would leave its container without a word.
		$tree  = [ [ 'elType' => 'container', 'elements' => [ $this->node( 'kidaaaa' ) ] ] ];
		$found = $this->edit->find( $tree, 'kidaaaa' );

		try {
			$this->edit->destinationParent( $found );
			$this->fail( 'An unnameable parent must refuse rather than answering the document root.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringNotContainsString( 'kidaaaa', $exception->getMessage() );
		}
	}

	public function test_destination_parent_answers_null_at_the_true_document_root(): void {
		$this->assertNull( $this->edit->destinationParent( $this->edit->find( $this->tree(), 'root111' ) ) );
	}

	public function test_destination_parent_answers_the_parent_id_in_the_ordinary_case(): void {
		$this->assertSame(
			'root111',
			$this->edit->destinationParent( $this->edit->find( $this->tree(), 'kidcccc' ) )
		);
	}

	public function test_find_never_matches_a_node_whose_stored_id_is_not_scalar(): void {
		$tree = [ [ 'id' => [ 'an', 'array' ], 'elType' => 'container' ] ];

		$this->assertNull( $this->edit->find( $tree, 'Array' ) );
	}

	public function test_path_names_the_parent_and_the_position(): void {
		$this->assertSame( 'root111/2', $this->edit->path( $this->tree(), 'kidcccc' ) );
	}

	public function test_path_of_a_root_element_names_no_parent(): void {
		$this->assertSame( '/1', $this->edit->path( $this->tree(), 'root222' ) );
	}

	public function test_path_is_null_for_an_element_the_document_does_not_hold(): void {
		$this->assertNull( $this->edit->path( $this->tree(), 'nosuch1' ) );
	}

	public function test_collect_ids_reports_every_stored_id_in_document_order(): void {
		$this->assertSame(
			[ 'root111', 'kidaaaa', 'kidbbbb', 'kidcccc', 'root222' ],
			$this->edit->collectIds( $this->tree() )
		);
	}

	public function test_collect_ids_skips_nodes_with_no_usable_stored_id(): void {
		$tree = [
			[ 'elType' => 'container' ],
			[ 'id' => [ 'an', 'array' ], 'elType' => 'container' ],
			[ 'id' => '', 'elType' => 'container' ],
			'not-an-element',
			$this->node( 'root222' ),
		];

		$this->assertSame( [ 'root222' ], $this->edit->collectIds( $tree ) );
	}

	public function test_insert_places_a_new_root_element_at_the_given_position(): void {
		$result = $this->edit->insert( $this->tree(), null, 1, $this->node( 'newaaaa' ) );

		$this->assertSame( [ 'root111', 'kidaaaa', 'kidbbbb', 'kidcccc', 'newaaaa', 'root222' ], $this->ids( $result ) );
	}

	public function test_insert_places_a_new_child_inside_the_named_parent(): void {
		$result = $this->edit->insert( $this->tree(), 'root111', 1, $this->node( 'newaaaa' ) );

		$this->assertSame(
			[ 'root111', 'kidaaaa', 'newaaaa', 'kidbbbb', 'kidcccc', 'root222' ],
			$this->ids( $result )
		);
	}

	public function test_insert_appends_when_the_index_is_past_the_end(): void {
		$result = $this->edit->insert( $this->tree(), 'root111', 99, $this->node( 'newaaaa' ) );

		$this->assertSame( 'root111/3', $this->edit->path( $result, 'newaaaa' ) );
	}

	public function test_insert_prepends_when_the_index_is_negative(): void {
		$result = $this->edit->insert( $this->tree(), 'root111', -5, $this->node( 'newaaaa' ) );

		$this->assertSame( 'root111/0', $this->edit->path( $result, 'newaaaa' ) );
	}

	public function test_insert_preserves_the_unknown_keys_of_the_inserted_node_and_its_neighbours(): void {
		$result = $this->edit->insert( $this->tree(), 'root111', 0, $this->node( 'newaaaa' ) );

		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$this->edit->find( $result, 'newaaaa' )['node']['zzz_unknown_vendor_key']
		);
		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$this->edit->find( $result, 'kidaaaa' )['node']['zzz_unknown_vendor_key']
		);
	}

	public function test_insert_leaves_the_caller_s_tree_untouched(): void {
		$tree   = $this->tree();
		$before = serialize( $tree );

		$this->edit->insert( $tree, 'root111', 0, $this->node( 'newaaaa' ) );

		$this->assertSame( $before, serialize( $tree ) );
	}

	public function test_insert_refuses_a_parent_the_document_does_not_hold(): void {
		$this->expectException( OperationException::class );

		$this->edit->insert( $this->tree(), 'nosuch1', 0, $this->node( 'newaaaa' ) );
	}

	public function test_the_refusal_for_a_missing_parent_names_target_not_found(): void {
		try {
			$this->edit->insert( $this->tree(), 'nosuch1', 0, $this->node( 'newaaaa' ) );
			$this->fail( 'Inserting into an absent parent must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringNotContainsString( 'nosuch1', $exception->getMessage() );
		}
	}

	public function test_remove_deletes_the_named_element_and_keeps_its_siblings_in_order(): void {
		$result = $this->edit->remove( $this->tree(), 'kidbbbb' );

		$this->assertSame( [ 'root111', 'kidaaaa', 'kidcccc', 'root222' ], $this->ids( $result ) );
	}

	public function test_remove_takes_the_whole_subtree_with_it(): void {
		$result = $this->edit->remove( $this->tree(), 'root111' );

		$this->assertSame( [ 'root222' ], $this->ids( $result ) );
	}

	public function test_remove_preserves_the_unknown_keys_of_the_surviving_nodes(): void {
		$result = $this->edit->remove( $this->tree(), 'kidbbbb' );

		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$this->edit->find( $result, 'kidcccc' )['node']['zzz_unknown_vendor_key']
		);
	}

	public function test_remove_refuses_an_element_the_document_does_not_hold(): void {
		try {
			$this->edit->remove( $this->tree(), 'nosuch1' );
			$this->fail( 'Removing an absent element must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	public function test_move_relocates_an_element_into_another_parent(): void {
		$result = $this->edit->move( $this->tree(), 'kidbbbb', 'root222', 0 );

		$this->assertSame( [ 'root111', 'kidaaaa', 'kidcccc', 'root222', 'kidbbbb' ], $this->ids( $result ) );
		$this->assertSame( 'root222/0', $this->edit->path( $result, 'kidbbbb' ) );
	}

	public function test_move_to_the_document_root_names_no_parent(): void {
		$result = $this->edit->move( $this->tree(), 'kidaaaa', null, 0 );

		$this->assertSame( '/0', $this->edit->path( $result, 'kidaaaa' ) );
		$this->assertSame( [ 'kidaaaa', 'root111', 'kidbbbb', 'kidcccc', 'root222' ], $this->ids( $result ) );
	}

	public function test_move_preserves_sibling_order_in_a_five_sibling_container(): void {
		$tree = [
			$this->node(
				'root111',
				[
					$this->node( 'sibaaaa' ),
					$this->node( 'sibbbbb' ),
					$this->node( 'sibcccc' ),
					$this->node( 'sibdddd' ),
					$this->node( 'sibeeee' ),
				]
			),
		];

		$result = $this->edit->move( $tree, 'sibaaaa', 'root111', 2 );

		$this->assertSame(
			[ 'root111', 'sibbbbb', 'sibcccc', 'sibaaaa', 'sibdddd', 'sibeeee' ],
			$this->ids( $result )
		);
	}

	public function test_move_preserves_the_unknown_keys_of_the_moved_element(): void {
		$result = $this->edit->move( $this->tree(), 'kidbbbb', 'root222', 0 );

		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$this->edit->find( $result, 'kidbbbb' )['node']['zzz_unknown_vendor_key']
		);
	}

	public function test_move_into_an_absent_parent_leaves_the_tree_byte_identical(): void {
		$tree   = $this->tree();
		$before = serialize( $tree );

		try {
			$this->edit->move( $tree, 'kidbbbb', 'nosuch1', 0 );
			$this->fail( 'Moving into an absent parent must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( $before, serialize( $tree ) );
	}

	public function test_move_of_an_absent_element_refuses_before_touching_anything(): void {
		$tree   = $this->tree();
		$before = serialize( $tree );

		try {
			$this->edit->move( $tree, 'nosuch1', 'root222', 0 );
			$this->fail( 'Moving an absent element must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( $before, serialize( $tree ) );
	}

	public function test_move_into_the_element_s_own_descendant_refuses(): void {
		$tree   = $this->tree();
		$before = serialize( $tree );

		try {
			$this->edit->move( $tree, 'root111', 'kidbbbb', 0 );
			$this->fail( 'Moving an element inside its own subtree must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( $before, serialize( $tree ) );
	}

	public function test_move_into_itself_refuses(): void {
		try {
			$this->edit->move( $this->tree(), 'root111', 'root111', 0 );
			$this->fail( 'Moving an element into itself must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * A DESCENDANT IS NOT ONLY A CHILD. The containment test walks the whole
	 * subtree, and a grandchild is where a test that only looked one level down
	 * would let the document be detached from itself.
	 */
	public function test_move_into_a_grandchild_of_the_element_refuses(): void {
		$tree   = [ $this->node( 'top1111', [ $this->node( 'mid1111', [ $this->node( 'low1111' ) ] ) ] ) ];
		$before = serialize( $tree );

		try {
			$this->edit->move( $tree, 'top1111', 'low1111', 0 );
			$this->fail( 'Moving an element into its own grandchild must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( $before, serialize( $tree ) );
	}

	/**
	 * THE INDEX COUNTS THE DESTINATION AFTER THE ELEMENT HAS LEFT. Moving up a
	 * level and along is where the two readings differ visibly: counted before
	 * the removal this lands one place short of where the caller asked.
	 */
	public function test_move_out_to_the_root_lands_at_the_index_the_caller_named(): void {
		$result = $this->edit->move( $this->tree(), 'kidaaaa', null, 1 );

		$this->assertSame( [ 'root111', 'kidbbbb', 'kidcccc', 'kidaaaa', 'root222' ], $this->ids( $result ) );
		$this->assertSame( '/1', $this->edit->path( $result, 'kidaaaa' ) );
	}

	/**
	 * The same journey backwards: a root element down into a container that is
	 * already holding children, between two of them.
	 */
	public function test_move_from_the_root_down_between_two_existing_children(): void {
		$result = $this->edit->move( $this->tree(), 'root222', 'root111', 1 );

		$this->assertSame( [ 'root111', 'kidaaaa', 'root222', 'kidbbbb', 'kidcccc' ], $this->ids( $result ) );
		$this->assertSame( 'root111/1', $this->edit->path( $result, 'root222' ) );
	}

	/**
	 * AN INDEX PAST THE END APPENDS RATHER THAN REFUSING. A caller working from
	 * a read taken a moment ago should not have a move fail because a sibling
	 * was deleted in between; the last position is what it meant.
	 */
	public function test_move_past_the_last_child_appends(): void {
		$result = $this->edit->move( $this->tree(), 'kidaaaa', 'root222', 99 );

		$this->assertSame( 'root222/0', $this->edit->path( $result, 'kidaaaa' ) );
		$this->assertSame( [ 'root111', 'kidbbbb', 'kidcccc', 'root222', 'kidaaaa' ], $this->ids( $result ) );
	}

	/**
	 * Within one parent, moving to a later index: the position is read in the
	 * list the element is no longer in, so the last index is the last place.
	 */
	public function test_move_within_the_same_parent_to_a_later_index(): void {
		$result = $this->edit->move( $this->tree(), 'kidaaaa', 'root111', 2 );

		$this->assertSame( [ 'root111', 'kidbbbb', 'kidcccc', 'kidaaaa', 'root222' ], $this->ids( $result ) );
		$this->assertSame( 'root111/2', $this->edit->path( $result, 'kidaaaa' ) );
	}

	public function test_a_duplicate_carries_every_unknown_key_of_its_source(): void {
		$mint  = new ElementorIdMint();
		$remap = new ElementorStyleRemap();
		$tree  = $this->tree();

		$source     = $this->edit->find( $tree, 'root111' );
		$reassigned = $mint->reassign( $source['node'], "op\0" . "7\0" . "fp\0" . 'payload', $this->edit->collectIds( $tree ) );
		$copy       = $remap->remap( $reassigned['tree'], $reassigned['map'] );
		$result     = $this->edit->insert( $tree, $this->edit->destinationParent( $source ), $source['index'] + 1, $copy );

		$duplicate = $this->edit->find( $result, $copy['id'] );
		$this->assertSame( [ 'written by' => 'some third-party plugin' ], $duplicate['node']['zzz_unknown_vendor_key'] );
		$this->assertSame( [ 'title' => 'Section' ], $duplicate['node']['elements'][0]['editor_settings'] );
		$this->assertSame( '/1', $this->edit->path( $result, $copy['id'] ) );
	}

	public function test_find_walks_past_a_non_array_member_of_a_child_list(): void {
		$tree = [ 'not-an-element', $this->node( 'root222' ) ];

		$this->assertSame( 'root222', $this->edit->find( $tree, 'root222' )['node']['id'] );
	}

	public function test_insert_places_a_new_child_inside_a_nested_parent(): void {
		$result = $this->edit->insert( $this->tree(), 'kidaaaa', 0, $this->node( 'newaaaa' ) );

		$this->assertSame( 'kidaaaa/0', $this->edit->path( $result, 'newaaaa' ) );
		$this->assertSame(
			[ 'root111', 'kidaaaa', 'newaaaa', 'kidbbbb', 'kidcccc', 'root222' ],
			$this->ids( $result )
		);
	}

	public function test_remove_walks_past_a_non_array_member_of_a_child_list(): void {
		$tree = [ 'not-an-element', $this->node( 'root222' ) ];

		$this->assertSame( [ 'not-an-element' ], $this->edit->remove( $tree, 'root222' ) );
	}

	public function test_remove_walks_past_a_leaf_that_stores_no_child_list(): void {
		$leaf = [ 'id' => 'leaf111', 'elType' => 'widget' ];
		$tree = [ $leaf, $this->node( 'root222' ) ];

		$this->assertSame( [ $leaf ], $this->edit->remove( $tree, 'root222' ) );
	}

	public function test_insert_skips_a_non_array_member_when_it_walks_for_the_parent(): void {
		$tree = [ 'not-an-element', $this->node( 'root111' ) ];

		$result = $this->edit->insert( $tree, 'root111', 0, $this->node( 'newaaaa' ) );

		$this->assertSame( [ 'root111', 'newaaaa' ], $this->ids( $result ) );
		$this->assertSame( 'not-an-element', $result[0] );
	}

	/**
	 * One container whose own child list carries junk AHEAD of the elements.
	 *
	 * The junk must sit in the SAME list the operation edits. A scalar in the
	 * outer list while the write happens in a clean inner one proves nothing:
	 * the two index spaces only disagree when the damaged member precedes the
	 * target inside the list being spliced.
	 *
	 * @return array[] The raw tree.
	 */
	private function tree_with_junk_before_its_children(): array {
		return [
			$this->node(
				'root111',
				[ 'not-an-element', $this->node( 'kidaaaa' ), $this->node( 'kidbbbb' ), $this->node( 'kidcccc' ) ]
			),
		];
	}

	public function test_a_duplicate_lands_after_its_source_when_junk_precedes_them(): void {
		// The documented pipeline, run against the list that used to break it:
		// find() counted elements, spliced() counted raw members, and the copy
		// arrived one seat early — in front of the element it was copied from.
		$tree  = $this->tree_with_junk_before_its_children();
		$found = $this->edit->find( $tree, 'kidbbbb' );

		$this->assertSame( 1, $found['index'] );

		$result   = $this->edit->insert(
			$tree,
			$this->edit->destinationParent( $found ),
			$found['index'] + 1,
			$this->node( 'newaaaa' )
		);
		$children = $result[0]['elements'];

		$this->assertSame( 'not-an-element', $children[0] );
		$this->assertSame( 'kidaaaa', $children[1]['id'] );
		$this->assertSame( 'kidbbbb', $children[2]['id'] );
		$this->assertSame( 'newaaaa', $children[3]['id'] );
		$this->assertSame( 'kidcccc', $children[4]['id'] );
	}

	public function test_insert_counts_element_positions_not_raw_members(): void {
		$result   = $this->edit->insert( $this->tree_with_junk_before_its_children(), 'root111', 0, $this->node( 'newaaaa' ) );
		$children = $result[0]['elements'];

		// Position 0 is the first ELEMENT, so the junk keeps the seat the
		// document gave it and the new node is still the first child element.
		$this->assertSame( 'not-an-element', $children[0] );
		$this->assertSame( 'newaaaa', $children[1]['id'] );
		$this->assertSame( 'kidaaaa', $children[2]['id'] );
		$this->assertSame( 0, $this->edit->find( $result, 'newaaaa' )['index'] );
	}

	public function test_move_counts_element_positions_not_raw_members(): void {
		$result   = $this->edit->move( $this->tree_with_junk_before_its_children(), 'kidaaaa', 'root111', 1 );
		$children = $result[0]['elements'];

		$this->assertSame( 'not-an-element', $children[0] );
		$this->assertSame( 'kidbbbb', $children[1]['id'] );
		$this->assertSame( 'kidaaaa', $children[2]['id'] );
		$this->assertSame( 'kidcccc', $children[3]['id'] );
		$this->assertSame( 'root111/1', $this->edit->path( $result, 'kidaaaa' ) );
	}

	public function test_move_past_the_last_element_lands_after_it_not_before_trailing_junk(): void {
		$tree = [
			$this->node( 'root111', [ $this->node( 'kidaaaa' ), $this->node( 'kidbbbb' ), 'not-an-element' ] ),
		];

		$result   = $this->edit->move( $tree, 'kidaaaa', 'root111', 99 );
		$children = $result[0]['elements'];

		$this->assertSame( 'kidbbbb', $children[0]['id'] );
		$this->assertSame( 'not-an-element', $children[1] );
		$this->assertSame( 'kidaaaa', $children[2]['id'] );
	}
}
