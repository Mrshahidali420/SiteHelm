<?php
/**
 * Tests for ElementorComposition (REQ-0078).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorComposition;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0078: the digest projection, tested against trees the real normalizer
 * produced.
 *
 * NO HAND-BUILT NODE ARRAYS. Every case below starts from raw stored-shape
 * element data and runs it through the real ElementorTree, because the projector
 * reads six normalized members — `id`, `elType`, `kind`, `label`, `children` and
 * `widgetType` — and a hand-built fixture is free to disagree with the normalizer
 * about any of them. The null-id and untyped-element cases are exactly where such
 * a disagreement would hide: those two members exist in the shapes they do
 * because ElementorTree decided them, and a digest tested against a fixture that
 * merely asserts the same beliefs would keep passing after the normalizer changed
 * its mind. ElementorTree is pure and takes no dependency on WordPress, so using
 * the real thing costs nothing.
 *
 * No WordPress function is stubbed and no process isolation is declared: neither
 * class under test here touches a WordPress symbol, and a test that needed one
 * would be testing the envelope, which is ElementorCompositionGetTest's job.
 */
final class ElementorCompositionTest extends TestCase {

	private ElementorComposition $composition;

	private ElementorTree $tree;

	protected function setUp(): void {
		parent::setUp();

		$this->composition = new ElementorComposition();
		$this->tree        = new ElementorTree();
	}

	/**
	 * Projects a raw stored-shape tree through the real normalizer.
	 *
	 * @param array<int, mixed> $raw The raw element list as Elementor stores it.
	 *
	 * @return array<string, mixed> The digest.
	 */
	private function digestOf( array $raw ): array {
		return $this->composition->digest( $this->tree->normalize( $raw ) );
	}

	/**
	 * One widget, in stored shape.
	 *
	 * @param string|null          $id       The stored identifier, null to omit it.
	 * @param string               $type     The widget type.
	 * @param array<string, mixed> $settings The stored settings.
	 *
	 * @return array<string, mixed> The raw element.
	 */
	private function widget( ?string $id, string $type, array $settings = [] ): array {
		$element = [
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => [],
		];

		if ( null !== $id ) {
			$element['id'] = $id;
		}

		return $element;
	}

	/**
	 * One container, in stored shape.
	 *
	 * @param string|null       $id       The stored identifier, null to omit it.
	 * @param string|null       $type     The element type, null to omit it entirely.
	 * @param array<int, mixed> $children The raw children.
	 *
	 * @return array<string, mixed> The raw element.
	 */
	private function container( ?string $id, ?string $type, array $children = [] ): array {
		$element = [ 'elements' => $children ];

		if ( null !== $id ) {
			$element['id'] = $id;
		}

		if ( null !== $type ) {
			$element['elType'] = $type;
		}

		return $element;
	}

