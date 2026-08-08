<?php
/**
 * Shared fixture world for the MenuItemsReorder tests (REQ-0030).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemsReorder;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * ONE RESPONSIBILITY: stand up the in-memory WordPress a MenuItemsReorder test
 * runs against — one menu, the five item rows it holds, the ownership map, and
 * the recording write double — and offer the drivers that run a request the way
 * the change engine does.
 *
 * It declares no test of its own. Every claim about behaviour lives in a
 * subclass.
 *
 * THE DEFINING PROPERTY OF THE OPERATION IS ALL-OR-NOTHING, and the fixture is
 * what makes that provable: `wp_update_nav_menu_item()` RECORDS every call in
 * $written before it mutates the row, so a refusal test can assert that nothing
 * at all was written. assertRefusesWithoutWriting() lives here rather than in
 * one subclass because both the refusal tests and the restore tests need it,
 * and an error code asserted without the empty-$written assertion would pass
 * against an implementation that wrote two of three items and then threw.
 *
 * THE STORED COLUMNS AND THE DERIVED PROPERTIES DISAGREE ON PURPOSE in
 * makeItem(); see its docblock. That disagreement must survive any edit here.
 */
abstract class MenuItemsReorderTestCase extends TestCase {

	protected MenuItemsReorder $operation;

	/**
	 * The menu wp_get_nav_menu_object() resolves, or null for a site with none.
	 */
	protected ?stdClass $menu = null;

	/**
	 * The item rows wp_get_nav_menu_items() serves for menu 5.
	 *
	 * @var mixed
	 */
	protected mixed $items = [];

	/**
	 * The menu each known item identifier belongs to.
	 *
	 * @var array<int, int>
	 */
	protected array $owner = [];

	/**
	 * Every wp_update_nav_menu_item() call, in the order it was made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $written = [];

	/**
	 * The item identifiers whose write reports a WordPress failure.
	 *
	 * @var int[]
	 */
	protected array $failing = [];

	/**
	 * Whether the resolved WordPress user holds `edit_theme_options`.
	 */
	protected bool $permitted = true;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new MenuItemsReorder( new MenuFields() );
		$this->permitted = true;
		$this->written   = [];
		$this->failing   = [];
		$this->menu      = $this->makeMenu( 5, 'Primary Navigation', 'primary-navigation' );
		$this->items     = [
			$this->makeItem( 11, 'Home', 0, 1 ),
			$this->makeItem( 12, 'Services', 0, 2 ),
			$this->makeItem( 13, 'Design', 12, 3 ),
			$this->makeItem( 14, 'Build', 12, 4 ),
			$this->makeItem( 15, 'Contact', 0, 5 ),
		];
		$this->owner     = [
			11 => 5,
			12 => 5,
			13 => 5,
			14 => 5,
			15 => 5,
			99 => 6,
		];

