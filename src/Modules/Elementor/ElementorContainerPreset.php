<?php
/**
 * The container settings a full-bleed section actually needs.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0114: one named preset for the section shape everybody asks for and
 * almost nobody gets right on the first attempt.
 *
 * THE DEFECT IS A WRITE THAT REPORTS SUCCESS WHILE THE PAGE RENDERS WRONG.
 * Elementor's kit applies 10px of padding on all four sides of every
 * container, and a container's `content_width` defaults to `boxed`. Neither
 * value is stored on the element, so a caller adding a container and reading
 * it back sees exactly what it sent and nothing about either default. The
 * section then renders inset inside a boxed column, the write says it
 * succeeded, and the only thing that disagrees is the page.
 *
 * `ElementorDocumentHints` already reports this after the fact and
 * `ServerInstructions` already warns about it in general. Both are advice a
 * caller has to act on with a second write it has to compose itself. This is
 * the same knowledge as a value it can send: `preset: "full-bleed"` writes the
 * two settings that make the section run edge to edge.
 *
 * THE PRESET IS A SHORTHAND, NEVER AN OVERRIDE. A caller that sends `padding`
 * or `content_width` of its own alongside the preset is REFUSED rather than
 * quietly overruled in either direction — the whole point of the feature is
 * that what was asked for and what was stored agree, and a preset that
 * silently won an argument with an explicit setting would be the same class of
 * defect wearing a nicer name. The refusal names the setting that collided.
 *
 * It is offered on `elementor-element-add` only, and only for a container.
 * Section and column are Elementor's older layout vocabulary with their own
 * width controls, so a preset written for container settings would store keys
 * they do not declare; a widget has no layout of its own at all.
 */
final class ElementorContainerPreset {

	/**
	 * The input member naming a preset.
	 */
	public const INPUT_PRESET = 'preset';

	/**
	 * The one preset this operation offers.
	 */
	public const PRESET_FULL_BLEED = 'full-bleed';

	/**
	 * Every preset name the schema admits.
	 *
	 * @var string[]
	 */
	public const PRESETS = [ self::PRESET_FULL_BLEED ];

	/**
	 * The container setting that carries the four paddings.
	 */
	public const KEY_PADDING = 'padding';

	/**
	 * The container setting that decides whether the content is boxed.
	 */
	public const KEY_CONTENT_WIDTH = 'content_width';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users; the only caller value quoted is a setting name this class owns.

	/**
	 * The input property fragment, for the operation definition.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function schema(): array {
		return [
			'type'        => [ 'string', 'null' ],
			'enum'        => array_merge( self::PRESETS, [ null ] ),
			'description' => 'Optional, and only for elType "container". "full-bleed" stores zero padding and full content width, the two settings a section needs to run edge to edge — Elementor\'s kit otherwise insets every container by 10px and boxes its content, neither of which is visible in what the write reports back. Do not send padding or content_width alongside it.',
		];
	}

	/**
	 * The settings a preset contributes.
	 *
	 * @param string $preset The preset name.
	 *
	 * @return array<string, mixed> The settings, keyed by setting name.
	 */
	public static function settingsFor( string $preset ): array {
		if ( self::PRESET_FULL_BLEED !== $preset ) {
			return [];
		}

		return [
			self::KEY_PADDING       => [
				'unit'     => 'px',
				'top'      => '0',
				'right'    => '0',
				'bottom'   => '0',
				'left'     => '0',
				'isLinked' => true,
			],
			self::KEY_CONTENT_WIDTH => 'full',
		];
	}

	/**
	 * The requested preset, checked against the element it was asked for.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param string               $el_type The requested element kind.
	 *
	 * @return string|null The preset name, or null when none was asked for.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the preset is
	 *                            not one this operation offers, or was asked for
	 *                            on something other than a container.
	 */
	public static function requested( array $input, string $el_type ): ?string {
		$preset = $input[ self::INPUT_PRESET ] ?? null;

		if ( null === $preset || '' === $preset ) {
			return null;
		}

		if ( ! is_string( $preset ) || ! in_array( $preset, self::PRESETS, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a preset this operation offers.',
				'Retry with preset set to "full-bleed", or omit preset and send the settings you want directly.'
			);
		}

		if ( ElementorElementAddInput::EL_TYPE_CONTAINER !== $el_type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A preset describes container settings, and this request is adding something else.',
				'Retry with elType set to "container", or omit preset and send this element\'s own settings.'
			);
		}

		return $preset;
	}

	/**
	 * The requested settings with the preset's settings folded in.
	 *
	 * Refuses rather than resolving a collision. A preset overruling an explicit
	 * value, or losing to one, both end with a stored element that does not match
	 * what the caller believes it asked for.
	 *
	 * @param string|null          $preset   The preset name, or null.
	 * @param array<string, mixed> $settings The settings the caller sent.
	 *
	 * @return array<string, mixed> The settings to store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the caller sent
	 *                            a setting the preset also writes.
	 */
	public static function apply( ?string $preset, array $settings ): array {
		if ( null === $preset ) {
			return $settings;
		}

		$contributed = self::settingsFor( $preset );

		foreach ( array_keys( $contributed ) as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'The preset writes %s, and this request also sends it, so what the element would end up with is ambiguous.',
					(string) $key
				),
				'Retry with either the preset or that setting, not both. The preset stores zero padding and full content width; sending them yourself gives you every other value too.'
			);
		}

		return array_merge( $settings, $contributed );
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
