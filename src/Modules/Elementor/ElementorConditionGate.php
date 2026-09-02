<?php
/**
 * Whether a written Elementor setting will actually render, or only store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * The judgement that separates a setting Elementor will render from one it will
 * store, read back, and silently drop.
 *
 * THE DEFECT. A classic control may declare a `condition` naming a companion
 * control and the values that switch it on. `Controls_Stack::is_control_visible()`
 * evaluates that condition at CSS-generation time and skips an unsatisfied
 * control entirely. `border_color` without `border_border`, `background_color`
 * without `background_background`: the value is accepted, persisted, returned
 * verbatim by the next read, and the page renders as though nothing had been
 * written. Every check this module already performs — key existence, prop
 * envelopes, the post-write verification read — passes. The write is a success
 * by every measure the plugin has, and the operator's change is invisible.
 * This class is the missing measure: not "does the widget accept this key" but
 * "will the widget render this key given everything else it will then hold".
 *
 * THE GATE REFUSES ONLY WHEN IT IS CERTAIN. Any condition form, operator, key
 * grammar or value shape this evaluator does not fully understand is treated as
 * satisfied. A false refusal blocks a legitimate write — including a
 * whole-document build — and is strictly worse than the silent no-render it
 * exists to prevent, which an operator can at least diagnose with a read. Every
 * clause below that narrows what is understood is therefore a safety property,
 * not an unfinished feature, and widening one without widening the tests that
 * pin it is how this class starts refusing writes Elementor would have
 * rendered.
 *
 * THE ORACLE IS THE DECLARED CONDITION, NEVER A SWITCHER HEURISTIC, and that
 * distinction is what keeps popover groups writable. Typography and box shadow
 * also have a starter control — `typography_typography`,
 * `box_shadow_box_shadow_type` — but those are `render_type: ui` and their
 * siblings declare NO condition on them: a font size written without
 * `typography_typography` renders perfectly well. A rule shaped as "a
 * `foo_foo`-style starter must accompany its group" would refuse those writes
 * on a pattern match, with nothing in Elementor agreeing. Reading the declared
 * condition and only the declared condition gets both families right for the
 * same reason, and needs no list of group names that would drift every release.
 *
 * THE NEGATED FORM IS FIRST-CLASS, because Border uses it and Border is half
 * the defect. Elementor spells a condition key `name` or `name!`, the trailing
 * bang inverting the test: Border's sub-controls declare
 * `[ 'border!' => [ '', 'none' ] ]`, meaning "visible while the border style is
 * anything except unset or none". Reading the bang as part of the control name
 * would look up a control called `border!`, find nothing, and fail open on
 * every border write — the gate would compile, pass its positive tests, and do
 * nothing for the case it was written for.
 *
 * WHAT IS UNDERSTOOD, exhaustively: a classic `condition` array whose keys are
 * a plain control name with an optional trailing `!`, whose values are a string
 * or an array of strings, and whose referenced control is declared in the stack
 * with a string-valued effective setting or default. Entries AND together, on
 * Elementor's own semantics, which is why ONE unsatisfied understood entry is
 * enough to refuse: with AND, a single failed entry hides the control whatever
 * the others say.
 *
 * WHAT FAILS OPEN, exhaustively — a control declaring `conditions` (the nested
 * relation/terms form), even alongside a classic `condition`, because the
 * nested form is not modelled here and an OR term might rescue the control; a
 * condition key carrying a `[sub_key]` index or any other shape the grammar
 * does not match; a condition value that is not a string or an array of
 * strings; a referenced effective value that is not a string, which covers
 * arrays, envelopes, null and the numeric corners where Elementor's loose
 * comparison and this one could disagree; a referenced key carried by
 * `__dynamic__` or `__globals__`, whose real value is resolved at render time
 * and is not in the map at all; a referenced control absent from the stack; and
 * a written control declaring no condition, which is the ordinary case and the
 * one that keeps popover groups ungated.
 *
 * ATOMIC WIDGETS ARE NOT GATED AT ALL. `ElementorWidgetSchema::atomic()` carries
 * no control descriptors, so an atomic widget arrives here with an empty map and
 * leaves unjudged. The atomic vocabulary expresses dependencies between props
 * differently and modelling it from the classic evaluator would be a guess.
 *
 * ONLY WRITTEN KEYS ARE JUDGED. A stored setting the request does not touch is
 * the site's own history and is never re-litigated — the same split
 * `ElementorPropCoercion` already draws between sweeping a stored tree and
 * judging a caller's input. A page that already holds an inert setting stays
 * saveable.
 *
 * REJECTED: AUTO-WRITING THE COMPANION. The obvious repair is to set the
 * switcher ourselves — write `background_background` when the caller writes
 * `background_color`, the way the layout dual-row fix writes both rows. It is
 * rejected because the satisfying value is a CONTENT decision, not a mechanical
 * one: `classic`, `gradient` and `video` are three different backgrounds, and
 * picking one would be a silent write of a value nobody chose, on a page the
 * operator then has to discover it on. Naming the companion and its accepted
 * values in the refusal puts the choice where it belongs, at the moment the
 * caller still holds the write.
 *
 * THE JUDGEMENT IS PURE AND DETERMINISTIC. No clock, no randomness, no registry
 * read, no iteration over anything but the caller's own arrays in their given
 * order — which is what lets `planChange()` run this twice, at preview and at
 * apply, and get byte-identical output both times.
 *
 * @package SiteHelm
 */
