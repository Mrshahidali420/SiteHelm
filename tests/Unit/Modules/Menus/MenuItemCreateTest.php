<?php
/**
 * Tests for MenuItemCreate (REQ-0028).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
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
use SiteHelm\Modules\Menus\MenuItemCreate;
use SiteHelm\Modules\Menus\MenuTarget;
use stdClass;

/**
 * REQ-0028: an operator adds one item to an existing navigation menu.
 *
 * The fixture world these tests run against — the menu, its existing item, and
 * every WordPress double — lives in MenuItemCreateTestCase. Only the claims about
 * behaviour live here.
 *
 * Every refusal below runs the WHOLE write — plan then apply — through
 * planThenApply(), never planChange() alone. That is what makes the "nothing
 * was written" claim able to fail: an implementation that moved a check out of
 * planChange() and into applyChange() would raise the same code from the same
 * payload, and a plan-only test would report the defect as a missing exception
 * rather than as the item it created on the way to raising it.
 *
 * "Nothing was written" is asserted as an EMPTY $callOrder rather than as an
 * unchanged menu, because wp_update_nav_menu_item() is the only function that
 * can create an item and an empty call order is the only evidence that it was
 * never reached.
 */
final class MenuItemCreateTest extends MenuItemCreateTestCase {

	public function test_the_definition_declares_the_write_shape_the_phase_requires(): void {
		$definition = MenuItemCreate::definition();

		$this->assertSame( 'menu-item-create', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( 'menu-write', $definition->dispatcherName() );
	}

	public function test_it_creates_a_custom_link_item(): void {
		$result = $this->planThenApply(
			[
				'menu'  => 'primary',
				'title' => 'Contact',
				'url'   => 'https://example.com/contact',
			]
		);

		$this->assertSame( [ 'wp_update_nav_menu_item' ], $this->callOrder );
		$this->assertSame( MenuFields::ITEM_PREFIX . '501', $result['targetKey'] );
		$this->assertSame( 'Contact', $result['after']['title'] );
		$this->assertSame( 'https://example.com/contact', $result['after']['url'] );
		$this->assertSame( 'custom', $result['after']['type'] );
		$this->assertSame( 'custom', $result['after']['object'] );
		$this->assertSame( 0, $result['after']['objectId'] );

		$this->assertSame( 'custom', $this->written[0]['menu-item-type'] );
		$this->assertSame( 'publish', $this->written[0]['menu-item-status'] );
	}

	public function test_it_creates_a_page_item(): void {
		$result = $this->planThenApply(
			[
				'menu'     => 5,
				'type'     => 'page',
				'objectId' => 42,
			]
		);

		$this->assertSame( [ 'wp_update_nav_menu_item' ], $this->callOrder );
		$this->assertSame( 'post_type', $result['after']['type'] );
		$this->assertSame( 'page', $result['after']['object'] );
		$this->assertSame( 42, $result['after']['objectId'] );

		// A post-type item's URL is core's permalink, so it is never promised:
		// promising it would make every page item report an adjustment.
		$this->assertArrayNotHasKey( 'url', $result['planned']->afterFields );
	}

	public function test_it_creates_a_taxonomy_item(): void {
		$result = $this->planThenApply(
			[
				'menu'     => 'primary',
				'type'     => 'category',
				'objectId' => 31,
			]
		);

		$this->assertSame( 'taxonomy', $result['after']['type'] );
		$this->assertSame( 'category', $result['after']['object'] );
		$this->assertSame( 31, $result['after']['objectId'] );
	}

	public function test_it_refuses_a_custom_link_item_with_no_url(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'  => 'primary',
				'title' => 'Contact',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'web address', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_custom_link_item_with_no_title(): void {
		$refusal = $this->refusalFrom(
			[
				'menu' => 'primary',
				'url'  => 'https://example.com/contact',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'title', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_object_id_that_does_not_exist(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'type'     => 'page',
				'objectId' => 9999,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_term_object_id_that_does_not_exist(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'type'     => 'category',
				'objectId' => 9999,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_unregistered_taxonomy(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'type'     => 'taxonomy',
				'object'   => 'nope',
				'objectId' => 31,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_unregistered_post_type(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'type'     => 'nonesuch',
				'objectId' => 42,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_an_object_id_whose_post_type_does_not_match_the_requested_type(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'type'     => 'post',
				'objectId' => 42,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'does not match', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_parent_belonging_to_a_different_menu(): void {
		$other                = $this->makeItem( 900, 'Elsewhere', 0 );
		$this->items[900]     = $other;
		$other_menu           = new stdClass();
		$other_menu->term_id  = 6;
		$other_menu->taxonomy = MenuFields::MENU_TAXONOMY;

		Functions\when( 'wp_get_object_terms' )->alias(
			function ( $id, $taxonomy = '' ) use ( $other_menu ) {
				if ( 900 === (int) $id ) {
					return [ $other_menu ];
				}

				$menu           = new stdClass();
				$menu->term_id  = 5;
				$menu->taxonomy = MenuFields::MENU_TAXONOMY;

				return isset( $this->items[ (int) $id ] ) ? [ $menu ] : [];
			}
		);

		$refusal = $this->refusalFrom(
			[
				'menu'   => 'primary',
				'title'  => 'Child',
				'url'    => 'https://example.com/child',
				'parent' => 900,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'parent', $refusal->getMessage() );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_menu_that_does_not_exist(): void {
		$refusal = $this->refusalFrom(
			[
				'menu'  => 'secondary',
				'title' => 'Contact',
				'url'   => 'https://example.com/contact',
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_user_without_edit_theme_options(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$refusal = $this->refusalFrom(
			[
				'menu'  => 'primary',
				'title' => 'Contact',
				'url'   => 'https://example.com/contact',
			]
		);

		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_it_refuses_a_target_key_that_names_no_menu(): void {
		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];

		$this->expectException( OperationException::class );

		$this->operation->planChange(
			new TargetState( MenuFields::MENU_PREFIX . 'primary', true, [] ),
			$input,
			$context
		);
	}

	public function test_the_snapshot_records_the_menu_and_its_items_and_is_side_effect_free(): void {
		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current = $this->operation->resolveTarget( $input, $context );

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame(
			[
				'item_ids' => [ 400 ],
				'menu_id'  => 5,
			],
			$first
		);
		$this->assertSame( $first, $second, 'captureSnapshot() is called twice and must answer identically.' );
		$this->assertSame( [], $this->callOrder, 'captureSnapshot() must write nothing.' );
	}

	public function test_the_snapshot_round_trips_through_restore_and_deletes_only_the_created_item(): void {
		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$planned    = $this->operation->planChange( $current, $input, $context );
		$target_key = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( MenuFields::ITEM_PREFIX . '501', $target_key );
		$this->assertArrayHasKey( 501, $this->items );

		$restored = $this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( MenuFields::MENU_PREFIX . '5', $restored );
		$this->assertArrayNotHasKey( 501, $this->items, 'restore() must delete the created item.' );
		$this->assertArrayHasKey( 400, $this->items, 'restore() must not delete an item the snapshot recorded.' );
		$this->assertContains( 'wp_delete_post', $this->callOrder );
	}

	public function test_restore_is_a_no_op_when_the_menu_already_matches_the_recorded_state(): void {
		$context = $this->makeContext();

		$restored = $this->operation->restore(
			[
				'item_ids' => [ 400 ],
				'menu_id'  => 5,
			],
			$context
		);

		$this->assertSame( MenuFields::MENU_PREFIX . '5', $restored );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_restore_refuses_a_state_that_names_no_menu(): void {
		$this->expectException( OperationException::class );

		$this->operation->restore( [ 'item_ids' => [ 400 ] ], $this->makeContext() );
	}

	public function test_restore_refuses_a_state_that_records_no_item_list(): void {
		try {
			$this->operation->restore( [ 'menu_id' => 5 ], $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertSame( [], $this->callOrder );

			return;
		}

		$this->fail( 'A state recording no item list must be refused rather than treated as an empty menu.' );
	}

	public function test_restore_refuses_when_the_created_item_survives_the_delete(): void {
		Functions\when( 'wp_delete_post' )->alias(
			function ( $id, $force = false ) {
				$this->callOrder[] = 'wp_delete_post';

				return false;
			}
		);

		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange( $current, $input, $context );
		$this->operation->applyChange( $current, $planned, $context );

		try {
			$this->operation->restore( (array) $snapshot, $context );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );

			return;
		}

		$this->fail( 'A restore that left the created item in place must not report success.' );
	}

	/**
	 * Core's `get_post()` answers `$GLOBALS['post']` for a falsy identifier.
	 *
	 * The double below is what makes that answerable in a unit test: the setUp
	 * double returns null for an unknown key, so an unguarded `get_post( 0 )`
	 * looked indistinguishable from a guarded one. Here id 0 hands back a REAL
	 * page — the global one — which satisfies both the object check and the
	 * post-type match, so an operation that omits the `> 0` test creates an item
	 * pointing at whatever page happened to be global instead of refusing.
	 */
	public function test_it_refuses_a_post_type_item_that_names_no_identifier(): void {
		$global             = new stdClass();
		$global->ID         = 77;
		$global->post_type  = 'page';
		$global->post_title = 'Whatever was global';

		Functions\when( 'get_post' )->alias(
			function ( $id = null ) use ( $global ) {
				if ( (int) $id <= 0 ) {
					return $global;
				}

				return $this->posts[ (int) $id ] ?? ( $this->items[ (int) $id ] ?? null );
			}
		);

		$refusal = $this->refusalFrom(
			[
				'menu'   => 'primary',
				'title'  => 'About',
				'type'   => 'post_type',
				'object' => 'page',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder, 'The global post must not become a menu item.' );
	}

	public function test_it_refuses_a_post_type_item_whose_identifier_is_zero(): void {
		$global             = new stdClass();
		$global->ID         = 77;
		$global->post_type  = 'page';
		$global->post_title = 'Whatever was global';

		Functions\when( 'get_post' )->alias(
			function ( $id = null ) use ( $global ) {
				if ( (int) $id <= 0 ) {
					return $global;
				}

				return $this->posts[ (int) $id ] ?? ( $this->items[ (int) $id ] ?? null );
			}
		);

		$refusal = $this->refusalFrom(
			[
				'menu'     => 'primary',
				'title'    => 'About',
				'type'     => 'post_type',
				'object'   => 'page',
				'objectId' => 0,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->callOrder );
	}

	/**
	 * Rollback deletes THE ITEM THIS OPERATION CREATED, not every item that was
	 * not there when the snapshot was taken.
	 *
	 * The difference is invisible on a quiet site and destructive on a busy one:
	 * a second operator adding an item between the snapshot and the rollback puts
	 * their item into the difference too, and a delete-the-difference reversal
	 * force-deletes it.
	 */
	public function test_restore_deletes_only_the_created_item_when_another_appeared_alongside_it(): void {
		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		// A concurrent operator adds an item of their own, after our snapshot.
		// ITS IDENTIFIER IS LOWER THAN THE ONE OUR WRITE WILL TAKE, deliberately:
		// the difference is sorted numerically, so a lower id puts THEIR item
		// first. With ours first, "delete the first item the difference names"
		// would delete the right one by luck and this test would pass against
		// the very defect it exists to catch.
		$this->items[450] = $this->makeItem( 450, 'Someone else\'s link', 0 );

		$planned = $this->operation->planChange( $current, $input, $context );
		$this->operation->applyChange( $current, $planned, $context );

		$this->operation->restore( (array) $snapshot, $context );

		$this->assertArrayNotHasKey( 501, $this->items, 'restore() must delete the item it created.' );
		$this->assertArrayHasKey( 450, $this->items, 'restore() must not delete another operator\'s item.' );
		$this->assertSame( [ 'wp_update_nav_menu_item', 'wp_delete_post' ], $this->callOrder );
	}

	/**
	 * A rollback arriving on a FRESH INSTANCE has no ownership record.
	 *
	 * SnapshotLifecycle::compensate() reverses on the same instance that applied,
	 * so the created id is in hand there. A rollback requested later is a new
	 * request and a new object, and all it has is the difference. One added item
	 * is ours by elimination; more than one is not attributable, and this asserts
	 * that the operation REFUSES rather than deleting all of them.
	 */
	public function test_restore_refuses_rather_than_guess_when_more_than_one_item_was_added(): void {
		$context = $this->makeContext();

		$this->items[600] = $this->makeItem( 600, 'First addition', 0 );
		$this->items[610] = $this->makeItem( 610, 'Second addition', 0 );

		$fields = new MenuFields();
		$fresh  = new MenuItemCreate( $fields, new MenuTarget( $fields ) );

		try {
			$fresh->restore(
				[
					'item_ids' => [ 400 ],
					'menu_id'  => 5,
				],
				$context
			);
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertSame( [], $this->callOrder, 'An unattributable rollback must delete nothing at all.' );
			$this->assertArrayHasKey( 600, $this->items );
			$this->assertArrayHasKey( 610, $this->items );

			return;
		}

		$this->fail( 'A rollback that cannot tell which item it added must be refused, not guessed at.' );
	}

	public function test_restore_on_a_fresh_instance_deletes_the_single_added_item(): void {
		$context = $this->makeContext();

		$this->items[600] = $this->makeItem( 600, 'The only addition', 0 );

		$fields = new MenuFields();
		$fresh  = new MenuItemCreate( $fields, new MenuTarget( $fields ) );

		$restored = $fresh->restore(
			[
				'item_ids' => [ 400 ],
				'menu_id'  => 5,
			],
			$context
		);

		$this->assertSame( MenuFields::MENU_PREFIX . '5', $restored );
		$this->assertArrayNotHasKey( 600, $this->items );
		$this->assertArrayHasKey( 400, $this->items );
	}

	/**
	 * The refusal envelope may not claim a snapshot the engine never took.
	 *
	 * This operation's snapshot policy is Supported, so apply can be reached with
	 * nothing captured. The target key below names no menu, which is the same
	 * condition captureSnapshot() answers null for, and the completed steps must
	 * then name the plan alone.
	 */
	public function test_the_refusal_names_no_snapshot_when_none_could_be_captured(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';

				$error         = new stdClass();
				$error->errors = [ 'nope' => [ 'refused' ] ];

				return $error;
			}
		);

		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		$unsnapshottable = new TargetState( MenuFields::ITEM_PREFIX . '9999', true, $current->fields );

		try {
			$this->operation->applyChange( $unsnapshottable, $planned, $context );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
			$this->assertSame( [ 'plan approved' ], $refusal->completedSteps );

			return;
		}

		$this->fail( 'The write was expected to be refused and was not.' );
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
				'menu'  => 'primary',
				'title' => 'Contact',
				'url'   => 'https://example.com/contact',
			]
		);

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame( [ 'plan approved', 'snapshot captured' ], $refusal->completedSteps );
	}

	public function test_it_carries_the_optional_fields_through_to_wordpress(): void {
		$result = $this->planThenApply(
			[
				'menu'        => 'primary',
				'title'       => 'Contact',
				'url'         => 'https://example.com/contact',
				'parent'      => 400,
				'position'    => 3,
				'target'      => '_blank',
				'classes'     => [ 'nav-cta', 'is--loud!' ],
				'description' => 'Talk to us',
				'xfn'         => 'me',
			]
		);

		$data = $this->written[0];

		$this->assertSame( 400, $data['menu-item-parent-id'] );
		$this->assertSame( 3, $data['menu-item-position'] );
		$this->assertSame( '_blank', $data['menu-item-target'] );
		$this->assertSame( 'nav-cta is--loud', $data['menu-item-classes'] );
		$this->assertSame( 'Talk to us', $data['menu-item-description'] );
		$this->assertSame( 'me', $data['menu-item-xfn'] );
		$this->assertSame( [ 'nav-cta', 'is--loud' ], $result['after']['classes'] );
	}

	public function test_an_unrecognised_target_value_is_normalized_to_the_same_window(): void {
		$this->planThenApply(
			[
				'menu'   => 'primary',
				'title'  => 'Contact',
				'url'    => 'https://example.com/contact',
				'target' => 'popup',
			]
		);

		$this->assertSame( '', $this->written[0]['menu-item-target'] );
	}

	public function test_the_read_back_refuses_a_target_key_that_names_no_item(): void {
		try {
			$this->operation->readBack( MenuFields::ITEM_PREFIX . '9999', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode );
			$this->assertStringContainsString( 'corr-menus-1', (string) $refusal->remediation );

			return;
		}

		$this->fail( 'A target key naming no item must fail verification.' );
	}

	public function test_the_promise_names_only_the_fields_the_payload_determined(): void {
		$context = $this->makeContext();
		$input   = [
			'menu'  => 'primary',
			'title' => 'Contact',
			'url'   => 'https://example.com/contact',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		$this->assertSame(
			[ 'title', 'url', 'type', 'object', 'objectId', 'parent' ],
			array_keys( $planned->afterFields )
		);
		$this->assertInstanceOf( PlannedChange::class, $planned );
	}
}
