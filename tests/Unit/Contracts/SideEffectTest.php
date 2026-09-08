<?php
/**
 * Tests for the SideEffect vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use SiteHelm\Contracts\SideEffect;
use SiteHelm\Tests\TestCase;

/**
 * The closed list of consequences an operation can declare.
 */
final class SideEffectTest extends TestCase {

	/**
	 * The slugs travel in the catalogue export, which clients are told to save
	 * and re-read rather than fetch again. Renaming one silently breaks a
	 * matcher written against a file somebody kept.
	 */
	public function test_the_vocabulary_is_exactly_these_five_slugs(): void {
		$this->assertSame(
			[
				'runs-installed-code',
				'replaces-running-code',
				'slows-the-next-visit',
				'changes-what-visitors-see',
				'leaves-references-behind',
			],
			array_map( static fn( SideEffect $case ): string => $case->value, SideEffect::cases() )
		);
	}

	/**
	 * A slug on its own tells an operator nothing, so every case owes a
	 * sentence. sentence() matches without a default arm, so a case added
	 * without one throws here rather than reaching a catalogue.
	 */
	public function test_every_case_carries_a_sentence_a_person_can_read(): void {
		foreach ( SideEffect::cases() as $case ) {
			$sentence = $case->sentence();

			$this->assertNotSame( '', trim( $sentence ), "{$case->value} has no sentence." );
			$this->assertStringEndsWith( '.', $sentence, "{$case->value} is not a sentence." );
		}
	}

	/**
	 * The two code cases are the pair most likely to be collapsed by a later
	 * author who reads them quickly. Running somebody else's code during this
	 * call and changing which code runs on the next one are different facts
	 * with different remedies, and saving a page again is the operation that
	 * has only the first.
	 */
	public function test_running_installed_code_and_replacing_it_stay_distinct(): void {
		$this->assertNotSame(
			SideEffect::RunsInstalledCode->sentence(),
			SideEffect::ReplacesRunningCode->sentence()
		);
	}
}
