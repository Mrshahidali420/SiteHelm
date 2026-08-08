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
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemUpdate;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0029: an operator changes SOME fields of one navigation menu item.
 *
 * THE DOUBLE FOR wp_update_nav_menu_item() CLOBBERS, exactly as core does: the
 * stored row is rebuilt from the supplied field array alone, and every field the
 * array omits takes core's default. That is not incidental fidelity — it is what
 * makes test_it_updates_only_the_title_and_leaves_every_other_field_intact()
 * able to fail. A double that merged for the operation would report a missing
 * merge as a pass, which is the "test that cannot fail" defect this codebase has
 * shipped six times.
 *
 * Refusals run the WHOLE write through planThenApply() rather than planChange()
 * alone, so a check moved into applyChange() is caught by the empty $callOrder
 * rather than being reported as an equivalent pass.
 */
final class MenuItemUpdateTest extends TestCase {

	private MenuItemUpdate $operation;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	private array $items = [];

	/** @var array<int, int> The menu each item belongs to, keyed by item identifier. */
	private array $itemMenus = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	private array $written = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MenuFields();
		$this->operation = new MenuItemUpdate( $fields, new MenuTarget( $fields ) );

		$this->written   = [];
		$this->callOrder = [];

		// Menu 5 holds a three-deep custom-link chain plus one post-type item.
		// Menu 6 holds one item, which is the parent no item of menu 5 may take.
		$this->items = [
			400 => $this->makeItem( 400, 'Home & Co', 0 ),
			410 => $this->makeItem( 410, 'Middle', 400 ),
			420 => $this->makeItem( 420, 'Deep', 410 ),
			430 => $this->makeItem( 430, 'About us', 0 ),
			900 => $this->makeItem( 900, 'Elsewhere', 0 ),
		];

		$this->items[430]->type      = 'post_type';
		$this->items[430]->object    = 'page';
		$this->items[430]->object_id = 42;
		$this->items[430]->url       = 'https://example.com/about/';

		$this->itemMenus = [
			400 => 5,
			410 => 5,
			420 => 5,
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

		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';
				$this->written[]   = $data;

				$id = (int) $item_id;

				// CORE'S CLOBBER, reproduced: the row is rebuilt from $data alone.
				//
				// AND THEN CORE'S DERIVATION, reproduced: the four columns are
				// stored, and `wp_setup_nav_menu_item()` computes `title`,
				// `description` and `attr_title` FROM THOSE COLUMNS on the way
				// back out. The previous double wrote menu-item-description
				// straight onto `description`, modelling a lossless round trip
				// WordPress does not perform, which is exactly what hid the
				// truncating, escaping and re-texturizing merge base.
				$item = $this->makeItem( $id, '', 0 );

				$item->post_status  = (string) ( $data['menu-item-status'] ?? 'publish' );
				$item->post_title   = stripslashes( (string) ( $data['menu-item-title'] ?? '' ) );
				$item->post_content = stripslashes( (string) ( $data['menu-item-description'] ?? '' ) );
				$item->post_excerpt = stripslashes( (string) ( $data['menu-item-attr-title'] ?? '' ) );

				$item->title       = self::derivedTitle( $item->post_title );
				$item->description = self::derivedDescription( $item->post_content );
				$item->attr_title  = self::derivedAttrTitle( $item->post_excerpt );

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

				$this->items[ $id ] = $item;

				return $id;
			}
		);
	}

	private function makeMenu( int $id, string $slug, string $name ): stdClass {
		$menu           = new stdClass();
		$menu->term_id  = $id;
		$menu->slug     = $slug;
		$menu->name     = $name;
		$menu->taxonomy = MenuFields::MENU_TAXONOMY;

		return $menu;
	}

	/**
	 * A stored description longer than the 200 words wp_trim_words() keeps.
	 *
	 * @return string The stored post_content.
	 */
	private static function storedDescription(): string {
		return implode( ' ', array_map( static fn( int $n ): string => 'word' . $n, range( 1, 250 ) ) );
	}

	/**
	 * `wp_setup_nav_menu_item()`'s `description`: wp_trim_words( post_content, 200 ).
	 *
	 * @param string $content The stored post_content.
	 *
	 * @return string The derived description.
	 */
	private static function derivedDescription( string $content ): string {
		$words = '' === trim( $content ) ? [] : preg_split( '/\s+/', trim( $content ) );
		$words = is_array( $words ) ? $words : [];

		return count( $words ) > 200 ? implode( ' ', array_slice( $words, 0, 200 ) ) . '…' : $content;
	}

	/**
	 * `wp_setup_nav_menu_item()`'s `title`: post_title through the_title filters,
	 * of which wptexturize is the one that rewrites the stored text.
	 *
	 * @param string $post_title The stored post_title.
	 *
	 * @return string The derived title.
	 */
	private static function derivedTitle( string $post_title ): string {
		return str_replace( '&', '&#038;', $post_title );
	}

	/**
	 * `wp_setup_nav_menu_item()`'s `attr_title`: post_excerpt through the
	 * nav_menu_attr_title filters, of which esc_html is the rewriting one.
	 *
	 * @param string $excerpt The stored post_excerpt.
	 *
	 * @return string The derived tooltip.
	 */
	private static function derivedAttrTitle( string $excerpt ): string {
		return str_replace( '&', '&amp;', $excerpt );
	}

	/**
	 * One menu item row whose STORED COLUMNS AND DERIVED PROPERTIES DISAGREE.
	 *
	 * The disagreement is the fixture's whole job. This class previously set the
	 * derived properties ALONE — no post_title, no post_content, no post_excerpt —
	 * so a merge base reading the rendering was indistinguishable from one reading
	 * the columns, and the truncation, the escaping, and the re-texturizing all
	 * passed unseen.
	 *
	 * @param int    $id     The item identifier.
	 * @param string $title  The STORED post_title.
	 * @param int    $parent The stored parent.
	 *
	 * @return stdClass The row.
	 */
	private function makeItem( int $id, string $title, int $parent ): stdClass {
		$item              = new stdClass();
		$item->ID          = $id;
		$item->post_type   = MenuFields::ITEM_POST_TYPE;
		$item->post_status = 'publish';

		$item->post_title   = $title;
		$item->post_content = self::storedDescription();
		$item->post_excerpt = 'Tips & tricks';

		$item->title       = self::derivedTitle( $item->post_title );
		$item->description = self::derivedDescription( $item->post_content );
		$item->attr_title  = self::derivedAttrTitle( $item->post_excerpt );

		$item->url              = 'https://example.com/original';
		$item->type             = 'custom';
		$item->object           = 'custom';
		$item->object_id        = 0;
		$item->menu_item_parent = $parent;
		$item->menu_order       = 2;
		$item->target           = '_blank';
		$item->xfn              = 'me';
		$item->classes          = [ 'nav-cta', 'is-loud' ];

		return $item;
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-menus-4',
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
}
