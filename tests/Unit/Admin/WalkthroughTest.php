<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\Walkthrough;
use SiteHelm\Tests\TestCase;

/**
 * The walkthrough's arithmetic, with no WordPress anywhere near it.
 *
 * `Walkthrough::steps()` is the whole of the state machine, so every transition
 * a site can be in is asserted here rather than through rendered markup.
 */
final class WalkthroughTest extends TestCase {

	/**
	 * @return list<string> The keys of the steps marked current.
	 */
	private static function current( array $steps ): array {
		$found = [];

		foreach ( $steps as $step ) {
			if ( $step['current'] ) {
				$found[] = $step['key'];
			}
		}

		return $found;
	}

	public function testAFreshSiteIsOnStepOne(): void {
		$steps = Walkthrough::steps( false, false, false, false, false );

		$this->assertCount( 5, $steps );
		$this->assertSame( [ Walkthrough::STEP_CONNECT ], self::current( $steps ) );
		$this->assertSame( 0, Walkthrough::done_count( $steps ) );
		$this->assertFalse( Walkthrough::is_complete( $steps ) );
	}

	public function testConnectingMovesToChoosingWhatItMayTouch(): void {
		$steps = Walkthrough::steps( true, false, false, false, false );

		$this->assertSame( [ Walkthrough::STEP_SCOPE ], self::current( $steps ) );
		$this->assertSame( 1, Walkthrough::done_count( $steps ) );
	}

	public function testTheFirstTwoDoneMovesToTheTestCall(): void {
		$steps = Walkthrough::steps( true, true, false, false, false );

		$this->assertSame( [ Walkthrough::STEP_CALL ], self::current( $steps ) );
		$this->assertSame( 2, Walkthrough::done_count( $steps ) );
	}

	public function testACalledSiteIsAskedForItsFirstChange(): void {
		$steps = Walkthrough::steps( true, true, true, false, false );

		$this->assertSame( [ Walkthrough::STEP_CHANGE ], self::current( $steps ) );
	}

	public function testAChangedSiteIsAskedToUndoIt(): void {
		$steps = Walkthrough::steps( true, true, true, true, false );

		$this->assertSame( [ Walkthrough::STEP_UNDO ], self::current( $steps ) );
		$this->assertSame( 4, Walkthrough::done_count( $steps ) );
		$this->assertFalse( Walkthrough::is_complete( $steps ) );
	}

	public function testEverythingDoneLeavesNoCurrentStep(): void {
		$steps = Walkthrough::steps( true, true, true, true, true );

		$this->assertSame( [], self::current( $steps ) );
		$this->assertSame( 5, Walkthrough::done_count( $steps ) );
		$this->assertTrue( Walkthrough::is_complete( $steps ) );
	}

	/**
	 * A site that undid a change without ever saving a permission mode is still
	 * missing step two. The current step is the FIRST open one, so the gap is
	 * pointed at rather than skipped past because a later step happens to be
	 * done.
	 */
	public function testAGapEarlyOnIsTheCurrentStepEvenWhenLaterOnesAreDone(): void {
		$steps = Walkthrough::steps( true, false, true, true, true );

		$this->assertSame( [ Walkthrough::STEP_SCOPE ], self::current( $steps ) );
		$this->assertSame( 4, Walkthrough::done_count( $steps ) );
		$this->assertFalse( Walkthrough::is_complete( $steps ) );
	}

	public function testTheStepsComeBackInTheOrderTheyAreDone(): void {
		$steps = Walkthrough::steps( false, false, false, false, false );

		$this->assertSame( Walkthrough::ORDER, array_column( $steps, 'key' ) );
	}
}
