<?php
/**
 * Tests for MenuItemsReorder (REQ-0030).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemsReorder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0030: menu item reordering.
 *
 * THE DEFINING PROPERTY IS ALL-OR-NOTHING. The porting source refuses each bad
 * entry individually, writes the rest, and reports a count — a partial write
 * reported as success. Every refusal test below therefore asserts BOTH the error
 * code AND that `wp_update_nav_menu_item()` was never called, because the code
 * alone would pass against an implementation that wrote two of three items and
 * then threw.
 */
final class MenuItemsReorderTest extends TestCase {

	private MenuItemsReorder $operation;

	/**
	 * The menu wp_get_nav_menu_object() resolves, or null for a site with none.
	 */
	private ?stdClass $menu = null;

	/**
	 * The item rows wp_get_nav_menu_items() serves for menu 5.
	 *
	 * @var mixed
	 */
	private mixed $items = [];

	/**
	 * The menu each known item identifier belongs to.
	 *
	 * @var array<int, int>
	 */
	private array $owner = [];

	/**
	 * Every wp_update_nav_menu_item() call, in the order it was made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The item identifiers whose write reports a WordPress failure.
	 *
	 * @var int[]
	 */
	private array $failing = [];

	/**
	 * Whether the resolved WordPress user holds `edit_theme_options`.
	 */
	private bool $permitted = true;

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

