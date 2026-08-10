<?php
/**
 * Tests for ElementorTree.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Tests\TestCase;

/**
 * The pure normalizer: raw stored tree in, frozen node shape and totals out.
 *
 * NO TEST DOUBLE APPEARS IN THIS FILE, and that is the point of the class being
 * pure. It calls no WordPress function, so there is nothing to fake and
 * therefore nothing a fake could be unfaithful about — the class of failure that
 * cost Phase 5 a data-loss bug behind a green suite cannot arise here.
 *
 * TWO INVARIANTS CARRY MORE WEIGHT THAN THE REST:
 *
 *  1. A BOUND BREACH REFUSES AND NEVER RETURNS A TRUNCATED TREE. The assertions
 *     below are on the throw, not on a short result. A partial tree that looks
 *     complete is exactly how Phase 6b would produce a diff that does not
 *     describe the change an operator approved, and an approved plan that
 *     describes the wrong change is worse than no plan.
 *  2. THE WALK CANNOT RECURSE PAST MAX_DEPTH EVEN ON A CYCLE. A stack overflow
 *     is a CRASH, not an error response: the dispatcher never gets to refuse,
 *     the audit entry is never written, and the operator sees a dead
 *     connection. `_elementor_data` is writable by any plugin that can write
 *     post meta, so a hostile or merely broken structure is reachable.
 */
final class ElementorTreeTest extends TestCase {

	private ElementorTree $tree;

