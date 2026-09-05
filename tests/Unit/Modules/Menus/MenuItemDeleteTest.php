<?php
/**
 * MenuItemDelete behaviour.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemDelete;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * ONE RESPONSIBILITY: prove that removing a menu item removes it, that an item
 * holding other items is refused rather than orphaning them, and that the
 * rollback this operation offers is one it can actually honour.
 *
 * THE DOUBLE FOR wp_delete_post() ONLY REMOVES THE ROW WHEN THE CALL FORCES IT,
 * exactly as core does. A double that removed the row either way would report a
 * trashing implementation as a pass, and a trashed nav_menu_item keeps its term
 * relationship — so the menu would still hold an item the operation called gone.
 *
 * AND THE DOUBLE FOR wp_update_nav_menu_item() INSERTS UNDER A FRESH IDENTIFIER
 * WHEN IT IS HANDED 0, which is what makes the restore's honesty testable: the
 * item comes back, and it comes back as a different row.
 */
final class MenuItemDeleteTest extends TestCase {


	private MenuItemDelete $operation;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	private array $items = [];

	/** @var array<int, int> The menu each item belongs to, keyed by item identifier. */
	private array $itemMenus = [];

	/** @var array<int, array{id: int, force: bool}> Every wp_delete_post() call, in order. */
	private array $deleted = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	private array $written = [];

	private int $nextId = 700;

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MenuFields();
		$this->operation = new MenuItemDelete( $fields, new MenuTarget( $fields ) );

		$this->deleted = [];
		$this->written = [];
		$this->nextId  = 700;

		// Menu 5 holds a parent with one child beneath it, plus one leaf.
		// Menu 6 holds one item, so a menu walk that ignores menu membership
		// counts a child it has no business seeing.
		$this->items = [
			400 => $this->makeItem( 400, 'Services', 0 ),
			410 => $this->makeItem( 410, 'Consulting', 400 ),
			430 => $this->makeItem( 430, 'Launch offer', 0 ),
			900 => $this->makeItem( 900, 'Elsewhere', 400 ),
		];

		$this->itemMenus = [
			400 => 5,
			410 => 5,
			430 => 5,
			900 => 6,
		];

		$menus = [
			5 => $this->makeMenu( 5, 'primary', 'Primary' ),
			6 => $this->makeMenu( 6, 'footer', 'Footer' ),
		];

