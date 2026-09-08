<?php
/**
 * MenuItemUpdate as a stored-rollback delegate (audit finding C1).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Menus\MenuFields;

/**
 * ONE RESPONSIBILITY: prove that a snapshot menu-item-update recorded can be put
 * back through the delegated rollback path — the state C1 found the plugin
 * advertised and then refused.
 *
 * THE LOAD-BEARING TEST IS test_the_promise_equals_the_read_back_of_a_real_rollback().
 * It restores a genuinely-changed item and asserts, field by field, that every
 * value promiseRollback() names is the value readBack() then reports. Without it
 * the promise could name anything at all and the suite would stay green, because
 * the delegated plan never re-reads the item itself — WriteVerifier does, and
 * only for the keys the promise carried. The custom-link fixture's title
 * ("Home & Co" stored, "Home &#038; Co" derived) and its 250-word description
 * are exactly the fields the promise must NOT carry, and this test is what proves
 * it does not carry the ones it cannot keep.
 */
final class MenuItemUpdateRollbackTest extends MenuItemUpdateTestCase {

	public function test_the_operation_is_a_rollback_delegate(): void {
		$this->assertInstanceOf( RollbackDelegate::class, $this->operation );
	}

	public function test_a_rollback_resolves_the_items_current_state(): void {
		$state = $this->operation->resolveRollbackTarget( 'menu-item:400', $this->makeContext() );

		$this->assertSame( 'menu-item:400', $state->targetKey );
		$this->assertTrue( $state->exists );
		// The current state is projected in readBack()'s vocabulary, not the
		// snapshot's, so the derived title is what a resolve reports.
		$this->assertSame( 'Home &#038; Co', $state->fields['title'] );
	}

	public function test_a_rollback_is_refused_without_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->operation->resolveRollbackTarget( 'menu-item:400', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
			$this->assertStringNotContainsString( 'edit_theme_options', $refusal->getMessage() );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_a_rollback_of_a_key_that_names_no_item_is_target_not_found(): void {
		try {
			$this->operation->resolveRollbackTarget( 'menu-item:not-a-number', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_a_custom_link_promise_carries_the_round_tripping_fields(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'item' => 400 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$promise = $this->operation->promiseRollback( $snapshot, $current, $context );

		// The address is promised because a custom link stores and reads it back
		// verbatim; the label and description never are, because core texturizes
		// and trims them on read.
		$this->assertSame(
			[ 'url', 'parent', 'position', 'target', 'classes', 'xfn' ],
			array_keys( $promise )
		);
		$this->assertSame( 'https://example.com/original', $promise['url'] );
		$this->assertSame( 0, $promise['parent'] );
		$this->assertSame( 2, $promise['position'] );
		$this->assertSame( MenuFields::TARGET_NEW_TAB, $promise['target'] );
		$this->assertSame( [ 'nav-cta', 'is-loud' ], $promise['classes'] );
		$this->assertSame( 'me', $promise['xfn'] );
	}

	public function test_a_post_type_promise_omits_the_recomputed_address(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'item' => 430 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$promise = $this->operation->promiseRollback( $snapshot, $current, $context );

		// A post-type item takes its address from the page it names, so the
		// recorded column need not equal the read-back and the address is left
		// out of the promise.
		$this->assertArrayNotHasKey( 'url', $promise );
		$this->assertSame(
			[ 'parent', 'position', 'target', 'classes', 'xfn' ],
			array_keys( $promise )
		);
	}

	public function test_the_promise_never_carries_the_derived_fields(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'item' => 400 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$promise = $this->operation->promiseRollback( $snapshot, $current, $context );

		$this->assertArrayNotHasKey( 'title', $promise );
		$this->assertArrayNotHasKey( 'description', $promise );
	}

	public function test_a_state_that_names_no_menu_promises_nothing(): void {
		$context = $this->makeContext();
		$current = new TargetState( 'menu-item:400', true, [] );

		$this->assertSame( [], $this->operation->promiseRollback( [ 'item_id' => 400 ], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [], $current, $context ) );
	}

	public function test_the_promise_equals_the_read_back_of_a_real_rollback(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'item' => 400 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		// A genuine edit: move the item beneath 430, open it in the same tab,
		// re-class it, and drop the XFN value. Every one of those is a field the
		// promise carries, so the rollback must move all of them back.
		$planned = $this->operation->planChange(
			$current,
			[
				'item'     => 400,
				'parent'   => 430,
				'target'   => MenuFields::TARGET_SAME_TAB,
				'classes'  => [ 'changed' ],
				'xfn'      => '',
			],
			$context
		);
		$this->operation->applyChange( $current, $planned, $context );

		// Put the recorded state back, then re-read it exactly as the engine does.
		$restored_key = $this->operation->restore( $snapshot, $context );
		$after        = $this->operation->readBack( $restored_key, $context )->fields;

		$promise = $this->operation->promiseRollback( $snapshot, $current, $context );

		// The whole point: for every field the promise names, the value it
		// promised is the value the item actually reads back at.
		foreach ( $promise as $field => $value ) {
			$this->assertSame( $value, $after[ $field ], "Promised {$field} does not match the read-back." );
		}
	}
}