	protected function setUp(): void {
		parent::setUp();
		$this->tree = new ElementorTree();
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
	 * @param string $id   The element id.
	 * @param string $type The widget type.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function widget( string $id, string $type ): array {
		return [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => [ 'title' => 'Some stored title' ],
			'elements'   => [],
		];
	}

	/**
	 * A single chain of containers nested to the requested depth.
	 *
	 * @param int $levels How many nesting levels to build.
	 *
	 * @return array[] The raw tree.
	 */
	private function chain( int $levels ): array {
		$node = $this->container( 'leaf' );

		for ( $i = 1; $i < $levels; $i++ ) {
			$node = $this->container( 'n' . $i, [ $node ] );
		}

		return [ $node ];
	}

	/**
	 * Asserts normalize() refuses, and returns the refusal for inspection.
	 *
	 * DELIBERATELY NOT expectException(): that would pass on a throw from
	 * anywhere, including a PHP TypeError from a half-finished walk. Catching
	 * the specific type here, and failing explicitly when nothing is thrown, is
	 * what makes "never returns a truncated tree" an assertion rather than a
	 * hope.
	 *
	 * @param array[] $raw The raw tree.
	 *
	 * @return OperationException The refusal.
	 */
	private function assertRefused( array $raw ): OperationException {
		try {
			$this->tree->normalize( $raw );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'ElementorTree::normalize() returned instead of refusing a breached bound.' );
	}

	public function test_a_nested_tree_normalizes_to_the_frozen_node_shape(): void {
		$result = $this->tree->normalize(
			[
				$this->container(
					'root123',
					[
						$this->widget( 'head456', 'heading' ),
						$this->container( 'inner78', [ $this->widget( 'img90ab', 'image' ) ] ),
					]
				),
			]
		);

		$this->assertSame( [ 'nodes', 'totals' ], array_keys( $result ) );

		$root = $result['nodes'][0];

		// The WHOLE key set, in order, asserted rather than sampled. This shape is
		// frozen by the spec and Phase 6b diffs against it; a member added or
		// dropped silently is a diff that stops describing part of the change.
		$this->assertSame(
			[ 'id', 'elType', 'widgetType', 'kind', 'label', 'depth', 'childCount', 'children' ],
			array_keys( $root )
		);

		$this->assertSame( 'root123', $root['id'] );
		$this->assertSame( 'container', $root['elType'] );
		$this->assertNull( $root['widgetType'] );
		$this->assertSame( 'container', $root['kind'] );
		$this->assertSame( 0, $root['depth'] );
		$this->assertSame( 2, $root['childCount'] );

		$heading = $root['children'][0];
		$this->assertSame( 'head456', $heading['id'] );
		$this->assertSame( 'widget', $heading['kind'] );
		$this->assertSame( 'heading', $heading['widgetType'] );
		$this->assertSame( 1, $heading['depth'] );
		$this->assertSame( 0, $heading['childCount'] );
		$this->assertSame( [], $heading['children'] );

		$image = $root['children'][1]['children'][0];
		$this->assertSame( 'img90ab', $image['id'] );
		$this->assertSame( 2, $image['depth'] );
	}

	public function test_element_ids_are_carried_through_unchanged(): void {
		// REQ-0033's acceptance criterion is STABLE ids, so they are never
		// re-minted on read. A normalizer that generated its own would produce a
		// different id on every request and make every Phase 6b diff total.
		$result = $this->tree->normalize( [ $this->container( 'a1b2c3d', [ $this->widget( 'e4f5a6b', 'image' ) ] ) ] );

		$this->assertSame( 'a1b2c3d', $result['nodes'][0]['id'] );
		$this->assertSame( 'e4f5a6b', $result['nodes'][0]['children'][0]['id'] );
	}

	public function test_element_settings_are_never_returned(): void {
		// Both raw fixtures above carry `settings`. They are large, may hold
		// arbitrary third-party data, and REQ-0033 asks for STRUCTURE, not
		// content. Phase 6b's element read can add a scoped, opt-in projection
		// when it needs one.
		$result = $this->tree->normalize( [ $this->container( 'root123', [ $this->widget( 'head456', 'heading' ) ] ) ] );

		$flattened = json_encode( $result );

		$this->assertStringNotContainsString( 'settings', (string) $flattened );
		$this->assertStringNotContainsString( 'Some stored title', (string) $flattened );
		$this->assertStringNotContainsString( '20px', (string) $flattened );
	}

	public function test_the_totals_describe_the_whole_tree(): void {
		$result = $this->tree->normalize(
			[
				$this->container(
					'root123',
					[
						$this->widget( 'head456', 'heading' ),
						$this->widget( 'head789', 'heading' ),
						$this->container( 'inner78', [ $this->widget( 'img90ab', 'image' ) ] ),
					]
				),
				$this->widget( 'spacer12', 'spacer' ),
			]
		);

		$this->assertSame( [ 'nodeCount', 'maxDepth', 'widgetTypeCounts' ], array_keys( $result['totals'] ) );
		$this->assertSame( 6, $result['totals']['nodeCount'] );

		// maxDepth counts LEVELS, so a tree whose deepest node sits at depth 2 has
		// three of them. The empty-tree test below pins the other end.
		$this->assertSame( 3, $result['totals']['maxDepth'] );

		$this->assertSame(
			[
				'heading' => 2,
				'image'   => 1,
				'spacer'  => 1,
			],
			$result['totals']['widgetTypeCounts']
		);
	}

	public function test_widget_type_counts_are_empty_when_a_tree_holds_only_containers(): void {
		$result = $this->tree->normalize( [ $this->container( 'root123', [ $this->container( 'inner78' ) ] ) ] );

		$this->assertSame( [], $result['totals']['widgetTypeCounts'] );
		$this->assertSame( 2, $result['totals']['nodeCount'] );
	}

	public function test_an_empty_tree_normalizes_to_nothing_rather_than_refusing(): void {
		$result = $this->tree->normalize( [] );

		$this->assertSame( [], $result['nodes'] );
		$this->assertSame(
			[
				'nodeCount'        => 0,
				'maxDepth'         => 0,
				'widgetTypeCounts' => [],
			],
			$result['totals']
		);
	}

	/**
	 * THE LABEL IS DERIVED. This asserts the value, and ElementorTree's docblock
	 * says so in the words the spec uses, because Phase 6b must never snapshot
	 * `label` as though it were a stored value. That is not a hypothetical: the
	 * menus module recorded `wp_setup_nav_menu_item()`'s COMPUTED `description`
	 * as a stored column, and it took a whole-branch review to find.
	 */
	public function test_the_label_is_derived_from_the_type_and_is_never_a_stored_value(): void {
		$result = $this->tree->normalize(
			[
				$this->container( 'root123', [ $this->widget( 'head456', 'heading' ) ] ),
			]
		);

		$this->assertSame( 'container', $result['nodes'][0]['label'] );
		$this->assertSame( 'heading', $result['nodes'][0]['children'][0]['label'] );

		// And specifically NOT the widget's stored title, which is the value a
		// well-meaning "make the label useful" change would reach for and which
		// would make label indistinguishable from stored content in a snapshot.
		$this->assertStringNotContainsString( 'Some stored title', $result['nodes'][0]['children'][0]['label'] );
	}

	public function test_a_node_missing_its_type_is_normalized_without_a_notice(): void {
		// failOnWarning is on in phpunit.xml.dist, so an undefined-index notice
		// from the walk fails this test rather than being swallowed. This is a
		// regression case: the upstream walker read $node['elType'] unguarded and
		// a template exported from an older Elementor has nodes without it.
		$result = $this->tree->normalize(
			[
				[
					'id'       => 'bare123',
					'elements' => [ [ 'id' => 'bare456' ] ],
				],
			]
		);

		$bare = $result['nodes'][0];

		$this->assertSame( 'bare123', $bare['id'] );
		$this->assertSame( '', $bare['elType'] );
		$this->assertNull( $bare['widgetType'] );

		// An untyped node is reported as a container, because `widget` is the
		// claim that carries meaning — a diff that called an unknown node a
		// widget would invite a Phase 6b write to treat it as replaceable.
		$this->assertSame( 'container', $bare['kind'] );
		$this->assertSame( 'element', $bare['label'] );
		$this->assertSame( 1, $bare['childCount'] );
		$this->assertSame( 2, $result['totals']['nodeCount'] );
	}

	public function test_a_node_with_no_usable_identifier_reports_an_empty_one(): void {
		$result = $this->tree->normalize( [ [ 'elType' => 'container', 'id' => [ 'nested' ] ] ] );

		$this->assertSame( '', $result['nodes'][0]['id'] );
	}

	public function test_a_widget_with_no_widget_type_is_still_a_widget(): void {
		$result = $this->tree->normalize( [ [ 'id' => 'w123456', 'elType' => 'widget' ] ] );

		$this->assertSame( 'widget', $result['nodes'][0]['kind'] );
		$this->assertNull( $result['nodes'][0]['widgetType'] );
		$this->assertSame( 'widget', $result['nodes'][0]['label'] );

		// A widget with no type contributes to no count. Recording it under ''
		// would put an unnamed bucket in the totals that no operator can act on.
		$this->assertSame( [], $result['totals']['widgetTypeCounts'] );
	}

	public function test_child_entries_that_are_not_nodes_are_skipped(): void {
		$result = $this->tree->normalize(
			[
				[
					'id'       => 'root123',
					'elType'   => 'container',
					'elements' => [ 'heading', 7, null, $this->widget( 'good123', 'image' ) ],
				],
			]
		);

		$this->assertSame( 1, $result['nodes'][0]['childCount'] );
		$this->assertSame( 'good123', $result['nodes'][0]['children'][0]['id'] );
		$this->assertSame( 2, $result['totals']['nodeCount'] );
	}

	public function test_an_elements_member_that_is_not_a_list_yields_no_children(): void {
		$result = $this->tree->normalize(
			[ [ 'id' => 'root123', 'elType' => 'container', 'elements' => 'nope' ] ]
		);

		$this->assertSame( 0, $result['nodes'][0]['childCount'] );
		$this->assertSame( [], $result['nodes'][0]['children'] );
	}

	public function test_a_tree_exactly_at_the_depth_bound_is_accepted(): void {
		// The boundary from the legal side, so the refusal below is known to be
		// an off-by-one away from correct rather than merely somewhere near it.
		$result = $this->tree->normalize( $this->chain( ElementorTree::MAX_DEPTH ) );

		$this->assertSame( ElementorTree::MAX_DEPTH, $result['totals']['maxDepth'] );
	}

	public function test_a_tree_deeper_than_the_depth_bound_refuses(): void {
		$refusal = $this->assertRefused( $this->chain( ElementorTree::MAX_DEPTH + 1 ) );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'Elementor editor', (string) $refusal->remediation );
	}

