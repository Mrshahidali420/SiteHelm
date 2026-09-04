<?php
/**
 * Tests for ElementorContainerPreset: the shorthand, and what it refuses.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorContainerPreset;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0114: the full-bleed preset.
 *
 * THE CLASS IS PURE, AND THE TESTS ARE ABOUT VALUES RATHER THAN EFFECTS. What
 * makes the preset worth having is that the two settings it stores are the two
 * Elementor's kit would otherwise override invisibly, and that it refuses
 * rather than resolving an argument with the caller. Both are claims about
 * return values and refusals, so nothing here needs a document, a registry or a
 * WordPress function.
 */
final class ElementorContainerPresetTest extends TestCase {

	/**
	 * The preset stores BOTH settings a full-bleed section needs.
	 *
	 * Padding alone leaves the section running edge to edge inside a boxed
	 * column, which looks like the feature half-worked and is a thing the caller
	 * would have to notice for itself. The kit's two defaults are 10px on all
	 * four sides and `boxed`, so the preset answers both.
	 */
	public function test_the_preset_stores_zero_padding_and_full_content_width(): void {
		$settings = ElementorContainerPreset::settingsFor( ElementorContainerPreset::PRESET_FULL_BLEED );

		$this->assertSame(
			[ ElementorContainerPreset::KEY_PADDING, ElementorContainerPreset::KEY_CONTENT_WIDTH ],
			array_keys( $settings )
		);
		$this->assertSame(
			[
				'unit'     => 'px',
				'top'      => '0',
				'right'    => '0',
				'bottom'   => '0',
				'left'     => '0',
				'isLinked' => true,
			],
			$settings[ ElementorContainerPreset::KEY_PADDING ]
		);
		$this->assertSame( 'full', $settings[ ElementorContainerPreset::KEY_CONTENT_WIDTH ] );
	}

	/**
	 * A name that is not a preset contributes nothing rather than half a shape.
	 * `requested()` is the gate that admits a name; this is the belt on it.
	 */
	public function test_an_unknown_name_contributes_no_settings(): void {
		$this->assertSame( [], ElementorContainerPreset::settingsFor( 'edge-to-edge' ) );
	}

	/**
	 * The schema admits exactly the presets that exist, plus null.
	 *
	 * A schema listing a name the class does not implement would be accepted at
	 * the gateway and then refused by `requested()`, which reads to a caller as
	 * the server disagreeing with itself.
	 */
	public function test_the_schema_admits_only_the_presets_that_exist(): void {
		$schema = ElementorContainerPreset::schema();

		$this->assertSame( [ 'string', 'null' ], $schema['type'] );
		$this->assertSame( [ ElementorContainerPreset::PRESET_FULL_BLEED, null ], $schema['enum'] );
		$this->assertStringContainsString( 'container', $schema['description'] );
	}

	// ------------------------------------------------------ what was asked for

	/**
	 * No preset member, or an empty one, is not an error: the member is optional
	 * and every existing caller omits it.
	 */
	public function test_an_absent_or_empty_preset_is_simply_no_preset(): void {
		$this->assertNull(
			ElementorContainerPreset::requested( [], ElementorElementAddInput::EL_TYPE_CONTAINER )
		);
		$this->assertNull(
			ElementorContainerPreset::requested(
				[ ElementorContainerPreset::INPUT_PRESET => null ],
				ElementorElementAddInput::EL_TYPE_CONTAINER
			)
		);
		$this->assertNull(
			ElementorContainerPreset::requested(
				[ ElementorContainerPreset::INPUT_PRESET => '' ],
				ElementorElementAddInput::EL_TYPE_CONTAINER
			)
		);
	}

	/**
	 * The one preset there is, asked for on the one element it fits.
	 */
	public function test_the_full_bleed_preset_is_accepted_on_a_container(): void {
		$this->assertSame(
			ElementorContainerPreset::PRESET_FULL_BLEED,
			ElementorContainerPreset::requested(
				[ ElementorContainerPreset::INPUT_PRESET => ElementorContainerPreset::PRESET_FULL_BLEED ],
				ElementorElementAddInput::EL_TYPE_CONTAINER
			)
		);
	}

