<?php
/**
 * Tests for ElementorIdMint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Tests\TestCase;

/**
 * Deterministic element id minting (spec Decision 2).
 *
 * NO TEST DOUBLE APPEARS IN THIS FILE and none can: the mint calls no WordPress
 * function, names no `\Elementor\` symbol, and reads no clock, no randomness and
 * no global. That is the whole point of it, so the tests are pure too.
 *
 * THE LOAD-BEARING INVARIANT IS DETERMINISM. `planChange()` runs twice and the
 * two payloads are digest-compared, so an id that differs between the preview
 * run and the apply run makes the plan un-appliable — every time, for every
 * write in this phase. The derivation is restated literally in
 * test_the_derivation_is_the_one_the_spec_freezes rather than recomputed through
 * the class under test: a test that derived the expectation from the code it is
 * testing would agree with any derivation at all, including a random one.
 */
final class ElementorIdMintTest extends TestCase {

	/**
	 * The seed shape a caller assembles: operationId, postId, state
	 * fingerprint and payload seed, NUL-joined.
	 */
	private const SEED = "elementor-element-add\0" . "42\0" . "fp-abc\0" . 'payload-1';

	/**
	 * The first three attempts of self::SEED, as sha256 fixes them.
	 */
	private const ATTEMPT_0 = 'c7069d9';
	private const ATTEMPT_1 = 'a0930d5';
	private const ATTEMPT_2 = '0519b1e';

	private ElementorIdMint $mint;

