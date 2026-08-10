<?php
/**
 * Shared fixture world for the MenuItemCreate tests (REQ-0028).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemCreate;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * ONE RESPONSIBILITY: stand up the in-memory WordPress a MenuItemCreate test runs
 * against — the menu, its existing item, the page and term the request may point
 * at, the write and delete doubles that record what was called — and offer the two
 * drivers that run a request the way the change engine does.
 *
 * It holds no assertions of its own. Every claim about behaviour lives in a
 * subclass, so a fixture change cannot quietly satisfy a test.
 *
 * THE DOUBLES ARE THE SAFETY NET. `$callOrder` is empty unless a WordPress write
 * function actually ran, which is the only evidence a refusal wrote nothing;
 * `$written` keeps every field array handed to `wp_update_nav_menu_item()`, which
 * is where the camelCase-to-`menu-item-*` mapping is checked.
 */
abstract class MenuItemCreateTestCase extends TestCase {

	protected MenuItemCreate $operation;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	protected array $items = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	protected array $written = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	protected array $callOrder = [];

	/** @var array<string, object> The terms get_term() will answer, keyed "taxonomy:id". */
	protected array $terms = [];

	/** @var array<int, object> The posts get_post() will answer, keyed by identifier. */
	protected array $posts = [];

	protected int $nextItemId = 500;

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MenuFields();
		$this->operation = new MenuItemCreate( $fields, new MenuTarget( $fields ) );

		$this->items      = [];
		$this->written    = [];
		$this->callOrder  = [];
		$this->nextItemId = 500;

		$menu           = new stdClass();
		$menu->term_id  = 5;
		$menu->name     = 'Primary';
		$menu->slug     = 'primary';
		$menu->taxonomy = MenuFields::MENU_TAXONOMY;

		$this->terms = [ 'category:31' => $this->makeTerm( 31, 'category' ) ];

		$page              = new stdClass();
		$page->ID          = 42;
		$page->post_type   = 'page';
		$page->post_title  = 'About us';
		$page->post_status = 'publish';
		$this->posts       = [ 42 => $page ];

		// An item already in menu 5, so a snapshot has something to record and
		// the restore has a recorded id it must NOT delete.
		$this->items[400] = $this->makeItem( 400, 'Existing', 0 );

		// WordPress is not loaded, so there is no WP_Error class to instantiate.
		// A stdClass carrying an `errors` member stands in for one, and
		// is_wp_error() recognises exactly that shape.
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof stdClass && isset( $thing->errors )
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( strip_tags( $v ) ) );
		Functions\when( 'sanitize_html_class' )->alias(
			static fn( string $v ): string => preg_replace( '/[^A-Za-z0-9_\-]/', '', $v ) ?? ''
		);
		Functions\when( 'esc_url_raw' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_get_nav_menu_object' )->alias(
			static fn( $key ) => in_array( $key, [ 5, '5', 'primary', 'Primary' ], true ) ? $menu : false
		);

		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $taxonomy ): bool => in_array( $taxonomy, [ 'category', 'post_tag' ], true )
		);
		Functions\when( 'get_term' )->alias(
			fn( $id, $taxonomy = '' ) => $this->terms[ $taxonomy . ':' . (int) $id ] ?? null
		);
		Functions\when( 'post_type_exists' )->alias(
			static fn( string $type ): bool => in_array( $type, [ 'page', 'post' ], true )
		);
		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => $this->posts[ (int) $id ] ?? ( $this->items[ (int) $id ] ?? null )
		);
		Functions\when( 'is_nav_menu_item' )->alias(
			fn( $id = 0 ): bool => isset( $this->items[ (int) $id ] )
		);
		Functions\when( 'wp_setup_nav_menu_item' )->alias( static fn( $post ) => $post );
		Functions\when( 'wp_get_object_terms' )->alias(
			fn( $id, $taxonomy = '' ) => isset( $this->items[ (int) $id ] ) ? [ $menu ] : []
		);
		Functions\when( 'wp_get_nav_menu_items' )->alias(
			fn( $menu_id ) => 5 === (int) $menu_id ? array_values( $this->items ) : []
		);

		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';
				$this->written[]   = $data;

				$id = 0 === (int) $item_id ? ++$this->nextItemId : (int) $item_id;

				$item                   = $this->makeItem( $id, '', 0 );
				$item->title            = stripslashes( (string) ( $data['menu-item-title'] ?? '' ) );
				$item->url              = stripslashes( (string) ( $data['menu-item-url'] ?? '' ) );
				$item->type             = (string) ( $data['menu-item-type'] ?? 'custom' );
				$item->object           = (string) ( $data['menu-item-object'] ?? 'custom' );
				$item->object_id        = (int) ( $data['menu-item-object-id'] ?? 0 );
				$item->menu_item_parent = (int) ( $data['menu-item-parent-id'] ?? 0 );
				$item->menu_order       = (int) ( $data['menu-item-position'] ?? 0 );
				$item->target           = (string) ( $data['menu-item-target'] ?? '' );
				$item->description      = stripslashes( (string) ( $data['menu-item-description'] ?? '' ) );
				$item->xfn              = (string) ( $data['menu-item-xfn'] ?? '' );
				$item->classes          = array_values(
					array_filter( explode( ' ', (string) ( $data['menu-item-classes'] ?? '' ) ) )
				);

				$this->items[ $id ] = $item;

				return $id;
			}
		);

		Functions\when( 'wp_delete_post' )->alias(
			function ( $id, $force = false ) {
				$this->callOrder[] = 'wp_delete_post';
				$post              = $this->items[ (int) $id ] ?? false;
				unset( $this->items[ (int) $id ] );

				return $post;
			}
		);
	}

	protected function makeTerm( int $id, string $taxonomy ): stdClass {
		$term           = new stdClass();
		$term->term_id  = $id;
		$term->taxonomy = $taxonomy;
		$term->name     = 'A term';

		return $term;
	}

	protected function makeItem( int $id, string $title, int $parent ): stdClass {
		$item                   = new stdClass();
		$item->ID               = $id;
		$item->post_type        = MenuFields::ITEM_POST_TYPE;
		$item->post_status      = 'publish';
		$item->title            = $title;
		$item->url              = 'https://example.com/';
		$item->type             = 'custom';
		$item->object           = 'custom';
		$item->object_id        = 0;
		$item->menu_item_parent = $parent;
		$item->menu_order       = 1;
		$item->target           = '';
		$item->attr_title       = '';
		$item->description      = '';
		$item->xfn              = '';
		$item->classes          = [];

		return $item;
	}

	protected function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-menus-1',
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
	 * Runs the whole write the way the change engine does: resolve, plan,
	 * capture, plan again, apply, read back.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array<string, mixed> Keys 'targetKey' and 'after'.
	 */
	protected function planThenApply( array $input ): array {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );

		$this->operation->planChange( $current, $input, $context );
		$this->operation->captureSnapshot( $current, $context );

		$planned    = $this->operation->planChange( $current, $input, $context );
		$target_key = $this->operation->applyChange( $current, $planned, $context );

		return [
			'targetKey' => $target_key,
			'after'     => $this->operation->readBack( $target_key, $context )->fields,
			'planned'   => $planned,
		];
	}

	/**
	 * Runs the whole write and reports the refusal instead of letting it escape.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return OperationException The refusal.
	 */
	protected function refusalFrom( array $input ): OperationException {
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
