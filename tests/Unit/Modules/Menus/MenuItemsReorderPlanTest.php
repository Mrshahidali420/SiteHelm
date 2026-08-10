<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): the promise the plan makes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;



/**
 * REQ-0030: planning and preview.
 *
 * The plan is the value WriteVerifier compares the persisted state against, so
 * these tests pin both what it promises and that the promise is exactly what a
 * subsequent apply produces. A plan that promised more than the operation owns
 * would fail verification on every unrelated change to the menu.
 */
final class MenuItemsReorderPlanTest extends MenuItemsReorderTestCase {

	// -------------------------------------------------------------------------
	// The promise.
	// -------------------------------------------------------------------------

	/**
	 * The plan promises the whole menu's projected order and nothing else, so
	 * WriteVerifier compares exactly the value this operation is responsible for.
	 */
	public function test_the_plan_promises_the_projected_order_and_nothing_else(): void {
		[ , $plan ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 15,
						'position' => 1,
					],
				]
			)
		);

		$this->assertSame( [ 'order' ], array_keys( $plan->afterFields ) );
		$this->assertSame( [ 'order' ], $plan->fieldOrder );
		$this->assertSame(
			[
				[
					'id'       => 11,
					'parent'   => 0,
					'position' => 1,
				],
				[
					'id'       => 12,
					'parent'   => 0,
					'position' => 2,
				],
				[
					'id'       => 13,
					'parent'   => 12,
					'position' => 3,
				],
				[
					'id'       => 14,
					'parent'   => 12,
					'position' => 4,
				],
				[
					'id'       => 15,
					'parent'   => 0,
					'position' => 1,
				],
			],
			$plan->afterFields['order']
		);
	}

	/**
	 * The promise is what the write actually produces. This is the assertion that
	 * makes the previous one worth having: a projected order nobody can reach is
	 * a promise WriteVerifier would classify as not-applied on every apply.
	 */
	public function test_the_promised_order_is_the_order_the_write_produces(): void {
		$input = $this->input(
			[
				[
					'id'       => 13,
					'parent'   => 0,
					'position' => 6,
				],
				[
					'id'       => 15,
					'position' => 2,
				],
			]
		);

		[ , $plan ] = $this->plan( $input );
		$fields     = $this->apply( $input );

		$this->assertSame( $plan->afterFields['order'], $fields['order'] );
	}
}
