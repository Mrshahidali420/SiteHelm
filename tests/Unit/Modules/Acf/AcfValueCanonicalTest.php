<?php
/**
 * Tests for AcfValueCanonical, the digest-stable projection of a value being written.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfValueCanonical;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The one property this class exists for: the same value projects the same way, twice.
 *
 * `PlanAdmission::assertPayloadMatches()` digests the projection at preview and
 * compares the digest at apply, so a projection that answered differently for two
 * structurally equal values would refuse an apply that was never changed — and one
 * that answered the SAME for two different values would apply a payload the
 * operator never approved. Both failures are silent, which is why the determinism
 * case below is the point of the file and not a footnote to it.
 *
 * NO PROCESS ISOLATION AND NO DOUBLES. The projection is pure: no clock, no
 * globals, no ACF call, no WordPress function. If a test here ever needs a double,
 * that is evidence the purity was lost rather than a reason to install one.
 *
 * IT DISPATCHES ON SHAPE AND NEVER ON A FIELD TYPE (spec Decision 4). Every case
 * below hands the projection a bare value with no definition beside it, which is
 * what makes a type switch unwritable rather than merely discouraged.
 */
final class AcfValueCanonicalTest extends TestCase {

	/**
	 * @return AcfValueCanonical The projection under test.
	 */
	private function canonical(): AcfValueCanonical {
		return new AcfValueCanonical();
	}

	// ------------------------------------------------------------------- scalars

	/**
	 * BOTH BOOLEANS BECOME INTEGERS, and the false case is the one that matters.
	 * `(int) false` is 0 and an implementation that mapped only true would leave
	 * false as false — which JSON-encodes differently from 0, so the digest taken
	 * at preview and the digest taken at apply would disagree for a checkbox an
	 * operator switched off.
	 */
	public function test_true_projects_to_the_integer_one(): void {
		$this->assertSame( 1, $this->canonical()->project( true ) );
	}

	public function test_false_projects_to_the_integer_zero(): void {
		$this->assertSame( 0, $this->canonical()->project( false ) );
	}

	public function test_a_string_projects_to_itself(): void {
		$this->assertSame( 'abc', $this->canonical()->project( 'abc' ) );
	}

	public function test_an_integer_projects_to_itself(): void {
		$this->assertSame( 42, $this->canonical()->project( 42 ) );
	}

	/**
	 * A float passes through as a float, and two spellings of one float agree.
	 *
	 * A float is the one scalar whose JSON bytes depend on the runtime — PHP's
	 * serialize_precision decides how many digits json_encode emits — so a
	 * projection that rounded, formatted, or stringified it would put a
	 * runtime-dependent value into a stored digest. It does none of those: the
	 * value is handed back untouched, and the encoding is left to the one place
	 * that encodes.
	 *
	 * @test
	 */
	public function test_a_float_projects_to_itself(): void {
		$this->assertSame( 1.5, $this->canonical()->project( 1.5 ) );
		$this->assertIsFloat(
			$this->canonical()->project( 2.0 ),
			'A whole-numbered float must not be narrowed to an integer.'
		);
		$this->assertSame(
			$this->canonical()->project( 0.1 + 0.2 ),
			$this->canonical()->project( 0.30000000000000004 ),
			'One float reached two ways projects to one value.'
		);
	}

	/**
	 * Null is a value an operator can legitimately write — it is how a field is
	 * cleared — so it must survive the projection as itself rather than becoming
	 * the null a truncation also answers with at some other depth.
	 */
	public function test_null_projects_to_itself(): void {
		$this->assertNull( $this->canonical()->project( null ) );
	}

	// -------------------------------------------------------------------- arrays

	/**
	 * A GAP IS CLOSED. ACF hands back repeater rows with whatever integer keys the
	 * editor's last delete left behind, and `[ 0 => 'a', 2 => 'b' ]` JSON-encodes
	 * as an object while `[ 'a', 'b' ]` encodes as an array. Two requests writing
	 * the same two rows would otherwise digest differently depending on how the
	 * rows were assembled.
	 */
	public function test_a_list_with_a_gap_is_reindexed(): void {
		$this->assertSame(
			[ 'a', 'b' ],
			$this->canonical()->project(
				[
					0 => 'a',
					2 => 'b',
				]
			)
		);
	}