	private function makeMenu( int $id, string $name, string $slug ): stdClass {
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
	private function makeItem( int $id, string $title, int $parent, int $order ): stdClass {
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
	private function stubWordPress(): void {
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

	private function makeContext(): OperationContext {
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
	private function input( array $items, mixed $menu = 'primary-navigation' ): array {
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
	private function plan( array $input ): array {
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
	private function apply( array $input ): array {
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
	private function storedOrder(): array {
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
	private function assertRefusesWithoutWriting( callable $run, ErrorCode $expected ): OperationException {
		try {
			$run();
		} catch ( OperationException $e ) {
			$this->assertSame( $expected, $e->errorCode );
			$this->assertSame( [], $this->written, 'A refused batch must write nothing at all.' );

			return $e;
		}

		$this->fail( 'The batch must be refused.' );
	}

	// -------------------------------------------------------------------------
	// The happy paths.
	// -------------------------------------------------------------------------

	/**
	 * The plain case the requirement names: three siblings given new positions in
	 * one call.
	 *
	 * Mutation that breaks this: dropping `menu-item-position` from the argument
	 * set MenuItemsReorder::item_args() builds.
	 */
	public function test_it_writes_the_new_position_for_every_named_sibling(): void {
		$fields = $this->apply(
			$this->input(
				[
					[
						'id'       => 15,
						'position' => 1,
					],
					[
						'id'       => 11,
						'position' => 2,
					],
					[
						'id'       => 12,
						'position' => 3,
					],
				]
			)
		);

		$this->assertSame( [ 15, 11, 12 ], array_column( $this->written, 'item' ) );
		$this->assertSame(
			[
				[
					'id'       => 11,
					'parent'   => 0,
					'position' => 2,
				],
				[
					'id'       => 12,
					'parent'   => 0,
					'position' => 3,
				],
				[
					'id'       => 13,
					'parent'   => 12,
					'position' => 3,
				],
				[
					'id'       => 14,
					'parent'   => 12,
					'position' => 4,
				],
				[
					'id'       => 15,
					'parent'   => 0,
					'position' => 1,
				],
			],
			$fields['order']
		);
	}

	/**
	 * A re-parent and a reorder in one call, which is the shape an operator uses
	 * to promote a child to top level.
	 *
	 * Mutation that breaks this: reading the parent from the stored row rather
	 * than from the entry in MenuItemsReorder::applyChange().
	 */
	public function test_it_reparents_and_reorders_in_one_call(): void {
		$fields = $this->apply(
			$this->input(
				[
					[
						'id'       => 13,
						'parent'   => 0,
						'position' => 6,
					],
					[
						'id'       => 14,
						'position' => 3,
					],
				]
			)
		);

		$this->assertSame(
			[
				'id'       => 13,
				'parent'   => 0,
				'position' => 6,
			],
			$fields['order'][2]
		);
		$this->assertSame(
			[
				'id'       => 14,
				'parent'   => 12,
				'position' => 3,
			],
			$fields['order'][3]
		);
	}

	/**
	 * An entry that names no parent must leave the item where it is nested. The
	 * absent-versus-zero axis again: `?? 0` on a missing `parent` would promote
	 * every reordered child to top level.
	 *
	 * Mutation that breaks this: replacing the array_key_exists() gate on
	 * `parent` with `(int) ( $entry['parent'] ?? 0 )`.
	 */
	public function test_an_entry_naming_no_parent_leaves_the_item_nested(): void {
		$fields = $this->apply(
			$this->input(
				[
					[
						'id'       => 13,
						'position' => 9,
					],
				]
			)
		);

		$this->assertSame( 12, $this->written[0]['args']['menu-item-parent-id'] );
		$this->assertSame(
			[
				'id'       => 13,
				'parent'   => 12,
				'position' => 9,
			],
			$fields['order'][2]
		);
	}

	/**
	 * A reorder must not be a content edit. wp_update_nav_menu_item() overwrites
	 * every field it is handed, so the merge has to carry the whole record — and
	 * it has to carry the STORED columns, not the derived properties
	 * wp_setup_nav_menu_item() computes. `description` is trimmed to 200 words on
	 * read and `title` is passed through the_title filters, so merging from those
	 * rewrites the item's own text on every reorder.
	 *
	 * Mutation that breaks this: merging `$row->description` instead of
	 * `$row->post_content`, or `$row->title` instead of `$row->post_title`.
	 */
	public function test_the_write_carries_the_stored_columns_rather_than_the_derived_ones(): void {
		$this->apply(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 4,
					],
				]
			)
		);

		$args = $this->written[0]['args'];

		$this->assertSame( 'Home', $args['menu-item-title'] );
		$this->assertSame( 'Stored description for Home', $args['menu-item-description'] );
		$this->assertSame( 'Stored attribute title for Home', $args['menu-item-attr-title'] );
		$this->assertSame( 11, $args['menu-item-db-id'] );
		$this->assertSame( 911, $args['menu-item-object-id'] );
		$this->assertSame( 'page', $args['menu-item-object'] );
		$this->assertSame( 'post_type', $args['menu-item-type'] );
		$this->assertSame( 'publish', $args['menu-item-status'] );
		$this->assertSame( 'nav-item', $args['menu-item-classes'] );
	}

	/**
	 * wp_update_nav_menu_item() hands its values to wp_update_post() and
	 * update_post_meta(), both of which unslash before storing, so an unslashed
	 * title loses a character on every reorder.
	 *
	 * Mutation that breaks this: dropping the wp_slash() call in
	 * MenuItemsReorder::applyChange().
	 */
	public function test_the_merged_record_is_slashed_on_the_way_in(): void {
		$this->items[0]->post_title = 'Home\\Office "Main"';

		$this->apply(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 4,
					],
				]
			)
		);

		$this->assertSame( 'Home\\\\Office \\"Main\\"', $this->written[0]['args']['menu-item-title'] );
	}

	// -------------------------------------------------------------------------
	// All-or-nothing refusals.
	// -------------------------------------------------------------------------

	/**
	 * THE REQUIREMENT'S CENTRAL CASE. Two valid entries and one that names no
	 * menu item at all: the valid two must not be written.
	 *
	 * Mutation that breaks this: moving the ownership check out of planChange()
	 * and into applyChange()'s loop, which is the porting source's shape.
	 */
	public function test_it_refuses_the_whole_batch_when_one_id_is_not_a_menu_item(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 3,
						],
						[
							'id'       => 4242,
							'position' => 1,
						],
						[
							'id'       => 12,
							'position' => 2,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * An identifier that IS a menu item but belongs to another menu. Reordering
	 * it under this menu's term would move it between menus, which is a different
	 * operation with a different risk.
	 */
	public function test_it_refuses_the_whole_batch_when_one_id_belongs_to_another_menu(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 1,
						],
						[
							'id'       => 99,
							'position' => 2,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * A parent naming an item of another menu is refused for the same reason:
	 * MenuFields::validateParent() answers false, and one false refuses the batch.
	 */
	public function test_it_refuses_a_parent_that_belongs_to_another_menu(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'parent'   => 99,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * A parent value that is not a menu item identifier at all — a string, here
	 * — is refused by normalized_entry() before MenuFields ever sees it, and it
	 * refuses the whole batch rather than only the malformed entry.
	 *
	 * Mutation that breaks this: dropping the `! is_int( $parent ) || $parent < 0`
	 * guard in MenuItemsReorder::normalized_entry().
	 */
	public function test_it_refuses_a_parent_that_is_not_a_menu_item_identifier(): void {
		$refusal = $this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'parent'   => 'not-an-id',
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);

		$this->assertSame(
			'One entry names a parent that is not a menu item identifier, so none of the requested order was written.',
			$refusal->getMessage()
		);
		$this->assertSame(
			'Send a parent identifier or 0 for top level, then request a fresh preview.',
			$refusal->remediation
		);
	}

	/**
	 * A batch that would make two items each other's ancestor. WordPress stores
	 * the relation without complaint and the menu then renders neither item, so
	 * the refusal has to happen here.
	 *
	 * Mutation that breaks this: removing the cycle walk from planChange().
	 */
	public function test_it_refuses_a_parent_that_would_create_a_cycle(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 12,
							'parent'   => 13,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * The shortest cycle there is. It needs no separate branch in the walk, and
	 * this test exists to prove that the general walk covers it rather than
	 * inviting a special case that would be one more thing to get wrong.
	 */
	public function test_it_refuses_an_item_that_would_become_its_own_parent(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'parent'   => 11,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * A cycle formed by two entries in ONE batch, neither of which is a cycle on
	 * its own. This is what makes the walk run over the PROJECTED order rather
	 * than over each entry against the stored tree.
	 *
	 * Mutation that breaks this: validating each entry's parent against the
	 * CURRENT parent map instead of the projected one.
	 */
	public function test_it_refuses_a_cycle_that_only_the_whole_batch_creates(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'parent'   => 12,
							'position' => 1,
						],
						[
							'id'       => 12,
							'parent'   => 11,
							'position' => 2,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	public function test_it_refuses_an_empty_items_array(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan( $this->input( [] ) ),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * The same item twice in one batch names two different positions for one row,
	 * so there is no order the operation could honestly promise.
	 */
	public function test_it_refuses_the_same_item_named_twice_in_one_batch(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 1,
						],
						[
							'id'       => 11,
							'position' => 2,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * A position of 0 is not "first": wp_update_nav_menu_item() replaces a 0
	 * position with the menu's item count plus one, so the item silently lands
	 * LAST. The input schema is the primary defence and the next test proves it;
	 * this one covers the same refusal for a caller that reaches planChange()
	 * directly.
	 */
	public function test_it_refuses_a_position_below_one(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 0,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);
	}

	/**
	 * The claim above, asserted rather than asserted about: the declared schema
	 * is what stops a 0 position at the dispatcher, so the schema must actually
	 * declare the bound and SchemaValidator must actually enforce it.
	 *
	 * Mutation that breaks this: dropping `minimum` from the `position` property
	 * in MenuItemsReorder::definition().
	 */
	public function test_the_input_schema_refuses_a_position_below_one(): void {
		try {
			( new SchemaValidator() )->validate(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 0,
						],
					]
				),
				MenuItemsReorder::definition()->inputSchema
			);
			$this->fail( 'SchemaValidator must refuse a position below 1.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * An empty `items` array passes schema validation — SchemaValidator
	 * implements no minItems — so the refusal above is known to cover a live path
	 * rather than an unreachable one.
	 */
	public function test_an_empty_items_array_passes_input_validation_and_reaches_the_plan(): void {
		$input = $this->input( [] );

		$this->assertSame(
			$input,
			( new SchemaValidator() )->validate( $input, MenuItemsReorder::definition()->inputSchema )
		);
	}

	public function test_it_refuses_a_menu_key_that_names_no_menu(): void {
		$this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 1,
						],
					],
					'no-such-menu'
				)
			),
			ErrorCode::TargetNotFound
		);
	}

	/**
	 * The capability is asked before the menu is resolved, so the refusal cannot
	 * be used to learn which menu keys exist, and it names neither the menu nor
	 * the key.
	 */
	public function test_it_refuses_without_edit_theme_options(): void {
		$this->permitted = false;

		$refusal = $this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::Forbidden
		);

		$this->assertStringNotContainsString( 'primary-navigation', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'Primary Navigation', $refusal->getMessage() );
	}

	/**
	 * An unpermitted caller gets the same refusal for a real key and an unknown
	 * one.
	 */
	public function test_an_unpermitted_caller_is_refused_the_same_way_for_a_real_and_an_unknown_key(): void {
		$this->permitted = false;
		$codes           = [];

		foreach ( [ 'primary-navigation', 'no-such-menu' ] as $key ) {
			try {
				$this->plan(
					$this->input(
						[
							[
								'id'       => 11,
								'position' => 1,
							],
						],
						$key
					)
				);
			} catch ( OperationException $e ) {
				$codes[] = $e->errorCode;
			}
		}

		$this->assertSame( [ ErrorCode::Forbidden, ErrorCode::Forbidden ], $codes );
	}

	/**
	 * A refusal must not echo an item identifier or a menu name back.
	 */
	public function test_a_refusal_names_no_item_and_no_menu(): void {
		$refusal = $this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 4242,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);

		$this->assertStringNotContainsString( '4242', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'Primary Navigation', $refusal->getMessage() );
	}

	// -------------------------------------------------------------------------
	// The promise.
	// -------------------------------------------------------------------------

	/**
	 * The plan promises the whole menu's projected order and nothing else, so
	 * WriteVerifier compares exactly the value this operation is responsible for.
	 */
	public function test_the_plan_promises_the_projected_order_and_nothing_else(): void {
		[ , $plan ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 15,
						'position' => 1,
					],
				]
			)
		);

		$this->assertSame( [ 'order' ], array_keys( $plan->afterFields ) );
		$this->assertSame( [ 'order' ], $plan->fieldOrder );
		$this->assertSame(
			[
				[
					'id'       => 11,
					'parent'   => 0,
					'position' => 1,
				],
				[
					'id'       => 12,
					'parent'   => 0,
					'position' => 2,
				],
				[
					'id'       => 13,
					'parent'   => 12,
					'position' => 3,
				],
				[
					'id'       => 14,
					'parent'   => 12,
					'position' => 4,
				],
				[
					'id'       => 15,
					'parent'   => 0,
					'position' => 1,
				],
			],
			$plan->afterFields['order']
		);
	}

	/**
	 * The promise is what the write actually produces. This is the assertion that
	 * makes the previous one worth having: a projected order nobody can reach is
	 * a promise WriteVerifier would classify as not-applied on every apply.
	 */
	public function test_the_promised_order_is_the_order_the_write_produces(): void {
		$input = $this->input(
			[
				[
					'id'       => 13,
					'parent'   => 0,
					'position' => 6,
				],
				[
					'id'       => 15,
					'position' => 2,
				],
			]
		);

		[ , $plan ] = $this->plan( $input );
		$fields     = $this->apply( $input );

		$this->assertSame( $plan->afterFields['order'], $fields['order'] );
	}

	// -------------------------------------------------------------------------
	// The snapshot and the restore.
	// -------------------------------------------------------------------------

	/**
	 * The snapshot records the prior parent AND position of every item in the
	 * menu, not only the ones the batch names, because a reorder is only
	 * reversible against the complete prior ordering.
	 */
	public function test_the_snapshot_records_the_prior_parent_and_position_of_every_item(): void {
		[ $current ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 5,
					],
				]
			)
		);

		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame( [ 'items', 'menu_id' ], array_keys( (array) $snapshot ) );
		$this->assertSame( 5, $snapshot['menu_id'] );
		$this->assertSame( $this->storedOrder(), $snapshot['items'] );
	}

	/**
	 * A resolved state that names no menu at all — `fields['id']` absent or 0
	 * — has nothing captureSnapshot() can capture, so it answers null rather
	 * than a snapshot the matching restore could never apply.
	 *
	 * Mutation that breaks this: dropping the `$menu_id <= 0` guard from
	 * MenuItemsReorder::captureSnapshot().
	 */
	public function test_capture_snapshot_answers_null_when_the_state_names_no_menu(): void {
		$state = new TargetState( 'menu:0', false, [] );

		$this->assertNull( $this->operation->captureSnapshot( $state, $this->makeContext() ) );
	}

	/**
	 * The change engine calls captureSnapshot() twice — once at preview for
	 * eligibility, once at apply for real — so it must be side-effect free and
	 * must answer identically both times.
	 *
	 * Mutation that breaks this: having captureSnapshot() write anything, or read
	 * live rows that the first call could have changed.
	 */
	public function test_capturing_the_snapshot_twice_answers_the_same_thing_and_writes_nothing(): void {
		[ $current ] = $this->plan(
			$this->input(
				[
					[
						'id'       => 11,
						'position' => 5,
					],
				]
			)
		);
		$context     = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->written );
	}

	/**
	 * The round trip the snapshot exists for: reorder, then restore, and the menu
	 * is byte-for-byte the order it started in.
	 */
	public function test_a_restore_puts_the_whole_prior_order_back(): void {
		$before  = $this->storedOrder();
		$context = $this->makeContext();
		$input   = $this->input(
			[
				[
					'id'       => 13,
					'parent'   => 0,
					'position' => 1,
				],
				[
					'id'       => 11,
					'position' => 4,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );
		$snapshot           = $this->operation->captureSnapshot( $current, $context );
		$this->operation->applyChange( $current, $plan, $context );

		$this->assertNotSame( $before, $this->storedOrder() );

		$key = $this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( 'menu:5', $key );
		$this->assertSame( $before, $this->storedOrder() );
	}

	/**
	 * THE `?? ''` TRAP. A recorded parent of 0 means "put this back at top
	 * level", and an ABSENT parent key means "do not touch the nesting". `??`
	 * collapses those two, and for a menu that is the difference between
	 * restoring a promoted item and silently promoting one that was never moved.
	 *
	 * Mutation that breaks this: replacing the array_key_exists() gate in
	 * MenuItemsReorder::restore() with `(int) ( $recorded['parent'] ?? 0 )`.
	 */
	public function test_a_restore_leaves_the_nesting_alone_when_the_record_omits_the_parent(): void {
		$context = $this->makeContext();

		$this->operation->restore(
			[
				'menu_id' => 5,
				'items'   => [
					[
						'id'       => 13,
						'position' => 7,
					],
				],
			],
			$context
		);

		$this->assertSame( 12, $this->written[0]['args']['menu-item-parent-id'] );
		$this->assertSame( 7, $this->written[0]['args']['menu-item-position'] );
	}

	/**
	 * The other half of the same axis: a RECORDED 0 is a value to write, not an
	 * absence to skip. Zero is how WordPress spells "top level", so a restore
	 * that skipped it would leave a promoted item promoted.
	 */
	public function test_a_recorded_zero_parent_is_written_back_rather_than_skipped(): void {
		$this->operation->restore(
			[
				'menu_id' => 5,
				'items'   => [
					[
						'id'       => 13,
						'parent'   => 0,
						'position' => 3,
					],
				],
			],
			$this->makeContext()
		);

		$this->assertSame( 0, $this->written[0]['args']['menu-item-parent-id'] );
		$this->assertSame( 0, (int) $this->items[2]->menu_item_parent );
	}

	/**
	 * A snapshot naming an item the menu no longer holds cannot be put back in
	 * full, and it is refused BEFORE anything is written rather than restoring
	 * the items that do still exist — the same all-or-nothing rule the forward
	 * write follows.
	 */
	public function test_a_restore_refuses_before_writing_when_a_recorded_item_is_gone(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 11,
							'parent'   => 0,
							'position' => 1,
						],
						[
							'id'       => 4242,
							'parent'   => 0,
							'position' => 2,
						],
					],
				],
				$this->makeContext()
			),
			ErrorCode::RollbackUnavailable
		);
	}

	public function test_a_restore_refuses_a_snapshot_that_names_no_menu(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore( [ 'items' => [] ], $this->makeContext() ),
			ErrorCode::RollbackUnavailable
		);
	}

	/**
	 * A restore has no WriteVerifier downstream, so if it does not measure what
	 * it stored, nothing does.
	 */
	public function test_a_restore_that_does_not_land_is_reported_as_failed(): void {
		$this->failing = [ 13 ];

		try {
			$this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 1,
						],
					],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore whose write failed must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * WordPress can accept a write and still not store what was sent — a
	 * wp_update_nav_menu_item filter can rewrite the arguments — and a restore
	 * has no WriteVerifier downstream to notice on its own. assert_restored()
	 * re-reads the menu and refuses when what actually landed disagrees with
	 * what was recorded, carrying every step that DID complete rather than
	 * reporting a clean restore that never happened.
	 *
	 * Mutation that breaks this: dropping the mismatch check in
	 * MenuItemsReorder::assert_restored(), or dropping `$completed` from the
	 * exception it throws there.
	 */
	public function test_a_restore_that_lands_differently_than_recorded_is_reported_with_what_completed(): void {
		Functions\when( 'wp_update_nav_menu_item' )->alias(
			function ( int $menu_id, int $item_id, array $args ): mixed {
				$this->written[] = [
					'menu' => $menu_id,
					'item' => $item_id,
					'args' => $args,
				];

				// Deliberately does NOT mutate $this->items, simulating a filter
				// that rewrote the arguments before WordPress stored them.
				return $item_id;
			}
		);

		try {
			$this->operation->restore(
				[
					'menu_id' => 5,
					'items'   => [
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 1,
						],
						[
							'id'       => 11,
							'position' => 4,
						],
					],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore that lands differently than recorded must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'WordPress stored a different menu order than the recorded snapshot held.',
				$e->getMessage()
			);
			$this->assertSame(
				'Reorder the menu on the WordPress menus screen instead.',
				$e->remediation
			);
			$this->assertSame(
				[ 'menu item 1 of 2 restored', 'menu item 2 of 2 restored' ],
				$e->completedSteps
			);
		}
	}

	/**
	 * A menu that holds no items at all — wp_get_nav_menu_items() answering
	 * false rather than an array — is the same "cannot be put back" refusal as
	 * a menu missing one recorded item, reached through item_rows()'s own
	 * defence against a non-array result.
	 */
	public function test_a_restore_refuses_when_the_menu_holds_no_items_at_all(): void {
		$this->assertRefusesWithoutWriting(
			fn(): string => $this->operation->restore(
				[
					'menu_id' => 6,
					'items'   => [
						[
							'id'       => 11,
							'parent'   => 0,
							'position' => 1,
						],
					],
				],
				$this->makeContext()
			),
			ErrorCode::RollbackUnavailable
		);
	}

	// -------------------------------------------------------------------------
	// Apply-phase failure reporting.
	// -------------------------------------------------------------------------

	/**
	 * VALIDATION CANNOT MAKE THE WRITE INFALLIBLE. Every entry passed planChange,
	 * and WordPress still refused the second of three — a save_post filter, a
	 * row deleted between plan and apply. What the operation owes the operator
	 * then is an exact account of what DID land, which is the field the porting
	 * source does not have and which ContentTermsAssign gets wrong by declaring
	 * its completed steps up front.
	 *
	 * The expected array is accumulated here from the recorded calls rather than
	 * hardcoded, so it cannot drift into agreeing with an implementation that
	 * reports a fixed list.
	 *
	 * Mutation that breaks this: declaring `$completed` with every step up front
	 * in applyChange().
	 */
	public function test_a_mid_batch_write_failure_reports_exactly_the_items_already_written(): void {
		$this->failing = [ 12 ];
		$context       = $this->makeContext();
		$input         = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
				[
					'id'       => 15,
					'position' => 1,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $e ) {
			$landed = array_slice( array_column( $this->written, 'item' ), 0, -1 );

			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 11, 12 ], array_column( $this->written, 'item' ) );
			$this->assertSame( [ 11 ], $landed );
			$this->assertSame(
				[ 'plan approved', 'snapshot captured', 'menu item 1 of 3 repositioned' ],
				$e->completedSteps
			);
		}
	}

	/**
	 * The first entry failing reports the two engine steps and no item step,
	 * which is the case a hardcoded list gets wrong in the other direction.
	 */
	public function test_a_first_entry_failure_reports_no_item_as_written(): void {
		$this->failing = [ 11 ];
		$context       = $this->makeContext();
		$input         = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $e->completedSteps );
			$this->assertSame( [ 11 ], array_column( $this->written, 'item' ) );
		}
	}

	/**
	 * A row that vanished between plan and apply is refused rather than written
	 * around, and the refusal still carries what landed.
	 */
	public function test_an_item_that_vanished_between_plan_and_apply_is_reported(): void {
		$context = $this->makeContext();
		$input   = $this->input(
			[
				[
					'id'       => 11,
					'position' => 3,
				],
				[
					'id'       => 12,
					'position' => 2,
				],
			]
		);

		[ $current, $plan ] = $this->plan( $input );

		$this->items = [ $this->items[0] ];

		try {
			$this->operation->applyChange( $current, $plan, $context );
			$this->fail( 'A vanished row must be reported.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				[ 'plan approved', 'snapshot captured', 'menu item 1 of 2 repositioned' ],
				$e->completedSteps
			);
		}
	}

	// -------------------------------------------------------------------------
	// Read-back.
	// -------------------------------------------------------------------------

	public function test_read_back_projects_the_stored_order_for_the_written_menu(): void {
		$state = $this->operation->readBack( 'menu:5', $this->makeContext() );

		$this->assertSame( 'menu:5', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( [ 'id', 'name', 'slug', 'order' ], array_keys( $state->fields ) );
		$this->assertSame( $this->storedOrder(), $state->fields['order'] );
	}

	public function test_read_back_refuses_a_key_that_names_no_menu(): void {
		try {
			$this->operation->readBack( 'menu:404', $this->makeContext() );
			$this->fail( 'A key naming no menu must fail verification.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	/**
	 * A row without an `ID` is a row a wp_get_nav_menu_items filter appended. It
	 * takes identifier 0, and 0 is also how "top level" is spelled — conflating
	 * the two produced an unbounded recursion in this module already. Such rows
	 * are dropped rather than projected.
	 *
	 * Mutation that breaks this: dropping the `> 0` filter from
	 * MenuItemsReorder::item_rows().
	 */
	public function test_a_row_without_an_identifier_is_dropped_from_the_projection(): void {
		$ghost               = new stdClass();
		$ghost->title        = 'Log in';
		$ghost->menu_order   = 9;
		$this->items[]       = $ghost;

		$order = $this->operation->readBack( 'menu:5', $this->makeContext() )->fields['order'];

		$this->assertSame( [ 11, 12, 13, 14, 15 ], array_column( $order, 'id' ) );
	}

	/**
	 * A target key without the menu prefix names no menu at all, and
	 * menu_id_from_key() answers null before any lookup is attempted.
	 *
	 * Mutation that breaks this: dropping the `str_starts_with()` guard from
	 * MenuItemsReorder::menu_id_from_key().
	 */
	public function test_read_back_refuses_a_key_with_no_menu_prefix(): void {
		// The tail past index 5 ("5") is a real, resolvable menu id on
		// purpose: it proves the refusal comes from the missing "menu:"
		// prefix itself, not merely from the tail failing to parse.
		try {
			$this->operation->readBack( 'abcde5', $this->makeContext() );
			$this->fail( 'A key with no menu prefix must fail verification.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	// -------------------------------------------------------------------------
	// The definition.
	// -------------------------------------------------------------------------

	/**
	 * The operation is not registered yet — the module registration is a
	 * serialized step the controller performs — so the definition's shape is
	 * asserted here directly rather than through the module's invariants tests.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = MenuItemsReorder::definition();

		$this->assertSame( 'menu-items-reorder', $definition->id );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( 'menu', $definition->domain->value );
		$this->assertSame( 'write', $definition->mode->value );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'medium', $definition->risk->value );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'required', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
		$this->assertSame( 1, $definition->schemaVersion );
	}

	/**
	 * `additionalProperties: false` at BOTH levels, which the requirement names
	 * explicitly: an unknown key inside an entry is as much a malformed request
	 * as an unknown key beside `menu`.
	 */
	public function test_the_input_schema_is_closed_at_both_levels(): void {
		$schema = MenuItemsReorder::definition()->inputSchema;
		$entry  = $schema['properties']['items']['items'];

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'menu', 'items' ], $schema['required'] );
		$this->assertFalse( $entry['additionalProperties'] );
		$this->assertSame( [ 'id', 'position' ], $entry['required'] );
		$this->assertSame( [ 'id', 'parent', 'position' ], array_keys( $entry['properties'] ) );

		try {
			( new SchemaValidator() )->validate(
				$this->input(
					[
						[
							'id'       => 11,
							'position' => 1,
							'title'    => 'Home',
						],
					]
				),
				$schema
			);
			$this->fail( 'An unknown property inside an entry must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so the shared write schema is asserted here.
	 */
	public function test_the_declared_output_schema_is_the_shared_write_union(): void {
		$schema = MenuItemsReorder::definition()->outputSchema;

		$this->assertCount( 2, $schema['oneOf'] );
		$this->assertSame( [ 'plan' ], $schema['oneOf'][0]['required'] );
		$this->assertSame( [ 'target', 'changed', 'state' ], $schema['oneOf'][1]['required'] );
	}

	/**
	 * The declared example must be a request the operation would actually
	 * accept, because it is what a client copies.
	 */
	public function test_the_declared_example_validates_against_the_declared_schema(): void {
		$definition = MenuItemsReorder::definition();

		$this->assertSame( 'menu-items-reorder', $definition->example['operation'] );
		$this->assertSame(
			$definition->example['arguments'],
			( new SchemaValidator() )->validate( $definition->example['arguments'], $definition->inputSchema )
		);
	}
}
