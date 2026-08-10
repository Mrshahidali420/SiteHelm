<?php
/**
 * Tests for MenuItemUpdate (REQ-0029).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemUpdate;
use SiteHelm\Modules\Menus\MenuTarget;
use stdClass;

/**
 * REQ-0029: an operator changes SOME fields of one navigation menu item.
 *
 * The fixture world these tests run against — the two menus, the item rows, the
 * clobbering write double, and the stored-versus-derived disagreement that makes
 * a rendering-reading merge base detectable — lives in MenuItemUpdateTestCase.
 * Only the claims about behaviour live here.
 *
 * Refusals run the WHOLE write through planThenApply() rather than planChange()
 * alone, so a check moved into applyChange() is caught by the empty $callOrder
 * rather than being reported as an equivalent pass.
 */
final class MenuItemUpdateTest extends MenuItemUpdateTestCase {

	public function test_the_definition_declares_the_write_shape_the_phase_requires(): void {
		$definition = MenuItemUpdate::definition();

		$this->assertSame( 'menu-item-update', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'item' ], $definition->inputSchema['required'] );
		$this->assertSame( 'menu-write', $definition->dispatcherName() );
	}

	/**
	 * THE LOAD-BEARING TEST of REQ-0029.
	 *
	 * wp_update_nav_menu_item() replaces the item wholesale, so an update that
	 * hands it only the changed field silently blanks the address, the tooltip,
	 * the classes, the description, the XFN value, the window target, the
	 * position, and the parent. Each of those is asserted separately, because a
	 * merge that covers seven of the eight is still a data-loss bug.
	 */
	public function test_it_updates_only_the_title_and_leaves_every_other_field_intact(): void {
		$result = $this->planThenApply(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( [ 'wp_update_nav_menu_item' ], $this->callOrder );
		$this->assertSame( MenuFields::ITEM_PREFIX . '400', $result['targetKey'] );
		$this->assertSame( 'Renamed', $result['after']['title'] );

		$this->assertSame( 'https://example.com/original', $result['after']['url'] );
		$this->assertSame( [ 'nav-cta', 'is-loud' ], $result['after']['classes'] );
		$this->assertSame( 'me', $result['after']['xfn'] );
		$this->assertSame( '_blank', $result['after']['target'] );
		$this->assertSame( 2, $result['after']['position'] );
		$this->assertSame( 0, $result['after']['parent'] );
		$this->assertSame( 'custom', $result['after']['type'] );

		// THE MERGE BASE MUST CARRY THE STORED COLUMNS, NOT THE RENDERING. The
		// description is 250 stored words that display as 200; the tooltip is
		// stored raw and displays escaped. A merge base reading the derived
		// properties would write the RENDERING back into the columns, so these
		// two assertions are what catch it.
		$this->assertSame( self::storedDescription(), $this->written[0]['menu-item-description'] );
		$this->assertSame( self::storedDescription(), $this->items[400]->post_content );

		// The tooltip is not an updatable field at all, so nothing in the payload
		// can carry it: only the merge base can keep it.
		$this->assertSame( 'Tips & tricks', $this->written[0]['menu-item-attr-title'] );
		$this->assertSame( 'Tips & tricks', $this->items[400]->post_excerpt );
		$this->assertSame( 'publish', $this->written[0]['menu-item-status'] );
	}

	public function test_it_updates_the_url_of_a_custom_link(): void {
		$result = $this->planThenApply(
			[
				'item' => 400,
				'url'  => 'https://example.com/changed',
			]
		);

		$this->assertSame( 'https://example.com/changed', $result['after']['url'] );

		// The stored title survives the write untouched; the projection shows the
		// texturized rendering of it. Asserting BOTH is what separates "the title
		// was carried across" from "the title was carried across as its rendering".
		$this->assertSame( 'Home & Co', $this->items[400]->post_title );
		$this->assertSame( 'Home & Co', $this->written[0]['menu-item-title'] );
		$this->assertSame( 'Home &#038; Co', $result['after']['title'] );
	}

	public function test_it_re_parents_an_item_within_the_same_menu(): void {
		$result = $this->planThenApply(
			[
				'item'   => 420,
				'parent' => 400,
			]
		);

		$this->assertSame( 400, $result['after']['parent'] );
		$this->assertSame( 400, $this->written[0]['menu-item-parent-id'] );
	}

	public function test_it_moves_an_item_to_the_top_level_when_the_parent_is_zero(): void {
		$result = $this->planThenApply(
			[
				'item'   => 410,
				'parent' => 0,
			]
		);

		$this->assertSame( 0, $result['after']['parent'] );
	}

	public function test_it_refuses_a_parent_belonging_to_a_different_menu(): void {
		$refusal = $this->refusalFrom(
			[
				'item'   => 400,
				'parent' => 900,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'parent', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_parent_that_is_a_descendant_of_the_item(): void {
		$refusal = $this->refusalFrom(
			[
				'item'   => 400,
				'parent' => 420,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'beneath', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_item_that_is_made_its_own_parent(): void {
		$refusal = $this->refusalFrom(
			[
				'item'   => 410,
				'parent' => 410,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_item_identifier_that_names_no_menu_item(): void {
		$refusal = $this->refusalFrom(
			[
				'item'  => 9999,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_user_without_edit_theme_options(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$refusal = $this->refusalFrom(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_request_that_names_no_field_to_change(): void {
		$refusal = $this->refusalFrom( [ 'item' => 400 ] );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_url_on_an_item_that_is_not_a_custom_link(): void {
		$refusal = $this->refusalFrom(
			[
				'item' => 430,
				'url'  => 'https://example.com/elsewhere',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'address', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_still_updates_the_title_of_an_item_that_is_not_a_custom_link(): void {
		$result = $this->planThenApply(
			[
				'item'  => 430,
				'title' => 'About',
			]
		);

		$this->assertSame( 'About', $result['after']['title'] );
		$this->assertSame( 'post_type', $result['after']['type'] );
		$this->assertSame( 'page', $result['after']['object'] );
		$this->assertSame( 42, $result['after']['objectId'] );
		$this->assertArrayNotHasKey( 'url', $result['planned']->afterFields );
	}

	public function test_it_refuses_a_target_key_that_names_no_item(): void {
		$this->expectException( OperationException::class );

		$this->operation->planChange(
			new TargetState( MenuFields::ITEM_PREFIX . 'primary', true, [] ),
			[
				'item'  => 400,
				'title' => 'Renamed',
			],
			$this->makeContext()
		);
	}

	public function test_the_promise_names_only_the_fields_the_request_supplied(): void {
		$context = $this->makeContext();
		$input   = [
			'item'        => 400,
			'description' => 'Talk to us',
			'title'       => 'Renamed',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		$this->assertSame( [ 'title', 'description' ], array_keys( $planned->afterFields ) );
	}

	public function test_the_snapshot_records_every_restorable_field_and_is_side_effect_free(): void {
		$context = $this->makeContext();
		$input   = [
			'item'  => 400,
			'title' => 'Renamed',
		];
		$current = $this->operation->resolveTarget( $input, $context );

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertIsArray( $first );
		$this->assertSame( 400, $first['item_id'] );
		$this->assertSame( 5, $first['menu_id'] );

		foreach ( MenuTarget::RESTORABLE_ITEM_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $first, $field . ' must be recorded or a rollback resets it.' );
		}

		$this->assertSame( $first, $second, 'captureSnapshot() is called twice and must answer identically.' );
		$this->assertSame( [], $this->callOrder, 'captureSnapshot() must write nothing.' );
	}

	public function test_the_snapshot_returns_null_for_a_target_key_that_names_no_item(): void {
		$this->assertNull(
			$this->operation->captureSnapshot(
				new TargetState( MenuFields::ITEM_PREFIX . '9999', true, [] ),
				$this->makeContext()
			)
		);
	}

	public function test_the_snapshot_round_trips_through_restore_and_puts_every_field_back(): void {
		$context = $this->makeContext();
		$input   = [
			'item'        => 400,
			'title'       => 'Renamed',
			'url'         => 'https://example.com/changed',
			'classes'     => [ 'quiet' ],
			'description' => '',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$planned = $this->operation->planChange( $current, $input, $context );
		$this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'Renamed', $this->items[400]->post_title );
		$this->assertSame( '', $this->items[400]->post_content );

		$restored = $this->operation->restore( (array) $snapshot, $context );

		// EVERY ASSERTION HERE READS A STORED COLUMN. Reading the derived
		// properties instead would let a rollback that restores the RENDERING
		// pass: 'Home &#038; Co' and the 200-word truncation both satisfy a
		// derived-side check, and neither is what was there before.
		$this->assertSame( MenuFields::ITEM_PREFIX . '400', $restored );
		$this->assertSame( 'Home & Co', $this->items[400]->post_title );
		$this->assertSame( 'https://example.com/original', $this->items[400]->url );
		$this->assertSame( [ 'nav-cta', 'is-loud' ], $this->items[400]->classes );
		$this->assertSame( self::storedDescription(), $this->items[400]->post_content );
		$this->assertSame( 'Tips & tricks', $this->items[400]->post_excerpt );
	}

	/**
	 * `isIdempotent` is a DECLARATION; this is the behaviour it declares.
	 *
	 * The flag assertion elsewhere in this class reads a boolean off the
	 * definition and can never fail while the boolean is spelled `true`. This
	 * one applies the same input twice and requires the second apply to change
	 * nothing. Before the merge base was corrected it did not converge: the
	 * carried-across title round-tripped through the_title once per apply, so
	 * `Home & Co` became `Home &#038; Co`, then `Home &#038;#038; Co`, and the
	 * stored description lost fifty words on the first apply and its trailing
	 * ellipsis grew a further truncation on every one after.
	 */
	public function test_applying_the_same_change_twice_converges(): void {
		$context = $this->makeContext();
		$input   = [
			'item' => 400,
			'url'  => 'https://example.com/changed',
		];

		$first  = $this->planThenApply( $input );
		$after  = clone $this->items[400];
		$second = $this->planThenApply( $input );

		$this->assertSame( $first['after'], $second['after'] );
		$this->assertEquals( $after, $this->items[400] );
		$this->assertSame( $this->written[0], $this->written[1] );

		// Named individually, because a whole-object comparison that regressed
		// would not say WHICH field drifted.
		$this->assertSame( 'Home & Co', $this->items[400]->post_title );
		$this->assertSame( self::storedDescription(), $this->items[400]->post_content );
		$this->assertSame( 'Tips & tricks', $this->items[400]->post_excerpt );
	}

	public function test_restore_leaves_a_field_the_recorded_state_does_not_name_untouched(): void {
		$this->operation->restore(
			[
				'item_id'          => 400,
				'menu_id'          => 5,
				'menu-item-title'  => 'Recorded',
				'menu-item-status' => 'publish',
			],
			$this->makeContext()
		);

		$this->assertArrayNotHasKey(
			'menu-item-url',
			$this->written[0],
			'An ABSENT key must not become a written empty string.'
		);
		$this->assertSame( 'Recorded', $this->written[0]['menu-item-title'] );
	}

	public function test_restore_writes_a_recorded_empty_string_back_as_empty(): void {
		$this->operation->restore(
			[
				'item_id'          => 400,
				'menu_id'          => 5,
				'menu-item-url'    => '',
				'menu-item-status' => 'publish',
			],
			$this->makeContext()
		);

		$this->assertArrayHasKey( 'menu-item-url', $this->written[0] );
		$this->assertSame( '', $this->written[0]['menu-item-url'] );
	}

	public function test_restore_refuses_a_state_that_names_no_item(): void {
		try {
			$this->operation->restore( [ 'menu-item-title' => 'Recorded' ], $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertSame( [], $this->callOrder );

			return;
		}

		$this->fail( 'A state naming no item must be refused rather than replayed.' );
	}

	public function test_it_carries_every_supplied_field_through_to_wordpress(): void {
		$result = $this->planThenApply(
			[
				'item'        => 410,
				'title'       => 'Middle again',
				'url'         => 'https://example.com/middle',
				'parent'      => 0,
				'position'    => 7,
				'target'      => '',
				'classes'     => [ 'nav-quiet', 'is--tidy!' ],
				'description' => 'A description',
				'xfn'         => 'colleague',
			]
		);

		$data = $this->written[0];

		$this->assertSame( 410, $data['menu-item-db-id'] );
		$this->assertSame( 0, $data['menu-item-parent-id'] );
		$this->assertSame( 7, $data['menu-item-position'] );
		$this->assertSame( '', $data['menu-item-target'] );
		$this->assertSame( 'nav-quiet is--tidy', $data['menu-item-classes'] );
		$this->assertSame( 'A description', $data['menu-item-description'] );
		$this->assertSame( 'colleague', $data['menu-item-xfn'] );
		$this->assertSame( [ 'nav-quiet', 'is--tidy' ], $result['after']['classes'] );
	}

	public function test_an_unrecognised_target_value_is_normalized_to_the_same_window(): void {
		$this->planThenApply(
			[
				'item'   => 400,
				'target' => 'popup',
			]
		);

		$this->assertSame( '', $this->written[0]['menu-item-target'] );
	}

	public function test_it_reports_execution_failed_when_wordpress_refuses_the_write(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';

				$error         = new stdClass();
				$error->errors = [ 'nope' => [ 'refused' ] ];

				return $error;
			}
		);

		$refusal = $this->refusalFrom(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame( [ 'plan approved', 'snapshot captured' ], $refusal->completedSteps );
	}

	public function test_it_reports_execution_failed_when_the_item_vanishes_before_the_merge(): void {
		$context = $this->makeContext();
		$input   = [
			'item'  => 400,
			'title' => 'Renamed',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		unset( $this->items[400] );

		try {
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
			$this->assertSame( [], $this->callOrder, 'A vanished item must be refused before anything is written.' );

			return;
		}

		$this->fail( 'An item that vanished between plan and apply must not be written wholesale.' );
	}

	public function test_the_read_back_refuses_a_target_key_that_names_no_item(): void {
		try {
			$this->operation->readBack( MenuFields::ITEM_PREFIX . '9999', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode );
			$this->assertStringContainsString( 'corr-menus-4', (string) $refusal->remediation );

			return;
		}

		$this->fail( 'A target key naming no item must fail verification.' );
	}

	public function test_a_loop_that_does_not_pass_through_the_moved_item_does_not_block_the_move(): void {
		// A menu can already hold a loop written straight into the database. The
		// ancestor walk has to TERMINATE on it rather than refuse every move in
		// the menu, so that an operator can repair it one item at a time.
		$this->items[700]      = $this->makeItem( 700, 'Loop A', 710 );
		$this->items[710]      = $this->makeItem( 710, 'Loop B', 700 );
		$this->itemMenus[700]  = 5;
		$this->itemMenus[710]  = 5;

		$result = $this->planThenApply(
			[
				'item'   => 400,
				'parent' => 700,
			]
		);

		$this->assertSame( 700, $result['after']['parent'] );
		$this->assertSame( 700, $this->written[0]['menu-item-parent-id'] );
	}

	public function test_a_menu_whose_items_cannot_be_listed_does_not_break_the_ancestor_walk(): void {
		// wp_get_nav_menu_items() is filtered, and a filter can substitute a
		// non-list. Casting one would make the walk iterate garbage.
		Functions\when( 'wp_get_nav_menu_items' )->justReturn( false );

		$result = $this->planThenApply(
			[
				'item'   => 420,
				'parent' => 400,
			]
		);

		$this->assertSame( 400, $result['after']['parent'] );
	}

	public function test_it_refuses_an_item_that_belongs_to_no_menu(): void {
		$this->itemMenus[400] = 0;

		$refusal = $this->refusalFrom(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	/**
	 * THE MERGE BASE MUST NOT HAND CORE A 0 AND CALL IT THE ITEM'S POSITION.
	 *
	 * `menu_order` 0 is where WordPress leaves the FIRST item of an empty menu —
	 * core's own `count( $menu_items )` arm answers 0 against a list `array_pop()`
	 * has emptied — so every menu this plugin creates has one. Handing that 0 back
	 * to `wp_update_nav_menu_item()` does not mean "position zero", it means
	 * "append", so a request that only renamed the item moved it from first to
	 * last. Nothing downstream noticed: a partial update does not promise
	 * `position`, so WriteVerifier had nothing to compare.
	 *
	 * Mutation that breaks this: dropping the correctAppendedPosition() call from
	 * MenuItemUpdate::applyChange().
	 */
	public function test_an_item_stored_first_is_not_moved_to_the_end_by_an_unrelated_edit(): void {
		$this->items[400]->menu_order = 0;
		$this->items[410]->menu_order = 1;
		$this->items[420]->menu_order = 2;
		$this->items[430]->menu_order = 3;

		$result = $this->planThenApply(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		$this->assertSame( 'Renamed', $result['after']['title'] );

		// 4 is where core's substitution put it. 0 is where it belongs.
		$this->assertSame( 0, $this->items[400]->menu_order );
		$this->assertSame( 0, $result['after']['position'] );

		// The correction runs AFTER the write it corrects, never instead of it:
		// a first write that never happened would leave the title unchanged.
		$this->assertSame( [ 'wp_update_nav_menu_item', 'wp_update_post' ], $this->callOrder );
	}

	/**
	 * The rollback has the identical hazard and needs the identical correction.
	 * Replaying a snapshot that recorded 0 would append the item a SECOND time,
	 * which is what made the prior arrangement unrecoverable through the engine
	 * rather than merely wrong once.
	 *
	 * Mutation that breaks this: dropping the correctAppendedPosition() call from
	 * MenuTarget::restoreItem().
	 */
	public function test_a_restore_puts_an_item_recorded_first_back_at_the_front(): void {
		$this->items[400]->menu_order = 0;
		$this->items[410]->menu_order = 1;

		$result = $this->planThenApply(
			[
				'item'  => 400,
				'title' => 'Renamed',
			]
		);

		// The snapshot records the truth about the item rather than a position
		// core would find convenient.
		$this->assertSame( 0, $result['snapshot']['menu-item-position'] );

		$key = $this->operation->restore( $result['snapshot'], $this->makeContext() );

		$this->assertSame( MenuFields::ITEM_PREFIX . '400', $key );
		$this->assertSame( 0, $this->items[400]->menu_order );
		$this->assertSame( 'Home & Co', $this->items[400]->post_title );
	}

	/**
	 * AN EMPTY TITLE IS AN INSTRUCTION, NOT AN ABSENT ONE. For a post_type item it
	 * is core's own "stay synced with the linked post": the item stops carrying its
	 * own label and shows the page's. `! empty()` in place of array_key_exists()
	 * drops it, and the request then names no field to change at all.
	 *
	 * Mutation that breaks this: `! empty( $input['title'] )` at the title gate in
	 * MenuItemUpdate::changed_fields().
	 */
	public function test_an_empty_title_is_written_rather_than_dropped(): void {
		$result = $this->planThenApply(
			[
				'item'  => 430,
				'title' => '',
			]
		);

		$this->assertSame( [ 'title' => '' ], $result['planned']->afterFields );
		$this->assertSame( '', $this->written[0]['menu-item-title'] );
		$this->assertSame( '', $this->items[430]->post_title );
		$this->assertSame( [ 'wp_update_nav_menu_item' ], $this->callOrder );
	}

	/**
	 * The same rule for the one field an operator is most likely to want CLEARED
	 * rather than set: there is no other way to remove an XFN relationship.
	 *
	 * Mutation that breaks this: `! empty( $input['xfn'] )` at the xfn gate in
	 * MenuItemUpdate::presentation_fields().
	 */
	public function test_an_empty_xfn_clears_the_stored_relationship(): void {
		$this->assertSame( 'me', $this->items[400]->xfn );

		$result = $this->planThenApply(
			[
				'item' => 400,
				'xfn'  => '',
			]
		);

		$this->assertSame( [ 'xfn' => '' ], $result['planned']->afterFields );
		$this->assertSame( '', $this->written[0]['menu-item-xfn'] );
		$this->assertSame( '', $result['after']['xfn'] );
	}

	/**
	 * A 0 POSITION IS REFUSED, AND REFUSED FOR WHAT IT IS. The sibling reorder has
	 * carried `minimum: 1` from the start and documents the bound as load bearing;
	 * this operation declared `minimum: 0` and forwarded the value unchanged, so an
	 * operator sending a zero-based "first" got "last".
	 *
	 * The MESSAGE is asserted, not just the code. `! empty()` at the position gate
	 * would drop the 0 and reach "the request names no field to change" — a
	 * refusal, so an error-code assertion alone would still pass, while telling the
	 * operator they sent nothing rather than that positions count from 1.
	 *
	 * Mutation that breaks this: `! empty( $input['position'] )` at the position
	 * gate in MenuItemUpdate::presentation_fields(), or removing the bound test.
	 */
	public function test_it_refuses_a_zero_position_rather_than_appending_the_item(): void {
		$refusal = $this->refusalFrom(
			[
				'item'     => 400,
				'position' => 0,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'counts from 1', $refusal->getMessage() );
		$this->assertStringContainsString( 'end of the menu', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	/**
	 * The schema carries the same bound, so the dispatcher refuses a 0 before the
	 * operation is reached at all. Both layers are asserted because either one
	 * alone leaves a route to the substitution.
	 */
	public function test_the_schema_declares_the_position_bound_the_sibling_reorder_declares(): void {
		$position = MenuItemUpdate::definition()->inputSchema['properties']['position'];

		$this->assertSame( 'integer', $position['type'] );
		$this->assertSame( 1, $position['minimum'] );
	}

	/**
	 * A supplied position of 1 or more is forwarded verbatim and needs no
	 * correction, which is what keeps correctAppendedPosition() from being a write
	 * on every update.
	 */
	public function test_a_supplied_position_is_written_without_a_second_write(): void {
		$result = $this->planThenApply(
			[
				'item'     => 400,
				'position' => 3,
			]
		);

		$this->assertSame( 3, $result['after']['position'] );
		$this->assertSame( 3, $this->written[0]['menu-item-position'] );
		$this->assertSame( [ 'wp_update_nav_menu_item' ], $this->callOrder );
	}
}
