<?php
/**
 * Pins the ceiling on an Elementor document read.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SiteHelm\Modules\Elementor\ElementorTreeNarrowing;

/**
 * The class that stops one read costing the client everything it had.
 *
 * THE FAILURE BEING PINNED IS NOT A WRONG ANSWER, IT IS AN UNDELIVERABLE ONE.
 * A megabyte of nodes is a correct response nobody receives, and the client
 * reports it as "the read did not work" without ever saying the size was the
 * reason. So the tests below are about two things: that the response fits, and
 * that the shortened one is impossible to mistake for the whole tree.
 */
final class ElementorTreeNarrowingTest extends TestCase {

	/**
	 * Stubs the encoder the measure is taken with.
	 *
	 * Brain Monkey leaks process-wide, so the setup is per test rather than
	 * shared.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): mixed => json_encode( $data ) );
	}

	/**
	 * Releases the stubs.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * One normalized node.
	 *
	 * @param string                           $id       The element identifier.
	 * @param int                              $depth    Its zero-based depth.
	 * @param array<int, array<string, mixed>> $children Its children.
	 * @param int                              $padding  How many bytes of label to carry.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function node( string $id, int $depth, array $children = [], int $padding = 0 ): array {
		return [
			'id'         => $id,
			'elType'     => 'container',
			'widgetType' => null,
			'kind'       => 'container',
			'label'      => str_repeat( 'x', $padding ),
			'depth'      => $depth,
			'childCount' => count( $children ),
			'children'   => $children,
		];
	}

	/**
	 * A three-level tree whose leaves push it past the ceiling.
	 *
	 * Two bands, four children each, four grandchildren each: 42 elements. At
	 * 8 KiB of label apiece the top two levels fit and the third cannot.
	 *
	 * @return array<int, array<string, mixed>> The tree.
	 */
	private function overlongTree(): array {
		$padding = 8192;
		$bands   = [];

		for ( $band = 0; $band < 2; $band++ ) {
			$children = [];

			for ( $child = 0; $child < 4; $child++ ) {
				$grandchildren = [];

				for ( $leaf = 0; $leaf < 4; $leaf++ ) {
					$grandchildren[] = $this->node( "leaf-$band-$child-$leaf", 2, [], $padding );
				}

				$children[] = $this->node( "child-$band-$child", 1, $grandchildren, $padding );
			}

			$bands[] = $this->node( "band-$band", 0, $children, $padding );
		}

		return $bands;
	}

	/**
	 * A tree that fits is handed back exactly as it arrived.
	 */
	public function test_a_tree_within_the_ceiling_is_untouched(): void {
		$tree = [ $this->node( 'band-0', 0, [ $this->node( 'child-0', 1 ) ] ) ];

		$result = ElementorTreeNarrowing::narrow( $tree );

		$this->assertSame( $tree, $result['nodes'], 'Nothing was over the ceiling, so nothing may be dropped.' );
		$this->assertFalse( $result['narrowed']['applied'] );
		$this->assertSame( 0, $result['narrowed']['omittedNodes'] );
		$this->assertSame( '', $result['narrowed']['message'], 'A complete response carries no excuse.' );
	}

	/**
	 * An overlong tree is cut to the deepest level that fits.
	 */
	public function test_an_overlong_tree_is_cut_to_the_deepest_level_that_fits(): void {
		$result = ElementorTreeNarrowing::narrow( $this->overlongTree() );

		$this->assertTrue( $result['narrowed']['applied'] );
		$this->assertSame( 1, $result['narrowed']['keptDepth'], 'Depth 1 fits and depth 2 does not, so depth 1 is the answer.' );
		$this->assertSame( 32, $result['narrowed']['omittedNodes'], 'Two bands and eight children were kept; the 32 leaves were not.' );
		$this->assertLessThanOrEqual(
			ElementorTreeNarrowing::MAX_NODES_BYTES,
			strlen( (string) json_encode( $result['nodes'] ) ),
			'The whole point is that the response fits.'
		);
	}

	/**
	 * A node whose children were dropped still says how many it has.
	 *
	 * The count and the empty array together are the only signal a client gets
	 * that there is more below. Rewriting the count to match the array would
	 * make the omission undetectable.
	 */
	public function test_a_pruned_node_keeps_its_true_child_count(): void {
		$result = ElementorTreeNarrowing::narrow( $this->overlongTree() );
		$child  = $result['nodes'][0]['children'][0];

		$this->assertSame( [], $child['children'], 'The level below the cut is not in the response.' );
		$this->assertSame( 4, $child['childCount'], 'The document still has four elements inside this one.' );
	}

	/**
	 * When even the top level overflows, the longest run of it that fits is kept.
	 */
	public function test_a_top_level_that_overflows_keeps_the_bands_that_fit(): void {
		$bands = [];

		for ( $band = 0; $band < 5; $band++ ) {
			$bands[] = $this->node( "band-$band", 0, [], 100000 );
		}

		$result = ElementorTreeNarrowing::narrow( $bands );

		$this->assertTrue( $result['narrowed']['applied'] );
		$this->assertSame( 0, $result['narrowed']['keptDepth'] );
		$this->assertSame( 2, count( $result['nodes'] ), 'Two bands of 100 KB fit under 256 KiB and a third does not.' );
		$this->assertSame( 3, $result['narrowed']['omittedNodes'] );
		$this->assertStringContainsString( 'rootId', $result['narrowed']['message'], 'The marker has to name the call that returns the rest.' );
	}

	/**
	 * A named element is found however deep it sits, with everything under it.
	 */
	public function test_a_subtree_is_returned_whole(): void {
		$found = ElementorTreeNarrowing::subtree( $this->overlongTree(), 'child-1-2' );

		$this->assertIsArray( $found );
		$this->assertSame( 'child-1-2', $found['id'] );
		$this->assertCount( 4, $found['children'], 'A subtree read is the way to see the depth the narrowing dropped.' );
	}

	/**
	 * An identifier no element carries answers null rather than a wrong element.
	 */
	public function test_an_unknown_identifier_finds_nothing(): void {
		$this->assertNull( ElementorTreeNarrowing::subtree( $this->overlongTree(), 'not-in-this-document' ) );
	}
}