	public function test_a_tree_with_more_nodes_than_the_node_bound_refuses(): void {
		$children = [];

		for ( $i = 0; $i <= ElementorTree::MAX_NODES; $i++ ) {
			$children[] = $this->widget( 'w' . $i, 'heading' );
		}

		$refusal = $this->assertRefused( [ $this->container( 'root123', $children ) ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_a_tree_exactly_at_the_node_bound_is_accepted(): void {
		$children = [];

		for ( $i = 0; $i < ElementorTree::MAX_NODES - 1; $i++ ) {
			$children[] = $this->widget( 'w' . $i, 'heading' );
		}

		$result = $this->tree->normalize( [ $this->container( 'root123', $children ) ] );

		$this->assertSame( ElementorTree::MAX_NODES, $result['totals']['nodeCount'] );
	}

	/**
	 * THE CENTRAL ASSERTION OF THIS FILE. A breach produces NOTHING — not a
	 * short tree, not a flagged tree, not a tree with a warning beside it.
	 */
	public function test_a_breached_bound_returns_no_tree_at_all_not_a_truncated_one(): void {
		$returned = 'the normalizer returned';

		try {
			$this->tree->normalize( $this->chain( ElementorTree::MAX_DEPTH + 5 ) );
		} catch ( OperationException $refusal ) {
			$returned = $refusal;
		}

		$this->assertInstanceOf( OperationException::class, $returned );

		// And the refusal itself carries no fragment of the tree it walked.
		$this->assertStringNotContainsString( 'leaf', $returned->getMessage() );
		$this->assertStringNotContainsString( 'leaf', (string) $returned->remediation );
	}

	/**
	 * A CYCLE MUST REFUSE, NOT CRASH.
	 *
	 * `json_decode()` cannot produce a reference cycle, so this structure comes
	 * from the other direction: any plugin able to write `_elementor_data`, or
	 * any future caller handing this normalizer an array it built itself. An
	 * unbounded recursive walk over it exhausts the stack, and a stack overflow
	 * is a fatal — the dispatcher never refuses, no audit entry is written, and
	 * the operator sees a dropped connection instead of an error.
	 *
	 * The depth bound is what makes this terminate, which is why the guard has
	 * to be tested BEFORE the descent rather than after it.
	 */
	public function test_a_cyclic_structure_refuses_instead_of_exhausting_the_stack(): void {
		$node             = $this->container( 'cycle12' );
		$node['elements'] = [ &$node ];

		$refusal = $this->assertRefused( [ $node ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_the_bounds_are_frozen(): void {
		$this->assertSame( 50, ElementorTree::MAX_DEPTH );
		$this->assertSame( 5000, ElementorTree::MAX_NODES );
	}
}
