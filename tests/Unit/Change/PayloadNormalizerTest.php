<?php
/**
 * Tests for PayloadNormalizer, TargetState, and PlannedChange.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Tests\TestCase;

/**
 * Tests canonical ordering, canonical JSON, and fingerprint determinism.
 */
final class PayloadNormalizerTest extends TestCase {

	private PayloadNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->normalizer = new PayloadNormalizer();
	}

	public function test_associative_keys_are_sorted_recursively(): void {
		$normalized = $this->normalizer->normalize(
			[
				'zeta'  => 1,
				'alpha' => [
					'z' => 1,
					'a' => 2,
				],
			]
		);

		$this->assertSame( [ 'alpha', 'zeta' ], array_keys( $normalized ) );
		$this->assertSame( [ 'a', 'z' ], array_keys( $normalized['alpha'] ) );
	}

	public function test_list_order_is_preserved(): void {
		$this->assertSame(
			[ 3, 1, 2 ],
			$this->normalizer->normalize( [ 3, 1, 2 ] )
		);
	}

	public function test_reordered_input_produces_identical_canonical_json(): void {
		$first  = $this->normalizer->canonicalJson(
			[
				'b' => [ 'y' => 2 ],
				'a' => 1,
			]
		);
		$second = $this->normalizer->canonicalJson(
			[
				'a' => 1,
				'b' => [ 'y' => 2 ],
			]
		);

		$this->assertSame( $first, $second );
	}

	public function test_canonical_json_does_not_escape_slashes_or_unicode(): void {
		$json = $this->normalizer->canonicalJson( [ 'path' => 'a/b', 'text' => 'ü' ] );

		$this->assertStringContainsString( 'a/b', $json );
		$this->assertStringContainsString( 'ü', $json );
	}

	public function test_fingerprint_is_sha256_of_the_canonical_json(): void {
		$value = [ 'a' => 1 ];

		$this->assertSame(
			hash( 'sha256', $this->normalizer->canonicalJson( $value ) ),
			$this->normalizer->fingerprint( $value )
		);
	}

	public function test_fingerprint_is_stable_for_reordered_input(): void {
		$this->assertSame(
			$this->normalizer->fingerprint(
				[
					'b' => 2,
					'a' => 1,
				]
			),
			$this->normalizer->fingerprint(
				[
					'a' => 1,
					'b' => 2,
				]
			)
		);
	}

	public function test_fingerprint_changes_when_a_value_changes(): void {
		$this->assertNotSame(
			$this->normalizer->fingerprint( [ 'a' => 1 ] ),
			$this->normalizer->fingerprint( [ 'a' => 2 ] )
		);
	}

	public function test_fingerprint_distinguishes_an_integer_from_its_string(): void {
		$this->assertNotSame(
			$this->normalizer->fingerprint( [ 'a' => 0 ] ),
			$this->normalizer->fingerprint( [ 'a' => '0' ] )
		);
	}

	public function test_target_state_requires_a_target_key(): void {
		$this->expectException( InvalidArgumentException::class );
		new TargetState( '  ', false, [] );
	}

	public function test_target_state_exposes_its_three_readonly_members(): void {
		$state = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( [ 'post_title' => 'x' ], $state->fields );
	}

	public function test_planned_change_requires_at_least_one_promised_field(): void {
		$this->expectException( InvalidArgumentException::class );
		new PlannedChange( [ 'title' => 'x' ], [] );
	}

	public function test_planned_change_defaults_field_order_and_warnings_to_empty(): void {
		$planned = new PlannedChange( [ 'title' => 'x' ], [ 'post_title' => 'x' ] );

		$this->assertSame( [ 'title' => 'x' ], $planned->payload );
		$this->assertSame( [ 'post_title' => 'x' ], $planned->afterFields );
		$this->assertSame( [], $planned->fieldOrder );
		$this->assertSame( [], $planned->warnings );
	}

	/**
	 * A float and an integer of the same magnitude are different states.
	 *
	 * Without JSON_PRESERVE_ZERO_FRACTION, 2.0 encodes as `2` — identical to
	 * the integer — so a field changing from one to the other produces the
	 * same fingerprint and a concurrent edit passes the staleness check
	 * unnoticed. A review found the flag was present but unguarded: removing
	 * it left the whole suite green.
	 */
	public function test_a_float_is_not_encoded_as_an_integer(): void {
		$normalizer = new PayloadNormalizer();

		$this->assertSame( '{"v":2.0}', $normalizer->canonicalJson( [ 'v' => 2.0 ] ) );
		$this->assertSame( '{"v":2}', $normalizer->canonicalJson( [ 'v' => 2 ] ) );
		$this->assertNotSame(
			$normalizer->fingerprint( [ 'v' => 2.0 ] ),
			$normalizer->fingerprint( [ 'v' => 2 ] )
		);
	}

	/**
	 * Keys are ordered as strings, so the order does not depend on PHP's
	 * numeric-key coercion.
	 *
	 * Two payloads carrying the same members in different insertion orders
	 * must reach the same bytes, or an unchanged target fingerprints
	 * differently at apply and fails with `conflict`.
	 */
	public function test_key_order_does_not_depend_on_insertion_order(): void {
		$normalizer = new PayloadNormalizer();

		$this->assertSame(
			$normalizer->canonicalJson( [ 'b' => 1, 'a' => 2, 'c' => 3 ] ),
			$normalizer->canonicalJson( [ 'c' => 3, 'a' => 2, 'b' => 1 ] )
		);
		$this->assertSame(
			'{"a":2,"b":1,"c":3}',
			$normalizer->canonicalJson( [ 'b' => 1, 'a' => 2, 'c' => 3 ] )
		);
	}

	/**
	 * Numeric-looking keys sort as strings, not as numbers.
	 *
	 * PHP coerces a numeric string key to an integer, so ksort()'s default
	 * SORT_REGULAR orders 9 before 10 while SORT_STRING orders "10" before
	 * "9". Either is internally consistent, but only one is pinned — and the
	 * fingerprint has to mean the same thing on every host and every PHP
	 * version, including whichever one issued a plan token that is about to be
	 * applied. Any map keyed by an identifier — terms by id, meta by numeric
	 * key — reaches this path.
	 */
	public function test_numeric_keys_sort_as_strings(): void {
		$normalizer = new PayloadNormalizer();

		$this->assertSame(
			'{"10":"x","100":"z","9":"y"}',
			$normalizer->canonicalJson(
				[
					'9'   => 'y',
					'100' => 'z',
					'10'  => 'x',
				]
			)
		);
	}
}
