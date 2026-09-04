<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\Walkthrough;
use SiteHelm\Tests\TestCase;

/**
 * The optional list's arithmetic, with no WordPress anywhere near it.
 *
 * `Walkthrough::steps()` is the whole of it, so every state a site can be in is
 * asserted here rather than through rendered markup. The invariant the tests
 * are really guarding is the absence of a sequence: no item is ever singled out
 * as the one to do next, and connecting is not in the list at all.
 */
final class WalkthroughTest extends TestCase {

	public function testAFreshSiteHasFourThingsOpenAndNoneOfThemMandatory(): void {
		$steps = Walkthrough::steps( false, false, false, false );

		$this->assertCount( 4, $steps );
		$this->assertSame( 0, Walkthrough::done_count( $steps ) );
		$this->assertFalse( Walkthrough::is_complete( $steps ) );
	}

	/**
	 * The list is a set of suggestions, not a sequence. Nothing in a returned
	 * item may say "do this one next": a current marker would invent an order
	 * the console does not impose, which is what the five-step version got
	 * wrong.
	 */
	public function testNoItemIsEverMarkedAsTheCurrentOne(): void {
		foreach ( [ false, true ] as $scoped ) {
			foreach ( Walkthrough::steps( $scoped, false, true, false ) as $step ) {
				$this->assertSame( [ 'key', 'done' ], array_keys( $step ) );
			}
		}
	}

	/**
	 * Connecting is the dialog's job. If it reappeared here it would be back to
	 * being one item among five, which is exactly the reading the split was
	 * made to avoid.
	 */
	public function testConnectingIsNotOneOfTheOptionalThings(): void {
		$keys = array_column( Walkthrough::steps( false, false, false, false ), 'key' );

		$this->assertSame( Walkthrough::ORDER, $keys );
		$this->assertNotContains( 'connect', $keys );
	}

	public function testEachAnswerMarksItsOwnItemAndNothingElse(): void {
		$this->assertSame( [ Walkthrough::STEP_SCOPE ], self::done( Walkthrough::steps( true, false, false, false ) ) );
		$this->assertSame( [ Walkthrough::STEP_CALL ], self::done( Walkthrough::steps( false, true, false, false ) ) );
		$this->assertSame( [ Walkthrough::STEP_CHANGE ], self::done( Walkthrough::steps( false, false, true, false ) ) );
		$this->assertSame( [ Walkthrough::STEP_UNDO ], self::done( Walkthrough::steps( false, false, false, true ) ) );
	}

	/**
	 * A gap early on is just a gap. The five-step version treated it as the
	 * step the owner had to be on; here the later done items keep their ticks
	 * and the open one is simply still open.
	 */
	public function testAGapEarlyOnDoesNotHoldTheLaterOnesBack(): void {
		$steps = Walkthrough::steps( false, true, true, true );

		$this->assertSame(
			[ Walkthrough::STEP_CALL, Walkthrough::STEP_CHANGE, Walkthrough::STEP_UNDO ],
			self::done( $steps )
		);
		$this->assertSame( 3, Walkthrough::done_count( $steps ) );
		$this->assertFalse( Walkthrough::is_complete( $steps ) );
	}

	public function testEverythingDoneIsComplete(): void {
		$steps = Walkthrough::steps( true, true, true, true );

		$this->assertSame( 4, Walkthrough::done_count( $steps ) );
		$this->assertTrue( Walkthrough::is_complete( $steps ) );
	}

	public function testAnEmptyListIsNotComplete(): void {
		$this->assertFalse( Walkthrough::is_complete( [] ) );
	}

	public function testTheItemsComeBackInTheOrderTheyAreOffered(): void {
		$steps = Walkthrough::steps( false, false, false, false );

		$this->assertSame( Walkthrough::ORDER, array_column( $steps, 'key' ) );
	}

	/**
	 * @param list<array{key: string, done: bool}> $steps The items.
	 *
	 * @return list<string> The keys of the items marked done.
	 */
	private static function done( array $steps ): array {
		$found = [];

		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				$found[] = $step['key'];
			}
		}

		return $found;
	}
}
