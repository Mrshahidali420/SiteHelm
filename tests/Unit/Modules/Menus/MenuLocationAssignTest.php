<?php
/**
 * Tests for MenuLocationAssign (REQ-0031).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuLocationAssign;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0031: an operator points a theme location at a menu, or clears it.
 *
 * The whole location table is ONE theme mod, so nearly every assertion below is
 * about the WHOLE MAP rather than about one key: a write that stored the right
 * value for `footer` while dropping `primary` is exactly the defect this
 * operation's snapshot shape exists to prevent, and a single-key assertion
 * would report it as a pass.
 *
 * Every refusal asserts that NO theme mod was written, not that the stored map
 * is unchanged. set_theme_mod() called with the map it already holds leaves the
 * map identical, so an unchanged map is consistent with a write having been
 * issued; only an empty write log says no write function was reached.
 */
final class MenuLocationAssignTest extends TestCase {

	private MenuLocationAssign $operation;

	/** @var array<string, mixed> The live nav_menu_locations theme mod. */
	private array $locations = [];

	/** @var array<int, array<string, mixed>> Every set_theme_mod() call, in order. */
	private array $themeModWrites = [];

	/** Whether the set_theme_mod() double actually persists what it is handed. */
	private bool $themeModPersists = true;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new MenuLocationAssign( new MenuFields() );

		// 'footer' is REGISTERED BUT ABSENT from the map, which is the state the
		// absent-versus-assigned restore cases turn on.
		$this->locations        = [ 'primary' => 34 ];
		$this->themeModWrites   = [];
		$this->themeModPersists = true;

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
				$this->themeModWrites[] = [
					'name'  => $name,
					'value' => $value,
				];

				if ( $this->themeModPersists && is_array( $value ) ) {
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
			correlationId: 'corr-menus-6',
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
	 * Runs the WHOLE write — resolve, plan, apply — and reports the refusal
	 * without letting it escape.
	 *
	 * Every refusal test goes through here rather than stopping at planChange(),
	 * so the "nothing was written" assertion is able to fail: an implementation
	 * that moved a check from planChange() into applyChange() would raise the
	 * same code from the same payload, and a plan-only test would never notice
	 * that the theme mod had already been rewritten by then.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );
			$planned = $this->operation->planChange( $current, $input, $context );
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = MenuLocationAssign::definition();

