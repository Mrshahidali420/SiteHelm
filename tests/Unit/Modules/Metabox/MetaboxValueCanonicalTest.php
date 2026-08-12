<?php
/**
 * Tests for the digest-stable value projection a Meta Box write plans against.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Modules\Metabox\MetaboxValueCanonical;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0051: the projection a plan's digest is taken over, and the dropped-write test.
 *
 * THE CLASS IS PURE AND THIS SUITE INSTALLS NOTHING. Determinism across two separate
 * requests is what PlanAdmission depends on, and a projection that consulted a
 * clock, a global or the site could not deliver it. A test here that needed a
 * doubled site would be evidence it had started.
 *
 * THE THREE PROPERTIES BEING PINNED are the three ways a digest silently drifts:
 * key ORDER must not matter, a gapped positional array must close, and a value at
 * the depth cap must become null rather than a sentinel a site could also store.
 */
final class MetaboxValueCanonicalTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var MetaboxValueCanonical
	 */
	private MetaboxValueCanonical $canonical;

	protected function setUp(): void {
		parent::setUp();

		$this->canonical = new MetaboxValueCanonical();
	}

	// ---------------------------------------------------------- the projection

	/**
	 * A boolean is 1 or 0 and never the empty string PHP casts false to. Left as a
	 * bool it would compare against a stored '1' or '' and refuse a correct write.
	 */
	public function test_booleans_project_to_one_and_zero(): void {
		$this->assertSame( 1, $this->canonical->projectInbound( true ), 'true is the stored 1.' );
		$this->assertSame( 0, $this->canonical->projectInbound( false ), 'false is the stored 0, not an empty string.' );
	}

	/**
	 * A map is ordered by key, so two clients sending the same change in a different
	 * member order produce one digest.
	 */
	public function test_a_map_projects_in_key_order_whatever_order_it_arrived_in(): void {
		$one = $this->canonical->projectInbound(
			[
				'b' => 1,
				'a' => 2,
			]
		);
		$two = $this->canonical->projectInbound(
			[
				'a' => 2,
				'b' => 1,
			]
		);

		$this->assertSame( $one, $two, 'A reordered map is the same change and must project identically.' );
		$this->assertSame( [ 'a', 'b' ], array_keys( $one ), 'The map is ordered by key.' );
	}

	/**
	 * THE LIST TEST IS "EVERY KEY IS AN INTEGER" AND NOT array_is_list(), and this is
	 * the case that separates them. Meta Box hands clone rows back carrying whatever
	 * keys the editor's last delete left behind, so `[ 0 => a, 2 => b ]` is an ordinary
	 * stored value; treated as a map it would keep its gap and digest apart from the
	 * identical two rows stored as `[ 0 => a, 1 => b ]`.
	 */
	public function test_a_gapped_positional_array_closes_into_a_list(): void {
		$this->assertSame(
			[ 'a', 'b' ],
			$this->canonical->projectInbound(
				[
					0 => 'a',
					2 => 'b',
				]
			),
			'A gapped positional array is the same two rows and must project as the same list.'
		);

		$this->assertSame(
			[ 'a', 'b' ],
			$this->canonical->projectInbound(
				[
					2 => 'b',
					0 => 'a',
				]
			),
			'The positional sort is numeric, so row 2 follows row 0 whichever order they arrived in.'
		);
	}

	/**
	 * An object and the map it flattens to must project identically, or one write's
	 * digest would depend on whether a client sent a JSON object or a PHP array.
	 */
	public function test_an_object_projects_as_the_map_of_its_public_members(): void {
		$object    = new stdClass();
		$object->b = 1;
		$object->a = 2;

		$this->assertSame(
			$this->canonical->projectInbound(
				[
					'a' => 2,
					'b' => 1,
				]
			),
			$this->canonical->projectInbound( $object ),
			'Unwrapping an object is the same step expressed differently and costs no depth level.'
		);
	}

	/**
	 * AT THE CAP A STRUCTURE BECOMES null AND NEVER A SENTINEL STRING. A sentinel is
	 * indistinguishable from a string a site really stores, and this projection is what
	 * a digest is taken over.
	 */
	public function test_a_structure_at_the_depth_cap_becomes_null(): void {
		$this->assertNull(
			$this->canonical->projectInbound( [ 'x' => 1 ], MetaboxSchemaFormat::MAX_DEPTH ),
			'The walk stops at the cap and answers null.'
		);
	}

	/**
	 * THE CAP BOUNDS A WALK, NOT A LEAF, which is why the scalar branch runs before
	 * it. Blanking a string that happens to sit exactly at the cap would drop a value
	 * the operator really sent from a request that still reported success.
	 */
	public function test_a_scalar_projects_at_any_depth(): void {
		$this->assertSame(
			'kept',
			$this->canonical->projectInbound( 'kept', MetaboxSchemaFormat::MAX_DEPTH + 5 ),
			'A leaf is projected whole however deep it sits.'
		);
	}

	// ---------------------------------------------------------- the truncation

	/**
	 * The question a snapshot has to ask before it records, mirroring the one walk both
	 * projections answer over, branch for branch.
	 */
	public function test_truncates_answers_for_the_shapes_the_projection_would_lose(): void {
		$deep = [ 'leaf' => 'x' ];

		for ( $level = 0; $level <= MetaboxSchemaFormat::MAX_DEPTH; $level++ ) {
			$deep = [ 'nested' => $deep ];
		}

		$this->assertTrue( $this->canonical->truncates( $deep ), 'A value past the cap would be lost.' );

		$this->assertFalse(
			$this->canonical->truncates( str_repeat( 'x', 100000 ) ),
			'A long leaf is projected whole, so a field holding one is recordable rather than refused.'
		);

		$this->assertFalse(
			$this->canonical->truncates(
				[
					'a' => [ 1, 2 ],
					'b' => 0,
				]
			),
			'An ordinary nested value survives the projection intact.'
		);
	}

	// ------------------------------------------------------------- the matching

	/**
	 * POSTMETA IS A TEXT COLUMN, so `===` would refuse almost every correct write: an
	 * integer 5 sent in comes back out as the string '5'. The tolerance is exactly the
	 * coercion the storage performs and nothing wider.
	 */
	public function test_a_scalar_matches_its_own_string_spelling(): void {
		$this->assertTrue( $this->canonical->matches( 5, '5' ), 'The storage returns an integer as its text.' );
		$this->assertTrue( $this->canonical->matches( 1, '1' ), 'A projected boolean matches its stored text.' );
		$this->assertFalse( $this->canonical->matches( 5, '6' ), 'A different value is a dropped write.' );
	}

	/**
	 * The three empty forms are one value, because `rwmb_meta()` answers `''` for a
	 * field with no row at all. Treating them apart would refuse every request to
	 * clear a field as a dropped write.
	 */
	public function test_the_three_empty_forms_are_one_value(): void {
		foreach ( [ null, '', [] ] as $promised ) {
			foreach ( [ null, '', [] ] as $stored ) {
				$this->assertTrue(
					$this->canonical->matches( $promised, $stored ),
					'Every spelling of "this field holds nothing" satisfies a request to clear it.'
				);
			}
		}
	}

	/**
	 * `0` IS NOT AN EMPTY FORM. It projects to a stored 0, which is a value Meta Box
	 * writes a row for and an operator can see on the editing screen — so a write that
	 * promised 0 and stored nothing is a dropped write and must be caught.
	 */
	public function test_zero_is_a_value_and_not_an_empty_form(): void {
		$this->assertFalse( $this->canonical->matches( 0, '' ), 'A promised 0 that stored nothing did not land.' );
		$this->assertTrue( $this->canonical->matches( 0, '0' ), 'A promised 0 stored as text 0 did land.' );

		// THE SETTLEMENT MUST NOT WIDEN THE EMPTY-FORM RULE, and this is the pairing that
		// would show it if it had. A field holding one row containing the empty string
		// settles to `''` and a promised 0 settles to `'0'`, so the dropped write the
		// pair describes is still refused AFTER both sides have been through the
		// boundary — the place the tolerance now lives.
		$this->assertFalse(
			$this->canonical->matches( $this->canonical->settle( 0 ), $this->canonical->settle( [ '' ] ) ),
			'A promised 0 against a field holding one empty row is still a dropped write once both sides are settled.'
		);
	}

	/**
	 * A structure is compared member by member, and a member on one side and not the
	 * other is a difference — which is what catches a write that stored a truncated
	 * structure while reporting nothing at all.
	 */
	public function test_structures_are_compared_member_by_member(): void {
		$promised = [
			[
				'heading' => 'First',
				'body'    => 'One',
			],
		];

		$this->assertTrue(
			$this->canonical->matches( $promised, $promised ),
			'A structure stored as promised matches.'
		);

		$this->assertFalse(
			$this->canonical->matches( $promised, [ [ 'heading' => 'First' ] ] ),
			'A structure that lost a member did not land as promised.'
		);

		$this->assertFalse(
			$this->canonical->matches( $promised, 'First' ),
			'A structure that came back as a scalar did not land as promised.'
		);
	}

	/**
	 * MATCHES HAS NO STRUCTURAL TOLERANCE, AND THAT IS THE FIX AND NOT AN OVERSIGHT.
	 *
	 * The row-list question is settled at the boundary, on both sides, before either
	 * reaches this comparison — and it has to be, because the change engine digests
	 * those same settled values with no tolerance whatever, so a difference forgiven
	 * only here would be refused one layer up on a write that had already landed. What
	 * is left for this comparison is a member the site re-shaped, which is the
	 * difference the guard exists to catch and which an unwrap at depth would hide.
	 */
	public function test_matching_forgives_no_difference_in_shape(): void {
		$this->assertFalse(
			$this->canonical->matches( 'A subtitle', [ 'A subtitle' ] ),
			'Both sides arrive settled, so a wrapper here is a shape the storage did not produce.'
		);

		$this->assertFalse(
			$this->canonical->matches( 9, [ 9, 10 ] ),
			'Two rows are not the one value that was promised.'
		);

		$this->assertFalse(
			$this->canonical->matches( [ 'ids' => 9 ], [ 'ids' => [ 9 ] ] ),
			'A member the site wrapped is a re-shaped row, not the row that was promised.'
		);

		$this->assertFalse(
			$this->canonical->matches( 'kept', [ 'a' => 'kept' ] ),
			'A keyed member is not a row list and is never unwrapped.'
		);
	}

	// ---------------------------------------------------------- the settlement

	/**
	 * THE PROMISE AND THE RE-READ MUST BE ONE CURRENCY, AND THIS IS WHERE THEY BECOME
	 * ONE.
	 *
	 * A field's rows are a list and a promise usually is not, and the wrapper count
	 * depends on the field's storage arity — one row per value, or the whole value
	 * serialized into one row — which nothing on the write path dispatches on. Both
	 * spellings therefore strip to a fixpoint, and both sides are settled the same way
	 * so the engine's digest comparison sees one value.
	 */
	public function test_the_row_wrapper_is_settled_away_on_whichever_side_carries_it(): void {
		$this->assertSame( 'A subtitle', $this->canonical->settle( [ 'A subtitle' ] ), 'One row is the value it holds.' );
		$this->assertSame( 'A subtitle', $this->canonical->settle( 'A subtitle' ), 'A promise made as the value stays it.' );

		$row = [
			'heading' => 'First',
			'body'    => 'One',
		];

		$this->assertSame(
			$row,
			$this->canonical->settle( [ [ $row ] ] ),
			'A one-clone group serialized into one row settles to the same value its promise does.'
		);
		$this->assertSame( $row, $this->canonical->settle( [ $row ] ), 'The promise it is measured against.' );

		$this->assertSame(
			[ '9', '10' ],
			$this->canonical->settle( [ 9, 10 ] ),
			'Two rows stay two rows, spelled as the text column answers.'
		);
	}

	/**
	 * THE SETTLEMENT IS THE TOP-LEVEL ROW LIST AND NOTHING BELOW IT. Only a field's own
	 * rows are wrapped by the storage; a member inside a row was put there by the
	 * operator, and folding it away would verify a row the site re-shaped as the row
	 * that was promised.
	 */
	public function test_the_settlement_does_not_reach_inside_a_row(): void {
		$this->assertSame(
			[ 'ids' => [ 9 ] ],
			$this->canonical->settle( [ [ 'ids' => [ 9 ] ] ] ),
			'The row comes out of its list; its own list of one is the operator\'s.'
		);

		$this->assertSame(
			[ [ 'a' ], [ 'b' ] ],
			$this->canonical->settle( [ [ 'a' ], [ 'b' ] ] ),
			'Two rows each holding a list of one keep both lists.'
		);
	}

	/**
	 * THE EMPTY FORMS BECOME ONE AND `0` IS NOT ONE OF THEM. A field with no row at all
	 * reads as `[]` where a cleared field reads as one row holding `''`, and a request
	 * to clear a field would otherwise promise one spelling and measure another. `0` is
	 * a value an operator can see on the editing screen, and it survives as text.
	 */
	public function test_the_empty_forms_settle_together_and_zero_does_not_join_them(): void {
		foreach ( [ null, '', [], [ '' ], [ null ], [ [] ] ] as $nothing ) {
			$this->assertSame( '', $this->canonical->settle( $nothing ), 'Every spelling of nothing settles alike.' );
		}

		$this->assertSame( '0', $this->canonical->settle( 0 ), 'A promised 0 is the text a row holds.' );
		$this->assertSame( '0', $this->canonical->settle( [ 0 ] ), 'And the row holding it settles to the same text.' );
	}

	// ------------------------------------------------------------ the two directions

	/**
	 * OUTBOUND DROPS A SERVER PATH WHEREVER IT SITS.
	 *
	 * Meta Box's formatted answer for an attachment field carries the filesystem
	 * location of the upload; a before-state and a read-back are built from this
	 * projection and go to a client, so the member is dropped by name at every depth.
	 * `url` is kept: it is the public address the site already publishes and an
	 * operator needs it to recognise which attachment a plan refers to.
	 */
	public function test_the_outbound_projection_drops_a_server_path_member_and_keeps_the_public_url(): void {
		$this->assertSame(
			[
				[
					'ID'   => 9,
					'meta' => [],
					'url'  => 'https://example.com/wp-content/uploads/2026/08/file-9.jpg',
				],
			],
			$this->canonical->projectOutbound( self::attachmentAnswer() )
		);
	}

	/**
	 * INBOUND KEEPS EVERY MEMBER, INCLUDING THOSE NAMES.
	 *
	 * The prohibition is on what this plugin emits, not on what a caller may send, and
	 * a field legitimately holding a member called `path` or `dir` is an ordinary
	 * thing on a real site. Removing it inbound stores something other than the change
	 * the operator approved and reports success for doing so.
	 *
	 * Asserted from the same fixture as the test above so the pair states the
	 * asymmetry rather than merely coexisting with it: collapsing the two projections
	 * into one fails one of them whichever way it is collapsed.
	 */
	public function test_the_inbound_projection_keeps_a_member_whose_name_resembles_a_server_path(): void {
		$this->assertSame(
			[
				[
					'ID'   => 9,
					'dir'  => '/var/www/html/wp-content/uploads/2026/08',
					'meta' => [ 'basedir' => '/var/www/html/wp-content/uploads' ],
					'path' => '/var/www/html/wp-content/uploads/2026/08/file-9.jpg',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/file-9.jpg',
				],
			],
			$this->canonical->projectInbound( self::attachmentAnswer() )
		);
	}

	/**
	 * One value carrying every member name the outbound rule removes and one it keeps.
	 *
	 * @return mixed[] The value both direction tests project.
	 */
	private static function attachmentAnswer(): array {
		return [
			[
				'ID'   => 9,
				'path' => '/var/www/html/wp-content/uploads/2026/08/file-9.jpg',
				'url'  => 'https://example.com/wp-content/uploads/2026/08/file-9.jpg',
				'dir'  => '/var/www/html/wp-content/uploads/2026/08',
				'meta' => [ 'basedir' => '/var/www/html/wp-content/uploads' ],
			],
		];
	}
}