	protected function setUp(): void {
		parent::setUp();
		$this->mint = new ElementorIdMint();
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
			'id'                    => $id,
			'elType'                => 'container',
			'settings'              => [ 'padding' => '20px' ],
			'zzz_unknown_vendor_key' => [ 'written by' => 'some third-party plugin' ],
			'elements'              => $children,
		];
	}

	public function test_the_derivation_is_the_one_the_spec_freezes(): void {
		$this->assertSame( self::ATTEMPT_0, $this->mint->mint( self::SEED, [] ) );
	}

	public function test_the_same_seed_and_existing_ids_mint_the_same_id(): void {
		$first  = $this->mint->mint( self::SEED, [ 'aaa1111', 'bbb2222' ] );
		$second = $this->mint->mint( self::SEED, [ 'aaa1111', 'bbb2222' ] );

		$this->assertSame( $first, $second );
	}

	public function test_the_minted_id_has_elementor_s_own_shape(): void {
		$id = $this->mint->mint( self::SEED, [] );

		$this->assertSame( ElementorIdMint::ID_LENGTH, strlen( $id ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $id );
	}

	public function test_a_different_seed_mints_a_different_id(): void {
		$this->assertNotSame(
			$this->mint->mint( self::SEED, [] ),
			$this->mint->mint( self::SEED . 'x', [] )
		);
	}

	public function test_a_colliding_first_attempt_steps_to_the_second(): void {
		$id = $this->mint->mint( self::SEED, [ self::ATTEMPT_0 ] );

		$this->assertSame( self::ATTEMPT_1, $id );
	}

	public function test_the_collision_walk_steps_past_every_taken_attempt(): void {
		$taken = [ self::ATTEMPT_0, self::ATTEMPT_1 ];

		$id = $this->mint->mint( self::SEED, $taken );

		$this->assertSame( self::ATTEMPT_2, $id );
		$this->assertNotContains( $id, $taken );
	}

	public function test_the_collision_walk_is_itself_deterministic(): void {
		$taken = [ self::ATTEMPT_0, self::ATTEMPT_1 ];

		$this->assertSame(
			$this->mint->mint( self::SEED, $taken ),
			$this->mint->mint( self::SEED, $taken )
		);
	}

	public function test_a_non_scalar_member_of_the_existing_set_is_ignored(): void {
		$id = $this->mint->mint( self::SEED, [ [ 'not', 'an', 'id' ], null ] );

		$this->assertSame( self::ATTEMPT_0, $id );
	}

	public function test_reassign_re_ids_every_level_of_a_three_level_subtree(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'mid2222', [ $this->node( 'leaf333' ) ] ) ] );

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$tree = $result['tree'];
		$this->assertNotSame( 'root111', $tree['id'] );
		$this->assertNotSame( 'mid2222', $tree['elements'][0]['id'] );
		$this->assertNotSame( 'leaf333', $tree['elements'][0]['elements'][0]['id'] );
	}

	public function test_reassign_maps_every_old_id_to_its_new_id(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'mid2222', [ $this->node( 'leaf333' ) ] ) ] );

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$this->assertSame( [ 'root111', 'mid2222', 'leaf333' ], array_keys( $result['map'] ) );
		$this->assertSame( $result['map']['root111'], $result['tree']['id'] );
		$this->assertSame( $result['map']['mid2222'], $result['tree']['elements'][0]['id'] );
		$this->assertSame( $result['map']['leaf333'], $result['tree']['elements'][0]['elements'][0]['id'] );
	}

	public function test_reassign_is_byte_identical_across_two_calls(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'mid2222', [ $this->node( 'leaf333' ) ] ) ] );

		$first  = $this->mint->reassign( $subtree, self::SEED, [ 'aaa1111' ] );
		$second = $this->mint->reassign( $subtree, self::SEED, [ 'aaa1111' ] );

		$this->assertSame( serialize( $first ), serialize( $second ) );
	}

	public function test_reassign_never_mints_an_id_the_document_already_holds(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'mid2222' ) ] );

		// The ids this subtree takes when the document holds none of them.
		$unobstructed = $this->mint->reassign( $subtree, self::SEED, [] );
		$taken        = array_values( $unobstructed['map'] );

		$result = $this->mint->reassign( $subtree, self::SEED, $taken );

		$this->assertNotContains( $result['tree']['id'], $taken );
		$this->assertNotContains( $result['tree']['elements'][0]['id'], $taken );
	}

	public function test_reassign_gives_two_siblings_distinct_ids(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'kid1111' ), $this->node( 'kid2222' ) ] );

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$ids = [
			$result['tree']['id'],
			$result['tree']['elements'][0]['id'],
			$result['tree']['elements'][1]['id'],
		];
		$this->assertSame( $ids, array_unique( $ids ) );
	}

	public function test_reassign_preserves_every_key_it_does_not_touch(): void {
		$subtree = $this->node( 'root111', [ $this->node( 'kid1111' ) ] );

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$result['tree']['zzz_unknown_vendor_key']
		);
		$this->assertSame( [ 'padding' => '20px' ], $result['tree']['elements'][0]['settings'] );
		$this->assertSame( 'container', $result['tree']['elements'][0]['elType'] );
	}

	public function test_reassign_leaves_a_node_with_no_stored_id_unnamed(): void {
		$subtree = [
			'elType'   => 'container',
			'elements' => [ $this->node( 'kid1111' ) ],
		];

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$this->assertArrayNotHasKey( 'id', $result['tree'] );
		$this->assertSame( [ 'kid1111' ], array_keys( $result['map'] ) );
	}

	public function test_reassign_re_ids_a_leaf_that_stores_no_child_list(): void {
		// A widget stores no `elements` key at all, which is the ordinary leaf
		// shape and must not be read as a damaged one.
		$leaf = [
			'id'         => 'leaf111',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => [ 'title' => 'A stored title' ],
		];

		$result = $this->mint->reassign( $leaf, self::SEED, [] );

		$this->assertSame( [ 'leaf111' ], array_keys( $result['map'] ) );
		$this->assertSame( $result['map']['leaf111'], $result['tree']['id'] );
		$this->assertArrayNotHasKey( 'elements', $result['tree'] );
		$this->assertSame( [ 'title' => 'A stored title' ], $result['tree']['settings'] );
	}

	public function test_reassign_skips_a_non_array_member_of_a_child_list(): void {
		$subtree = $this->node( 'root111', [] );
		// A damaged export: a scalar where a child element belongs.
		$subtree['elements'] = [ 'not-an-element', $this->node( 'kid1111' ) ];

		$result = $this->mint->reassign( $subtree, self::SEED, [] );

		$this->assertSame( 'not-an-element', $result['tree']['elements'][0] );
		$this->assertNotSame( 'kid1111', $result['tree']['elements'][1]['id'] );
	}

	// ------------------------------------------------------- nameTree()

	/**
	 * One raw element with NO id key at all, which is the shape a caller who has
	 * composed a layout from scratch sends.
	 *
	 * @param array[] $children The raw child nodes.
	 *
	 * @return array<string, mixed> The raw node.
	 */
	private function unnamed( array $children = [] ): array {
		return [
			'elType'   => 'container',
			'settings' => [ 'padding' => '20px' ],
			'elements' => $children,
		];
	}

	/**
	 * Every id in a named list, in document order.
	 *
	 * @param array[] $elements The named element list.
	 *
	 * @return string[] The ids.
	 */
	private function ids( array $elements ): array {
		$ids = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, $this->ids( $element['elements'] ) );
			}
		}

		return $ids;
	}

	public function test_name_tree_names_a_node_that_arrived_without_an_id(): void {
		$named = $this->mint->nameTree( [ $this->unnamed() ], self::SEED, [] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $named[0]['id'] );
	}

	public function test_name_tree_names_a_node_whose_id_is_the_empty_string(): void {
		$element       = $this->unnamed();
		$element['id'] = '';

		$named = $this->mint->nameTree( [ $element ], self::SEED, [] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $named[0]['id'] );
	}

	public function test_name_tree_names_a_node_whose_id_is_not_scalar(): void {
		$element       = $this->unnamed();
		$element['id'] = [ 'not', 'an', 'id' ];

		$named = $this->mint->nameTree( [ $element ], self::SEED, [] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $named[0]['id'] );
	}

	public function test_name_tree_leaves_a_supplied_id_exactly_as_it_was_sent(): void {
		$named = $this->mint->nameTree( [ $this->node( 'root111' ) ], self::SEED, [] );

		$this->assertSame( 'root111', $named[0]['id'] );
	}

	public function test_name_tree_names_every_level_of_a_deep_tree(): void {
		$tree = [ $this->unnamed( [ $this->unnamed( [ $this->unnamed() ] ) ] ) ];

		$named = $this->mint->nameTree( $tree, self::SEED, [] );

		$this->assertCount( 3, $this->ids( $named ) );

		foreach ( $this->ids( $named ) as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $id );
		}
	}

	/**
	 * THE LOAD-BEARING CASE. `planChange()` runs twice and the two payloads are
	 * digest-compared, so a naming pass that produced different output on the
	 * second run would make every plan it ever built un-appliable.
	 */
	public function test_name_tree_is_byte_identical_across_two_calls(): void {
		$tree = [ $this->unnamed( [ $this->unnamed(), $this->node( 'kid1111' ) ] ), $this->unnamed() ];

		$first  = $this->mint->nameTree( $tree, self::SEED, [ 'aaa1111' ] );
		$second = $this->mint->nameTree( $tree, self::SEED, [ 'aaa1111' ] );

		$this->assertSame( serialize( $first ), serialize( $second ) );
	}

	public function test_name_tree_gives_every_node_in_a_tree_a_distinct_id(): void {
		$tree = [
			$this->unnamed( [ $this->unnamed(), $this->unnamed() ] ),
			$this->unnamed( [ $this->unnamed() ] ),
		];

		$ids = $this->ids( $this->mint->nameTree( $tree, self::SEED, [] ) );

		$this->assertCount( 5, $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	/**
	 * A minted id must not land on an id the CALLER supplied further down the same
	 * tree, which is why the supplied ids are collected in a pass of their own
	 * before any minting starts.
	 */
	public function test_name_tree_never_mints_an_id_the_caller_supplied_elsewhere(): void {
		// What the first node takes when nothing obstructs it.
		$unobstructed = $this->mint->nameTree( [ $this->unnamed() ], self::SEED, [] );
		$would_take   = (string) $unobstructed[0]['id'];

		// Now the same tree, with a LATER sibling already carrying that very id.
		$tree = [ $this->unnamed(), $this->node( $would_take ) ];

		$named = $this->mint->nameTree( $tree, self::SEED, [] );

		$this->assertNotSame( $would_take, $named[0]['id'] );
		$this->assertSame( $would_take, $named[1]['id'] );
	}

	public function test_name_tree_never_mints_an_id_the_destination_already_holds(): void {
		$unobstructed = $this->mint->nameTree( [ $this->unnamed() ], self::SEED, [] );
		$would_take   = (string) $unobstructed[0]['id'];

		$named = $this->mint->nameTree( [ $this->unnamed() ], self::SEED, [ $would_take ] );

		$this->assertNotSame( $would_take, $named[0]['id'] );
	}

	/**
	 * A scalar where a child belongs consumes NO position, so the siblings after
	 * it keep the paths `ElementorTreeDiff` would name them by — and therefore the
	 * ids they would have taken had the damaged member not been there.
	 */
	public function test_name_tree_skips_a_scalar_without_shifting_the_paths_after_it(): void {
		$clean   = [ $this->unnamed(), $this->unnamed() ];
		$damaged = [ 'not-an-element', $this->unnamed(), $this->unnamed() ];

		$named_clean   = $this->mint->nameTree( $clean, self::SEED, [] );
		$named_damaged = $this->mint->nameTree( $damaged, self::SEED, [] );

		$this->assertSame( 'not-an-element', $named_damaged[0] );
		$this->assertSame( $this->ids( $named_clean ), $this->ids( $named_damaged ) );
	}

	public function test_name_tree_preserves_every_key_it_does_not_touch(): void {
		$named = $this->mint->nameTree( [ $this->node( 'root111', [ $this->unnamed() ] ) ], self::SEED, [] );

		$this->assertSame( 'container', $named[0]['elType'] );
		$this->assertSame(
			[ 'written by' => 'some third-party plugin' ],
			$named[0]['zzz_unknown_vendor_key']
		);
		$this->assertSame( [ 'padding' => '20px' ], $named[0]['elements'][0]['settings'] );
	}

	public function test_name_tree_names_a_leaf_that_stores_no_child_list(): void {
		$leaf = [
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => [ 'title' => 'A stored title' ],
		];

		$named = $this->mint->nameTree( [ $leaf ], self::SEED, [] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $named[0]['id'] );
		$this->assertArrayNotHasKey( 'elements', $named[0] );
	}

	/**
	 * The two rules are deliberately opposite, and this case pins the difference
	 * so that a later reader cannot "tidy" one into the other: `reassign()` copies
	 * elements that already exist and leaves an unnamed one unnamed, while
	 * `nameTree()` originates elements that have never existed and names them.
	 */
	public function test_name_tree_and_reassign_disagree_about_an_unnamed_node_on_purpose(): void {
		$unnamed = $this->unnamed();

		$copied = $this->mint->reassign( $unnamed, self::SEED, [] );
		$named  = $this->mint->nameTree( [ $unnamed ], self::SEED, [] );

		$this->assertArrayNotHasKey( 'id', $copied['tree'] );
		$this->assertArrayHasKey( 'id', $named[0] );
	}

	/**
	 * An icon-list's repeater exactly as a caller composing one from scratch
	 * sends it: rows of real control values, and not an `_id` anywhere.
	 *
	 * @param string[] $texts One text per row.
	 *
	 * @return array<string, mixed> The raw settings map.
	 */
	private function icon_list( array $texts = [ 'Fast setup', 'No lock-in' ] ): array {
		$rows = [];

		foreach ( $texts as $text ) {
			$rows[] = [
				'text' => $text,
				'link' => [ 'url' => '' ],
			];
		}

		return [ 'icon_list' => $rows ];
	}

	/**
	 * Every row `_id` one repeater setting carries, in row order.
	 *
	 * @param array<string, mixed> $settings The named settings map.
	 * @param string               $key      The repeater's setting key.
	 *
	 * @return string[] The row ids.
	 */
	private function row_ids( array $settings, string $key = 'icon_list' ): array {
		$ids = [];

		foreach ( $settings[ $key ] as $row ) {
			$ids[] = $row['_id'] ?? null;
		}

		return $ids;
	}

	public function test_name_repeaters_names_a_row_that_arrived_without_an_id(): void {
		$named = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] );

		foreach ( $this->row_ids( $named ) as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $id );
		}
	}

	public function test_name_repeaters_is_byte_identical_across_two_calls(): void {
		$this->assertSame(
			$this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] ),
			$this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] )
		);
	}

	public function test_name_repeaters_leaves_a_supplied_row_id_exactly_as_it_was_sent(): void {
		$settings                          = $this->icon_list();
		$settings['icon_list'][0]['_id']   = 'abc1234';

		$named = $this->mint->nameRepeaters( $settings, self::SEED, [] );

		$this->assertSame( 'abc1234', $named['icon_list'][0]['_id'] );
	}

	public function test_name_repeaters_gives_two_sibling_rows_distinct_ids(): void {
		$named = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] );
		$ids   = $this->row_ids( $named );

		$this->assertNotSame( $ids[0], $ids[1] );
	}

	/**
	 * Two rows holding byte-identical content still diverge, because the row
	 * seed folds in the row's position.
	 */
	public function test_name_repeaters_gives_two_identical_rows_distinct_ids(): void {
		$named = $this->mint->nameRepeaters( $this->icon_list( [ 'Same', 'Same' ] ), self::SEED, [] );
		$ids   = $this->row_ids( $named );

		$this->assertNotSame( $ids[0], $ids[1] );
	}

	public function test_name_repeaters_gives_two_setting_keys_on_one_widget_distinct_ids(): void {
		$settings = $this->icon_list();
		$settings['tabs'] = $settings['icon_list'];

		$named = $this->mint->nameRepeaters( $settings, self::SEED, [] );

		$this->assertSame( [], array_intersect( $this->row_ids( $named ), $this->row_ids( $named, 'tabs' ) ) );
	}

	/**
	 * The row seed quotes the ELEMENT's id, which `nameTree()` supplies, so the
	 * same repeater content in two widgets does not produce the same row ids.
	 */
	public function test_name_tree_gives_the_same_repeater_in_two_widgets_distinct_row_ids(): void {
		$widget = [
			'elType'     => 'widget',
			'widgetType' => 'icon-list',
			'settings'   => $this->icon_list(),
		];

		$named = $this->mint->nameTree( [ $widget, $widget ], self::SEED, [] );

		$this->assertSame(
			[],
			array_intersect(
				$this->row_ids( $named[0]['settings'] ),
				$this->row_ids( $named[1]['settings'] )
			)
		);
	}

	public function test_name_tree_names_the_rows_of_a_widget_nested_deep_in_a_tree(): void {
		$widget = [
			'elType'     => 'widget',
			'widgetType' => 'icon-list',
			'settings'   => $this->icon_list(),
		];

		$named = $this->mint->nameTree( [ $this->unnamed( [ $this->unnamed( [ $widget ] ) ] ) ], self::SEED, [] );
		$rows  = $this->row_ids( $named[0]['elements'][0]['elements'][0]['settings'] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[0] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[1] );
	}

	public function test_name_repeaters_never_mints_a_row_id_a_sibling_row_supplied(): void {
		$unobstructed = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] );
		$would_take   = (string) $this->row_ids( $unobstructed )[0];

		$settings                        = $this->icon_list();
		$settings['icon_list'][1]['_id'] = $would_take;

		$named = $this->mint->nameRepeaters( $settings, self::SEED, [] );

		$this->assertNotSame( $would_take, $named['icon_list'][0]['_id'] );
		$this->assertSame( $would_take, $named['icon_list'][1]['_id'] );
	}

	/**
	 * Elementor's GALLERY controls store attachments as a list of associative
	 * arrays — `[ 'id' => 12, 'url' => '…' ]` — which passes every structural
	 * clause a repeater passes. It is excluded by key set on purpose, because an
	 * `_id` written into a media list is a key Elementor never wrote.
	 */
	public function test_name_repeaters_leaves_a_gallery_setting_alone(): void {
		$settings = [
			'gallery' => [
				[
					'id'  => 12,
					'url' => 'https://example.test/one.jpg',
				],
				[
					'id'  => 13,
					'url' => 'https://example.test/two.jpg',
				],
			],
		];

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	/**
	 * A list of LISTS is not a repeater: a repeater row is a map of control name
	 * to value, so it is associative by construction.
	 */
	public function test_name_repeaters_leaves_a_list_of_lists_alone(): void {
		$settings = [ 'shapes' => [ [ 1, 2 ], [ 3, 4 ] ] ];

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	public function test_name_repeaters_leaves_a_scalar_setting_alone(): void {
		$settings = [
			'title' => 'Our services',
			'size'  => 24,
		];

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	/**
	 * An ordinary settings MAP — a link control, a dimensions control — is a
	 * single value, not a row list, and keeps its keys untouched.
	 */
	public function test_name_repeaters_leaves_an_ordinary_control_map_alone(): void {
		$settings = [
			'padding' => [
				'unit'   => 'px',
				'top'    => '20',
				'bottom' => '20',
			],
		];

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	public function test_name_repeaters_leaves_an_empty_repeater_alone(): void {
		$settings = [ 'icon_list' => [] ];

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	/**
	 * A MALFORMED MEMBER DISQUALIFIES THE WHOLE SETTING, which is deliberately
	 * NOT `nameTree()`'s skip-without-consuming-a-position rule. There the
	 * surrounding list is unambiguously an element list, so a scalar in it is
	 * damage; here the scalar is itself the evidence that the value was never a
	 * repeater, so the honest reading is to name none of it. Nothing is
	 * corrupted either way, and this direction cannot half-name a widget.
	 */
	public function test_name_repeaters_leaves_the_whole_setting_alone_when_a_member_is_a_scalar(): void {
		$settings                     = $this->icon_list();
		$settings['icon_list'][]      = 'not-a-row';

		$this->assertSame( $settings, $this->mint->nameRepeaters( $settings, self::SEED, [] ) );
	}

	/**
	 * A repeater whose rows hold a repeater of their own — Elementor has them —
	 * is named all the way down, and the recursion is not bounded because a PHP
	 * array is a finite tree.
	 */
	public function test_name_repeaters_names_a_repeater_nested_inside_a_row(): void {
		$settings = [
			'tabs' => [
				[
					'tab_title' => 'First',
					'sub_items' => [ [ 'text' => 'Inner one' ], [ 'text' => 'Inner two' ] ],
				],
			],
		];

		$named = $this->mint->nameRepeaters( $settings, self::SEED, [] );
		$inner = $named['tabs'][0]['sub_items'];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $named['tabs'][0]['_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $inner[0]['_id'] );
		$this->assertNotSame( $inner[0]['_id'], $inner[1]['_id'] );
	}

	public function test_name_repeaters_preserves_every_key_it_does_not_touch(): void {
		$named = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] );

		$this->assertSame( 'Fast setup', $named['icon_list'][0]['text'] );
		$this->assertSame( [ 'url' => '' ], $named['icon_list'][0]['link'] );
	}

	public function test_name_repeaters_never_mints_a_row_id_the_destination_already_holds(): void {
		$unobstructed = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [] );
		$would_take   = (string) $this->row_ids( $unobstructed )[0];

		$named = $this->mint->nameRepeaters( $this->icon_list(), self::SEED, [ $would_take ] );

		$this->assertNotSame( $would_take, $this->row_ids( $named )[0] );
	}
}
