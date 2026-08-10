<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): the snapshot and the restore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Menus\MenuItemsReorder;

/**
 * REQ-0030: snapshot capture and rollback.
 *
 * A reorder is only reversible against the COMPLETE prior ordering, not against
 * the entries the batch named, so the snapshot tests pin every item in the menu.
 * The restore tests then pin the two failure shapes that matter: a rollback that
 * cannot run refuses before writing, and a rollback that runs but does not land
 * is reported as failed with what completed rather than as success.
 */
final class MenuItemsReorderRestoreTest extends MenuItemsReorderTestCase {

	// -------------------------------------------------------------------------
	// The snapshot and the restore.
	// -------------------------------------------------------------------------

	/**
	 * The snapshot records the prior parent AND position of every item in the
	 * menu, not only the ones the batch names, because a reorder is only
	 * reversible against the complete prior ordering.
	 */
	public function test_the_snapshot_records_the_prior_parent_and_position_of_every_item(): void {
		[ $current ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 5,
					],
				]
			)
		);

		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame( [ 'items', 'menu_id' ], array_keys( (array) $snapshot ) );
		$this->assertSame( 5, $snapshot['menu_id'] );
		$this->assertSame( $this->storedOrder(), $snapshot['items'] );
	}

	/**
	 * A resolved state that names no menu at all — `fields['id']` absent or 0
	 * — has nothing captureSnapshot() can capture, so it answers null rather
	 * than a snapshot the matching restore could never apply.
	 *
	 * Mutation that breaks this: dropping the `$menu_id <= 0` guard from
	 * MenuItemsReorder::captureSnapshot().
	 */
	public function test_capture_snapshot_answers_null_when_the_state_names_no_menu(): void {
		$state = new TargetState( 'menu:0', false, [] );

		$this->assertNull( $this->operation->captureSnapshot( $state, $this->makeContext() ) );
	}

	/**
	 * The change engine calls captureSnapshot() twice — once at preview for
	 * eligibility, once at apply for real — so it must be side-effect free and
	 * must answer identically both times.
	 *
	 * Mutation that breaks this: having captureSnapshot() write anything, or read
	 * live rows that the first call could have changed.
	 */
	public function test_capturing_the_snapshot_twice_answers_the_same_thing_and_writes_nothing(): void {
		[ $current ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 5,
					],
				]
			)
		);
		$context     = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->written );
	}

	/**
	 * The round trip the snapshot exists for: reorder, then restore, and the menu
	 * is byte-for-byte the order it started in.
	 */
	public function test_a_restore_puts_the_whole_prior_order_back(): void {
		$before  = $this->storedOrder();
		$context = $this->makeContext();
		$input   = $this->input(
			[
				[
					'id'       => 13,
					'parent'   => 0,
					'position' => 1,
				],
				[
					'id'       => 11,
					'position' => 4,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );
		$snapshot           = $this->operation->captureSnapshot( $current, $context );
		$this->operation->applyChange( $current, $plan, $context );

		$this->assertNotSame( $before, $this->storedOrder() );

		$key = $this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( 'menu:5', $key );
		$this->assertSame( $before, $this->storedOrder() );
	}

	/**
	 * THE `?? ''` TRAP. A recorded parent of 0 means "put this back at top
	 * level", and an ABSENT parent key means "do not touch the nesting". `??`
	 * collapses those two, and for a menu that is the difference between
	 * restoring a promoted item and silently promoting one that was never moved.
	 *
	 * Mutation that breaks this: replacing the array_key_exists() gate in
	 * MenuItemsReorder::restore() with `(int) ( $recorded['parent'] ?? 0 )`.
	 */
	public function test_a_restore_leaves_the_nesting_alone_when_the_record_omits_the_parent(): void {
		$context = $this->makeContext();

		$this->operation->restore(
			[
				'menu_id' => 5,
				'items'   => [
					[
						'id'       => 13,
						'position' => 7,
					],
				],
			],
			$context
		);

		$this->assertSame( 12, $this->written[0]['args']['menu-item-parent-id'] );
		$this->assertSame( 7, $this->written[0]['args']['menu-item-position'] );
	}

	/**
	 * The other half of the same axis: a RECORDED 0 is a value to write, not an
	 * absence to skip. Zero is how WordPress spells "top level", so a restore
	 * that skipped it would leave a promoted item promoted.
	 */
	public function test_a_recorded_zero_parent_is_written_back_rather_than_skipped(): void {
		$this->operation->restore(
			[
				'menu_id' => 5,
				'items'   => [
					[
						'id'       => 13,
						'parent'   => 0,
						'position' => 3,
					],
				],
			],
			$this->makeContext()
		);

		$this->assertSame( 0, $this->written[0]['args']['menu-item-parent-id'] );
		$this->assertSame( 0, (int) $this->items[2]->menu_item_parent );
	}

	/**
	 * A snapshot naming an item the menu no longer holds cannot be put back in
	 * full, and it is refused BEFORE anything is written rather than restoring
	 * the items that do still exist — the same all-or-nothing rule the forward
	 * write follows.
	 */
	public function test_a_restore_refuses_before_writing_when_a_recorded_item_is_gone(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 11,
							'parent'   => 0,
							'position' => 1,
						],
						[
							'id'       => 4242,
							'parent'   => 0,
							'position' => 2,
						],
					],
				],
				$this->makeContext()
			),
			ErrorCode::RollbackUnavailable
		);
	}

	public function test_a_restore_refuses_a_snapshot_that_names_no_menu(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore( [ 'items' => [] ], $this->makeContext() ),
			ErrorCode::RollbackUnavailable
		);
	}

	/**
	 * A restore has no WriteVerifier downstream, so if it does not measure what
	 * it stored, nothing does.
	 */
	public function test_a_restore_that_does_not_land_is_reported_as_failed(): void {
		$this->failing = [ 13 ];

		try {
			$this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 1,
						],
					],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore whose write failed must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * WordPress can accept a write and still not store what was sent — a
	 * wp_update_nav_menu_item filter can rewrite the arguments — and a restore
	 * has no WriteVerifier downstream to notice on its own. assert_restored()
	 * re-reads the menu and refuses when what actually landed disagrees with
	 * what was recorded, carrying every step that DID complete rather than
	 * reporting a clean restore that never happened.
	 *
	 * Mutation that breaks this: dropping the mismatch check in
	 * MenuItemsReorder::assert_restored(), or dropping `$completed` from the
	 * exception it throws there.
	 */
	public function test_a_restore_that_lands_differently_than_recorded_is_reported_with_what_completed(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( int $menu_id, int $item_id, array $args ): mixed {
				$this->written[] = [
					'menu' => $menu_id,
					'item' => $item_id,
					'args' => $args,
				];

				// Deliberately does NOT mutate $this->items, simulating a filter
				// that rewrote the arguments before WordPress stored them.
				return $item_id;
			}
		);

		try {
			$this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 1,
						],
						[
							'id'       => 11,
							'position' => 4,
						],
					],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore that lands differently than recorded must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'Every recorded menu item was written, but WordPress stored a different order than the recorded snapshot held.',
				$e->getMessage()
			);
			$this->assertSame(
				'Reorder the menu on the WordPress menus screen instead.',
				$e->remediation
			);
			$this->assertSame(
				[ 'menu item 1 of 2 restored', 'menu item 2 of 2 restored' ],
				$e->completedSteps
			);
		}
	}

	/**
	 * A RECORDED POSITION OF 0 IS PUT BACK AT 0, not delegated to WordPress.
	 *
	 * This test used to assert the opposite, and the reasoning it carried was the
	 * bug written down: `wp_update_nav_menu_item()` substitutes "the end of the
	 * menu" for a 0, so — the argument ran — a recorded 0 can never read back as 0
	 * and "whatever WordPress chose IS the restored state". It is not. A recorded 0
	 * means the item was stored FIRST; appending it lands it LAST; and exempting
	 * that from the comparison made assert_restored() certify the one rollback most
	 * likely to have gone wrong. restore() now writes the position again through
	 * MenuTarget::correctAppendedPosition(), so the recorded value is honoured
	 * literally and the verification measures it.
	 *
	 * The stub below reproduces core's substitution, which the shared double
	 * deliberately does not — so the correction has something real to correct.
	 *
	 * Mutation that breaks this: dropping the correctAppendedPosition() call from
	 * MenuItemsReorder::restore(), or restoring the `0 !== $target['position']`
	 * exemption in assert_restored().
	 */
	public function test_a_recorded_zero_position_is_restored_to_zero_rather_than_appended(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( int $menu_id, int $item_id, array $args ): mixed {
				$this->written[] = [
					'menu' => $menu_id,
					'item' => $item_id,
					'args' => $args,
				];

				$rows     = is_array( $this->items ) ? $this->items : [];
				$position = (int) $args['menu-item-position'];

				// Core's documented substitution for an unset position.
				if ( 0 === $position ) {
					$position = count( $rows ) + 1;
				}

				foreach ( $rows as $row ) {
					if ( (int) $row->ID === $item_id ) {
						$row->menu_item_parent = (int) $args['menu-item-parent-id'];
						$row->menu_order       = $position;
					}
				}

				return $item_id;
			}
		);

		$key = $this->operation->restore(
			[
				'menu_id' => 5,
				'items'   => [
					[
						'id'       => 13,
						'parent'   => 0,
						'position' => 0,
					],
				],
			],
			$this->makeContext()
		);

		$this->assertSame( 'menu:5', $key );
		$this->assertSame( 0, (int) $this->items[2]->menu_item_parent );

		// 6 is what core's substitution stored and 0 is what the snapshot recorded.
		// Asserting 0 is the whole point: the item was first, and it is first again.
		$this->assertSame( 0, (int) $this->items[2]->menu_order );
	}

	/**
	 * The parent is compared independently of the position, so a filter that
	 * swallows the nesting is still reported even when the position landed.
	 *
	 * Mutation that breaks this: dropping the parent from the assert_restored()
	 * comparison, or skipping the whole row when the recorded position is 0.
	 */
	public function test_a_recorded_zero_position_still_verifies_the_parent(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( int $menu_id, int $item_id, array $args ): mixed {
				$this->written[] = [
					'menu' => $menu_id,
					'item' => $item_id,
					'args' => $args,
				];

				// A filter that swallowed the parent but stored the row.
				return $item_id;
			}
		);

		try {
			$this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 0,
						],
					],
				],
				$this->makeContext()
			);
			$this->fail( 'A parent that did not land must still be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 'menu item 1 of 1 restored' ], $e->completedSteps );
		}
	}

	/**
	 * A menu that holds no items at all — wp_get_nav_menu_items() answering
	 * false rather than an array — is the same "cannot be put back" refusal as
	 * a menu missing one recorded item, reached through MenuArrangement::itemRows()'s own
	 * defence against a non-array result.
	 */
	public function test_a_restore_refuses_when_the_menu_holds_no_items_at_all(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore(
				[
					'menu_id' => 6,
					'items'   => [
						[
							'id'       => 11,
							'parent'   => 0,
							'position' => 1,
						],
					],
				],
				$this->makeContext()
			),
			ErrorCode::RollbackUnavailable
		);
	}
}
