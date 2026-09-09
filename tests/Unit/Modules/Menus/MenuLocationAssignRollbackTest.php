<?php
/**
 * MenuLocationAssign as a stored-rollback delegate (audit finding C1).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuLocationAssign;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * ONE RESPONSIBILITY: prove that a location map a location-assign recorded can be
 * put back through the delegated rollback path — the state C1 found the plugin
 * advertised and then refused.
 *
 * THE LOAD-BEARING TEST IS test_the_promise_equals_the_read_back_of_a_real_rollback().
 * A restore puts the recorded state of its own location back, so the location
 * this snapshot names ends up holding what the recorded map assigned it; the promise is
 * that value read through readBack()'s own `menuId` rule. The test reassigns the
 * location for real, restores the snapshot, re-reads it, and asserts the promised
 * assignment is the one the location now reports — the guard against a promise
 * that names an assignment the restore does not actually reproduce.
 *
 * The fixture mirrors MenuLocationAssignTest's, whose makeContext() is private and
 * so cannot be inherited: 'primary' holds menu 34, 'footer' is registered but
 * absent, and the theme registers exactly those two locations.
 */
final class MenuLocationAssignRollbackTest extends TestCase {

	private MenuLocationAssign $operation;

	/** @var array<string, mixed> The live nav_menu_locations theme mod. */
	private array $locations = [];

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new MenuLocationAssign( new MenuFields() );
		$this->locations = [ 'primary' => 34 ];

		Functions\when( 'user_can' )->justReturn( true );

		Functions\when( 'get_registered_nav_menus' )->justReturn(
			[
				'primary' => 'Primary Navigation',
				'footer'  => 'Footer Navigation',
			]
		);

		Functions\when( 'get_nav_menu_locations' )->alias( fn(): array => $this->locations );

		Functions\when( 'set_theme_mod' )->alias(
			function ( $name, $value ): void {
				if ( is_array( $value ) ) {
					$this->locations = $value;
				}
			}
		);

		Functions\when( 'wp_get_nav_menu_object' )->alias(
			static function ( $key ) {
				$menus = [
					12 => 'main-menu',
					34 => 'secondary-menu',
				];

				foreach ( $menus as $id => $slug ) {
					if ( $key === $id || $key === $slug ) {
						$term          = new stdClass();
						$term->term_id = $id;
						$term->name    = ucfirst( $slug );
						$term->slug    = $slug;

						return $term;
					}
				}

				return null;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-menus-rollback',
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

	public function test_the_operation_is_a_rollback_delegate(): void {
		$this->assertInstanceOf( RollbackDelegate::class, $this->operation );
	}

	public function test_a_rollback_resolves_the_locations_current_assignment(): void {
		$state = $this->operation->resolveRollbackTarget( 'menu-location:primary', $this->makeContext() );

		$this->assertSame( 'menu-location:primary', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame(
			[
				'location' => 'primary',
				'menuId'   => 34,
			],
			$state->fields
		);
	}

	public function test_a_rollback_is_refused_without_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->operation->resolveRollbackTarget( 'menu-location:primary', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
			$this->assertStringNotContainsString( 'edit_theme_options', $refusal->getMessage() );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_a_rollback_of_a_key_that_names_no_location_is_target_not_found(): void {
		try {
			$this->operation->resolveRollbackTarget( 'menu-location:sidebar', $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );

			return;
		}

		$this->fail( 'The rollback was expected to be refused and was not.' );
	}

	public function test_the_promise_carries_the_assignment_the_recorded_map_held(): void {
		$snapshot = [
			'location'  => 'primary',
			'locations' => [ 'primary' => 34 ],
		];

		$this->assertSame(
			[
				'location' => 'primary',
				'menuId'   => 34,
			],
			$this->operation->promiseRollback(
				$snapshot,
				$this->operation->resolveTarget( [ 'location' => 'primary', 'menu' => null ], $this->makeContext() ),
				$this->makeContext()
			)
		);
	}

	public function test_a_state_that_names_no_location_promises_nothing(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'location' => 'primary', 'menu' => null ], $context );

		$this->assertSame( [], $this->operation->promiseRollback( [ 'locations' => [ 'primary' => 34 ] ], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [ 'location' => '', 'locations' => [] ], $current, $context ) );
		$this->assertSame( [], $this->operation->promiseRollback( [ 'location' => 'primary', 'locations' => 'not-an-array' ], $current, $context ) );
	}

	public function test_a_sibling_assignment_made_after_the_snapshot_survives_the_rollback(): void {
		// The snapshot records the whole map, but the restore writes back only
		// its own location: a menu assigned to another location between the
		// write and the rollback is not this rollback's to unassign.
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'location' => 'primary', 'menu' => null ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->locations = [
			'primary' => 12,
			'footer'  => 12,
		];

		$this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( 34, $this->locations['primary'] );
		$this->assertSame( 12, $this->locations['footer'] );
	}

	public function test_the_promise_equals_the_read_back_of_a_real_rollback(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'location' => 'primary', 'menu' => null ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		// A genuine reassignment: point 'primary' at a different menu, the change
		// the whole-map promise must move back.
		$plan = $this->operation->planChange(
			$current,
			[
				'location' => 'primary',
				'menu'     => 'main-menu',
			],
			$context
		);
		$this->operation->applyChange( $current, $plan, $context );
		$this->assertSame( 12, $this->locations['primary'] );

		// Put the recorded map back, then re-read it exactly as the engine does.
		$restored_key = $this->operation->restore( (array) $snapshot, $context );
		$after        = $this->operation->readBack( $restored_key, $context )->fields;

		$promise = $this->operation->promiseRollback( (array) $snapshot, $current, $context );

		foreach ( $promise as $field => $value ) {
			$this->assertSame( $value, $after[ $field ], "Promised {$field} does not match the read-back." );
		}
	}
}
