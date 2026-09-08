<?php
/**
 * MenuItemsReorder as a stored-rollback delegate (audit finding C1).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * ONE RESPONSIBILITY: prove that a menu arrangement a reorder recorded can be put
 * back through the delegated rollback path — the state C1 found the plugin
 * advertised and then refused.
 *
 * THE LOAD-BEARING TEST IS test_the_promise_equals_the_read_back_of_a_real_rollback().
 * A reorder never touches a label, so the WHOLE recorded arrangement round-trips
 * and the promise is simply that arrangement under `order`. The test reorders the
 * menu for real, restores the snapshot, re-reads it, and asserts the promised
 * arrangement is the one the menu now reports — the guard against a promise that
 * names an arrangement the restore does not actually reproduce.
 */
final class MenuItemsReorderRollbackTest extends MenuItemsReorderTestCase {

	public function test_the_operation_is_a_rollback_delegate(): void {
		$this->assertInstanceOf( RollbackDelegate::class, $this->operation );
	}

	public function test_a_rollback_resolves_the_menus_current_arrangement(): void {
		$state = $this->operation->resolveRollbackTarget( 'menu:5', $this->makeContext() );

		$this->assertSame( 'menu:5', $state->targetKey );
		$this->assertTrue( $state->exists );
		// The arrangement is projected in readBack()'s vocabulary, which is the
		// same id-sorted list the stored rows carry.
		$this->assertSame( $this->storedOrder(), $state->fields['order'] );
	}

	public function test_a_rollback_is_refused_without_the_capability(): void {
		$this->permitted = false;

		try {
			$this->operation->resolveRollbackTarget( 'menu:5', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
			$this->assertStringNotContainsString( 'edit_theme_options', $refusal->getMessage() );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_a_rollback_of_a_key_that_names_no_menu_is_target_not_found(): void {
		try {
			$this->operation->resolveRollbackTarget( 'menu:999', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_the_promise_carries_the_whole_recorded_arrangement(): void {
		$snapshot = [
			'items'   => $this->storedOrder(),
			'menu_id' => 5,
		];

		$this->assertSame(
			[ 'order' => $this->storedOrder() ],
			$this->operation->promiseRollback( $snapshot, $this->operation->resolveTarget( $this->input( [] ), $this->makeContext() ), $this->makeContext() )
		);
	}

	public function test_a_state_that_names_no_menu_promises_nothing(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $this->input( [] ), $context );

		$this->assertSame( [], $this->operation->promiseRollback( [ 'menu_id' => 0, 'items' => [] ], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [ 'menu_id' => 5, 'items' => 'not-an-array' ], $current, $context ) );
	}

	public function test_the_promise_equals_the_read_back_of_a_real_rollback(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( $this->input( [] ), $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		// A genuine reorder: pull item 13 out of its parent to the top level and
		// re-nest item 15 beneath item 12. Both are fields the whole-arrangement
		// promise must move back.
		$plan = $this->operation->planChange(
			$current,
			$this->input(
				[
					[
						'id'       => 13,
						'parent'   => 0,
						'position' => 1,
					],
					[
						'id'       => 15,
						'parent'   => 12,
						'position' => 4,
					],
				]
			),
			$context
		);
		$this->operation->applyChange( $current, $plan, $context );

		// Put the recorded arrangement back, then re-read it exactly as the engine
		// does.
		$restored_key = $this->operation->restore( $snapshot, $context );
		$after        = $this->operation->readBack( $restored_key, $context )->fields;

		$promise = $this->operation->promiseRollback( $snapshot, $current, $context );

		$this->assertSame( $after['order'], $promise['order'], 'The promised arrangement is not the one the menu reads back at.' );
	}
}