	/**
	 * A list is ordered BY KEY, not by the order its members happened to arrive.
	 *
	 * `{"2":"a","0":"b"}` and `{"0":"b","2":"a"}` are two JSON spellings of one
	 * object; json_decode hands them back in different insertion orders, and a bare
	 * array_values() would answer ['a','b'] for the first and ['b','a'] for the
	 * second. Two spellings of one value digesting apart surfaces as a stale plan
	 * an operator cannot diagnose.
	 *
	 * @test
	 */
	public function test_a_positional_array_is_ordered_by_key_and_not_by_arrival(): void {
		$this->assertSame(
			[ 'b', 'a' ],
			$this->canonical()->project(
				[
					2 => 'a',
					0 => 'b',
				]
			),
			'The member at key 0 comes first however late it arrived.'
		);

		$this->assertSame(
			$this->canonical()->project( json_decode( '{"2":"a","0":"b"}', true ) ),
			$this->canonical()->project( json_decode( '{"0":"b","2":"a"}', true ) ),
			'Two JSON spellings of one object must project identically.'
		);
	}

	/**
	 * Integer keys sort as numbers, so 10 does not land before 2.
	 *
	 * @test
	 */
	public function test_a_positional_array_orders_its_keys_numerically(): void {
		$this->assertSame(
			[ 'two', 'ten' ],
			$this->canonical()->project(
				[
					10 => 'ten',
					2  => 'two',
				]
			)
		);
	}

	/**
	 * An associative array is KEY-SORTED, which is the whole of what makes two
	 * differently-ordered spellings of one map digest alike.
	 */
	public function test_an_associative_array_is_key_sorted(): void {
		$this->assertSame(
			[
				'alpha' => 1,
				'beta'  => 2,
				'gamma' => 3,
			],
			$this->canonical()->project(
				[
					'gamma' => 3,
					'alpha' => 1,
					'beta'  => 2,
				]
			)
		);
	}

	/**
	 * The sort is asserted on the KEY ORDER and not only on the pairs.
	 * `assertSame` on two associative arrays compares order in PHPUnit, but stating
	 * it separately is what stops a later refactor to `assertEquals` from silently
	 * removing the only assertion that can see an unsorted map.
	 */
	public function test_the_sorted_key_order_is_the_projection(): void {
		$this->assertSame(
			[ 'alpha', 'beta', 'gamma' ],
			array_keys(
				$this->canonical()->project(
					[
						'gamma' => 3,
						'alpha' => 1,
						'beta'  => 2,
					]
				)
			)
		);
	}

	/**
	 * The rules apply all the way down, not only at the top. A repeater is a list
	 * of maps, so a projection that canonicalized the outer list and left the rows
	 * alone would leave every row's key order — the thing that actually varies —
	 * untouched.
	 */
	public function test_nesting_is_canonicalized_recursively(): void {
		$projected = $this->canonical()->project(
			[
				2 => [
					'title' => 'Second',
					'order' => true,
				],
				0 => [
					'order' => false,
					'title' => 'First',
				],
			]
		);

		// The rows come back in KEY order — row 0 before row 2 — however they were
		// inserted, and each row's own members come back key-sorted.
		$this->assertSame(
			[
				[
					'order' => 0,
					'title' => 'First',
				],
				[
					'order' => 1,
					'title' => 'Second',
				],
			],
			$projected
		);
	}

	/**
	 * An empty array stays an empty array. It is how an operator clears a repeater,
	 * and a projection that answered null for it would make "remove every row"
	 * indistinguishable from "clear the field".
	 */
	public function test_an_empty_array_projects_to_an_empty_array(): void {
		$this->assertSame( [], $this->canonical()->project( [] ) );
	}

	// ------------------------------------------------------------------- objects

	/**
	 * An object is flattened to its public properties and then takes the array
	 * rule, sort and all. A caller can reach this with a decoded JSON object, and
	 * two objects carrying the same properties in a different declaration order
	 * must digest alike for the same reason two maps must.
	 */
	public function test_an_object_is_flattened_to_its_public_properties(): void {
		$value        = new stdClass();
		$value->gamma = 3;
		$value->alpha = 1;

		$this->assertSame(
			[
				'alpha' => 1,
				'gamma' => 3,
			],
			$this->canonical()->project( $value )
		);
	}

	/**
	 * Unwrapping an object is the SAME step expressed differently, not a step
	 * further in, which is what the normalizer does on the read side. If the
	 * unwrap consumed a level, an object and the map it flattens to would project
	 * differently at the same nesting — and the digest of one write would depend on
	 * whether the client sent a JSON object or an equivalent PHP array.
	 */
	public function test_flattening_an_object_does_not_consume_a_level_of_depth(): void {
		$object      = new stdClass();
		$object->one = 'deep';

		$this->assertSame(
			$this->canonical()->project( [ 'one' => 'deep' ], AcfFields::MAX_DEPTH - 1 ),
			$this->canonical()->project( $object, AcfFields::MAX_DEPTH - 1 )
		);
	}

	// ----------------------------------------------------------------- depth cap

