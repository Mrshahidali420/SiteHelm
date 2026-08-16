<?php
/**
 * Input validation for the two global-token writes (REQ-0070, REQ-0071).
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
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorGlobalColorsUpdate;
use SiteHelm\Modules\Elementor\ElementorGlobalTypographyUpdate;
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorKitWrite;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * What the two global-token writes will and will not accept.
 *
 * THE VALUE RULES ARE THE POINT. Every setting these two operations store is
 * compiled into a CSS declaration on every page of the site, so the guards
 * below are not tidiness: a colour that is not a colour, a key that is not a
 * typography key, or an unbounded string is a broken stylesheet everywhere at
 * once. `SchemaValidator` cannot express any of them — it checks types and
 * required members — which is why they are in the handler and tested here.
 *
 * EVERY REFUSAL ASSERTS THE SPECIFIC ERROR CODE. A bare expectException on
 * OperationException would pass against a handler that refused everything for
 * the wrong reason.
 *
 * NO REFUSAL MAY ECHO A SUBMITTED VALUE, and the last test in each group proves
 * it against the message and the remediation together rather than trusting the
 * literals to stay literal.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorGlobalTokenInputTest extends TestCase {

	private ElementorGlobalColorsUpdate $colors;

	private ElementorGlobalTypographyUpdate $typography;

	/**
	 * The stored kit settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	protected function setUp(): void {
		parent::setUp();

		$presence = new ElementorPresence();
		$writes   = new ElementorKitWrite(
			new ElementorKit( $presence ),
			new ElementorCacheInvalidator( new ElementorApi( $presence ) )
		);

		$this->colors     = new ElementorGlobalColorsUpdate( $writes );
		$this->typography = new ElementorGlobalTypographyUpdate( $writes );

		$this->settings = [
			ElementorKit::KEY_SYSTEM_COLORS     => [ [ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ] ],
			ElementorKit::KEY_SYSTEM_TYPOGRAPHY => [ [ '_id' => 'primary', 'title' => 'Primary' ] ],
		];

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 42 );
		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => ElementorKit::META_SETTINGS === $key ? $this->settings : ''
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string|false => json_encode( $value )
		);

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( TokenInputPluginStub::class, 'Elementor\Plugin' );
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

	/**
	 * Plans a colour change, or throws.
	 *
	 * @param array<int, mixed> $entries The submitted entries.
	 *
	 * @return array<string, mixed> The planned payload.
	 */
	private function planColors( array $entries ): array {
		$context = $this->makeContext();
		$input   = [ ElementorGlobalColorsUpdate::INPUT_ENTRIES => $entries ];

		return $this->colors->planChange( $this->colors->resolveTarget( $input, $context ), $input, $context )->payload;
	}

	/**
	 * Plans a typography change, or throws.
	 *
	 * @param array<int, mixed> $entries The submitted entries.
	 *
	 * @return array<string, mixed> The planned payload.
	 */
	private function planTypography( array $entries ): array {
		$context = $this->makeContext();
		$input   = [ ElementorGlobalTypographyUpdate::INPUT_ENTRIES => $entries ];

		return $this->typography->planChange( $this->typography->resolveTarget( $input, $context ), $input, $context )->payload;
	}

	/**
	 * Asserts that planning the given colour entries is refused as invalid input.
	 *
	 * @param array<int, mixed> $entries The submitted entries.
	 */
	private function assertColorsRefused( array $entries ): OperationException {
		try {
			$this->planColors( $entries );
			$this->fail( 'Expected the colour change to be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );

			return $e;
		}
	}

	/**
	 * Asserts that planning the given typography entries is refused as invalid input.
	 *
	 * @param array<int, mixed> $entries The submitted entries.
	 */
	private function assertTypographyRefused( array $entries ): OperationException {
		try {
			$this->planTypography( $entries );
			$this->fail( 'Expected the typography change to be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );

			return $e;
		}
	}

	// ---------------------------------------------------------- the definitions

	/**
	 * @dataProvider bothWrites
	 *
	 * @param string $class The operation class.
	 * @param string $id    Its registered identifier.
	 */
	public function test_each_write_declares_the_write_shape_and_the_site_settings_capability( string $class, string $id ): void {
		$definition = $class::definition();

		$this->assertSame( $id, $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( [ ElementorKit::CAPABILITY ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertFalse( $definition->inputSchema['properties']['entries']['items']['additionalProperties'] );
		$this->assertSame( ElementorKit::MAX_ENTRIES, $definition->inputSchema['properties']['entries']['maxItems'] );
		$this->assertSame( ElementorKitWrite::FIELD_ORDER, $definition->outputSchema['required'] );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function bothWrites(): array {
		return [
			'colors'     => [ ElementorGlobalColorsUpdate::class, 'elementor-global-colors-update' ],
			'typography' => [ ElementorGlobalTypographyUpdate::class, 'elementor-global-typography-update' ],
		];
	}

	// ------------------------------------------------------------- shared refusals

	public function test_a_change_naming_no_entries_is_refused_by_both_writes(): void {
		$this->assertColorsRefused( [] );
		$this->assertTypographyRefused( [] );
	}

	public function test_the_same_identifier_named_twice_is_refused_rather_than_resolved_last_one_wins(): void {
		$this->assertColorsRefused(
			[
				[ 'id' => 'primary', 'color' => '#111111' ],
				[ 'id' => 'primary', 'color' => '#222222' ],
			]
		);

		$this->assertTypographyRefused(
			[
				[ 'id' => 'primary', 'title' => 'One' ],
				[ 'id' => 'primary', 'title' => 'Two' ],
			]
		);
	}

	public function test_an_entry_setting_nothing_is_refused_rather_than_planned_as_a_change_that_changes_nothing(): void {
		$this->assertColorsRefused( [ [ 'id' => 'primary' ] ] );
		$this->assertTypographyRefused( [ [ 'id' => 'primary' ] ] );
	}

	public function test_an_entry_that_is_not_an_object_is_refused(): void {
		$this->assertColorsRefused( [ 'primary' ] );
		$this->assertTypographyRefused( [ 'primary' ] );
	}

	public function test_an_identifier_outside_the_declared_pattern_is_refused(): void {
		$this->assertColorsRefused( [ [ 'id' => 'has space', 'color' => '#111111' ] ] );
		$this->assertColorsRefused( [ [ 'id' => '', 'color' => '#111111' ] ] );
		$this->assertColorsRefused( [ [ 'id' => 7, 'color' => '#111111' ] ] );
		$this->assertTypographyRefused( [ [ 'id' => str_repeat( 'a', 65 ), 'title' => 'x' ] ] );
	}

	// ------------------------------------------------------------------- colours

	/**
	 * @dataProvider acceptedColors
	 *
	 * @param string $color The submitted colour.
	 */
	public function test_a_colour_form_elementor_stores_is_accepted( string $color ): void {
		$payload = $this->planColors( [ [ 'id' => 'primary', 'color' => $color ] ] );

		$this->assertSame(
			$color,
			$payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_COLORS ][0]['color']
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function acceptedColors(): array {
		return [
			'six-digit hex'   => [ '#1A73E8' ],
			'three-digit hex' => [ '#abc' ],
			'eight-digit hex' => [ '#1A73E8FF' ],
			'rgb'             => [ 'rgb(26, 115, 232)' ],
			'rgba'            => [ 'rgba(26, 115, 232, 0.5)' ],
			// An empty string is how an operator clears an entry's colour.
			'cleared'         => [ '' ],
		];
	}

	/**
	 * @dataProvider rejectedColors
	 *
	 * @param mixed $color The submitted colour.
	 */
	public function test_a_value_that_is_not_a_colour_is_refused( mixed $color ): void {
		$this->assertColorsRefused( [ [ 'id' => 'primary', 'color' => $color ] ] );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function rejectedColors(): array {
		return [
			'named colour'      => [ 'red' ],
			'no hash'           => [ '1A73E8' ],
			'five-digit hex'    => [ '#12345' ],
			'not hex digits'    => [ '#GGGGGG' ],
			'css function'      => [ 'var(--e-global-color-primary)' ],
			'injection attempt' => [ '#fff; background: url(https://example.com/x)' ],
			'not a string'      => [ 16711680 ],
			'null'              => [ null ],
			'array'             => [ [ '#ffffff' ] ],
			'over the length'   => [ '#' . str_repeat( 'a', 64 ) ],
		];
	}

	public function test_a_colour_refusal_never_echoes_the_submitted_value(): void {
		$needle = '#GGGGGG';
		$e      = $this->assertColorsRefused( [ [ 'id' => 'primary', 'color' => $needle ] ] );

		$this->assertStringNotContainsString( $needle, $e->getMessage() . ' ' . (string) $e->remediation );
	}

	public function test_a_colour_entry_may_set_its_title_alone(): void {
		$payload = $this->planColors( [ [ 'id' => 'primary', 'title' => 'Brand blue' ] ] );
		$entry   = $payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_COLORS ][0];

		$this->assertSame( 'Brand blue', $entry['title'] );
		$this->assertSame( '#6EC1E4', $entry['color'] );
	}

	public function test_a_title_longer_than_the_declared_maximum_is_refused(): void {
		$this->assertColorsRefused(
			[ [ 'id' => 'primary', 'title' => str_repeat( 'a', ElementorGlobalColorsUpdate::TITLE_MAX_LENGTH + 1 ) ] ]
		);
	}

	// ---------------------------------------------------------------- typography

	public function test_a_typography_setting_is_stored_under_the_key_it_was_sent_with(): void {
		$payload = $this->planTypography(
			[
				[
					'id'       => 'primary',
					'settings' => [ 'typography_font_family' => 'Inter', 'typography_font_weight' => '600' ],
				],
			]
		);

		$entry = $payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_TYPOGRAPHY ][0];

		$this->assertSame( 'Inter', $entry['typography_font_family'] );
		$this->assertSame( '600', $entry['typography_font_weight'] );
	}

	/**
	 * The nested form is what Elementor's size controls store, and it is the ONLY
	 * nesting accepted.
	 */
	public function test_the_nested_size_form_elementor_stores_is_accepted(): void {
		$payload = $this->planTypography(
			[
				[
					'id'       => 'primary',
					'settings' => [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 16, 'sizes' => [] ] ],
				],
			]
		);

		$entry = $payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_TYPOGRAPHY ][0];

		$this->assertSame( [ 'unit' => 'px', 'size' => 16, 'sizes' => [] ], $entry['typography_font_size'] );
	}

	/**
	 * @dataProvider rejectedSettingKeys
	 *
	 * @param mixed $key The submitted settings key.
	 */
	public function test_a_key_that_is_not_a_typography_setting_is_refused( mixed $key ): void {
		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => [ $key => 'x' ] ] ] );
	}

	/**
	 * The `_id` case is the load-bearing one: without the prefix rule a request
	 * could re-point an entry every page in the site references by name.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public static function rejectedSettingKeys(): array {
		return [
			'the identifier'   => [ '_id' ],
			'the title'        => [ 'title' ],
			'no prefix'        => [ 'font_family' ],
			'wrong prefix'     => [ 'typographyfont' ],
			'upper case'       => [ 'typography_FONT' ],
			'a list index'     => [ 0 ],
			'over the length'  => [ 'typography_' . str_repeat( 'a', 49 ) ],
		];
	}

	/**
	 * @dataProvider rejectedSettingValues
	 *
	 * @param mixed $value The submitted value.
	 */
	public function test_a_setting_value_in_a_form_this_site_will_not_store_is_refused( mixed $value ): void {
		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => [ 'typography_font_family' => $value ] ] ] );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function rejectedSettingValues(): array {
		return [
			'a bare list'          => [ [ 'a', 'b' ] ],
			'two levels of nesting' => [ [ 'unit' => [ 'size' => [ 'deeper' => 1 ] ] ] ],
			'a nested key that is not one' => [ [ 'UNIT!' => 'px' ] ],
			'an object of objects' => [ [ 'sizes' => [ [ 'size' => 1 ] ] ] ],
			'an over-long string'  => [ str_repeat( 'a', ElementorGlobalTypographyUpdate::VALUE_MAX_LENGTH + 1 ) ],
		];
	}

	public function test_null_clears_a_setting_rather_than_being_refused(): void {
		$payload = $this->planTypography(
			[ [ 'id' => 'primary', 'settings' => [ 'typography_font_family' => null ] ] ]
		);

		$entry = $payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_TYPOGRAPHY ][0];

		$this->assertArrayHasKey( 'typography_font_family', $entry );
		$this->assertNull( $entry['typography_font_family'] );
	}

	public function test_a_settings_object_that_is_empty_or_a_list_is_refused(): void {
		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => [] ] ] );
		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => [ 'a', 'b' ] ] ] );
		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => 'typography_font_family' ] ] );
	}

	public function test_more_settings_than_one_request_accepts_are_refused(): void {
		$settings = [];

		for ( $i = 0; $i <= ElementorGlobalTypographyUpdate::MAX_SETTINGS; $i++ ) {
			$settings[ 'typography_key_' . $i ] = 'x';
		}

		$this->assertTypographyRefused( [ [ 'id' => 'primary', 'settings' => $settings ] ] );
	}

	public function test_a_typography_refusal_never_echoes_the_submitted_key_or_value(): void {
		$e = $this->assertTypographyRefused(
			[ [ 'id' => 'primary', 'settings' => [ 'not_a_typography_key' => 'secret-value' ] ] ]
		);

		$encoded = $e->getMessage() . ' ' . (string) $e->remediation;

		$this->assertStringNotContainsString( 'not_a_typography_key', $encoded );
		$this->assertStringNotContainsString( 'secret-value', $encoded );
	}

	public function test_a_typography_entry_may_set_its_title_alone(): void {
		$payload = $this->planTypography( [ [ 'id' => 'primary', 'title' => 'Headings' ] ] );

		$this->assertSame(
			'Headings',
			$payload[ ElementorKitWrite::PAYLOAD_LISTS ][ ElementorKit::KEY_SYSTEM_TYPOGRAPHY ][0]['title']
		);
	}
}

/**
 * Stands in for `Elementor\Plugin` so the presence gate finds a class.
 */
final class TokenInputPluginStub {

	/**
	 * The singleton the presence gate reads.
	 *
	 * @var self|null
	 */
	public static $instance = null;
}
