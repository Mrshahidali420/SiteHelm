<?php
/**
 * Tests for ElementorKit, the shared site-settings accessor (REQ-0069..0071).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * The accessor every global-token operation resolves its target through.
 *
 * WHAT THIS FILE EXISTS TO PIN is the guard ORDER, not merely that each guard
 * exists. Capability comes before presence and presence before the option read,
 * and the ordering is a disclosure rule rather than a style preference: a caller
 * with no rights over site appearance must not learn from a refusal whether this
 * site runs Elementor. So the forbidden case below asserts `Forbidden` on a site
 * where Elementor is ABSENT — an implementation that checked presence first
 * would answer `IntegrationUnavailable` there and fail.
 *
 * THE SECOND THING PINNED is that `write()` disbelieves `update_post_meta()`.
 * That function answers false both for a failure and for a value that was
 * already what was asked for, so a write that trusted its return would refuse
 * successful no-op writes and accept failed real ones. The store below answers
 * false on EVERY call for exactly that reason, and the happy-path test still
 * requires the write to succeed.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The store reproduces three
 * WordPress behaviours and no others:
 *
 * 1. `get_post_meta( $id, $key, true )` answers `''` for an absent row — the
 *    sentinel that has bitten this codebase before — and the stored value
 *    otherwise, unserialized, which is what WordPress does for an array.
 * 2. `update_post_meta()` stores the value and answers false, as it does for an
 *    unchanged value.
 * 3. `get_option()` answers false for an option that is not set.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorKitTest extends TestCase {

	private ElementorKit $kit;

	/**
	 * Whether user_can( 'edit_theme_options' ) approves the caller.
	 */
	private bool $mayEditTheme = true;

	/**
	 * The site's option table, as far as these tests are concerned.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * The site's post meta, keyed post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->kit          = new ElementorKit( new ElementorPresence() );
		$this->mayEditTheme = true;
		$this->options      = [ ElementorKit::OPTION_ACTIVE => 42 ];
		$this->meta         = [];

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => ElementorKit::CAPABILITY === $capability && $this->mayEditTheme
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, mixed $default_value = false ): mixed => $this->options[ $name ] ?? $default_value
		);

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => $this->meta[ $post_id ][ $key ] ?? ''
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->meta[ $post_id ][ $key ] = $value;

				return false;
			}
		);

		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string|false => json_encode( $value )
		);
	}

	/**
	 * Makes Elementor look installed at the given version.
	 */
	private function installElementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( KitPluginStub::class, 'Elementor\Plugin' );
		}
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

	// ------------------------------------------------------------ guard order

	public function test_a_caller_without_the_theme_capability_is_refused_before_elementor_is_looked_for(): void {
		// Elementor is deliberately NOT installed here. A handler that asked about
		// presence first would answer IntegrationUnavailable and tell an
		// unauthorised caller what this site runs.
		$this->mayEditTheme = false;

		try {
			$this->kit->activeId( $this->makeContext() );
			$this->fail( 'A caller who may not edit theme options must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_an_authorised_caller_on_a_site_without_elementor_is_told_the_integration_is_absent(): void {
		try {
			$this->kit->activeId( $this->makeContext() );
			$this->fail( 'A site with no Elementor holds no global tokens.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_a_site_with_no_active_kit_is_a_refusal_rather_than_an_empty_palette(): void {
		$this->installElementor();
		$this->options = [];

		try {
			$this->kit->activeId( $this->makeContext() );
			$this->fail( 'No active kit must be refused, not reported as an empty palette.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_a_non_numeric_active_kit_option_names_no_kit(): void {
		$this->installElementor();
		$this->options = [ ElementorKit::OPTION_ACTIVE => 'not-a-post-id' ];

		try {
			$this->kit->activeId( $this->makeContext() );
			$this->fail( 'A junk option value must not resolve to a kit.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_the_active_kit_is_reported_when_every_guard_passes(): void {
		$this->installElementor();

		$this->assertSame( 42, $this->kit->activeId( $this->makeContext() ) );
	}

	public function test_no_refusal_names_the_capability_or_the_option_it_read(): void {
		$this->mayEditTheme = false;

		try {
			$this->kit->activeId( $this->makeContext() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$encoded = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( ElementorKit::OPTION_ACTIVE, $encoded );
			$this->assertStringNotContainsString( ElementorKit::CAPABILITY, $encoded );
		}
	}

	// ----------------------------------------------------------- stored settings

	public function test_a_kit_that_has_never_been_saved_reads_as_empty_settings(): void {
		$this->assertSame( [], $this->kit->settings( 42 ) );
	}

	public function test_settings_stored_in_a_form_elementor_never_saves_are_refused_rather_than_treated_as_empty(): void {
		// Treating this as [] would let a write replace a whole site's settings row
		// with one repeater list.
		$this->meta[42][ ElementorKit::META_SETTINGS ] = 'a:1:{s:1:"a";s:1:"b";}';

		try {
			$this->kit->settings( 42 );
			$this->fail( 'A malformed settings row must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	public function test_stored_settings_are_returned_exactly_as_stored(): void {
		$stored = [
			ElementorKit::KEY_SYSTEM_COLORS => [ [ '_id' => 'primary', 'color' => '#6EC1E4' ] ],
			'container_width'               => [ 'size' => 1140, 'unit' => 'px' ],
		];

		$this->meta[42][ ElementorKit::META_SETTINGS ] = $stored;

		$this->assertSame( $stored, $this->kit->settings( 42 ) );
	}

	// ------------------------------------------------------------ entry reading

	public function test_entries_without_a_usable_identifier_are_left_out_of_what_is_read(): void {
		$settings = [
			ElementorKit::KEY_CUSTOM_COLORS => [
				[ '_id' => 'brand', 'color' => '#123456' ],
				[ 'color' => '#654321' ],
				[ '_id' => '   ', 'color' => '#000000' ],
				[ '_id' => 99, 'color' => '#ffffff' ],
				'not-an-entry',
			],
		];

		$entries = $this->kit->entries( $settings, ElementorKit::KEY_CUSTOM_COLORS );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'brand', $entries[0]['_id'] );
	}

	public function test_a_repeater_key_the_row_does_not_hold_reads_as_no_entries(): void {
		$this->assertSame( [], $this->kit->entries( [], ElementorKit::KEY_SYSTEM_TYPOGRAPHY ) );
		$this->assertSame( [], $this->kit->entries( [ ElementorKit::KEY_SYSTEM_TYPOGRAPHY => 'junk' ], ElementorKit::KEY_SYSTEM_TYPOGRAPHY ) );
	}

	// -------------------------------------------------------------- target keys

	public function test_a_target_key_round_trips_through_the_kit_identifier(): void {
		$this->assertSame( 'elementor-kit:42', ElementorKit::targetKey( 42 ) );
		$this->assertSame( 42, ElementorKit::kitIdFromKey( 'elementor-kit:42' ) );
	}

	/**
	 * The zero case is the load-bearing one: `get_post_meta( 0, ... )` reads
	 * whatever `$post` happens to be global on some configurations, so a parser
	 * that answered 0 for a malformed key would read a stranger's meta.
	 *
	 * @dataProvider malformedTargetKeys
	 *
	 * @param string $key The malformed key.
	 */
	public function test_a_malformed_target_key_names_no_kit( string $key ): void {
		$this->assertNull( ElementorKit::kitIdFromKey( $key ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function malformedTargetKeys(): array {
		return [
			'no prefix'      => [ '42' ],
			'wrong prefix'   => [ 'elementor-document:42' ],
			'empty suffix'   => [ 'elementor-kit:' ],
			'zero'           => [ 'elementor-kit:0' ],
			'negative'       => [ 'elementor-kit:-1' ],
			'not digits'     => [ 'elementor-kit:42abc' ],
			'leading space'  => [ 'elementor-kit: 42' ],
			'float'          => [ 'elementor-kit:4.2' ],
		];
	}

	// ------------------------------------------------------------------ digests

	public function test_the_digest_describes_content_rather_than_key_order(): void {
		$one = $this->kit->digest( [ 'a' => 1, 'b' => 2 ] );
		$two = $this->kit->digest( [ 'b' => 2, 'a' => 1 ] );

		$this->assertSame( $one, $two );
	}

	public function test_the_digest_still_distinguishes_two_orderings_of_the_same_list(): void {
		// A repeater's order is what the editor displays, so two palettes holding
		// the same colours in different orders are genuinely different states.
		$one = $this->kit->digest( [ [ '_id' => 'a' ], [ '_id' => 'b' ] ] );
		$two = $this->kit->digest( [ [ '_id' => 'b' ], [ '_id' => 'a' ] ] );

		$this->assertNotSame( $one, $two );
	}

	public function test_the_digest_changes_when_a_stored_value_changes(): void {
		$this->assertNotSame(
			$this->kit->digest( [ 'color' => '#000000' ] ),
			$this->kit->digest( [ 'color' => '#000001' ] )
		);
	}

	// -------------------------------------------------------------------- writes

	public function test_a_write_replaces_only_the_named_keys_and_keeps_the_rest_of_the_row(): void {
		$this->meta[42][ ElementorKit::META_SETTINGS ] = [
			ElementorKit::KEY_SYSTEM_COLORS => [ [ '_id' => 'primary', 'color' => '#6EC1E4' ] ],
			'container_width'               => [ 'size' => 1140 ],
		];

		$this->kit->write(
			42,
			$this->kit->settings( 42 ),
			[ ElementorKit::KEY_SYSTEM_COLORS => [ [ '_id' => 'primary', 'color' => '#ff0000' ] ] ]
		);

		$stored = $this->meta[42][ ElementorKit::META_SETTINGS ];

		$this->assertSame( '#ff0000', $stored[ ElementorKit::KEY_SYSTEM_COLORS ][0]['color'] );
		$this->assertSame( [ 'size' => 1140 ], $stored['container_width'] );
	}

	/**
	 * The store answers false from update_post_meta() on every call, so a write
	 * that read that return instead of re-reading the row would refuse here.
	 */
	public function test_a_successful_write_is_not_refused_for_update_post_meta_answering_false(): void {
		$this->kit->write( 42, [], [ ElementorKit::KEY_CUSTOM_COLORS => [] ] );

		$this->assertSame( [ ElementorKit::KEY_CUSTOM_COLORS => [] ], $this->meta[42][ ElementorKit::META_SETTINGS ] );
	}

	public function test_a_write_that_did_not_land_is_refused(): void {
		Functions\when( 'update_post_meta' )->justReturn( false );

		try {
			$this->kit->write( 42, [], [ ElementorKit::KEY_CUSTOM_COLORS => [ [ '_id' => 'brand' ] ] ] );
			$this->fail( 'A write that did not land must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}
}

/**
 * Stands in for `Elementor\Plugin` so the presence gate finds a class.
 */
final class KitPluginStub {

	/**
	 * The singleton the presence gate reads.
	 *
	 * @var self|null
	 */
	public static $instance = null;
}