		// WordPress is not loaded, so there is no WP_Error class to instantiate.
		// A stdClass carrying an `errors` member stands in for one.
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof stdClass && isset( $thing->errors )
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'get_post' )->alias( fn( $id = null ) => $this->items[ (int) $id ] ?? null );
		Functions\when( 'is_nav_menu_item' )->alias( fn( $id = 0 ): bool => isset( $this->items[ (int) $id ] ) );
		Functions\when( 'wp_setup_nav_menu_item' )->alias( static fn( $post ) => $post );
		Functions\when( 'wp_get_object_terms' )->alias(
			function ( $id, $taxonomy = '' ) use ( $menus ) {
				$menu_id = $this->itemMenus[ (int) $id ] ?? 0;

				return 0 === $menu_id ? [] : [ $menus[ $menu_id ] ];
			}
		);
		Functions\when( 'wp_get_nav_menu_items' )->alias(
			fn( $menu_id ) => array_values(
				array_filter(
					$this->items,
					fn( object $item ): bool => ( $this->itemMenus[ (int) $item->ID ] ?? 0 ) === (int) $menu_id
				)
			)
		);

		// CORE'S TRASH-OR-DELETE SPLIT, reproduced: only a forced call removes
		// the row. A nav_menu_item that is merely trashed keeps its menu term,
		// so the menu still holds it.
		Functions\when( 'wp_delete_post' )->alias(
			function ( $id = 0, $force = false ) {
				$id = (int) $id;

				$this->deleted[] = [
					'id'    => $id,
					'force' => (bool) $force,
				];

				$row = $this->items[ $id ] ?? null;

				if ( null === $row ) {
					return false;
				}

				if ( true !== $force ) {
					$row->post_status = 'trash';

					return $row;
				}

				unset( $this->items[ $id ], $this->itemMenus[ $id ] );

				return $row;
			}
		);

		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->written[] = $data;

				// CORE'S INSERT-ON-ZERO, reproduced: a 0 identifier is a new row.
				$id = 0 === (int) $item_id ? ++$this->nextId : (int) $item_id;

				$item = $this->makeItem( $id, '', 0 );

				$item->post_status      = (string) ( $data['menu-item-status'] ?? 'publish' );
				$item->post_title       = stripslashes( (string) ( $data['menu-item-title'] ?? '' ) );
				$item->post_content     = stripslashes( (string) ( $data['menu-item-description'] ?? '' ) );
				$item->post_excerpt     = stripslashes( (string) ( $data['menu-item-attr-title'] ?? '' ) );
				$item->title            = $item->post_title;
				$item->description      = $item->post_content;
				$item->attr_title       = $item->post_excerpt;
				$item->url              = stripslashes( (string) ( $data['menu-item-url'] ?? '' ) );
				$item->type             = (string) ( $data['menu-item-type'] ?? 'custom' );
				$item->object           = (string) ( $data['menu-item-object'] ?? 'custom' );
				$item->object_id        = (int) ( $data['menu-item-object-id'] ?? 0 );
				$item->menu_item_parent = (int) ( $data['menu-item-parent-id'] ?? 0 );
				$item->menu_order       = (int) ( $data['menu-item-position'] ?? 0 );
				$item->target           = (string) ( $data['menu-item-target'] ?? '' );
				$item->xfn              = stripslashes( (string) ( $data['menu-item-xfn'] ?? '' ) );
				$item->classes          = array_values(
					array_filter( explode( ' ', stripslashes( (string) ( $data['menu-item-classes'] ?? '' ) ) ) )
				);

				$this->items[ $id ]     = $item;
				$this->itemMenus[ $id ] = (int) $menu_id;

				return $id;
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr = [] ) {
				$id  = (int) ( $postarr['ID'] ?? 0 );
				$row = $this->items[ $id ] ?? null;

				if ( ! is_object( $row ) || ! array_key_exists( 'menu_order', $postarr ) ) {
					return 0;
				}

				$row->menu_order = (int) $postarr['menu_order'];

				return $id;
			}
		);
	}

	public function test_the_definition_declares_the_write_shape_the_phase_requires(): void {
		$definition = MenuItemDelete::definition();

		$this->assertSame( 'menu-item-delete', $definition->id );
		$this->assertSame( Domain::Menu, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( 'menu-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertTrue( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertSame( [ 'item' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'item' ], array_keys( $definition->inputSchema['properties'] ) );
		$this->assertInstanceOf( RollbackDelegate::class, $this->operation );
	}

	public function test_it_removes_the_item_from_the_menu(): void {
		$result = $this->planThenApply( [ 'item' => 430 ] );

		$this->assertSame( 'menu-item:430', $result['targetKey'] );
		$this->assertSame( [ 'exists' => false ], $result['after'] );
		$this->assertArrayNotHasKey( 430, $this->items );

		$remaining = array_map(
			static fn( object $item ): int => (int) $item->ID,
			wp_get_nav_menu_items( 5 )
		);
		$this->assertNotContains( 430, $remaining );
	}

	public function test_the_removal_is_forced_rather_than_trashed(): void {
		$this->planThenApply( [ 'item' => 430 ] );

		$this->assertSame( [ [ 'id' => 430, 'force' => true ] ], $this->deleted );
	}

	public function test_it_refuses_an_item_that_has_items_beneath_it_and_names_them(): void {
		$refusal = $this->refusalFrom( [ 'item' => 400 ] );

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringContainsString( '1 item', $refusal->getMessage() );
		$this->assertStringContainsString( '410', $refusal->remediation );
		$this->assertStringContainsString( 'menu-item-update', $refusal->remediation );

		// The refusal is a refusal: nothing was deleted.
		$this->assertSame( [], $this->deleted );
		$this->assertArrayHasKey( 400, $this->items );
	}

	public function test_a_child_in_another_menu_does_not_block_the_removal(): void {
		// Item 900 stores 400 as its parent but lives in menu 6, so a child walk
		// that queries the parent identifier alone would refuse this removal.
		unset( $this->items[410], $this->itemMenus[410] );

		$result = $this->planThenApply( [ 'item' => 400 ] );

		$this->assertSame( [ 'exists' => false ], $result['after'] );
		$this->assertArrayHasKey( 900, $this->items );
	}

	public function test_the_plan_promises_absence_and_names_the_item_it_removes(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'item' => 430 ], $context );
		$planned = $this->operation->planChange( $current, [ 'item' => 430 ], $context );

		$this->assertSame( [ 'exists' => false ], $planned->afterFields );
		$this->assertSame( [ 'exists' ], $planned->fieldOrder );
		$this->assertSame( 430, $planned->payload['item'] );
		$this->assertSame( 5, $planned->payload['menu'] );
		$this->assertSame( 'Launch offer', $planned->payload['title'] );
		$this->assertSame( 'https://example.com/original', $planned->payload['url'] );
	}

	public function test_the_snapshot_records_the_whole_field_set_and_changes_nothing(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'item' => 430 ], $context );

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame( 430, $first['item_id'] );
		$this->assertSame( 5, $first['menu_id'] );
		$this->assertSame( 'Launch offer', $first['menu-item-title'] );
		$this->assertSame( 'https://example.com/original', $first['menu-item-url'] );
		$this->assertSame( [], $this->deleted );
		$this->assertSame( [], $this->written );
	}

	public function test_an_item_still_present_after_the_delete_fails_the_write(): void {
		// A before_delete_post handler that vetoes the removal: core still
		// answers, and the row is still there.
		Functions\when( 'wp_delete_post' )->justReturn( false );

		$refusal = $this->refusalFrom( [ 'item' => 430 ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'still present', $refusal->getMessage() );
	}

	public function test_the_restore_puts_the_item_back_under_a_new_identifier(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'item' => 430 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->operation->planChange( $current, [ 'item' => 430 ], $context );
		$this->operation->applyChange( $current, $this->operation->planChange( $current, [ 'item' => 430 ], $context ), $context );

		$restored_key = $this->operation->restore( $snapshot, $context );

		$this->assertNotSame( 'menu-item:430', $restored_key );

		$restored_id = MenuTarget::itemIdFromKey( $restored_key );
		$this->assertNotNull( $restored_id );

		$row = $this->items[ $restored_id ];
		$this->assertSame( 'Launch offer', $row->post_title );
		$this->assertSame( 'https://example.com/original', $row->url );
		$this->assertSame( 5, $this->itemMenus[ $restored_id ] );

		// The dead identifier is never handed forwards: core's own
		// wp_update_nav_menu_item() would pass it to wp_update_post(), which
		// refuses a row that is gone.
		$this->assertArrayNotHasKey( 'menu-item-db-id', $this->written[0] );
	}

	public function test_the_restore_refuses_a_state_that_names_no_menu(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->restore( [ 'item_id' => 430 ], $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );

			throw $refusal;
		}
	}

	public function test_a_rollback_resolves_against_the_absence_the_write_left(): void {
		$this->planThenApply( [ 'item' => 430 ] );

		$state = $this->operation->resolveRollbackTarget( 'menu-item:430', $this->makeContext() );

		$this->assertSame( 'menu-item:430', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [ 'exists' => false ], $state->fields );
	}

	public function test_a_rollback_is_refused_without_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->operation->resolveRollbackTarget( 'menu-item:430', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
			$this->assertStringNotContainsString( 'edit_theme_options', $refusal->getMessage() );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_a_rollback_promises_the_item_will_be_there_again(): void {
		$context = $this->makeContext();
		$current = new \SiteHelm\Change\TargetState( 'menu-item:430', false, [ 'exists' => false ] );

		$this->assertSame(
			[ 'exists' => true ],
			$this->operation->promiseRollback(
				[
					'item_id' => 430,
					'menu_id' => 5,
				],
				$current,
				$context
			)
		);

		// A state naming no menu promises nothing, which the caller turns into
		// rollback_unavailable rather than into a promise it cannot keep.
		$this->assertSame( [], $this->operation->promiseRollback( [ 'item_id' => 430 ], $current, $context ) );
	}

	public function test_a_caller_who_may_not_administer_menus_is_refused(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$refusal = $this->refusalFrom( [ 'item' => 430 ] );

		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertStringNotContainsString( 'edit_theme_options', $refusal->getMessage() );
	}

	public function test_an_unknown_item_is_refused(): void {
		$refusal = $this->refusalFrom( [ 'item' => 9999 ] );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->deleted );
	}

	private function makeMenu( int $id, string $slug, string $name ): stdClass {
		$menu           = new stdClass();
		$menu->term_id  = $id;
		$menu->slug     = $slug;
		$menu->name     = $name;
		$menu->taxonomy = MenuFields::MENU_TAXONOMY;

		return $menu;
	}

	private function makeItem( int $id, string $title, int $parent ): stdClass {
		$item              = new stdClass();
		$item->ID          = $id;
		$item->post_type   = MenuFields::ITEM_POST_TYPE;
		$item->post_status = 'publish';

		$item->post_title   = $title;
		$item->post_content = 'A description.';
		$item->post_excerpt = 'Tooltip';

		$item->title       = $title;
		$item->description = $item->post_content;
		$item->attr_title  = $item->post_excerpt;

		$item->url              = 'https://example.com/original';
		$item->type             = 'custom';
		$item->object           = 'custom';
		$item->object_id        = 0;
		$item->menu_item_parent = $parent;
		$item->menu_order       = 2;
		$item->target           = '_blank';
		$item->xfn              = 'me';
		$item->classes          = [ 'nav-cta' ];

		return $item;
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-menus-delete',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'menus' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the whole write the way the change engine does.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array<string, mixed> Keys 'targetKey', 'after', 'planned', 'snapshot'.
	 */
	private function planThenApply( array $input ): array {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );

		$this->operation->planChange( $current, $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$planned    = $this->operation->planChange( $current, $input, $context );
		$target_key = $this->operation->applyChange( $current, $planned, $context );

		return [
			'targetKey' => $target_key,
			'after'     => $this->operation->readBack( $target_key, $context )->fields,
			'planned'   => $planned,
			'snapshot'  => $snapshot,
		];
	}

	/**
	 * Runs the whole write and reports the refusal instead of letting it escape.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalFrom( array $input ): OperationException {
		try {
			$this->planThenApply( $input );
		} catch ( OperationException $refusal ) {
			return $refusal;
		} catch ( Throwable $other ) {
			$this->fail( 'Expected an OperationException, got ' . $other::class . ': ' . $other->getMessage() );
		}

		$this->fail( 'The write was expected to be refused and was not.' );
	}
}