		$this->stubWordPress();
	}

	protected function makeMenu( int $id, string $name, string $slug ): stdClass {
		$menu          = new stdClass();
		$menu->term_id = $id;
		$menu->name    = $name;
		$menu->slug    = $slug;

		return $menu;
	}

	/**
	 * One menu item row shaped the way wp_get_nav_menu_items() serves it.
	 *
	 * Both the raw post columns AND the derived properties are present, and they
	 * DISAGREE on purpose: `description` carries the 200-word-trimmed rendering
	 * that wp_setup_nav_menu_item() produces while `post_content` carries the
	 * stored text, and `title` carries the texturized rendering while
	 * `post_title` carries the stored text. A reorder that merged from the
	 * derived properties would silently rewrite both on every call, which is the
	 * data loss the porting source ships.
	 */
	protected function makeItem( int $id, string $title, int $parent, int $order ): stdClass {
		$item                   = new stdClass();
		$item->ID               = $id;
		$item->post_title       = $title;
		$item->post_content     = 'Stored description for ' . $title;
		$item->post_excerpt     = 'Stored attribute title for ' . $title;
		$item->post_status      = 'publish';
		$item->title            = 'Texturized ' . $title;
		$item->description      = 'Trimmed description for ' . $title;
		$item->attr_title       = 'Filtered attribute title for ' . $title;
		$item->url              = 'https://example.com/' . strtolower( $title );
		$item->type             = 'post_type';
		$item->object           = 'page';
		$item->object_id        = 900 + $id;
		$item->menu_item_parent = $parent;
		$item->menu_order       = $order;
		$item->target           = '';
		$item->classes          = [ 'nav-item', '' ];
		$item->xfn              = '';

		return $item;
	}

	/**
	 * Stubs every core call this operation reaches, directly or through
	 * MenuFields.
	 *
	 * `wp_update_nav_menu_item()` MUTATES the row it names rather than only
	 * recording the call, so a read after a write sees what the write stored.
	 * Without that, every round-trip assertion below would be asserting against
	 * the fixture rather than against the operation's own effect.
	 */
	protected function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool =>
				'edit_theme_options' === $capability && $this->permitted
		);
		Functions\when( 'wp_get_nav_menu_object' )->alias(
			function ( mixed $key ): mixed {
				if ( null === $this->menu ) {
					return false;
				}

				$known = [ $this->menu->term_id, $this->menu->slug, $this->menu->name ];

				return in_array( $key, $known, true ) ? $this->menu : false;
			}
		);
		Functions\when( 'wp_get_nav_menu_items' )->alias(
			fn( int $menu_id ): mixed => 5 === $menu_id ? $this->items : false
		);
		Functions\when( 'is_nav_menu_item' )->alias(
			fn( int $item_id ): bool => array_key_exists( $item_id, $this->owner )
		);
		Functions\when( 'wp_get_object_terms' )->alias(
			function ( int $item_id, string $taxonomy ): mixed {
				if ( ! array_key_exists( $item_id, $this->owner ) ) {
					return [];
				}

				$term          = new stdClass();
				$term->term_id = $this->owner[ $item_id ];

				return [ $term ];
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( mixed $thing ): bool => is_object( $thing ) && isset( $thing->is_wp_error_double )
		);
		Functions\when( 'wp_slash' )->alias( [ self::class, 'slash' ] );
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( int $menu_id, int $item_id, array $args ): mixed {
				$this->written[] = [
					'menu' => $menu_id,
					'item' => $item_id,
					'args' => $args,
				];

				if ( in_array( $item_id, $this->failing, true ) ) {
					$error                    = new stdClass();
					$error->is_wp_error_double = true;

					return $error;
				}

				foreach ( is_array( $this->items ) ? $this->items : [] as $row ) {
					if ( (int) $row->ID === $item_id ) {
						$row->menu_item_parent = (int) $args['menu-item-parent-id'];
						$row->menu_order       = (int) $args['menu-item-position'];
					}
				}

				return $item_id;
			}
		);
	}

	/**
	 * wp_slash(), which the operation must apply because wp_update_nav_menu_item()
	 * hands its values to wp_update_post() and update_post_meta(), both of which
	 * unslash before storing.
	 *
	 * @param mixed $value The value to slash.
	 *
	 * @return mixed The slashed value.
	 */
	public static function slash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'slash' ], $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	protected function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
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
	 * The input a client sends.
	 *
	 * @param array<int, array<string, mixed>> $items The reorder entries.
	 * @param mixed                            $menu  The menu argument.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	protected function input( array $items, mixed $menu = 'primary-navigation' ): array {
		return [
			'menu'  => $menu,
			'items' => $items,
		];
	}

	/**
	 * Runs resolve and plan, the two phases every refusal happens in.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array{0: TargetState, 1: PlannedChange} The resolved state and plan.
	 */
	protected function plan( array $input ): array {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );

		return [ $current, $this->operation->planChange( $current, $input, $context ) ];
	}

	/**
	 * Runs the whole write the way the change engine drives it.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array<string, mixed> The verified persisted field map.
	 */
	protected function apply( array $input ): array {
		$context           = $this->makeContext();
		[ $current, $plan ] = $this->plan( $input );

		$this->operation->captureSnapshot( $current, $context );
		$key = $this->operation->applyChange( $current, $plan, $context );

		return $this->operation->readBack( $key, $context )->fields;
	}

	/**
	 * The stored order, read straight from the fixture rows.
	 *
	 * @return array<int, array<string, int>> Each item's identifier, parent, and position.
	 */
	protected function storedOrder(): array {
		$order = [];

		foreach ( is_array( $this->items ) ? $this->items : [] as $row ) {
			$order[] = [
				'id'       => (int) $row->ID,
				'parent'   => (int) $row->menu_item_parent,
				'position' => (int) $row->menu_order,
			];
		}

		return $order;
	}

	/**
	 * Asserts a refusal and, crucially, that NOTHING was written.
	 *
	 * @param callable  $run      The call that must refuse.
	 * @param ErrorCode $expected The expected error code.
	 *
	 * @return OperationException The refusal, for further assertions.
	 */
	protected function assertRefusesWithoutWriting( callable $run, ErrorCode $expected ): OperationException {
		try {
			$run();
		} catch ( OperationException $e ) {
			$this->assertSame( $expected, $e->errorCode );
			$this->assertSame( [], $this->written, 'A refused batch must write nothing at all.' );

			return $e;
		}

		$this->fail( 'The batch must be refused.' );
	}
}
