<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): apply-phase failure reporting.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0030: what the operation reports when the write itself fails.
 *
 * VALIDATION CANNOT MAKE THE WRITE INFALLIBLE. Every entry can pass planChange
 * and WordPress still refuse one of them. What the operator is owed then is an
 * exact account of what DID land, accumulated from the calls that were actually
 * made rather than declared up front.
 */
final class MenuItemsReorderFailureReportingTest extends MenuItemsReorderTestCase {

	// -------------------------------------------------------------------------
	// Apply-phase failure reporting.
	// -------------------------------------------------------------------------

	/**
	 * VALIDATION CANNOT MAKE THE WRITE INFALLIBLE. Every entry passed planChange,
	 * and WordPress still refused the second of three — a save_post filter, a
	 * row deleted between plan and apply. What the operation owes the operator
	 * then is an exact account of what DID land, which is the field the porting
	 * source does not have and which ContentTermsAssign gets wrong by declaring
	 * its completed steps up front.
	 *
	 * The expected array is accumulated here from the recorded calls rather than
	 * hardcoded, so it cannot drift into agreeing with an implementation that
	 * reports a fixed list.
	 *
	 * Mutation that breaks this: declaring `$completed` with every step up front
	 * in applyChange().
	 */
	public function test_a_mid_batch_write_failure_reports_exactly_the_items_already_written(): void {
		$this->failing = [ 12 ];
		$context       = $this->makeContext();
		$input         = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
				[
					'id'       => 15,
					'position' => 1,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $e ) {
			$landed = array_slice( array_column( $this->written, 'item' ), 0, -1 );

			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 11, 12 ], array_column( $this->written, 'item' ) );
			$this->assertSame( [ 11 ], $landed );
			$this->assertSame(
				[ 'plan approved', 'snapshot captured', 'menu item 1 of 3 repositioned' ],
				$e->completedSteps
			);
		}
	}

	/**
	 * The first entry failing reports the two engine steps and no item step,
	 * which is the case a hardcoded list gets wrong in the other direction.
	 */
	public function test_a_first_entry_failure_reports_no_item_as_written(): void {
		$this->failing = [ 11 ];
		$context       = $this->makeContext();
		$input         = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $e->completedSteps );
			$this->assertSame( [ 11 ], array_column( $this->written, 'item' ) );
		}
	}

	/**
	 * A row that vanished between plan and apply is refused rather than written
	 * around, and the refusal still carries what landed.
	 */
	public function test_an_item_that_vanished_between_plan_and_apply_is_reported(): void {
		$context = $this->makeContext();
		$input   = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		$this->items = [ $this->items[0] ];

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A vanished row must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				[ 'plan approved', 'snapshot captured', 'menu item 1 of 2 repositioned' ],
				$e->completedSteps
			);
		}
	}
}
