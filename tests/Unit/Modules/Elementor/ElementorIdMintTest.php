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
}
