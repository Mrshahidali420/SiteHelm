<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): the all-or-nothing refusals.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemsReorder;
use SiteHelm\Schema\SchemaValidator;

/**
 * REQ-0030: validation and refusal.
 *
 * THE DEFINING PROPERTY IS ALL-OR-NOTHING. The porting source refuses each bad
 * entry individually, writes the rest, and reports a count — a partial write
 * reported as success. Every refusal test below therefore asserts BOTH the error
 * code AND that `wp_update_nav_menu_item()` was never called, because the code
 * alone would pass against an implementation that wrote two of three items and
 * then threw.
 */
final class MenuItemsReorderRefusalTest extends MenuItemsReorderTestCase {

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
		$refusal = $this->assertRefusesWithoutWriting(
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

		// The MESSAGE, not just the code: every refusal in planChange() is
		// invalid_input, so a code-only assertion passes against any of the four
		// other refusals this one could collapse into.
		$this->assertSame(
			'Every entry must name a menu item identifier and a position of 1 or more, so none of the requested order was written.',
			$refusal->getMessage()
		);
	}

	/**
	 * THE SAME GUARD'S IDENTIFIER BOUND, which had no test and so looked dead.
	 *
	 * The membership check further down planChange() would reject a 0 identifier a
	 * moment later, which is why removing the bound leaves the suite green — but it
	 * would reject it as "does not name an item of this menu", sending the operator
	 * to look up a menu that never had an item 0. 0 is also the root-parent
	 * sentinel, the conflation that has already produced one unbounded recursion in
	 * this module, and it does not travel past normalized_entry().
	 *
	 * Mutation that breaks this: removing `$id < 1` from the guard in
	 * MenuItemsReorder::normalized_entry().
	 */
	public function test_it_refuses_an_identifier_below_one_as_a_malformed_entry(): void {
		$refusal = $this->assertRefusesWithoutWriting(
			fn(): array => $this->plan(
				$this->input(
					[
						[
							'id'       => 0,
							'position' => 1,
						],
					]
				)
			),
			ErrorCode::InvalidInput
		);

		$this->assertSame(
			'Every entry must name a menu item identifier and a position of 1 or more, so none of the requested order was written.',
			$refusal->getMessage()
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
}
