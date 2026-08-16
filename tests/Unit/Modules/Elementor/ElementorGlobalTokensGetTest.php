<?php
/**
 * Tests for ElementorGlobalTokensGet (REQ-0069).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorGlobalTokensGet;
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0069: the palette and the type styles, with the handles a write uses.
 *
 * THE ACCEPTANCE EVIDENCE IS THE IDENTIFIER, so the central test here is not
 * that colours come back but that every entry carries the `_id` a later write
 * addresses it by — and that the identifier is Elementor's stored `_id` rather
 * than the list position, which moves the moment a custom colour is added.
 *
 * THE SECOND THING PINNED is the absent-vs-empty rule on `color`. An entry that
 * stores no colour must OMIT the member rather than report null, because a
 * client that round-tripped a null back through the colour write would set an
 * empty colour, which is a different stored state.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorGlobalTokensGetTest extends TestCase {

	private ElementorGlobalTokensGet $handler;

	/**
	 * Whether user_can( 'edit_theme_options' ) approves the caller.
	 */
	private bool $mayEditTheme = true;

	/**
	 * The stored kit settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	protected function setUp(): void {
		parent::setUp();

		$presence      = new ElementorPresence();
		$this->handler = new ElementorGlobalTokensGet( new ElementorKit( $presence ), $presence );

		$this->mayEditTheme = true;
		$this->settings     = $this->kitSettings();

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => ElementorKit::CAPABILITY === $capability && $this->mayEditTheme
		);

		Functions\when( 'get_option' )->justReturn( 42 );

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => 42 === $post_id && ElementorKit::META_SETTINGS === $key
				? $this->settings
				: ''
		);
	}

	/**
	 * A kit holding both system and custom entries of both kinds.
	 *
	 * The values are Elementor 4.2.0's own system defaults, so the shape the
	 * projection is asserted against is the shape a real site stores.
	 *
	 * @return array<string, mixed>
	 */
	private function kitSettings(): array {
		return [
			ElementorKit::KEY_SYSTEM_COLORS     => [
				[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
				[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
			],
			ElementorKit::KEY_CUSTOM_COLORS     => [
				[ '_id' => 'brand', 'title' => 'Brand', 'color' => '#123456' ],
				// Stores no colour at all: the member must be ABSENT, not null.
				[ '_id' => 'unset', 'title' => 'Unset' ],
			],
			ElementorKit::KEY_SYSTEM_TYPOGRAPHY => [
				[
					'_id'                    => 'primary',
					'title'                  => 'Primary',
					'typography_typography'  => 'custom',
					'typography_font_family' => 'Roboto',
					'typography_font_weight' => '600',
				],
			],
			ElementorKit::KEY_CUSTOM_TYPOGRAPHY => [
				[ '_id' => 'quote', 'title' => 'Quote', 'typography_font_style' => 'italic' ],
			],
			'container_width'                   => [ 'size' => 1140, 'unit' => 'px' ],
		];
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Makes Elementor look installed.
	 */
	private function installElementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( TokensGetPluginStub::class, 'Elementor\Plugin' );
		}
	}

	/**
	 * Runs the operation on a site with Elementor installed.
	 *
	 * @return array<string, mixed> The response payload.
	 */
	private function read(): array {
		$this->installElementor();

		return $this->handler->handle( [], $this->makeContext() );
	}

	// ------------------------------------------------------------- the definition

	public function test_the_definition_declares_the_read_shape_and_the_site_settings_capability(): void {
		$definition = ElementorGlobalTokensGet::definition();

		$this->assertSame( 'elementor-global-tokens-get', $definition->id );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ ElementorKit::CAPABILITY ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertSame( [], $definition->inputSchema['properties'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	// ------------------------------------------------------------------- refusals

	public function test_a_caller_without_the_theme_capability_is_refused(): void {
		$this->mayEditTheme = false;

		try {
			$this->handler->handle( [], $this->makeContext() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_a_site_without_elementor_is_told_the_integration_is_absent(): void {
		try {
			$this->handler->handle( [], $this->makeContext() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	// ------------------------------------------------------------------ the answer

	public function test_every_colour_carries_the_identifier_a_write_addresses_it_by(): void {
		$colors = $this->read()['colors'];

		$this->assertSame(
			[ 'primary', 'secondary', 'brand', 'unset' ],
			array_column( $colors, 'id' )
		);
	}

	public function test_the_system_palette_is_reported_before_the_custom_one_and_each_says_which_it_is(): void {
		$colors = $this->read()['colors'];

		$this->assertSame( [ 'system', 'system', 'custom', 'custom' ], array_column( $colors, 'scope' ) );
	}

	public function test_a_stored_colour_is_reported_exactly_as_stored(): void {
		$colors = $this->read()['colors'];

		$this->assertSame( '#6EC1E4', $colors[0]['color'] );
		$this->assertSame( 'Primary', $colors[0]['title'] );
	}

	public function test_an_entry_storing_no_colour_omits_the_member_rather_than_reporting_null(): void {
		$colors = $this->read()['colors'];

		$this->assertSame( 'unset', $colors[3]['id'] );
		$this->assertArrayNotHasKey( 'color', $colors[3] );
	}

	public function test_the_colour_count_matches_what_was_reported(): void {
		$answer = $this->read();

		$this->assertSame( count( $answer['colors'] ), $answer['colorCount'] );
		$this->assertSame( 4, $answer['colorCount'] );
	}

	public function test_a_type_style_reports_every_stored_typography_member_rather_than_a_fixed_field_list(): void {
		$typography = $this->read()['typography'];

		$this->assertSame(
			[
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'typography_font_weight' => '600',
			],
			$typography[0]['settings']
		);
	}

	public function test_a_type_style_settings_map_excludes_the_identifier_and_the_title(): void {
		$settings = $this->read()['typography'][0]['settings'];

		$this->assertArrayNotHasKey( '_id', $settings );
		$this->assertArrayNotHasKey( 'title', $settings );
	}

	public function test_the_typography_count_matches_what_was_reported(): void {
		$answer = $this->read();

		$this->assertSame( count( $answer['typography'] ), $answer['typographyCount'] );
		$this->assertSame( 2, $answer['typographyCount'] );
	}

	public function test_the_answer_names_the_kit_it_was_read_from_and_the_version_that_answered(): void {
		$answer = $this->read();

		$this->assertSame( 42, $answer['kitId'] );
		$this->assertSame( '3.25.0', $answer['elementorVersion'] );
	}

	public function test_the_answer_declares_every_member_its_output_schema_requires_and_no_others(): void {
		$answer = $this->read();

		$this->assertSame(
			ElementorGlobalTokensGet::definition()->outputSchema['required'],
			array_keys( $answer )
		);
	}

	/**
	 * A kit that has never had its settings saved is a normal state, not a
	 * refusal — an operator whose site is freshly built must be told the palette
	 * is empty rather than that something is wrong.
	 */
	public function test_a_kit_with_no_stored_settings_answers_an_empty_palette(): void {
		$this->settings = [];

		$answer = $this->read();

		$this->assertSame( 0, $answer['colorCount'] );
		$this->assertSame( [], $answer['colors'] );
		$this->assertSame( 0, $answer['typographyCount'] );
		$this->assertSame( [], $answer['typography'] );
	}

	/**
	 * Entries with no usable identifier cannot be addressed by any write, so
	 * reporting them would hand a client a handle that refuses.
	 */
	public function test_an_entry_with_no_usable_identifier_is_not_reported(): void {
		$this->settings[ ElementorKit::KEY_CUSTOM_COLORS ][] = [ 'color' => '#abcdef' ];

		$this->assertSame( 4, $this->read()['colorCount'] );
	}

	public function test_an_entry_with_a_non_string_title_still_reports_a_string(): void {
		$this->settings[ ElementorKit::KEY_CUSTOM_COLORS ][0]['title'] = [ 'unexpected' ];

		$colors = $this->read()['colors'];

		$this->assertSame( '', $colors[2]['title'] );
	}
}

/**
 * Stands in for `Elementor\Plugin` so the presence gate finds a class.
 */
final class TokensGetPluginStub {

	/**
	 * The singleton the presence gate reads.
	 *
	 * @var self|null
	 */
	public static $instance = null;
}
