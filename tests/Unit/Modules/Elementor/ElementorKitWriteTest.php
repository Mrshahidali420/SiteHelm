<?php
/**
 * Tests for the shared global-token write machinery (REQ-0070, REQ-0071).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorGlobalColorsUpdate;
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorKitWrite;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * The six phases of a global-token write, driven through a real operation.
 *
 * DRIVEN THROUGH `ElementorGlobalColorsUpdate` RATHER THAN CALLED DIRECTLY, so
 * the seam between the operation's input validation and the shared machinery is
 * covered rather than assumed. Two layers each correct in isolation and wired to
 * each other wrongly is a defect this codebase has shipped before.
 *
 * THE FOUR THINGS THIS FILE EXISTS TO PIN:
 *
 * 1. A named entry is MERGED, not replaced, and entries the request does not
 *    name are untouched. That is the requirement's acceptance evidence.
 * 2. An identifier the site does not hold refuses the WHOLE request. A
 *    partially-applied palette is the failure mode a two-phase write exists to
 *    prevent.
 * 3. The snapshot records PRESENCE separately from content, and the restore
 *    honours it — a kit that never had a custom palette must not come back from
 *    a rollback holding an empty one.
 * 4. Apply discards the kit's generated stylesheet. Without it the row is
 *    correct, every re-read agrees it is correct, and the site keeps serving the
 *    old brand colours.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The store answers `''` for an
 * absent meta row, stores what it is given, and returns false from
 * `update_post_meta()` on every call — the WordPress behaviours that have caught
 * this codebase before. Nothing else about WordPress is reproduced.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorKitWriteTest extends TestCase {

	private ElementorGlobalColorsUpdate $operation;

	/**
	 * The site's post meta, keyed post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	/**
	 * The meta keys delete_post_meta() was asked to remove, in order.
	 *
	 * @var string[]
	 */
	private array $deleted = [];

	protected function setUp(): void {
		parent::setUp();

		$presence = new ElementorPresence();
		$kit      = new ElementorKit( $presence );

		$this->operation = new ElementorGlobalColorsUpdate(
			new ElementorKitWrite( $kit, new ElementorCacheInvalidator( new ElementorApi( $presence ) ) )
		);

		$this->deleted            = [];
		$this->meta               = [];
		$this->meta[42][ ElementorKit::META_SETTINGS ] = $this->kitSettings();

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 42 );

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => $this->meta[ $post_id ][ $key ] ?? ''
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->meta[ $post_id ][ $key ] = $value;

				return false;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->deleted[] = $key;
				unset( $this->meta[ $post_id ][ $key ] );

				return true;
			}
		);

		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => '', 'error' => false ] );
		Functions\when( 'wp_delete_file' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string|false => json_encode( $value )
		);

		$this->installElementor();
	}

	/**
	 * Makes Elementor look installed.
	 */
	private function installElementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( KitWritePluginStub::class, 'Elementor\Plugin' );
		}
	}

	/**
	 * A kit holding a system palette, a custom palette and unrelated settings.
	 *
	 * @return array<string, mixed>
	 */
	private function kitSettings(): array {
		return [
			ElementorKit::KEY_SYSTEM_COLORS => [
				[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
				[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
			],
			ElementorKit::KEY_CUSTOM_COLORS => [
				[ '_id' => 'brand', 'title' => 'Brand', 'color' => '#123456' ],
			],
			'container_width'               => [ 'size' => 1140, 'unit' => 'px' ],
		];
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The stored settings as they now are.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return $this->meta[42][ ElementorKit::META_SETTINGS ];
	}

	/**
	 * Runs resolve → plan → apply for one request.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return string The written target key.
	 */
	private function apply( array $input ): string {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		return $this->operation->applyChange( $current, $planned, $context );
	}

	/**
	 * One well-formed request naming one entry.
	 *
	 * @param string               $id      The entry identifier.
	 * @param array<string, mixed> $members The members to set.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function request( string $id, array $members ): array {
		return [ ElementorGlobalColorsUpdate::INPUT_ENTRIES => [ [ 'id' => $id ] + $members ] ];
	}

	// -------------------------------------------------------------------- resolve

	public function test_the_resolved_target_names_the_active_kit_and_always_exists(): void {
		$current = $this->operation->resolveTarget( [], $this->makeContext() );

		$this->assertSame( 'elementor-kit:42', $current->targetKey );
		$this->assertTrue( $current->exists );
	}

	public function test_the_resolved_state_counts_only_addressable_entries_across_both_lists(): void {
		$this->meta[42][ ElementorKit::META_SETTINGS ][ ElementorKit::KEY_CUSTOM_COLORS ][] = [ 'color' => '#abcdef' ];

		$current = $this->operation->resolveTarget( [], $this->makeContext() );

		$this->assertSame( 3, $current->fields[ ElementorKitWrite::FIELD_COUNT ] );
	}

	// ----------------------------------------------------------------------- plan

	public function test_a_named_entry_is_merged_so_its_other_members_survive(): void {
		$this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) );

		$entry = $this->stored()[ ElementorKit::KEY_SYSTEM_COLORS ][0];

		$this->assertSame( '#ff0000', $entry['color'] );
		$this->assertSame( 'Primary', $entry['title'] );
		$this->assertSame( 'primary', $entry['_id'] );
	}

	public function test_entries_the_request_does_not_name_are_left_exactly_as_they_were(): void {
		$before = $this->kitSettings();

		$this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) );

		$this->assertSame( $before[ ElementorKit::KEY_SYSTEM_COLORS ][1], $this->stored()[ ElementorKit::KEY_SYSTEM_COLORS ][1] );
		$this->assertSame( $before[ ElementorKit::KEY_CUSTOM_COLORS ][0], $this->stored()[ ElementorKit::KEY_CUSTOM_COLORS ][0] );
	}

	public function test_settings_outside_the_two_palettes_are_left_exactly_as_they_were(): void {
		$this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) );

		$this->assertSame( [ 'size' => 1140, 'unit' => 'px' ], $this->stored()['container_width'] );
	}

	public function test_a_custom_entry_is_found_in_the_second_list(): void {
		$this->apply( $this->request( 'brand', [ 'title' => 'Renamed' ] ) );

		$this->assertSame( 'Renamed', $this->stored()[ ElementorKit::KEY_CUSTOM_COLORS ][0]['title'] );
		$this->assertSame( '#123456', $this->stored()[ ElementorKit::KEY_CUSTOM_COLORS ][0]['color'] );
	}

	public function test_an_identifier_the_site_does_not_hold_refuses_the_whole_request_and_changes_nothing(): void {
		$before = $this->stored();

		$input = [
			ElementorGlobalColorsUpdate::INPUT_ENTRIES => [
				[ 'id' => 'primary', 'color' => '#ff0000' ],
				[ 'id' => 'no-such-entry', 'color' => '#00ff00' ],
			],
		];

		try {
			$this->apply( $input );
			$this->fail( 'An unknown identifier must refuse the whole request.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( $before, $this->stored() );
	}

	public function test_the_preview_detail_names_the_changed_keys_and_never_their_values(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange( $current, $this->request( 'brand', [ 'color' => '#ABCDEF' ] ), $context );

		$this->assertSame(
			[
				'entries' => [
					[
						'id'          => 'brand',
						'list'        => ElementorKit::KEY_CUSTOM_COLORS,
						'changedKeys' => [ 'color' ],
					],
				],
			],
			$planned->previewDetail
		);

		$this->assertStringNotContainsString( '#ABCDEF', (string) json_encode( $planned->previewDetail ) );
	}

	public function test_the_promised_state_is_measured_by_the_same_formula_the_read_back_uses(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [], $context );
		$input   = $this->request( 'primary', [ 'color' => '#ff0000' ] );
		$planned = $this->operation->planChange( $current, $input, $context );

		$this->operation->applyChange( $current, $planned, $context );

		$this->assertSame(
			$planned->afterFields,
			$this->operation->readBack( $current->targetKey, $context )->fields
		);
	}

	public function test_the_promised_state_differs_from_the_state_before_the_change(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange( $current, $this->request( 'primary', [ 'color' => '#ff0000' ] ), $context );

		$this->assertNotSame(
			$current->fields[ ElementorKitWrite::FIELD_DIGEST ],
			$planned->afterFields[ ElementorKitWrite::FIELD_DIGEST ]
		);
	}

	/**
	 * A repeater with gaps in its keys — which a hand-edited row can have — must
	 * be re-indexed, or it encodes as a JSON object and Elementor reads it back
	 * as no palette at all.
	 */
	public function test_a_repeater_stored_with_gaps_is_written_back_as_a_list(): void {
		$this->meta[42][ ElementorKit::META_SETTINGS ][ ElementorKit::KEY_CUSTOM_COLORS ] = [
			3 => [ '_id' => 'brand', 'color' => '#123456' ],
		];

		$this->apply( $this->request( 'brand', [ 'color' => '#ff0000' ] ) );

		$this->assertSame( [ 0 ], array_keys( $this->stored()[ ElementorKit::KEY_CUSTOM_COLORS ] ) );
	}

	// ---------------------------------------------------------------- apply

	public function test_applying_discards_the_kits_generated_stylesheet(): void {
		$this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) );

		$this->assertContains( ElementorCacheInvalidator::META_CSS, $this->deleted );
	}

	public function test_applying_answers_the_target_key_of_the_kit_it_wrote(): void {
		$this->assertSame( 'elementor-kit:42', $this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) ) );
	}

	// -------------------------------------------------------- snapshot and restore

	public function test_the_snapshot_records_both_palettes_as_stored(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( 42, $snapshot[ ElementorKitWrite::SNAPSHOT_KIT ] );
		$this->assertSame(
			$this->kitSettings()[ ElementorKit::KEY_SYSTEM_COLORS ],
			$snapshot[ ElementorKitWrite::SNAPSHOT_LISTS ][ ElementorKit::KEY_SYSTEM_COLORS ]
		);
	}

	public function test_the_snapshot_is_side_effect_free_and_safe_to_take_twice(): void {
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [], $context );

		$this->assertSame(
			$this->operation->captureSnapshot( $current, $context ),
			$this->operation->captureSnapshot( $current, $context )
		);

		$this->assertSame( $this->kitSettings(), $this->stored() );
	}

	public function test_a_rollback_puts_every_recorded_entry_back(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->apply( $this->request( 'primary', [ 'color' => '#ff0000' ] ) );
		$this->operation->restore( $snapshot, $context );

		$this->assertSame( $this->kitSettings(), $this->stored() );
	}

	/**
	 * The load-bearing case for `array_key_exists`: a kit that has never held a
	 * custom palette stores no `custom_colors` key at all, and a restore that
	 * wrote `[]` back would leave the row in a state the site was never in.
	 */
	public function test_a_rollback_removes_a_key_that_was_absent_when_the_snapshot_was_taken(): void {
		$settings = $this->kitSettings();
		unset( $settings[ ElementorKit::KEY_CUSTOM_COLORS ] );
		$this->meta[42][ ElementorKit::META_SETTINGS ] = $settings;

		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		// A later write puts the key there.
		$this->meta[42][ ElementorKit::META_SETTINGS ][ ElementorKit::KEY_CUSTOM_COLORS ] = [ [ '_id' => 'brand' ] ];

		$this->operation->restore( $snapshot, $context );

		$this->assertArrayNotHasKey( ElementorKit::KEY_CUSTOM_COLORS, $this->stored() );
	}

	public function test_a_rollback_leaves_settings_outside_the_palettes_alone(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->meta[42][ ElementorKit::META_SETTINGS ]['container_width'] = [ 'size' => 1600 ];

		$this->operation->restore( $snapshot, $context );

		$this->assertSame( [ 'size' => 1600 ], $this->stored()['container_width'] );
	}

	public function test_a_rollback_discards_the_kits_generated_stylesheet_too(): void {
		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->deleted = [];
		$this->operation->restore( $snapshot, $context );

		$this->assertContains( ElementorCacheInvalidator::META_CSS, $this->deleted );
	}

	/**
	 * @dataProvider unusableRestoreStates
	 *
	 * @param array<string, mixed> $state The unusable recorded state.
	 */
	public function test_an_unusable_recorded_state_is_refused_as_a_rollback_rather_than_written( array $state ): void {
		try {
			$this->operation->restore( $state, $this->makeContext() );
			$this->fail( 'An unusable recorded state must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( $this->kitSettings(), $this->stored() );
	}

	/**
	 * @return array<string, array{0:array<string, mixed>}>
	 */
	public static function unusableRestoreStates(): array {
		return [
			'empty'          => [ [] ],
			'no kit'         => [ [ ElementorKitWrite::SNAPSHOT_LISTS => [], ElementorKitWrite::SNAPSHOT_PRESENT => [] ] ],
			'kit not an int' => [
				[
					ElementorKitWrite::SNAPSHOT_KIT     => '42',
					ElementorKitWrite::SNAPSHOT_LISTS   => [],
					ElementorKitWrite::SNAPSHOT_PRESENT => [],
				],
			],
			'no presence map' => [
				[
					ElementorKitWrite::SNAPSHOT_KIT   => 42,
					ElementorKitWrite::SNAPSHOT_LISTS => [],
				],
			],
		];
	}

	// ------------------------------------------------------------------ read back

	public function test_a_read_back_of_a_key_naming_no_kit_fails_verification(): void {
		try {
			$this->operation->readBack( 'elementor-kit:not-a-number', $this->makeContext() );
			$this->fail( 'A key naming no kit must fail verification.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}
}

/**
 * Stands in for `Elementor\Plugin` so the presence gate finds a class.
 */
final class KitWritePluginStub {

	/**
	 * The singleton the presence gate reads.
	 *
	 * @var self|null
	 */
	public static $instance = null;
}
