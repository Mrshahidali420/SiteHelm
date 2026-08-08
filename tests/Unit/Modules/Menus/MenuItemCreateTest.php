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
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemCreate;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0028: an operator adds one item to an existing navigation menu.
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
final class MenuItemCreateTest extends TestCase {

	private MenuItemCreate $operation;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	private array $items = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	private array $written = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	/** @var array<string, object> The terms get_term() will answer, keyed "taxonomy:id". */
	private array $terms = [];

	/** @var array<int, object> The posts get_post() will answer, keyed by identifier. */
	private array $posts = [];

	private int $nextItemId = 500;

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

	private function makeTerm( int $id, string $taxonomy ): stdClass {
		$term           = new stdClass();
		$term->term_id  = $id;
		$term->taxonomy = $taxonomy;
		$term->name     = 'A term';

		return $term;
	}

	private function makeItem( int $id, string $title, int $parent ): stdClass {
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

	private function makeContext(): OperationContext {
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
	private function planThenApply( array $input ): array {
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
