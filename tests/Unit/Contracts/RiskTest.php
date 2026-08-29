<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use SiteHelm\Contracts\Risk;
use SiteHelm\Tests\TestCase;

/**
 * Tests for the risk order.
 *
 * The order is not decoration: `atLeast()` exists so that a gate written against
 * it refuses a newly added top tier by default, and every assertion here is
 * about that property rather than about the four names.
 */
final class RiskTest extends TestCase {

	/**
	 * The order, lowest first, written out rather than derived.
	 *
	 * Derived from `cases()` it would only assert that the enum agrees with
	 * itself. Written out, a case inserted in the middle — which silently
	 * renumbers every gate above it — fails here and has to be acknowledged.
	 *
	 * @var array<int, string>
	 */
	private const ORDER = [ 'low', 'medium', 'high', 'extreme' ];

	public function test_the_order_is_the_one_the_gates_were_written_against(): void {
		$this->assertSame(
			self::ORDER,
			array_map( static fn( Risk $risk ): string => $risk->value, Risk::cases() )
		);
	}

	public function test_rank_follows_declaration_order_with_no_gaps_or_ties(): void {
		$ranks = array_map( static fn( Risk $risk ): int => $risk->rank(), Risk::cases() );

		$this->assertSame( range( 0, count( Risk::cases() ) - 1 ), $ranks );
	}

	public function test_a_level_is_at_least_itself(): void {
		foreach ( Risk::cases() as $risk ) {
			$this->assertTrue( $risk->atLeast( $risk ), $risk->value );
		}
	}

	public function test_a_lower_level_is_not_at_least_a_higher_one(): void {
		$this->assertFalse( Risk::Low->atLeast( Risk::Medium ) );
		$this->assertFalse( Risk::Medium->atLeast( Risk::High ) );
		$this->assertFalse( Risk::High->atLeast( Risk::Extreme ) );
	}

	public function test_a_higher_level_is_at_least_a_lower_one(): void {
		$this->assertTrue( Risk::Medium->atLeast( Risk::Low ) );
		$this->assertTrue( Risk::High->atLeast( Risk::Medium ) );
		$this->assertTrue( Risk::Extreme->atLeast( Risk::High ) );
		$this->assertTrue( Risk::Extreme->atLeast( Risk::Low ) );
	}

	/**
	 * The whole reason the enum has methods.
	 *
	 * A gate written as `Risk::High !== $risk` admits `Extreme`, because
	 * `Extreme` is not the case it names. The same gate written ordinally
	 * refuses it. This asserts the difference directly, so the two spellings
	 * cannot be treated as interchangeable by a later reader.
	 */
	public function test_an_ordinal_gate_refuses_a_tier_added_above_it_where_an_inequality_admits_it(): void {
		$this->assertTrue( Risk::High !== Risk::Extreme, 'The inequality spelling lets Extreme through.' );
		$this->assertTrue( Risk::Extreme->atLeast( Risk::High ), 'The ordinal spelling stops it.' );
	}

	/**
	 * Nothing outranks Extreme, which is what makes it usable as a ceiling.
	 */
	public function test_extreme_is_the_top_of_the_order(): void {
		foreach ( Risk::cases() as $risk ) {
			$this->assertTrue( Risk::Extreme->atLeast( $risk ), $risk->value );
		}
	}
}
