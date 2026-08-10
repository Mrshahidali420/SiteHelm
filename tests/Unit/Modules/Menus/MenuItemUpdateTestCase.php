<?php
/**
 * Shared fixture world for the MenuItemUpdate tests (REQ-0029).
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
use SiteHelm\Modules\Menus\MenuItemUpdate;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * ONE RESPONSIBILITY: stand up the in-memory WordPress a MenuItemUpdate test runs
 * against — two menus, the item rows they hold, and the write double — and offer
 * the two drivers that run a request the way the change engine does.
 *
 * It holds no assertions of its own. Every claim about behaviour lives in a
 * subclass.
 *
 * THE DOUBLE FOR wp_update_nav_menu_item() CLOBBERS, exactly as core does: the
 * stored row is rebuilt from the supplied field array alone, and every field the
 * array omits takes core's default. That is not incidental fidelity — it is what
 * makes test_it_updates_only_the_title_and_leaves_every_other_field_intact()
 * able to fail. A double that merged for the operation would report a missing
 * merge as a pass, which is the "test that cannot fail" defect this codebase has
 * shipped six times.
 *
 * AND THE DOUBLE SUBSTITUTES FOR A 0 POSITION, exactly as core does; see
 * storedPosition(). It did not, and that single unfaithful rule is why a merge
 * base handing core a 0 — which moves the menu's FIRST item to LAST — sat under
 * a passing suite: the load-bearing merge test happens to use a fixture at
 * menu_order 2, so nothing ever exercised the substitution.
 *
 * THE STORED COLUMNS AND THE DERIVED PROPERTIES DISAGREE ON PURPOSE. makeItem()
 * stores 250 words of post_content against a 200-word derived `description`, and
 * a post_title of "Home & Co" against a derived `title` of "Home &#038; Co". A
 * merge base reading the rendering rather than the columns is caught by that
 * disagreement and by nothing else, so it must survive any edit to this file.
 */
abstract class MenuItemUpdateTestCase extends TestCase {


	protected MenuItemUpdate $operation;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	protected array $items = [];

	/** @var array<int, int> The menu each item belongs to, keyed by item identifier. */
	protected array $itemMenus = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	protected array $written = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	protected array $callOrder = [];

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

				// AND THEN CORE'S POSITION SUBSTITUTION, reproduced. This double
				// wrote menu-item-position straight onto menu_order, which is the
				// one rule it was unfaithful on — and the rule REQ-0029's own merge
				// test depends on, so that test passed only because its fixture
				// happens to sit at menu_order 2.
				$item->menu_order = $this->storedPosition(
					(int) $menu_id,
					(int) ( $data['menu-item-position'] ?? 0 )
				);
				$item->target           = (string) ( $data['menu-item-target'] ?? '' );
				$item->xfn              = stripslashes( (string) ( $data['menu-item-xfn'] ?? '' ) );
				$item->classes          = array_values(
					array_filter( explode( ' ', stripslashes( (string) ( $data['menu-item-classes'] ?? '' ) ) ) )
				);

				$this->items[ $id ] = $item;

				return $id;
			}
		);

		// The only route to a stored menu_order of 0, because
		// wp_update_nav_menu_item() substitutes for a 0 and offers no way to opt
		// out. Recorded in $callOrder so a test can prove the correction ran
		// AFTER the write it corrects rather than instead of it.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr = [] ) {
				$this->callOrder[] = 'wp_update_post';

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

	/**
	 * What core actually stores for a requested `menu-item-position`.
	 *
	 * VERBATIM FROM wp-includes/nav-menu.php, because a double that is faithful
	 * everywhere except the one rule under test is how this class of bug survives
	 * a suite: a 0 is not "position zero", it is "append", and the empty-menu arm
	 * answers `count( $menu_items )` against a list `array_pop()` has already
	 * emptied — which is why 0 is a REACHABLE stored value and not a theory.
	 *
	 * The list is read the way core reads it, sorted by the stored order and
	 * INCLUDING the row being written, because core substitutes before it saves.
	 *
	 * @param int $menu_id   The menu being written to.
	 * @param int $requested The requested position.
	 *
	 * @return int The position core would store.
	 */
	protected function storedPosition( int $menu_id, int $requested ): int {
		if ( 0 !== $requested ) {
			return $requested;
		}

		$menu_items = array_values(
			array_filter(
				$this->items,
				fn( object $item ): bool => ( $this->itemMenus[ (int) $item->ID ] ?? 0 ) === $menu_id
			)
		);
		usort( $menu_items, static fn( object $a, object $b ): int => $a->menu_order <=> $b->menu_order );

		$last_item = array_pop( $menu_items );

		return null !== $last_item ? 1 + (int) $last_item->menu_order : count( $menu_items );
	}

	protected function makeMenu( int $id, string $slug, string $name ): stdClass {
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
	protected static function storedDescription(): string {
		return implode( ' ', array_map( static fn( int $n ): string => 'word' . $n, range( 1, 250 ) ) );
	}

	/**
	 * `wp_setup_nav_menu_item()`'s `description`: wp_trim_words( post_content, 200 ).
	 *
	 * @param string $content The stored post_content.
	 *
	 * @return string The derived description.
	 */
	protected static function derivedDescription( string $content ): string {
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
	protected static function derivedTitle( string $post_title ): string {
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
	protected static function derivedAttrTitle( string $excerpt ): string {
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
	protected function makeItem( int $id, string $title, int $parent ): stdClass {
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

	protected function makeContext(): OperationContext {
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
	protected function planThenApply( array $input ): array {
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
