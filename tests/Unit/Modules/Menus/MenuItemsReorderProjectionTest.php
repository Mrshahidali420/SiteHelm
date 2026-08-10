<?php
/**
 * Tests for MenuItemsReorder (REQ-0030): read-back and the declared contract.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Menus\MenuItemsReorder;
use SiteHelm\Schema\SchemaValidator;
use stdClass;

/**
 * REQ-0030: the projection the operation reads back, and the definition it
 * publishes.
 *
 * Read-back is what the change engine verifies against, so it must refuse a key
 * it cannot resolve rather than answering an empty order, and it must drop rows
 * it cannot identify rather than projecting a zero. The definition tests pin the
 * declared shape against the shared write union the matrix requires.
 */
final class MenuItemsReorderProjectionTest extends MenuItemsReorderTestCase {

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
	 * MenuArrangement::itemRows().
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
	 * MenuTarget::menuIdFromKey() answers null before any lookup is attempted.
	 *
	 * Mutation that breaks this: dropping the `str_starts_with()` guard from
	 * MenuTarget::id_from_key().
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

	/**
	 * THE READ-BACK MUST RESOLVE BY TERM ID, never through the id/slug/name key
	 * resolver. `wp_get_nav_menu_object()` tries the term lookup, then a SLUG
	 * lookup, then a NAME lookup — so on a site where some other menu carries the
	 * bare-number slug "5", a write to menu 5 whose term has since gone would
	 * verify against that other menu and report ITS order as the written state.
	 *
	 * Mutation that breaks this: dropping the `term_id` comparison from
	 * MenuItemsReorder::menu_by_id().
	 */
	public function test_read_back_refuses_a_menu_that_only_a_slug_lookup_answers(): void {
		$shadow = $this->makeMenu( 6, 'Footer', '5' );

		// Core's own fallback chain: the term lookup for 5 finds nothing, and the
		// slug lookup for "5" finds a DIFFERENT menu.
		Functions\when( 'wp_get_nav_menu_object' )->alias(
			static fn( mixed $key ): mixed => '5' === (string) $key ? $shadow : false
		);

		try {
			$this->operation->readBack( 'menu:5', $this->makeContext() );
			$this->fail( 'A menu found only by its slug must not be accepted as the written one.' );
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
