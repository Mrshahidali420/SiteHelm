<?php
/**
 * Tests for the planned-change value object.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use InvalidArgumentException;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Tests\TestCase;

/**
 * The value object four shipped modules construct.
 *
 * `previewDetail` (Phase 6b, REQ-0035) is the machine-only channel a tree diff
 * rides. It is the LAST constructor parameter and defaults to `[]`, which is
 * what makes the change purely additive: every existing construction site in
 * Core, Media, Menus and Elementor keeps working untouched.
 *
 * THE ONE INVARIANT THAT CARRIES WEIGHT: a rich `previewDetail` does NOT
 * satisfy the "promise at least one field" requirement. Verification compares
 * `afterFields` after the write; `previewDetail` is never verified at all, so a
 * change that promised nothing but described a great deal would be a write no
 * post-write check could confirm.
 */
final class PlannedChangeTest extends TestCase {

	public function test_preview_detail_defaults_to_empty(): void {
		$change = new PlannedChange( [ 'id' => 42 ], [ 'post_title' => 'New' ] );

		$this->assertSame( [], $change->previewDetail );
	}

	/**
	 * The new value is the FIFTH parameter, so the four positional arguments
	 * every shipped module already passes keep their meaning.
	 */
	public function test_the_four_existing_positional_arguments_keep_their_meaning(): void {
		$change = new PlannedChange(
			[ 'id' => 42 ],
			[ 'post_title' => 'New' ],
			[ 'post_title' ],
			[ 'A warning.' ]
		);

		$this->assertSame( [ 'id' => 42 ], $change->payload );
		$this->assertSame( [ 'post_title' => 'New' ], $change->afterFields );
		$this->assertSame( [ 'post_title' ], $change->fieldOrder );
		$this->assertSame( [ 'A warning.' ], $change->warnings );
		$this->assertSame( [], $change->previewDetail );
	}

	public function test_preview_detail_is_carried_verbatim(): void {
		$detail = [
			'before'  => [ [ 'id' => 'a1' ] ],
			'after'   => [ [ 'id' => 'a1' ] ],
			'changes' => [
				[
					'op'        => 'updated',
					'elementId' => 'a1',
					'fromPath'  => '0',
					'toPath'    => '0',
				],
			],
		];

		$change = new PlannedChange( [ 'id' => 42 ], [ 'post_title' => 'New' ], [], [], $detail );

		$this->assertSame( $detail, $change->previewDetail );
	}

	public function test_an_empty_after_state_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new PlannedChange( [ 'id' => 42 ], [] );
	}

	/**
	 * A rich preview detail does not stand in for a promised field.
	 *
	 * Without this the additive channel would quietly become a way to plan a
	 * change nothing verifies: `WriteVerifier` reads `afterFields` and nothing
	 * else, so a change carrying only `previewDetail` would apply and then be
	 * classified against an empty promise.
	 */
	public function test_preview_detail_does_not_satisfy_the_promised_field_requirement(): void {
		$this->expectException( InvalidArgumentException::class );

		new PlannedChange(
			[ 'id' => 42 ],
			[],
			[],
			[],
			[
				'before'  => [ [ 'id' => 'a1' ] ],
				'after'   => [ [ 'id' => 'a2' ] ],
				'changes' => [
					[
						'op'        => 'added',
						'elementId' => 'a2',
						'fromPath'  => null,
						'toPath'    => '0',
					],
				],
			]
		);
	}
}
