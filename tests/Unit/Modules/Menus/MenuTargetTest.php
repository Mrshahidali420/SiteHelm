<?php
/**
 * Tests for MenuTarget, the shared menus target resolver.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The three behaviours the four menus writes share: naming a target, recording
 * one menu item completely, and replaying that record.
 *
 * The snapshot and restore halves are tested as a PAIR wherever possible, because
 * the defect they exist to prevent is asymmetric: a field the snapshot omits and
 * a field the restore ignores both pass a one-sided test, and only the round trip
 * shows that the value came back.
 */
final class MenuTargetTest extends TestCase {

	private MenuTarget $targets;

	/** @var array<int, object> The live nav menu item rows, keyed by identifier. */
	private array $items = [];

	/** @var array<int, array<string, mixed>> Every wp_update_nav_menu_item() field array, in order. */
	private array $written = [];

	/** @var string[] The WordPress write functions called, in order. */
	private array $callOrder = [];

	/** @var int|null The menu wp_get_object_terms() reports as owning any item. */
	private ?int $owningMenuId = 5;

	protected function setUp(): void {
		parent::setUp();

		$this->targets      = new MenuTarget( new MenuFields() );
		$this->items        = [];
		$this->written      = [];
		$this->callOrder    = [];
		$this->owningMenuId = 5;

		$menu           = new stdClass();
		$menu->term_id  = 5;
		$menu->slug     = 'primary';
		$menu->taxonomy = MenuFields::MENU_TAXONOMY;

		$this->items[400] = $this->makeItem( 400 );

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
		Functions\when( 'wp_get_nav_menu_object' )->alias(
			static fn( $key ) => in_array( $key, [ 5, '5', 'primary' ], true ) ? $menu : false
		);
		Functions\when( 'is_nav_menu_item' )->alias( fn( $id = 0 ): bool => isset( $this->items[ (int) $id ] ) );
		Functions\when( 'get_post' )->alias( fn( $id = null ) => $this->items[ (int) $id ] ?? null );
		Functions\when( 'wp_setup_nav_menu_item' )->alias( static fn( $post ) => $post );
		Functions\when( 'wp_get_object_terms' )->alias(
			function ( $id, $taxonomy = '' ) use ( $menu ) {
				if ( null === $this->owningMenuId || ! isset( $this->items[ (int) $id ] ) ) {
					return [];
				}

				$term           = new stdClass();
				$term->term_id  = $this->owningMenuId;
				$term->taxonomy = MenuFields::MENU_TAXONOMY;

				return [ $term ];
			}
		);
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';
				$this->written[]   = $data;

				// CORE'S CLOBBER-THEN-DERIVE, reproduced: the field array is stored
				// into the COLUMNS, and the display properties are then computed FROM
				// those columns rather than copied from the request. A double that
				// echoed menu-item-description straight back into `description` would
				// model a lossless round trip WordPress does not perform, and would
				// report a snapshot reading the derived values as a pass.
				$item                   = $this->makeItem( (int) $item_id );
				$item->post_status      = (string) ( $data['menu-item-status'] ?? '' );
				$item->post_title       = stripslashes( (string) ( $data['menu-item-title'] ?? '' ) );
				$item->post_content     = stripslashes( (string) ( $data['menu-item-description'] ?? '' ) );
				$item->post_excerpt     = stripslashes( (string) ( $data['menu-item-attr-title'] ?? '' ) );
				$item->title            = self::derivedTitle( $item->post_title );
				$item->description      = self::derivedDescription( $item->post_content );
				$item->attr_title       = self::derivedAttrTitle( $item->post_excerpt );
				$item->url              = stripslashes( (string) ( $data['menu-item-url'] ?? '' ) );
				$item->type             = (string) ( $data['menu-item-type'] ?? '' );
				$item->object           = (string) ( $data['menu-item-object'] ?? '' );
				$item->object_id        = (int) ( $data['menu-item-object-id'] ?? 0 );
				$item->menu_item_parent = (int) ( $data['menu-item-parent-id'] ?? 0 );
				$item->menu_order       = (int) ( $data['menu-item-position'] ?? 0 );
				$item->target           = (string) ( $data['menu-item-target'] ?? '' );
				$item->xfn              = (string) ( $data['menu-item-xfn'] ?? '' );
				$item->classes          = array_values(
					array_filter( explode( ' ', (string) ( $data['menu-item-classes'] ?? '' ) ) )
				);

				$this->items[ (int) $item_id ] = $item;

				return (int) $item_id;
			}
		);
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
	 * That disagreement is the whole fixture. `wp_setup_nav_menu_item()` decorates
	 * the post with `title`, `description`, and `attr_title` computed FROM the
	 * columns rather than equal to them, and a fixture where the two happen to
	 * match cannot tell a snapshot that reads the columns apart from one that reads
	 * the rendering — which is exactly the shape that let a 250-word description
	 * come back as 201 words and `Home & Co` come back as `Home &#038; Co`.
	 *
	 * @param int $id The item identifier.
	 *
	 * @return stdClass The row.
	 */
	private function makeItem( int $id ): stdClass {
		$item              = new stdClass();
		$item->ID          = $id;
		$item->post_type   = MenuFields::ITEM_POST_TYPE;
		$item->post_status = 'publish';

		$item->post_title   = 'Products & More';
		$item->post_content = self::storedDescription();
		$item->post_excerpt = 'Tips & tricks';

		$item->title       = self::derivedTitle( $item->post_title );
		$item->description = self::derivedDescription( $item->post_content );
		$item->attr_title  = self::derivedAttrTitle( $item->post_excerpt );

		$item->url              = 'https://example.com/products';
		$item->type             = 'post_type';
		$item->object           = 'page';
		$item->object_id        = 42;
		$item->menu_item_parent = 7;
		$item->menu_order       = 3;
		$item->target           = '_blank';
		$item->xfn              = 'me';
		$item->classes          = [ 'nav-cta', 'is--loud' ];

		return $item;
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-target-1',
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

	public function test_the_key_helpers_round_trip_an_identifier(): void {
		$this->assertSame( 'menu:5', MenuTarget::menuTargetKey( 5 ) );
		$this->assertSame( 'menu-item:400', MenuTarget::itemTargetKey( 400 ) );
		$this->assertSame( 5, MenuTarget::menuIdFromKey( 'menu:5' ) );
		$this->assertSame( 400, MenuTarget::itemIdFromKey( 'menu-item:400' ) );
	}

	/**
	 * The identifier reader must answer null, never 0, for every key it cannot
	 * parse. Zero is not an absent menu: handing it to wp_update_nav_menu_item()
	 * as a menu identifier asks WordPress to CREATE one.
	 *
	 * @dataProvider unparseableKeys
	 *
	 * @param string $key The target key.
	 */
	public function test_the_identifier_reader_answers_null_for_a_key_it_cannot_parse( string $key ): void {
		$this->assertNull( MenuTarget::menuIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]> The unparseable keys.
	 */
	public static function unparseableKeys(): array {
		return [
			'another prefix'   => [ 'menu-item:400' ],
			'no prefix at all' => [ '5' ],
			'a slug'           => [ 'menu:primary' ],
			'a negative id'    => [ 'menu:-5' ],
			'a zero id'        => [ 'menu:0' ],
			'nothing after it' => [ 'menu:' ],
		];
	}

	public function test_it_resolves_a_menu_by_slug(): void {
		$state = $this->targets->resolveMenu( 'primary', $this->makeContext() );

		$this->assertSame( 'menu:5', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_it_refuses_a_menu_key_that_names_nothing(): void {
		$this->expectException( OperationException::class );

		$this->targets->resolveMenu( 'secondary', $this->makeContext() );
	}

	/**
	 * The capability is tested BEFORE the lookup, so a caller without it learns
	 * nothing about which menus exist. A key naming a real menu and a key naming
	 * none must be refused identically, and with forbidden rather than not-found.
	 */
	public function test_it_refuses_every_menu_key_identically_without_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$real    = $this->refusalFrom( 'primary' );
		$missing = $this->refusalFrom( 'secondary' );

		$this->assertSame( ErrorCode::Forbidden, $real->errorCode );
		$this->assertSame( ErrorCode::Forbidden, $missing->errorCode );
		$this->assertSame( $real->getMessage(), $missing->getMessage() );
	}

	private function refusalFrom( string $key ): OperationException {
		try {
			$this->targets->resolveMenu( $key, $this->makeContext() );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The lookup was expected to be refused and was not.' );
	}

	public function test_it_resolves_an_existing_item_into_the_read_projection(): void {
		$state = $this->targets->resolveItem( 400, $this->makeContext() );

		$this->assertSame( 'menu-item:400', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 400, $state->fields['id'] );
		$this->assertSame( 'Products &#038; More', $state->fields['title'] );
		$this->assertSame( [ 'nav-cta', 'is--loud' ], $state->fields['classes'] );
	}

	public function test_it_refuses_an_item_identifier_that_names_nothing(): void {
		try {
			$this->targets->resolveItem( 9999, $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );

			return;
		}

		$this->fail( 'An identifier naming no item must be refused.' );
	}

	public function test_it_refuses_an_item_without_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->targets->resolveItem( 400, $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );

			return;
		}

		$this->fail( 'A caller without the capability must be refused.' );
	}

	/**
	 * A row a wp_setup_nav_menu_item filter substituted without an `ID` must be
	 * treated as no item at all. Zero is both "absent" and the root-parent
	 * sentinel, and conflating them produced an unbounded recursion in this
	 * module already.
	 */
	public function test_an_item_row_carrying_no_identifier_is_treated_as_absent(): void {
		Functions\when( 'wp_setup_nav_menu_item' )->alias(
			static function ( $post ) {
				$row        = clone $post;
				$row->ID    = 0;
				$row->title = 'Injected';

				return $row;
			}
		);

		$this->expectException( OperationException::class );

		$this->targets->resolveItem( 400, $this->makeContext() );
	}

	public function test_the_verification_read_names_the_correlation_when_the_item_is_gone(): void {
		try {
			$this->targets->verifyRead( 'menu-item:9999', 'corr-target-1' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode );
			$this->assertStringContainsString( 'corr-target-1', (string) $refusal->remediation );

			return;
		}

		$this->fail( 'A key naming no item must fail verification.' );
	}

	public function test_the_verification_read_projects_the_item_it_re_reads(): void {
		$state = $this->targets->verifyRead( 'menu-item:400', 'corr-target-1' );

		$this->assertSame( 'menu-item:400', $state->targetKey );
		$this->assertSame( 'Products &#038; More', $state->fields['title'] );
	}

	public function test_the_verification_read_refuses_a_key_that_is_not_an_item_key(): void {
		$this->expectException( OperationException::class );

		$this->targets->verifyRead( 'menu:5', 'corr-target-1' );
	}

	/**
	 * The snapshot must record EVERY wp_update_nav_menu_item() field, not the
	 * subset a given write touches, because that call defaults every field it is
	 * not handed. A missing key here is a field a rollback resets rather than
	 * restores.
	 */
	public function test_the_snapshot_records_every_restorable_field_in_sorted_order(): void {
		$snapshot = $this->targets->snapshotItem( 400 );

		$expected = array_merge( [ 'item_id', 'menu_id' ], MenuTarget::RESTORABLE_ITEM_FIELDS );
		sort( $expected, SORT_STRING );

		$this->assertSame( $expected, array_keys( (array) $snapshot ) );
		$this->assertSame( 400, $snapshot['item_id'] );
		$this->assertSame( 5, $snapshot['menu_id'] );
		$this->assertSame( 'nav-cta is--loud', $snapshot['menu-item-classes'] );
		$this->assertSame( 'publish', $snapshot['menu-item-status'] );
		$this->assertSame( 7, $snapshot['menu-item-parent-id'] );
		$this->assertSame( '_blank', $snapshot['menu-item-target'] );
		$this->assertSame( [], $this->callOrder, 'The snapshot must write nothing.' );
	}

	/**
	 * THE LOAD-BEARING TEST of the snapshot's SOURCE.
	 *
	 * `wp_setup_nav_menu_item()` hands over a row whose `title`, `description`,
	 * and `attr_title` are DERIVED from the stored columns, not equal to them.
	 * Anything this snapshot records is replayed into `wp_update_nav_menu_item()`
	 * by restoreItem() and is the merge base MenuItemUpdate writes from, so
	 * recording the derived values writes the rendering back over the source:
	 * a 250-word description stored back as 200, a tooltip stored back as its
	 * escaped form, and a title texturized once more on every write.
	 *
	 * Each of the three is asserted separately, because sourcing two of the
	 * three correctly is still a data-loss bug.
	 */
	public function test_the_snapshot_records_the_stored_columns_not_the_derived_display_values(): void {
		$snapshot = (array) $this->targets->snapshotItem( 400 );

		$this->assertSame( 'Products & More', $snapshot['menu-item-title'] );
		$this->assertSame( self::storedDescription(), $snapshot['menu-item-description'] );
		$this->assertSame( 'Tips & tricks', $snapshot['menu-item-attr-title'] );

		$this->assertNotSame( $this->items[400]->title, $snapshot['menu-item-title'] );
		$this->assertNotSame( $this->items[400]->description, $snapshot['menu-item-description'] );
		$this->assertNotSame( $this->items[400]->attr_title, $snapshot['menu-item-attr-title'] );
	}

	/**
	 * A draft menu item must snapshot as a draft.
	 *
	 * The recorded status is replayed verbatim, and `wp_update_nav_menu_item()`
	 * resolves anything other than 'publish' to 'draft'. A hardcoded 'publish'
	 * therefore does not merely mis-record the field: it PUBLISHES an unfinished
	 * item to the live site as a side effect of an update, or of a rollback that
	 * was asked to put the site back the way it was.
	 */
	public function test_the_snapshot_records_a_draft_item_as_a_draft(): void {
		$this->items[400]->post_status = 'draft';

		$snapshot = (array) $this->targets->snapshotItem( 400 );

		$this->assertSame( 'draft', $snapshot['menu-item-status'] );

		$this->targets->restoreItem( $snapshot );

		$this->assertSame( 'draft', $this->written[0]['menu-item-status'] );
		$this->assertSame( 'draft', $this->items[400]->post_status );
	}

	public function test_the_snapshot_is_identical_on_a_second_call(): void {
		$this->assertSame( $this->targets->snapshotItem( 400 ), $this->targets->snapshotItem( 400 ) );
	}

	public function test_the_snapshot_is_absent_for_an_identifier_that_names_no_item(): void {
		$this->assertNull( $this->targets->snapshotItem( 9999 ) );
	}

	public function test_the_snapshot_is_absent_for_an_item_no_menu_owns(): void {
		$this->owningMenuId = null;

		$this->assertNull( $this->targets->snapshotItem( 400 ) );
	}

	public function test_the_snapshot_round_trips_every_field_back_onto_the_item(): void {
		$snapshot = (array) $this->targets->snapshotItem( 400 );

		// The item drifts: every recorded field is now wrong, including the two
		// that a `?? ''` gate would silently reset rather than restore.
		$this->items[400]->post_title   = 'Renamed';
		$this->items[400]->post_excerpt = '';
		$this->items[400]->post_content = '';
		$this->items[400]->classes      = [];

		$restored = $this->targets->restoreItem( $snapshot );

		$this->assertSame( 'menu-item:400', $restored );
		$this->assertSame( 'Products & More', $this->items[400]->post_title );
		$this->assertSame( 'Tips & tricks', $this->items[400]->post_excerpt );
		$this->assertSame( self::storedDescription(), $this->items[400]->post_content );
		$this->assertSame( [ 'nav-cta', 'is--loud' ], $this->items[400]->classes );
		$this->assertSame( 'publish', $this->written[0]['menu-item-status'] );

		// A RESTORE IS A FIXED POINT, and this is where a snapshot sourced from
		// the derived properties gives itself away: re-snapshotting the restored
		// item would answer the RENDERING, so a second round trip would truncate
		// the description again and texturize the title again.
		$this->assertSame( $snapshot, (array) $this->targets->snapshotItem( 400 ) );
	}

	/**
	 * An ABSENT key means "this state does not describe that field" and must be
	 * left alone. A recorded '' means "set this back to empty" and must be sent.
	 * `??` collapses the two, which is how a rollback silently clears a field
	 * nobody recorded.
	 */
	public function test_a_field_the_state_omits_is_not_sent_and_a_recorded_empty_one_is(): void {
		$this->targets->restoreItem(
			[
				'item_id'          => 400,
				'menu_id'          => 5,
				'menu-item-title'  => 'Products',
				'menu-item-xfn'    => '',
				'menu-item-status' => 'publish',
			]
		);

		$this->assertArrayNotHasKey( 'menu-item-attr-title', $this->written[0] );
		$this->assertArrayHasKey( 'menu-item-xfn', $this->written[0] );
		$this->assertSame( '', $this->written[0]['menu-item-xfn'] );
	}

	/**
	 * @dataProvider unrestorableStates
	 *
	 * @param array<string, mixed> $state The recorded state.
	 */
	public function test_it_refuses_a_state_that_cannot_be_restored( array $state ): void {
		try {
			$this->targets->restoreItem( $state );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertSame( [], $this->callOrder );

			return;
		}

		$this->fail( 'A state that names no item, no menu, or no field must be refused.' );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>> The unrestorable states.
	 */
	public static function unrestorableStates(): array {
		return [
			'no item identifier'  => [ [ 'menu_id' => 5 ] ],
			'no menu identifier'  => [ [ 'item_id' => 400 ] ],
			'a non-numeric item'  => [
				[
					'item_id' => 'four hundred',
					'menu_id' => 5,
				],
			],
			'no recorded fields'  => [
				[
					'item_id' => 400,
					'menu_id' => 5,
				],
			],
			'only unusable field' => [
				[
					'item_id'             => 400,
					'menu_id'             => 5,
					'menu-item-object-id' => 'not a number',
					'menu-item-title'     => [ 'not a string' ],
				],
			],
		];
	}

	public function test_it_reports_execution_failed_when_wordpress_refuses_the_replay(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';

				$error         = new stdClass();
				$error->errors = [ 'nope' => [ 'refused' ] ];

				return $error;
			}
		);

		try {
			$this->targets->restoreItem( (array) $this->targets->snapshotItem( 400 ) );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );

			return;
		}

		$this->fail( 'A refused replay must not report success.' );
	}

	public function test_it_reports_execution_failed_when_the_replay_writes_nothing(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( $menu_id, $item_id, $data = [] ) {
				$this->callOrder[] = 'wp_update_nav_menu_item';

				return 0;
			}
		);

		try {
			$this->targets->restoreItem( (array) $this->targets->snapshotItem( 400 ) );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );

			return;
		}

		$this->fail( 'A replay that wrote nothing must not report success.' );
	}
}