final class ElementorConditionGate {

	/**
	 * The control member carrying a classic condition.
	 */
	public const KEY_CONDITION = 'condition';

	/**
	 * The control member carrying the nested relation/terms form.
	 *
	 * Its mere presence is a fail-open trigger; it is never parsed.
	 */
	public const KEY_CONDITIONS = 'conditions';

	/**
	 * The control member carrying the value Elementor assumes when nothing is
	 * stored.
	 */
	public const KEY_DEFAULT = 'default';

	/**
	 * The stored member holding dynamic-tag bindings, keyed by control name.
	 */
	public const KEY_DYNAMIC = '__dynamic__';

	/**
	 * The stored member holding global-token bindings, keyed by control name.
	 */
	public const KEY_GLOBALS = '__globals__';

	/**
	 * The verdict member naming the written setting that will not render.
	 */
	public const VERDICT_KEY = 'key';

	/**
	 * The verdict member naming the control that gates it.
	 */
	public const VERDICT_COMPANION = 'companion';

	/**
	 * The verdict member listing the companion values the condition names.
	 */
	public const VERDICT_ACCEPTED = 'accepted';

	/**
	 * The verdict member saying whether the condition was the negated form.
	 */
	public const VERDICT_NEGATED = 'negated';

	/**
	 * The only condition-key shape this evaluator claims to understand: a plain
	 * control name, with an optional trailing `!` for the negated form.
	 *
	 * Deliberately narrower than Elementor's own grammar, which also allows a
	 * `name[sub_key]` index into an array-valued setting. Anything this pattern
	 * does not match fails open, and both halves of the defect this class was
	 * written for — Border and Background — use plain keys.
	 *
	 * @var string
	 */
	public const CONDITION_KEY_PATTERN = '/^([A-Za-z0-9_-]{1,64})(!?)$/';