	/**
	 * A SCALAR SITTING AT THE CAP IS PROJECTED AS ITSELF. The cap exists to stop an
	 * unbounded walk, and a leaf is not a walk; blanking it would drop a value the
	 * operator really sent while the request still reported success.
	 */
	public function test_a_scalar_at_the_depth_cap_is_projected_rather_than_dropped(): void {
		$this->assertSame( 'leaf', $this->canonical()->project( 'leaf', AcfFields::MAX_DEPTH ) );
	}

	/**
	 * A STRUCTURE AT THE CAP BECOMES null AND NOT A SENTINEL STRING. A sentinel is
	 * indistinguishable from a string a site really stores, and this projection
	 * feeds a digest that decides whether an apply matches its preview.
	 */
	public function test_a_structure_at_the_depth_cap_becomes_null(): void {
		$this->assertNull( $this->canonical()->project( [ 'still' => 'here' ], AcfFields::MAX_DEPTH ) );
	}

	/**
	 * The cap is reached by descending, not only by being handed a depth. This
	 * builds a real structure MAX_DEPTH + 1 levels deep from the default starting
	 * depth and asserts the bottom is gone while everything above it survived —
	 * an assertion the degenerate "everything is null" output would fail.
	 */
	public function test_a_structure_nested_past_the_cap_is_cut_off_at_the_cap(): void {
		$value = [ 'bottom' => 'leaf' ];

		for ( $level = 0; $level < AcfFields::MAX_DEPTH; $level++ ) {
			$value = [ 'down' => $value ];
		}

		$projected = $this->canonical()->project( $value );

		for ( $level = 0; $level < AcfFields::MAX_DEPTH; $level++ ) {
			$this->assertIsArray( $projected, 'Everything above the cap must survive.' );
			$this->assertArrayHasKey( 'down', $projected, 'Every level above the cap keeps its member.' );
			$projected = $projected['down'];
		}

		$this->assertNull( $projected, 'The level at the cap is dropped.' );
	}

	// ---------------------------------------------------------------- unencodable

	/**
	 * Anything JSON cannot carry becomes null rather than being cast. `(string)` on
	 * a resource is the text 'Resource id #3' — a value the site does not store,
	 * arriving in the payload as though it did.
	 */
	public function test_a_value_json_cannot_carry_becomes_null(): void {
		$handle = fopen( 'php://memory', 'rb' );

		$this->assertNull( $this->canonical()->project( $handle ) );

		fclose( $handle );
	}

	// -------------------------------------------------------------- determinism

	/**
	 * THE PROPERTY THE WHOLE CLASS EXISTS FOR, and the one assertPayloadMatches()
	 * relies on: two structurally equal maps whose members were assembled in a
	 * different order project to output that is identical, order included.
	 *
	 * Asserted with assertSame on the projections of two SEPARATELY BUILT values
	 * rather than on one value projected twice. Projecting one value twice is
	 * satisfied by any pure function, including one that preserved insertion order
	 * — which is exactly the implementation this test has to be able to fail.
	 */
	public function test_two_differently_ordered_spellings_of_one_value_project_identically(): void {
		$first = [
			'rows'  => [
				[
					'heading' => 'One',
					'body'    => 'First',
				],
			],
			'title' => 'A page',
			'live'  => true,
		];

		$second = [
			'live'  => true,
			'title' => 'A page',
			'rows'  => [
				[
					'body'    => 'First',
					'heading' => 'One',
				],
			],
		];

		$canonical = $this->canonical();

		$this->assertSame( $canonical->project( $first ), $canonical->project( $second ) );
	}

	/**
	 * And the digests agree, which is the form the plan admission actually compares
	 * them in. Encoding is what turns a difference in key ORDER into a difference in
	 * bytes, so this is the assertion that can see an unsorted projection even if
	 * assertSame above were ever weakened to assertEquals.
	 */
	public function test_two_differently_ordered_spellings_of_one_value_digest_identically(): void {
		$canonical = $this->canonical();

		$first = $canonical->project(
			[
				'b' => 2,
				'a' => 1,
			]
		);

		$second = $canonical->project(
			[
				'a' => 1,
				'b' => 2,
			]
		);

		$this->assertSame(
			hash( 'sha256', (string) json_encode( $first ) ),
			hash( 'sha256', (string) json_encode( $second ) )
		);
	}

	/**
	 * Two values that are NOT the same must not project alike. Without this, every
	 * determinism assertion above is also satisfied by a projection that answered a
	 * constant for everything.
	 */
	public function test_two_different_values_do_not_project_alike(): void {
		$canonical = $this->canonical();

		$this->assertNotSame(
			$canonical->project( [ 'a' => 1 ] ),
			$canonical->project( [ 'a' => 2 ] )
		);
	}
}
