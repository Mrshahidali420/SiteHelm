<?php
/**
 * Pins the filtering walk over one stored Elementor tree.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use PHPUnit\Framework\TestCase;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeSearch;

/**
 * Pins `ElementorTreeSearch`.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is `matchCount` counting past the
 * limit while `matches` stops at it. A truncating search whose count also
 * stopped at the limit would report every over-large result as exactly `limit`
 * matches and `truncated` would be permanently false, which reads as a complete
 * answer and is not one. Several tests below therefore assert the count and the
 * returned length as two separate numbers rather than one.
 *
 * THE SECOND DISTINCTION is that a matched setting VALUE never leaves this
 * class. `matchedSettingKeys` names keys; a test asserts the searched value
 * appears nowhere in the encoded result, which is the assertion that would fail
 * the day someone adds a helpful `matchedValue` member.
 *
 * NO TEST DOUBLE APPEARS IN THIS FILE, and its absence is the point. The class
 * under test calls no WordPress function and touches no plugin, so every case
 * here is a plain array in and a plain array out. A double would be a place for
 * the test to disagree with the site.
 *
 * @covers \SiteHelm\Modules\Elementor\ElementorTreeSearch
 */
final class ElementorTreeSearchTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var ElementorTreeSearch
	 */
	private ElementorTreeSearch $search;

	/**
	 * Builds the subject.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->search = new ElementorTreeSearch();
	}

	/**
	 * A small tree: a container holding a container holding two headings and a
	 * button.
	 *
	 * The nesting is real rather than flat because depth, the descent into
	 * grandchildren and the top-level-key rule are all things a flat tree cannot
	 * tell apart from a broken walk.
	 *
	 * @return array[] The raw tree.
	 */
	private function tree(): array {
		return [
			[
				'id'       => 'aaa111',
				'elType'   => 'container',
				'settings' => [ 'background_color' => '#ffffff' ],
				'elements' => [
					[
						'id'       => 'bbb222',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'ccc333',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Call us on 0800 000 000' ],
								'elements'   => [],
							],
							[
								'id'         => 'ddd444',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Our opening hours' ],
								'elements'   => [],
							],
							[
								'id'         => 'eee555',
								'elType'     => 'widget',
								'widgetType' => 'button',
								'settings'   => [
									'text' => 'Book',
									'link' => [
										'url'         => 'https://example.test/book',
										'is_external' => true,
									],
								],
								'elements'   => [],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Runs a search over the sample tree.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   The limit.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function searched( array $filters, int $limit = 50 ): array {
		return $this->search->search( $this->tree(), $filters, $limit );
	}

	/**
	 * The ids in one result, in order.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return array<int, string|null> The ids.
	 */
	private function ids( array $result ): array {
		return array_map( static fn( array $match ): ?string => $match['id'], $result['matches'] );
	}

	/**
	 * A widget-type filter returns only widgets of that type.
	 */
	public function test_a_widget_type_filter_returns_only_widgets_of_that_type(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading' ] );

		$this->assertSame( [ 'ccc333', 'ddd444' ], $this->ids( $result ) );
		$this->assertSame( 2, $result['matchCount'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * An element-type filter matches containers, which carry no widget type.
	 */
	public function test_an_element_type_filter_matches_containers(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'container' ] );

		$this->assertSame( [ 'aaa111', 'bbb222' ], $this->ids( $result ) );
		$this->assertNull( $result['matches'][0]['widgetType'] );
		$this->assertSame( 'container', $result['matches'][0]['kind'] );
	}

	/**
	 * A widget-type filter never matches a container.
	 *
	 * A container storing a stray `widgetType` — third parties write
	 * `_elementor_data` directly — must not answer a widget filter, because the
	 * client's next call treats what came back as a replaceable widget.
	 */
	public function test_a_container_storing_a_stray_widget_type_does_not_match_a_widget_filter(): void {
		$result = $this->search->search(
			[
				[
					'id'         => 'fff666',
					'elType'     => 'container',
					'widgetType' => 'heading',
					'elements'   => [],
				],
			],
			[ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading' ],
			50
		);

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['matchCount'] );
	}

	/**
	 * Filters narrow rather than widen.
	 */
	public function test_two_filters_are_conjunctive(): void {
		$both = $this->searched(
			[
				ElementorTreeSearch::FILTER_WIDGET_TYPE      => 'heading',
				ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800',
			]
		);

		$this->assertSame( [ 'ccc333' ], $this->ids( $both ) );

		// Each filter alone matches more, which is what makes the pair above a
		// narrowing rather than a coincidence.
		$this->assertCount( 2, $this->searched( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading' ] )['matches'] );
		$this->assertCount( 1, $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800' ] )['matches'] );
	}

	/**
	 * All three filters together still narrow.
	 */
	public function test_all_three_filters_are_conjunctive(): void {
		$result = $this->searched(
			[
				ElementorTreeSearch::FILTER_EL_TYPE          => 'widget',
				ElementorTreeSearch::FILTER_WIDGET_TYPE      => 'button',
				ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'Book',
			]
		);

		$this->assertSame( [ 'eee555' ], $this->ids( $result ) );
	}

	/**
	 * One filter disagreeing with the others excludes the element.
	 */
	public function test_an_element_failing_one_of_three_filters_does_not_match(): void {
		$result = $this->searched(
			[
				ElementorTreeSearch::FILTER_EL_TYPE          => 'widget',
				ElementorTreeSearch::FILTER_WIDGET_TYPE      => 'button',
				ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'opening hours',
			]
		);

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['matchCount'] );
	}

	/**
	 * The needle is matched case-insensitively and by substring.
	 */
	public function test_the_needle_matches_case_insensitively_and_by_substring(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'OPENING' ] );

		$this->assertSame( [ 'ddd444' ], $this->ids( $result ) );
		$this->assertSame( [ 'title' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	/**
	 * A value nested inside a setting is reported under its top-level key.
	 *
	 * `link` holds a map, and `example.test` lives inside it. The client can
	 * write `link`; it cannot write `link.url`, so `link` is the honest answer.
	 */
	public function test_a_nested_match_is_reported_under_its_top_level_key(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'example.test' ] );

		$this->assertSame( [ 'eee555' ], $this->ids( $result ) );
		$this->assertSame( [ 'link' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	/**
	 * Every matching top-level key is named, not just the first.
	 */
	public function test_every_matching_key_is_named(): void {
		$result = $this->search->search(
			[
				[
					'id'       => 'ggg777',
					'elType'   => 'widget',
					'settings' => [
						'title'       => 'Sale',
						'subtitle'    => 'Summer sale',
						'description' => 'Nothing here',
					],
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'sale' ],
			50
		);

		$this->assertSame( [ 'title', 'subtitle' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	/**
	 * The matched value appears nowhere in the encoded result.
	 *
	 * THE REGRESSION LOCK FOR DECISION 5. It is asserted against the ENCODED
	 * result rather than against a known member, because the defect it guards
	 * against is a new member nobody thought to check.
	 */
	public function test_no_matched_setting_value_appears_anywhere_in_the_result(): void {
		$result  = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800' ] );
		$encoded = (string) json_encode( $result );

		$this->assertStringNotContainsString( 'Call us on', $encoded );
		$this->assertStringNotContainsString( '0800 000 000', $encoded );
		$this->assertStringContainsString( 'title', $encoded );
	}

	/**
	 * A search that matches nothing answers an empty list, not a refusal.
	 */
	public function test_a_search_matching_nothing_answers_an_empty_list(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'nothing-like-this' ] );

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['matchCount'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * An element with no settings does not match a settings filter.
	 *
	 * The needle is one that DOES match a sibling, so the test fails if the walk
	 * stops matching altogether rather than only when the empty-settings element
	 * is wrongly included.
	 */
	public function test_an_element_with_no_settings_does_not_match_a_settings_filter(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '#ffffff' ] );

		$this->assertSame( [ 'aaa111' ], $this->ids( $result ) );
	}

	/**
	 * A settings key stored as something other than a map matches nothing.
	 */
	public function test_settings_stored_as_a_string_match_nothing(): void {
		$result = $this->search->search(
			[
				[
					'id'       => 'hhh888',
					'elType'   => 'widget',
					'settings' => 'corrupted',
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'corrupted' ],
			50
		);

		$this->assertSame( 0, $result['matchCount'] );
	}

	/**
	 * A boolean setting never matches, whatever the needle.
	 *
	 * `true` stringifies to `'1'` and `false` to `''`. Comparing them as text
	 * would make every search for "1" match every element with a switch turned
	 * on, and an empty needle match every element on the page.
	 */
	public function test_a_boolean_setting_never_matches(): void {
		$result = $this->search->search(
			[
				[
					'id'       => 'iii999',
					'elType'   => 'widget',
					'settings' => [
						'is_external' => true,
						'is_hidden'   => false,
					],
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '1' ],
			50
		);

		$this->assertSame( 0, $result['matchCount'] );
	}

	/**
	 * A numeric setting matches by its text.
	 */
	public function test_a_numeric_setting_matches_by_its_text(): void {
		$result = $this->search->search(
			[
				[
					'id'       => 'jjj000',
					'elType'   => 'widget',
					'settings' => [
						'columns' => 12,
						'ratio'   => 1.5,
					],
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '1' ],
			50
		);

		$this->assertSame( [ 'columns', 'ratio' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	/**
	 * Depth counts the elements enclosing a match.
	 */
	public function test_depth_counts_the_enclosing_elements(): void {
		$by_id = [];

		foreach ( $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'container' ] )['matches'] as $match ) {
			$by_id[ $match['id'] ] = $match['depth'];
		}

		$this->assertSame( 0, $by_id['aaa111'] );
		$this->assertSame( 1, $by_id['bbb222'] );
		$this->assertSame( 2, $this->searched( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading' ] )['matches'][0]['depth'] );
	}

	/**
	 * Matches arrive in document order.
	 */
	public function test_matches_arrive_in_document_order(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ] );

		$this->assertSame( [ 'ccc333', 'ddd444', 'eee555' ], $this->ids( $result ) );
	}

	/**
	 * The limit bounds the returned list while the count carries the total.
	 *
	 * THE CENTRAL ASSERTION OF THIS FILE. Two numbers, deliberately different: a
	 * count that stopped where the collection stopped would make `truncated`
	 * permanently false and every over-large answer look complete.
	 */
	public function test_the_count_exceeds_the_returned_length_when_truncated(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ], 1 );

		$this->assertCount( 1, $result['matches'] );
		$this->assertSame( 3, $result['matchCount'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( [ 'ccc333' ], $this->ids( $result ) );
	}

	/**
	 * A limit exactly equal to the number of matches is not a truncation.
	 *
	 * The off-by-one that would make every complete answer claim to be truncated
	 * lives precisely here.
	 */
	public function test_a_limit_equal_to_the_match_count_is_not_truncated(): void {
		$result = $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ], 3 );

		$this->assertCount( 3, $result['matches'] );
		$this->assertSame( 3, $result['matchCount'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * An element storing no identifier is returned with a null id.
	 */
	public function test_an_element_storing_no_identifier_is_returned_with_a_null_id(): void {
		$result = $this->search->search(
			[
				[
					'elType'   => 'widget',
					'settings' => [ 'title' => 'Findable' ],
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'findable' ],
			50
		);

		$this->assertSame( 1, $result['matchCount'] );
		$this->assertNull( $result['matches'][0]['id'] );
	}

	/**
	 * An identifier stored as an empty string reads as no identifier.
	 */
	public function test_an_empty_identifier_reads_as_no_identifier(): void {
		$result = $this->search->search(
			[
				[
					'id'       => '',
					'elType'   => 'widget',
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ],
			50
		);

		$this->assertNull( $result['matches'][0]['id'] );
	}

	/**
	 * A member of the tree that is not an array is skipped rather than counted.
	 */
	public function test_a_tree_member_that_is_not_an_array_is_skipped(): void {
		$result = $this->search->search(
			[
				'stray',
				[
					'id'       => 'kkk111',
					'elType'   => 'widget',
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ],
			50
		);

		$this->assertSame( [ 'kkk111' ], $this->ids( $result ) );
	}

	/**
	 * An element type stored as something other than a scalar reads as blank.
	 *
	 * `(string)` on an array is a fatal, and `_elementor_data` is third-party
	 * writable, so the walk must survive one.
	 */
	public function test_a_non_scalar_element_type_reads_as_blank(): void {
		$result = $this->search->search(
			[
				[
					'id'       => 'lll222',
					'elType'   => [ 'widget' ],
					'elements' => [],
				],
			],
			[ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ],
			50
		);

		$this->assertSame( 0, $result['matchCount'] );
	}

	/**
	 * A tree holding more elements than the shared bound is refused.
	 */
	public function test_a_tree_past_the_node_bound_is_refused(): void {
		$flat = [];

		for ( $i = 0; $i <= ElementorTree::MAX_NODES; $i++ ) {
			$flat[] = [
				'id'       => 'n' . $i,
				'elType'   => 'widget',
				'elements' => [],
			];
		}

		$refusal = $this->refusal( $flat, [ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'more elements', $refusal->getMessage() );
	}

	/**
	 * A tree nested deeper than the shared bound is refused.
	 */
	public function test_a_tree_past_the_depth_bound_is_refused(): void {
		$node = [
			'id'       => 'deep',
			'elType'   => 'container',
			'elements' => [],
		];

		for ( $i = 0; $i <= ElementorTree::MAX_DEPTH; $i++ ) {
			$node = [
				'id'       => 'c' . $i,
				'elType'   => 'container',
				'elements' => [ $node ],
			];
		}

		$refusal = $this->refusal( [ $node ], [ ElementorTreeSearch::FILTER_EL_TYPE => 'container' ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'deeper', $refusal->getMessage() );
	}

	/**
	 * The bounds are the tree read's, not a second pair.
	 *
	 * A document searchable but not readable, or readable but not searchable,
	 * would be a bound that exists in one place and not the other.
	 */
	public function test_a_tree_just_inside_the_node_bound_is_searched(): void {
		$flat = [];

		for ( $i = 0; $i < ElementorTree::MAX_NODES; $i++ ) {
			$flat[] = [
				'id'       => 'n' . $i,
				'elType'   => 'widget',
				'elements' => [],
			];
		}

		$result = $this->search->search( $flat, [ ElementorTreeSearch::FILTER_EL_TYPE => 'widget' ], 1 );

		$this->assertSame( ElementorTree::MAX_NODES, $result['matchCount'] );
	}

	/**
	 * Runs a search expected to refuse and returns the refusal.
	 *
	 * A bare `expectException( OperationException::class )` passes for any of the
	 * eleven error codes and so proves nothing about which one was raised. Each
	 * caller asserts the code itself.
	 *
	 * @param array[]              $tree    The raw tree.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $tree, array $filters ): OperationException {
		try {
			$this->search->search( $tree, $filters, 50 );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The search was expected to refuse and did not.' );
	}
}
