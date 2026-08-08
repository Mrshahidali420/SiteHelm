<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): the writes that succeed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use SiteHelm\Modules\Menus\MenuItemsReorder;

/**
 * REQ-0030: the happy paths — what a reorder actually writes.
 *
 * Every test here drives resolve, plan, apply and read back, and asserts on the
 * argument set that reached `wp_update_nav_menu_item()` rather than only on the
 * projection, because a merge that read the derived properties rather than the
 * stored columns is invisible from the projection alone.
 */
final class MenuItemsReorderTest extends MenuItemsReorderTestCase {

	// -------------------------------------------------------------------------
	// The happy paths.
	// -------------------------------------------------------------------------

	/**
	 * The plain case the requirement names: three siblings given new positions in
	 * one call.
	 *
	 * Mutation that breaks this: dropping `menu-item-position` from the argument
	 * set MenuArrangement::itemArgs() builds.
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
}
