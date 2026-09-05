<?php
/**
 * Tests for the health enum's one behaviour.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Tests\TestCase;

/**
 * The line that decides whether a state is a caveat or a refusal.
 *
 * EVERY GATE IN THE PLUGIN USED TO COMPARE AGAINST `active` DIRECTLY, and that was
 * correct for exactly as long as `active` was the only state in which a call could
 * be answered. Adding a fourth state made four of those comparisons wrong at once —
 * the dispatcher would have refused every operation of an unconfigured module,
 * rollback would have been withheld, and the console would have greyed the card and
 * left it out of the active count. All four now ask this method instead, so the
 * question "can this module serve a call" has one answer in one place rather than
 * four copies that drift apart the next time a state is added.
 *
 * Both halves are asserted for every case. A test that only listed the operational
 * ones would pass just as well against a method that returned true unconditionally.
 */
final class ModuleHealthTest extends TestCase {

	/**
	 * Active is the ordinary case, and unconfigured is the one worth the method.
	 *
	 * `unconfigured` means the plugin behind the module is loaded and in range and
	 * storing everything written to it, while not yet acting on what it holds. The
	 * operations work. What is missing belongs in a report, not in a refusal.
	 */
	public function test_active_and_unconfigured_modules_can_serve_a_call(): void {
		$this->assertTrue( ModuleHealth::Active->isOperational() );
		$this->assertTrue( ModuleHealth::Unconfigured->isOperational() );
	}

	public function test_inactive_and_version_blocked_modules_cannot(): void {
		$this->assertFalse( ModuleHealth::Inactive->isOperational() );
		$this->assertFalse( ModuleHealth::VersionBlocked->isOperational() );
	}

	/**
	 * A NEW CASE IS NOT OPERATIONAL UNTIL SOMEBODY DECIDES IT IS. This asserts the
	 * shape of the method rather than a value: it names the operational states and
	 * returns false for the rest, so a fifth case added without touching it is
	 * refused rather than quietly admitted to every write path in the plugin.
	 */
	public function test_every_case_answers_one_way_or_the_other(): void {
		$operational = [];

		foreach ( ModuleHealth::cases() as $case ) {
			if ( $case->isOperational() ) {
				$operational[] = $case->value;
			}
		}

		$this->assertSame( [ 'active', 'unconfigured' ], $operational );
	}
}
