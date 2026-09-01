<?php
/**
 * What one Elementor widget declares it accepts, and in which vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * One widget's declared settings, carrying WHICH KIND of declaration it is.
 *
 * ELEMENTOR HAS TWO WIDGET VOCABULARIES AT ONCE, and conflating them is what
 * this class exists to make impossible. An ATOMIC (V4) widget — `e-heading`,
 * `e-paragraph` — declares `get_props_schema()`, and every value it stores is a
 * `{"$$type": …, "value": …}` envelope. A CLASSIC widget — `html`, `heading`,
 * `image`, `button`, `shortcode`, and every third-party widget ever shipped —
 * extends `Widget_Base` and declares CONTROLS through `get_controls()`, whose
 * values are plain scalars and arrays. Enveloping a classic setting corrupts
 * it; leaving an atomic prop unenveloped locks the page (#101).
 *
 * CLASSIC-NESS IS A THIRD ANSWER, and that is the whole point of the type.
 * Before this class the write path had two: a prop schema, or null. Null means
 * "nothing was read" and is a refusal, so every classic widget on the site —
 * the overwhelming majority of widgets on the overwhelming majority of sites —
 * was refused as though Elementor were broken. `atomic( [] )` is a different
 * answer again: "this widget was read, in the atomic vocabulary, and declares
 * no props". The three must never collapse into each other.
 *
 * A CONTROL IS A WRITABLE SETTING IF AND ONLY IF IT DECLARES A `default`. That
 * is not a heuristic: `Controls_Manager` stores layout and UI controls —
 * `section`, `tab`, `tabs`, `raw_html`, `alert`, `heading`, `divider` — in the
 * same stack as data controls, and those carry no default because they hold no
 * value. Naming the types instead would hardcode a list that drifts with every
 * Elementor release. `ElementorApi` reads that key; this class only remembers
 * the names and the raw gating declarations it was handed.
 *
 * IT CARRIES THE GATING DECLARATIONS RAW AND UNINTERPRETED. A classic control
 * may declare `condition` / `conditions`, and Elementor's
 * `Controls_Stack::is_control_visible()` drops an unsatisfied control's value
 * at CSS-generation time — the value stores, reads back and verifies green
 * while nothing renders. `ElementorConditionGate` is the one place that reads
 * those declarations; this class transports them verbatim so the read stays a
 * dumb reach and the judgement stays in one place. Interpreting them here would
 * put half an evaluator in a value object.
 *
 * @package SiteHelm
 */
final class ElementorWidgetSchema {

	/**
	 * Constructs the value object.
	 *
	 * Private so a vocabulary can only be entered through the named constructor
	 * that owns it, which is what makes an object carrying an atomic flag and
	 * classic content unbuildable.
	 *
	 * @param bool                                 $is_atomic     True for the atomic prop vocabulary.
	 * @param array<string, array<string, string>> $props         Prop key => descriptor, empty for a classic widget.
	 * @param array<string, true>                  $setting_names The declared setting names, as a lookup.
	 * @param array<string, array<string, mixed>>  $controls      Control name => raw gating descriptor, empty for an atomic widget.
	 */
	private function __construct(
		private readonly bool $is_atomic,
		private readonly array $props,
		private readonly array $setting_names,
		private readonly array $controls,
	) {
	}

	/**
	 * The schema of an atomic widget, from its declared prop types.
	 *
	 * Carries no control descriptors, and that is a statement rather than an
	 * omission: the atomic vocabulary declares dependencies between props in its
	 * own way, and `ElementorConditionGate` reads an empty map as "nothing to
	 * judge here" rather than as "nothing is gated".
	 *
	 * @param array<string, array<string, string>> $props Prop key => descriptor.
	 *
	 * @return self The schema.
	 */
	public static function atomic( array $props ): self {
		return new self( true, $props, array_fill_keys( array_keys( $props ), true ), [] );
	}

	/**
	 * The schema of a classic widget, from the names of its data controls.
	 *
	 * THE DESCRIPTOR MAP IS REQUIRED, NOT DEFAULTED. A defaulted argument would
	 * let a future reader of the control stack build a schema whose gating
	 * declarations are silently empty, and an empty map is indistinguishable
	 * from "this widget gates nothing" — which is exactly the shape of the
	 * defect the gate exists to catch, re-created one layer up.
	 *
	 * @param array<int, string>                  $setting_names The declared control names.
	 * @param array<string, array<string, mixed>> $controls      Control name => raw gating descriptor.
	 *
	 * @return self The schema.
	 */
	public static function classic( array $setting_names, array $controls ): self {
		return new self( false, [], array_fill_keys( $setting_names, true ), $controls );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether this widget speaks the atomic prop vocabulary.
	 *
	 * @return bool True for an atomic widget.
	 */
	public function isAtomic(): bool {
		return $this->is_atomic;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The declared prop descriptors, keyed by prop name.
	 *
	 * Always empty for a classic widget: a control is not a prop and carries no
	 * prop type that could be reported here.
	 *
	 * @return array<string, array<string, string>> The descriptors.
	 */
	public function props(): array {
		return $this->props;
	}

	/**
	 * The raw gating descriptors of the writable controls, keyed by name.
	 *
	 * Each entry carries `condition`, `conditions` and `default` exactly as the
	 * control stack declared them, with no normalisation: the gate's own safety
	 * rule is that a shape it does not recognise is treated as satisfied, and
	 * normalising here would turn an unrecognised shape into a recognised one
	 * before the gate ever saw it.
	 *
	 * Always empty for an atomic widget.
	 *
	 * @return array<string, array<string, mixed>> The descriptors.
	 */
	public function controls(): array {
		return $this->controls;
	}

	/**
	 * Whether the widget declares a setting by this name.
	 *
	 * One question for both vocabularies, because the write path's key check
	 * asks each the same thing: does this widget accept a setting called this?
	 *
	 * @param string $key The setting name.
	 *
	 * @return bool True when the widget declares it.
	 */
	public function declares( string $key ): bool {
		return array_key_exists( $key, $this->setting_names );
	}
}