	/**
	 * A name nobody implements is refused rather than ignored.
	 *
	 * Ignoring it would store a container carrying the kit's own defaults while
	 * the caller believed it had asked for something else, which is precisely the
	 * defect class this preset exists to close.
	 */
	public function test_a_preset_this_operation_does_not_offer_is_refused(): void {
		$this->expectRefusal(
			[ ElementorContainerPreset::INPUT_PRESET => 'edge-to-edge' ],
			ElementorElementAddInput::EL_TYPE_CONTAINER
		);
	}

	/**
	 * A non-string preset is refused on the same footing as an unknown name.
	 */
	public function test_a_preset_that_is_not_a_name_is_refused(): void {
		$this->expectRefusal(
			[ ElementorContainerPreset::INPUT_PRESET => [ 'full-bleed' ] ],
			ElementorElementAddInput::EL_TYPE_CONTAINER
		);
	}

	/**
	 * The preset writes CONTAINER settings, so it is refused on anything else.
	 *
	 * Section and column are Elementor's older layout vocabulary with their own
	 * width controls, and a widget has no layout of its own; storing
	 * `content_width` on any of the three writes a key the element never
	 * declared.
	 */
	public function test_a_preset_asked_for_on_something_other_than_a_container_is_refused(): void {
		foreach ( [ 'section', 'column', 'widget' ] as $el_type ) {
			$this->expectRefusal(
				[ ElementorContainerPreset::INPUT_PRESET => ElementorContainerPreset::PRESET_FULL_BLEED ],
				$el_type
			);
		}
	}

	// ---------------------------------------------------------- folding it in

	/**
	 * Without a preset the caller's settings pass through untouched — the path
	 * every existing caller takes.
	 */
	public function test_no_preset_leaves_the_settings_exactly_as_they_were(): void {
		$settings = [ 'flex_gap' => [ 'size' => 20 ] ];

		$this->assertSame( $settings, ElementorContainerPreset::apply( null, $settings ) );
	}

	/**
	 * The preset's settings join the caller's rather than replacing them.
	 */
	public function test_the_preset_is_added_to_the_settings_the_caller_sent(): void {
		$applied = ElementorContainerPreset::apply(
			ElementorContainerPreset::PRESET_FULL_BLEED,
			[ 'flex_gap' => [ 'size' => 20 ] ]
		);

		$this->assertSame( [ 'size' => 20 ], $applied['flex_gap'] );
		$this->assertSame( 'full', $applied[ ElementorContainerPreset::KEY_CONTENT_WIDTH ] );
		$this->assertArrayHasKey( ElementorContainerPreset::KEY_PADDING, $applied );
	}

	/**
	 * A SETTING THE PRESET ALSO WRITES IS A REFUSAL, NOT A PRECEDENCE RULE.
	 *
	 * Either resolution stores an element that does not match what the caller
	 * believes it asked for: the preset winning discards an explicit value, the
	 * caller winning produces a container named full-bleed that is not. The
	 * refusal names the setting, so the caller knows which half to drop.
	 */
	public function test_a_setting_the_preset_also_writes_is_refused_by_name(): void {
		foreach ( [ ElementorContainerPreset::KEY_PADDING, ElementorContainerPreset::KEY_CONTENT_WIDTH ] as $key ) {
			try {
				ElementorContainerPreset::apply(
					ElementorContainerPreset::PRESET_FULL_BLEED,
					[ $key => 'whatever this element declares' ]
				);
				$this->fail( 'A collision between the preset and an explicit setting must be refused.' );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
				$this->assertStringContainsString( $key, $exception->getMessage() );
			}
		}
	}

	/**
	 * Asserts one preset request is refused as invalid input.
	 *
	 * @param array<string, mixed> $input   The arguments.
	 * @param string               $el_type The requested element kind.
	 */
	private function expectRefusal( array $input, string $el_type ): void {
		try {
			ElementorContainerPreset::requested( $input, $el_type );
			$this->fail( 'The preset request must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}
}