	/**
	 * A three-band page: a section of two columns, a container, a naked widget.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function page(): array {
		return [
			$this->container(
				'band1',
				'section',
				[
					$this->container(
						'col1',
						'column',
						[
							$this->widget( 'w1', 'heading', [ 'title' => 'Secret internal note' ] ),
							$this->widget( 'w2', 'text-editor' ),
						]
					),
					$this->container(
						'col2',
						'column',
						[ $this->widget( 'w3', 'heading' ) ]
					),
				]
			),
			$this->container(
				'band2',
				'container',
				[ $this->widget( 'w4', 'button' ) ]
			),
			$this->widget( 'band3', 'spacer' ),
		];
	}

	// ---------------------------------------------------------------- totals

	public function test_an_empty_document_answers_an_empty_digest_rather_than_nothing(): void {
		$digest = $this->digestOf( [] );

		$this->assertSame(
			[
				'nodeCount'      => 0,
				'maxDepth'       => 0,
				'widgetCount'    => 0,
				'containerCount' => 0,
				'bandCount'      => 0,
			],
			$digest['totals'],
			'A document Elementor controls but into which nothing has been saved is the state an operator building a page is in, and every total for it is zero.'
		);
		$this->assertSame( [], $digest['widgets'] );
		$this->assertSame( [], $digest['containers'] );
		$this->assertSame( [], $digest['bands'] );
		$this->assertSame( 0, $digest['untypedElements'] );
		$this->assertSame( 0, $digest['unidentifiedElements'] );
	}

	public function test_the_node_count_and_the_depth_are_the_numbers_the_normalizer_counted(): void {
		$normalized = $this->tree->normalize( $this->page() );
		$digest     = $this->composition->digest( $normalized );

		$this->assertSame(
			$normalized['totals']['nodeCount'],
			$digest['totals']['nodeCount'],
			'The digest must report the normalizer\'s own node count. Re-deriving it here would give two walks that can drift, and a digest that contradicts the full read it was meant to replace is worse than no digest.'
		);
		$this->assertSame( $normalized['totals']['maxDepth'], $digest['totals']['maxDepth'] );
		$this->assertSame( 9, $digest['totals']['nodeCount'] );
		$this->assertSame( 3, $digest['totals']['maxDepth'] );
	}

	public function test_widgets_and_containers_are_counted_separately_and_bands_are_the_top_level(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame( 5, $digest['totals']['widgetCount'] );
		$this->assertSame( 4, $digest['totals']['containerCount'] );
		$this->assertSame( 3, $digest['totals']['bandCount'] );
		$this->assertSame(
			$digest['totals']['nodeCount'],
			$digest['totals']['widgetCount'] + $digest['totals']['containerCount'],
			'Every element is a widget or a container and nothing is both, so the two counts must account for the whole page.'
		);
	}

	public function test_the_band_count_equals_the_number_of_band_entries(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertCount( $digest['totals']['bandCount'], $digest['bands'] );
	}

	// ---------------------------------------------------------------- census

	public function test_the_widget_census_holds_one_entry_per_type_ordered_by_count(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame(
			[
				[
					'type'  => 'heading',
					'count' => 2,
				],
				[
					'type'  => 'button',
					'count' => 1,
				],
				[
					'type'  => 'spacer',
					'count' => 1,
				],
				[
					'type'  => 'text-editor',
					'count' => 1,
				],
			],
			$digest['widgets'],
			'Most used first, ties broken alphabetically. Two headings are one entry saying two, never two entries.'
		);
	}

	public function test_the_container_census_is_keyed_on_the_stored_element_type(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame(
			[
				[
					'type'  => 'column',
					'count' => 2,
				],
				[
					'type'  => 'container',
					'count' => 1,
				],
				[
					'type'  => 'section',
					'count' => 1,
				],
			],
			$digest['containers']
		);
	}

	public function test_a_census_tie_is_broken_by_name_so_two_reads_of_one_page_agree(): void {
		$raw = [
			$this->container(
				'b1',
				'section',
				[
					$this->widget( 'w1', 'zebra' ),
					$this->widget( 'w2', 'alpha' ),
					$this->widget( 'w3', 'middle' ),
				]
			),
		];

		$first  = $this->digestOf( $raw );
		$second = $this->digestOf( $raw );

		$this->assertSame(
			[ 'alpha', 'middle', 'zebra' ],
			array_column( $first['widgets'], 'type' )
		);
		$this->assertSame(
			$first,
			$second,
			'Two digests of one unchanged page must be identical, or a client diffing them sees a change that did not happen.'
		);
	}

	// ----------------------------------------------------------------- bands

	public function test_each_band_names_itself_and_the_size_of_the_read_it_would_cost(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame(
			[
				'index'           => 0,
				'id'              => 'band1',
				'elType'          => 'section',
				'label'           => 'section',
				'childCount'      => 2,
				'descendantCount' => 5,
				'widgetTypeCount' => 2,
				'widgetTypes'     => [ 'heading', 'text-editor' ],
			],
			$digest['bands'][0],
			'descendantCount excludes the band itself: it is how many elements the operator has not seen yet, which is the number they are deciding whether to spend a read on.'
		);
	}

	public function test_bands_are_reported_in_stored_order_with_their_positions(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame( [ 0, 1, 2 ], array_column( $digest['bands'], 'index' ) );
		$this->assertSame( [ 'band1', 'band2', 'band3' ], array_column( $digest['bands'], 'id' ) );
	}

	public function test_a_top_level_widget_names_its_own_type_and_holds_nothing(): void {
		$digest = $this->digestOf( $this->page() );

		$this->assertSame(
			[
				'index'           => 2,
				'id'              => 'band3',
				'elType'          => 'widget',
				'label'           => 'spacer',
				'childCount'      => 0,
				'descendantCount' => 0,
				'widgetTypeCount' => 1,
				'widgetTypes'     => [ 'spacer' ],
			],
			$digest['bands'][2],
			'A widget saved straight onto the page is a band too, and the type it renders is the one thing that identifies it.'
		);
	}

	public function test_a_band_names_widget_types_from_every_depth_beneath_it(): void {
		$digest = $this->digestOf(
			[
				$this->container(
					'b1',
					'section',
					[
						$this->container(
							'c1',
							'column',
							[
								$this->container(
									'inner',
									'container',
									[ $this->widget( 'deep', 'image-carousel' ) ]
								),
							]
						),
					]
				),
			]
		);

		$this->assertSame( [ 'image-carousel' ], $digest['bands'][0]['widgetTypes'] );
		$this->assertSame( 3, $digest['bands'][0]['descendantCount'] );
	}

	public function test_a_band_holding_many_of_one_widget_names_that_type_once(): void {
		$children = [];

		for ( $i = 0; $i < 40; $i++ ) {
			$children[] = $this->widget( 'w' . $i, 'heading' );
		}

		$digest = $this->digestOf( [ $this->container( 'b1', 'section', $children ) ] );

		$this->assertSame(
			[ 'heading' ],
			$digest['bands'][0]['widgetTypes'],
			'The list is distinct types, not occurrences; if it grew with the occurrence count the digest would be a full read wearing a smaller name.'
		);
		$this->assertSame( 1, $digest['bands'][0]['widgetTypeCount'] );
		$this->assertSame( 40, $digest['bands'][0]['descendantCount'] );
	}

	public function test_a_bands_widget_type_list_is_capped_and_says_so_by_still_counting_them_all(): void {
		$children = [];
		$over     = ElementorComposition::MAX_BAND_WIDGET_TYPES + 5;

		for ( $i = 0; $i < $over; $i++ ) {
			// Zero-padded so alphabetical order is also numeric order, which is what
			// makes the retained slice below assertable rather than merely countable.
			$children[] = $this->widget( 'w' . $i, sprintf( 'widget-%02d', $i ) );
		}

		$digest = $this->digestOf( [ $this->container( 'b1', 'section', $children ) ] );
		$band   = $digest['bands'][0];

		$this->assertCount( ElementorComposition::MAX_BAND_WIDGET_TYPES, $band['widgetTypes'] );
		$this->assertSame(
			$over,
			$band['widgetTypeCount'],
			'A truncated list must stay visibly truncated: the true distinct count is what tells a client the names it is reading are not all of them.'
		);
		$this->assertSame( 'widget-00', $band['widgetTypes'][0] );
		$this->assertSame(
			sprintf( 'widget-%02d', ElementorComposition::MAX_BAND_WIDGET_TYPES - 1 ),
			$band['widgetTypes'][ ElementorComposition::MAX_BAND_WIDGET_TYPES - 1 ],
			'The retained names are the alphabetically first ones, so which names survive is a rule rather than an accident of stored order.'
		);
	}

	// -------------------------------------------------- the two honesty counts

	public function test_elements_with_no_stored_identifier_are_counted_wherever_they_sit(): void {
		$digest = $this->digestOf(
			[
				$this->container(
					null,
					'section',
					[
						$this->widget( 'w1', 'heading' ),
						$this->widget( null, 'button' ),
					]
				),
				$this->container( 'band2', 'container', [ $this->widget( null, 'spacer' ) ] ),
			]
		);

		$this->assertSame(
			3,
			$digest['unidentifiedElements'],
			'Every element write addresses its target by identifier, so this count is how much of the page SiteHelm cannot change — and it has to include the ones nested out of sight.'
		);
		$this->assertNull(
			$digest['bands'][0]['id'],
			'A band with no stored identifier reports null, not the empty string: an empty string is a value a client can compare against a real id, and every such element carries the same one.'
		);
	}

	public function test_elements_with_no_stored_type_are_counted_and_read_as_containers(): void {
		$digest = $this->digestOf(
			[
				$this->container( 'band1', null, [ $this->widget( 'w1', 'heading' ) ] ),
				$this->container( 'band2', 'section', [ $this->container( 'nested', null ) ] ),
			]
		);

		$this->assertSame( 2, $digest['untypedElements'] );
		$this->assertSame(
			[
				[
					'type'  => '',
					'count' => 2,
				],
				[
					'type'  => 'section',
					'count' => 1,
				],
			],
			$digest['containers'],
			'An untyped element is counted as a container under the empty-string key, because reading an unknown element as a widget would invite a write to treat it as a replaceable leaf.'
		);
		$this->assertSame( '', $digest['bands'][0]['elType'] );
		$this->assertSame( 'element', $digest['bands'][0]['label'] );
	}

	// ------------------------------------------------------ what it withholds

	public function test_the_digest_carries_no_element_and_no_stored_setting(): void {
		$digest = $this->digestOf( $this->page() );
		$json   = (string) json_encode( $digest );

		$this->assertArrayNotHasKey( 'nodes', $digest );
		$this->assertArrayNotHasKey( 'children', $digest );
		$this->assertStringNotContainsString(
			'Secret internal note',
			$json,
			'The digest describes shape. A stored setting value reaching it would make the cheap read a disclosure the expensive one at least declares.'
		);
		$this->assertStringNotContainsString(
			'"settings"',
			$json
		);
	}

	public function test_the_digest_is_smaller_than_the_tree_it_summarizes(): void {
		$normalized = $this->tree->normalize( $this->page() );

		$this->assertLessThan(
			strlen( (string) json_encode( $normalized ) ),
			strlen( (string) json_encode( $this->composition->digest( $normalized ) ) ),
			'The whole point of the operation is that it costs less than the read it replaces.'
		);
	}

	public function test_a_normalized_value_missing_its_members_is_projected_rather_than_fataled(): void {
		$digest = $this->composition->digest( [] );

		$this->assertSame( 0, $digest['totals']['nodeCount'] );
		$this->assertSame( [], $digest['bands'] );
	}

	public function test_the_digest_declares_exactly_the_members_its_schema_requires(): void {
		$this->assertSame(
			[ 'totals', 'widgets', 'containers', 'bands', 'untypedElements', 'unidentifiedElements' ],
			array_keys( $this->digestOf( $this->page() ) ),
			'The envelope merges the document summary onto this array and its output schema closes additionalProperties, so a member added here without a schema entry fails the schema conformance check rather than reaching a client undeclared.'
		);
	}
}