		$this->assertSame( 'menu-location-assign', $definition->id );
		$this->assertSame( Domain::Menu, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'location', 'menu' ], $definition->inputSchema['required'] );
		$this->assertSame(
			[ 'location', 'menu' ],
			array_keys( $definition->inputSchema['properties'] )
		);
	}

	public function test_assigns_a_menu_to_a_registered_location_that_held_none(): void {
		$refusal = $this->planAndApply(
			[
				'location' => 'footer',
				'menu'     => 'main-menu',
			]
		);

		$this->assertNull( $refusal );
		$this->assertCount( 1, $this->themeModWrites );
		$this->assertSame( MenuFields::LOCATIONS_THEME_MOD, $this->themeModWrites[0]['name'] );
		$this->assertSame(
			[
				'primary' => 34,
				'footer'  => 12,
			],
			$this->themeModWrites[0]['value']
		);
	}

	public function test_reassigns_a_location_that_already_held_a_different_menu(): void {
		$refusal = $this->planAndApply(
			[
				'location' => 'primary',
				'menu'     => 'main-menu',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( [ 'primary' => 12 ], $this->themeModWrites[0]['value'] );
	}

	public function test_a_null_menu_removes_the_location_key_rather_than_zeroing_it(): void {
		$refusal = $this->planAndApply(
			[
				'location' => 'primary',
				'menu'     => null,
			]
		);

		$this->assertNull( $refusal );
		$this->assertArrayNotHasKey( 'primary', $this->themeModWrites[0]['value'] );
		$this->assertSame( [], $this->themeModWrites[0]['value'] );
	}

	public function test_the_planned_change_promises_the_menu_identifier_alone(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];

		$planned = $this->operation->planChange(
			$this->operation->resolveTarget( $input, $context ),
			$input,
			$context
		);

		$this->assertSame( [ 'menuId' => 12 ], $planned->afterFields );
		$this->assertSame(
			[
				'location' => 'footer',
				'menuId'   => 12,
			],
			$planned->payload
		);
	}

	public function test_refuses_a_location_the_theme_has_not_registered(): void {
		$refusal = $this->planAndApply(
			[
				'location' => 'sidebar',
				'menu'     => 'main-menu',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->themeModWrites );
	}

	public function test_refuses_a_menu_key_that_names_no_menu(): void {
		$refusal = $this->planAndApply(
			[
				'location' => 'footer',
				'menu'     => 'ghost-menu',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertStringContainsString( 'menu', $refusal->getMessage() );
		$this->assertSame( [], $this->themeModWrites );
	}

	public function test_refuses_a_user_without_edit_theme_options(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$refusal = $this->planAndApply(
			[
				'location' => 'footer',
				'menu'     => 'main-menu',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame( [], $this->themeModWrites );
	}

	public function test_refuses_a_payload_that_names_no_menu_argument_at_all(): void {
		$context = $this->makeContext();
		$input   = [ 'location' => 'footer' ];
		$current = $this->operation->resolveTarget( $input, $context );

		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'menu' );

		try {
			$this->operation->planChange( $current, $input, $context );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::InvalidInput, $error->errorCode );
			$this->assertSame( [], $this->themeModWrites );

			throw $error;
		}
	}

	public function test_refuses_a_menu_argument_that_is_neither_a_string_nor_null(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => [ 'main-menu' ],
		];
		$current = $this->operation->resolveTarget( $input, $context );

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'An array menu argument must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::InvalidInput, $error->errorCode );
			$this->assertSame( [], $this->themeModWrites );
		}
	}

	public function test_the_snapshot_records_the_whole_location_map_and_writes_nothing(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];
		$current = $this->operation->resolveTarget( $input, $context );

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame(
			[
				'location'  => 'footer',
				'locations' => [ 'primary' => 34 ],
			],
			$first
		);
		$this->assertSame( $first, $second, 'captureSnapshot() must be side-effect free and stable across two calls.' );
		$this->assertSame( [], $this->themeModWrites );
	}

	public function test_restore_returns_a_previously_unassigned_location_to_absent(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$this->operation->applyChange( $current, $this->operation->planChange( $current, $input, $context ), $context );

		$this->assertSame( 12, $this->locations['footer'] );

		$restored = $this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( 'menu-location:footer', $restored );
		$this->assertArrayNotHasKey( 'footer', $this->locations );
		$this->assertSame( [ 'primary' => 34 ], $this->locations );
	}

	public function test_restore_returns_a_previously_assigned_location_to_its_prior_menu(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'primary',
			'menu'     => 'main-menu',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$this->operation->applyChange( $current, $this->operation->planChange( $current, $input, $context ), $context );

		$this->assertSame( 12, $this->locations['primary'] );

		$this->operation->restore( (array) $snapshot, $context );

		$this->assertSame( [ 'primary' => 34 ], $this->locations );
	}

	public function test_restore_puts_back_a_sibling_location_the_write_never_touched(): void {
		$this->locations = [
			'primary' => 34,
			'footer'  => 34,
		];

		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];
		$current  = $this->operation->resolveTarget( $input, $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$this->operation->applyChange( $current, $this->operation->planChange( $current, $input, $context ), $context );

		$this->operation->restore( (array) $snapshot, $context );

		$this->assertSame(
			[
				'footer'  => 34,
				'primary' => 34,
			],
			$this->locations
		);
	}

	public function test_restore_refuses_a_snapshot_that_carries_no_location_map(): void {
		try {
			$this->operation->restore( [ 'location' => 'footer' ], $this->makeContext() );
			$this->fail( 'A snapshot without a recorded map must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $error->errorCode );
			$this->assertSame( [], $this->themeModWrites );
		}
	}

	public function test_restore_refuses_a_snapshot_that_names_no_location(): void {
		try {
			$this->operation->restore( [ 'locations' => [ 'primary' => 34 ] ], $this->makeContext() );
			$this->fail( 'A snapshot without a recorded location must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $error->errorCode );
			$this->assertSame( [], $this->themeModWrites );
		}
	}

	public function test_restore_refuses_when_the_recorded_map_does_not_read_back(): void {
		$this->themeModPersists = false;

		try {
			$this->operation->restore(
				[
					'location'  => 'footer',
					'locations' => [ 'footer' => 12 ],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore that did not land must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}
	}

	public function test_restore_refuses_when_a_recorded_absence_reads_back_as_assigned(): void {
		$this->themeModPersists = false;
		$this->locations        = [ 'footer' => 12 ];

		try {
			$this->operation->restore(
				[
					'location'  => 'footer',
					'locations' => [],
				],
				$this->makeContext()
			);
			$this->fail( 'A recorded absence that reads back assigned must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}
	}

	/**
	 * The one pair of states a value comparison cannot separate, and therefore the
	 * only reason restore() tests presence at all: sameAssignment( null, null ) is
	 * true, so a value-only `??` compare would call this restored. It is not — the
	 * map the snapshot recorded held the key, and the map that came back does not.
	 */
	public function test_restore_refuses_when_a_recorded_null_entry_reads_back_absent(): void {
		$this->themeModPersists = false;
		$this->locations        = [];

		try {
			$this->operation->restore(
				[
					'location'  => 'footer',
					'locations' => [ 'footer' => null ],
				],
				$this->makeContext()
			);
			$this->fail( 'A recorded null entry that reads back absent must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}
	}

	/**
	 * The same pair the other way round. A recorded absence means the key must not
	 * exist afterwards, and a map that came back carrying it — even holding null —
	 * is not the map that was recorded.
	 */
	public function test_restore_refuses_when_a_recorded_absence_reads_back_holding_null(): void {
		$this->themeModPersists = false;
		$this->locations        = [ 'footer' => null ];

		try {
			$this->operation->restore(
				[
					'location'  => 'footer',
					'locations' => [],
				],
				$this->makeContext()
			);
			$this->fail( 'A recorded absence that reads back holding null must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}
	}

	public function test_read_back_projects_the_persisted_assignment(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$this->operation->applyChange( $current, $this->operation->planChange( $current, $input, $context ), $context );

		$state = $this->operation->readBack( 'menu-location:footer', $context );

		$this->assertInstanceOf( TargetState::class, $state );
		$this->assertTrue( $state->exists );
		$this->assertSame(
			[
				'location' => 'footer',
				'menuId'   => 12,
			],
			$state->fields
		);
	}

	public function test_the_resolved_state_projects_an_unassigned_location_as_a_null_menu(): void {
		$state = $this->operation->resolveTarget(
			[
				'location' => 'footer',
				'menu'     => null,
			],
			$this->makeContext()
		);

		$this->assertSame( 'menu-location:footer', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame(
			[
				'location' => 'footer',
				'menuId'   => null,
			],
			$state->fields
		);
	}

	public function test_a_stored_zero_projects_as_an_unassigned_location(): void {
		$this->locations = [ 'primary' => 0 ];

		$state = $this->operation->resolveTarget(
			[
				'location' => 'primary',
				'menu'     => null,
			],
			$this->makeContext()
		);

		$this->assertNull( $state->fields['menuId'] );
	}

	/**
	 * A filtered theme mod that is not a map must read as an EMPTY map, and the
	 * assertion is on what gets WRITTEN rather than on the projection, because a
	 * projection assertion cannot fail: `(array) 'not-a-map'` carries no
	 * 'primary' key either, so both the guard and the cast project a null menu.
	 * Only the write shows the difference — a cast would store the garbage
	 * member back over the site's real location table.
	 */
	public function test_a_non_array_location_map_reads_as_an_empty_map(): void {
		$this->themeModPersists = false;
		Functions\when( 'get_nav_menu_locations' )->justReturn( 'not-a-map' );

		$refusal = $this->planAndApply(
			[
				'location' => 'footer',
				'menu'     => 'main-menu',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( [ 'footer' => 12 ], $this->themeModWrites[0]['value'] );
	}

	public function test_restore_refuses_when_the_location_reads_back_holding_a_different_menu(): void {
		$this->themeModPersists = false;
		$this->locations        = [ 'footer' => 99 ];

		try {
			$this->operation->restore(
				[
					'location'  => 'footer',
					'locations' => [ 'footer' => 12 ],
				],
				$this->makeContext()
			);
			$this->fail( 'A restore that stored a different menu must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
		}
	}

	public function test_read_back_refuses_a_target_key_that_names_no_location(): void {
		try {
			$this->operation->readBack( 'menu-item:987654321', $this->makeContext() );
			$this->fail( 'A target key without the location prefix must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::VerificationFailed, $error->errorCode );
		}
	}

	/**
	 * The prefix alone is a target key TargetState will happily construct — it is
	 * non-empty — and it names no location, so it must be refused rather than
	 * projected as a location whose slug is the empty string.
	 */
	public function test_read_back_refuses_a_target_key_that_carries_the_prefix_alone(): void {
		try {
			$this->operation->readBack( 'menu-location:', $this->makeContext() );
			$this->fail( 'A target key carrying the prefix alone must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::VerificationFailed, $error->errorCode );
		}
	}

	public function test_the_snapshot_of_a_target_key_that_names_no_location_is_null(): void {
		$state = new TargetState( 'menu-item:987654321', true, [] );

		$this->assertNull( $this->operation->captureSnapshot( $state, $this->makeContext() ) );
	}

	public function test_apply_refuses_a_target_key_that_names_no_location(): void {
		$context = $this->makeContext();
		$input   = [
			'location' => 'footer',
			'menu'     => 'main-menu',
		];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		try {
			$this->operation->applyChange( new TargetState( 'menu-item:987654321', true, [] ), $planned, $context );
			$this->fail( 'A target key without the location prefix must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( [], $this->themeModWrites );
		}
	}
}