	/**
	 * Not instantiable: the class is a named vocabulary and one pure judgement.
	 */
	private function __construct() {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The first written setting this write would store without rendering.
	 *
	 * FIRST, not all of them, and the singular is the message's design rather
	 * than a shortcut. A caller fixing one companion usually fixes the group,
	 * and a refusal listing six unsatisfied conditions teaches less than one
	 * that names a single companion control and the values it accepts. The
	 * iteration order is the caller's own, so the "first" is stable across the
	 * preview and apply evaluations of the same request.
	 *
	 * THE EFFECTIVE MAP, NOT THE WRITTEN ONE, ANSWERS EVERY REFERENCE. Elementor
	 * evaluates a condition against the settings the element will actually hold,
	 * so a switcher stored by an earlier write satisfies a condition just as a
	 * switcher sent in this request does. Judging against the written map alone
	 * would refuse every partial update that touches a gated setting without
	 * re-sending its companion, which is the normal way to edit one.
	 *
	 * One pass produces both the decision and everything the message needs, so
	 * the refusal can never describe a different evaluation from the one that
	 * refused.
	 *
	 * @param array<string, mixed>                $written   The keys this request writes.
	 * @param array<string, mixed>                $effective The settings the element will hold: stored, with the written keys laid over them.
	 * @param array<string, array<string, mixed>> $controls  Control name => raw gating descriptor, from ElementorWidgetSchema::controls().
	 *
	 * @return array{key: string, companion: string, accepted: array<int, string>, negated: bool}|null
	 *         The verdict for the first written key that will not render, or null
	 *         when every written key renders — or when nothing could be judged.
	 */
	public static function firstUnrenderable( array $written, array $effective, array $controls ): ?array {
		foreach ( array_keys( $written ) as $key ) {
			$verdict = self::judge_key( (string) $key, $effective, $controls );

			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		return null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The verdict one written key earns, or null when it renders or cannot be
	 * judged.
	 *
	 * THE NESTED FORM DISQUALIFIES THE WHOLE CONTROL, not just its own half. A
	 * control may declare `condition` and `conditions` together, and upstream
	 * requires both to pass; but this evaluator models only the classic half, so
	 * refusing on it would ignore whatever the nested half says. That is exactly
	 * the false refusal the class forbids, so the presence of `conditions` ends
	 * the judgement before the classic half is read.
	 *
	 * @param string                              $key       The written setting name.
	 * @param array<string, mixed>                $effective The settings the element will hold.
	 * @param array<string, array<string, mixed>> $controls  The gating descriptors.
	 *
	 * @return array{key: string, companion: string, accepted: array<int, string>, negated: bool}|null The verdict.
	 */
	private static function judge_key( string $key, array $effective, array $controls ): ?array {
		$descriptor = $controls[ $key ] ?? null;

		if ( ! is_array( $descriptor ) ) {
			return null;
		}

		if ( null !== ( $descriptor[ self::KEY_CONDITIONS ] ?? null ) ) {
			return null;
		}

		$condition = $descriptor[ self::KEY_CONDITION ] ?? null;

		if ( ! is_array( $condition ) ) {
			return null;
		}

		foreach ( $condition as $raw_key => $raw_value ) {
			$verdict = self::judge_entry( $key, (string) $raw_key, $raw_value, $effective, $controls );

			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		return null;
	}

	/**
	 * The verdict one condition entry earns, or null when it is satisfied or not
	 * understood.
	 *
	 * SATISFIED AND NOT-UNDERSTOOD DELIBERATELY SHARE AN ANSWER. Distinguishing
	 * them would only be useful to a caller that wanted to escalate an
	 * unparseable entry into a refusal, which is precisely the behaviour this
	 * class exists to forbid; collapsing them makes the safe reading the only
	 * reachable one.
	 *
	 * AN UNUNDERSTOOD ENTRY DOES NOT RESCUE ITS SIBLINGS. Entries AND together
	 * upstream, so a sibling entry that is understood and unsatisfied still
	 * hides the control however the unparseable one would have evaluated. That
	 * is why this returns per entry and the caller keeps looking rather than
	 * abandoning the control.
	 *
	 * @param string                              $key       The written setting name.
	 * @param string                              $raw_key   The condition key, possibly negated.
	 * @param mixed                               $raw_value The condition's accepted value or values.
	 * @param array<string, mixed>                $effective The settings the element will hold.
	 * @param array<string, array<string, mixed>> $controls  The gating descriptors.
	 *
	 * @return array{key: string, companion: string, accepted: array<int, string>, negated: bool}|null The verdict.
	 */
	private static function judge_entry( string $key, string $raw_key, mixed $raw_value, array $effective, array $controls ): ?array {
		$matches = [];

		if ( 1 !== preg_match( self::CONDITION_KEY_PATTERN, $raw_key, $matches ) ) {
			return null;
		}

		$companion = $matches[1];
		$negated   = '!' === $matches[2];
		$accepted  = self::accepted_values( $raw_value );

		if ( null === $accepted ) {
			return null;
		}

		$actual = self::effective_value( $companion, $effective, $controls );

		if ( null === $actual ) {
			return null;
		}

		// Loose, as `Controls_Stack::is_control_visible()` is. Both sides are
		// already known to be strings, so loose and strict agree here; the loose
		// form is kept because upstream's is loose and a later widening of the
		// value shapes must inherit upstream's comparison, not a stricter one
		// this gate invented.
		$satisfied = in_array( $actual, $accepted, false ); // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse -- Mirrors Elementor's own loose comparison; see above.

		if ( $negated ) {
			$satisfied = ! $satisfied;
		}

		if ( $satisfied ) {
			return null;
		}

		return [
			self::VERDICT_KEY       => $key,
			self::VERDICT_COMPANION => $companion,
			self::VERDICT_ACCEPTED  => $accepted,
			self::VERDICT_NEGATED   => $negated,
		];
	}

	/**
	 * The condition's accepted values as a list of strings, or null when the
	 * shape is not one this evaluator reads.
	 *
	 * STRINGS ONLY, and that is the mitigation for the one way a correct
	 * evaluator could still refuse a rendering write: Elementor compares
	 * loosely, so `0`, `''`, `false` and `'0'` have corners where a
	 * reimplementation and the original disagree. Switcher vocabularies are
	 * strings — `classic`, `gradient`, `solid`, `none`, `yes` — so restricting
	 * to strings costs the gate nothing it was built to catch and removes the
	 * disagreement entirely.
	 *
	 * An empty array is not a usable condition and answers null, because
	 * `in_array()` against nothing would refuse every positive condition and
	 * satisfy every negated one on an accident of shape.
	 *
	 * @param mixed $raw_value The declared value.
	 *
	 * @return array<int, string>|null The accepted values, or null.
	 */
	private static function accepted_values( mixed $raw_value ): ?array {
		if ( is_string( $raw_value ) ) {
			return [ $raw_value ];
		}

		if ( ! is_array( $raw_value ) || [] === $raw_value ) {
			return null;
		}

		$values = [];

		foreach ( $raw_value as $member ) {
			if ( ! is_string( $member ) ) {
				return null;
			}

			$values[] = $member;
		}

		return $values;
	}

	/**
	 * The value Elementor will see for the referenced control, or null when this
	 * evaluator cannot be sure what that value is.
	 *
	 * THE STORED VALUE WINS OVER THE DEFAULT, INCLUDING WHEN IT IS EMPTY, because
	 * that is Elementor's own precedence: a control the element holds a value for
	 * is judged on that value, and only a control it holds nothing for falls back
	 * to what the stack declares. Preferring a non-empty default over a stored
	 * empty string would satisfy conditions Elementor considers unsatisfied and
	 * silently un-gate exactly the Border case.
	 *
	 * A DYNAMIC OR GLOBAL BINDING IS AN UNKNOWN, NOT AN ABSENCE. Those members
	 * hold a tag resolved at render time, so the value that will actually be
	 * compared is not in this map; treating the un-bound stored value as the
	 * truth would judge a control on a value it will never have.
	 *
	 * A CONTROL THE STACK DOES NOT DECLARE IS AN UNKNOWN TOO. Reading a missing
	 * control as null and comparing would refuse writes whose companion is
	 * supplied by a widget this plugin cannot see the declarations of.
	 *
	 * @param string                              $companion The referenced control name.
	 * @param array<string, mixed>                $effective The settings the element will hold.
	 * @param array<string, array<string, mixed>> $controls  The gating descriptors.
	 *
	 * @return string|null The value, or null when it cannot be established.
	 */
	private static function effective_value( string $companion, array $effective, array $controls ): ?string {
		if ( self::is_bound( $companion, $effective ) ) {
			return null;
		}

		if ( array_key_exists( $companion, $effective ) ) {
			$value = $effective[ $companion ];

			return is_string( $value ) ? $value : null;
		}

		$descriptor = $controls[ $companion ] ?? null;

		if ( ! is_array( $descriptor ) || ! array_key_exists( self::KEY_DEFAULT, $descriptor ) ) {
			return null;
		}

		$default = $descriptor[ self::KEY_DEFAULT ];

		return is_string( $default ) ? $default : null;
	}

	/**
	 * Whether the referenced control's value is supplied at render time rather
	 * than by the stored map.
	 *
	 * A binding container of an unexpected shape counts as a binding: an
	 * unreadable container is not evidence that the control is unbound, and the
	 * whole class errs towards not knowing.
	 *
	 * @param string               $companion The referenced control name.
	 * @param array<string, mixed> $effective The settings the element will hold.
	 *
	 * @return bool True when the value cannot be read from the map.
	 */
	private static function is_bound( string $companion, array $effective ): bool {
		foreach ( [ self::KEY_DYNAMIC, self::KEY_GLOBALS ] as $member ) {
			if ( ! array_key_exists( $member, $effective ) ) {
				continue;
			}

			$bindings = $effective[ $member ];

			if ( ! is_array( $bindings ) || array_key_exists( $companion, $bindings ) ) {
				return true;
			}
		}

		return false;
	}
}
