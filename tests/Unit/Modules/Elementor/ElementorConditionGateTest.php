<?php
/**
 * Tests for ElementorConditionGate.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorConditionGate;
use SiteHelm\Modules\Elementor\ElementorWidgetSchema;
use SiteHelm\Tests\TestCase;

/**
 * The judgement that separates a setting Elementor renders from one it stores
 * and silently drops.
 *
 * NO DOUBLES ANYWHERE IN THIS FILE, and that is the payoff of the class being
 * pure and static rather than an injected collaborator. Every case below is
 * three arrays in and one verdict out, so nothing here can pass because a
 * fixture drifted away from what Elementor really answers.
 *
 * THE FAIL-OPEN CASES ARE THE SAFETY PROPERTY AND OUTNUMBER THE REFUSALS ON
 * PURPOSE. A false refusal blocks a legitimate write — a 175-element document
 * build among them — and is strictly worse than the silent no-render the class
 * exists to prevent. Each shape the evaluator does not claim to understand gets
 * its own named test, so widening the understood set without widening these
 * pins fails here rather than in production.
 *
 * THE DEFECT ITSELF, in Elementor's own vocabulary: Background's sub-controls
 * declare the positive form `[ 'background' => [ 'classic', 'gradient' ] ]`
 * against the stored key `background_background`, and Border's declare the
 * NEGATED form `[ 'border!' => [ '', 'none' ] ]`. Both are reproduced verbatim
 * below rather than paraphrased, because a gate that handled only the positive
 * form would pass a plausible-looking suite and do nothing for half the defect.
 */
final class ElementorConditionGateTest extends TestCase {

	/**
	 * The Background group's real declaration: a colour gated on the switcher.
	 *
	 * @return array<string, array<string, mixed>> The control descriptors.
	 */
	private function backgroundControls(): array {
		return [
			'background_background' => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'background_color'      => [
				'condition'  => [ 'background_background' => [ 'classic', 'gradient' ] ],
				'conditions' => null,
				'default'    => '',
			],
		];
	}

	/**
	 * The Border group's real declaration, in the negated form.
	 *
	 * @return array<string, array<string, mixed>> The control descriptors.
	 */
	private function borderControls(): array {
		return [
			'border_border' => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'border_color'  => [
				'condition'  => [ 'border_border!' => [ '', 'none' ] ],
				'conditions' => null,
				'default'    => '',
			],
		];
	}

	/**
	 * THE DEFECT. A colour written with no switcher anywhere: not in the request,
	 * not in the stored settings, and the switcher's own default empty. Elementor
	 * accepts this write, returns it verbatim on the next read, and renders
	 * nothing.
	 */
	public function test_a_background_colour_written_without_its_switcher_is_refused(): void {
		$written = [ 'background_color' => '#ff0000' ];

		$verdict = ElementorConditionGate::firstUnrenderable( $written, $written, $this->backgroundControls() );

		$this->assertSame(
			[
				ElementorConditionGate::VERDICT_KEY       => 'background_color',
				ElementorConditionGate::VERDICT_COMPANION => 'background_background',
				ElementorConditionGate::VERDICT_ACCEPTED  => [ 'classic', 'gradient' ],
				ElementorConditionGate::VERDICT_NEGATED   => false,
			],
			$verdict,
			'The verdict must carry everything the refusal message needs, from the same pass that made the decision.'
		);
	}

	/**
	 * A switcher stored by an earlier write satisfies the condition.
	 *
	 * The single most important non-refusal in this file: judging the written map
	 * alone would refuse every partial update that edits a gated setting without
	 * re-sending its companion, which is the ordinary way to edit one.
	 */
	public function test_a_switcher_the_element_already_holds_satisfies_the_condition(): void {
		$written   = [ 'background_color' => '#ff0000' ];
		$effective = [
			'background_background' => 'classic',
			'background_color'      => '#ff0000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( $written, $effective, $this->backgroundControls() ),
			'A companion stored last week renders the write exactly as one sent today does.'
		);
	}

	/**
	 * The companion sent in the same request satisfies the condition.
	 */
	public function test_a_switcher_written_in_the_same_request_satisfies_the_condition(): void {
		$written = [
			'background_background' => 'gradient',
			'background_color'      => '#ff0000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( $written, $written, $this->backgroundControls() ),
			'Sending the companion alongside is the remediation the refusal asks for; it must actually work.'
		);
	}

	/**
	 * A control never stored is judged on the default the stack declares.
	 *
	 * Elementor falls back to the declared default when an element holds no
	 * value, so a gate that read "absent" as "unsatisfied" would refuse writes
	 * to every widget whose switcher defaults to an on value.
	 */
	public function test_the_referenced_controls_declared_default_satisfies_the_condition(): void {
		$controls                                       = $this->backgroundControls();
		$controls['background_background']['default']    = 'classic';
		$written                                        = [ 'background_color' => '#ff0000' ];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( $written, $written, $controls ),
			'An unstored control is worth its declared default, which is what Elementor will compare.'
		);
	}

	/**
	 * A stored empty string beats a non-empty default, as it does upstream.
	 *
	 * The inverse of the case above and the one that keeps Border gated: an
	 * element that holds `border_border = ''` is judged on the empty string, not
	 * on whatever the stack would have defaulted to.
	 */
	public function test_a_stored_value_wins_over_the_default_even_when_it_is_empty(): void {
		$controls                            = $this->borderControls();
		$controls['border_border']['default'] = 'solid';

		$verdict = ElementorConditionGate::firstUnrenderable(
			[ 'border_color' => '#000000' ],
			[
				'border_border' => '',
				'border_color'  => '#000000',
			],
			$controls
		);

		$this->assertNotNull( $verdict, 'Preferring a non-empty default over a stored empty value would un-gate the Border case entirely.' );
	}

	/**
	 * The negated form refuses when the effective value IS in the excluded list.
	 */
	public function test_a_negated_condition_refuses_when_the_companion_is_in_the_excluded_list(): void {
		$written = [ 'border_color' => '#000000' ];

		$this->assertSame(
			[
				ElementorConditionGate::VERDICT_KEY       => 'border_color',
				ElementorConditionGate::VERDICT_COMPANION => 'border_border',
				ElementorConditionGate::VERDICT_ACCEPTED  => [ '', 'none' ],
				ElementorConditionGate::VERDICT_NEGATED   => true,
			],
			ElementorConditionGate::firstUnrenderable( $written, $written, $this->borderControls() ),
			'The bang must be read as negation and stripped from the companion name, never looked up as part of it.'
		);
	}

	/**
	 * The negated form passes when the effective value is outside the list.
	 */
	public function test_a_negated_condition_passes_when_the_companion_is_outside_the_excluded_list(): void {
		$written   = [ 'border_color' => '#000000' ];
		$effective = [
			'border_border' => 'solid',
			'border_color'  => '#000000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( $written, $effective, $this->borderControls() ),
			'A border style of solid is exactly the state in which a border colour renders.'
		);
	}

	/**
	 * A scalar condition value is equality, not membership.
	 */
	public function test_a_scalar_condition_value_is_compared_as_equality(): void {
		$controls = [
			'gate'  => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'value' => [
				'condition'  => [ 'gate' => 'yes' ],
				'conditions' => null,
				'default'    => '',
			],
		];

		$this->assertNotNull(
			ElementorConditionGate::firstUnrenderable( [ 'value' => 'x' ], [ 'value' => 'x' ], $controls ),
			'A bare string is a one-member condition and must be evaluated, not skipped as an unread shape.'
		);
		$this->assertNull(
			ElementorConditionGate::firstUnrenderable(
				[ 'value' => 'x' ],
				[
					'gate'  => 'yes',
					'value' => 'x',
				],
				$controls
			)
		);
	}

	/**
	 * Entries AND, so one unsatisfied entry hides the control whatever the other
	 * says. This is why the gate may refuse on a single failed entry.
	 */
	public function test_one_unsatisfied_entry_refuses_even_when_its_sibling_is_satisfied(): void {
		$controls = [
			'one'   => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'two'   => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'value' => [
				'condition'  => [
					'one' => 'yes',
					'two' => 'yes',
				],
				'conditions' => null,
				'default'    => '',
			],
		];

		$verdict = ElementorConditionGate::firstUnrenderable(
			[ 'value' => 'x' ],
			[
				'one'   => 'yes',
				'value' => 'x',
			],
			$controls
		);

		$this->assertNotNull( $verdict );
		$this->assertSame( 'two', $verdict[ ElementorConditionGate::VERDICT_COMPANION ], 'The refusal must name the entry that actually failed.' );
	}

	/**
	 * An unparseable entry does not rescue an understood, unsatisfied sibling.
	 *
	 * Entries AND upstream, so the sibling hides the control however the
	 * unparseable one would have evaluated. Abandoning the whole control at the
	 * first unread entry would silently un-gate any control that pairs a plain
	 * condition with an indexed one.
	 */
	public function test_an_unreadable_entry_does_not_rescue_an_unsatisfied_sibling(): void {
		$controls = [
			'gate'  => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'value' => [
				'condition'  => [
					'other[sub]' => 'yes',
					'gate'       => 'yes',
				],
				'conditions' => null,
				'default'    => '',
			],
		];

		$verdict = ElementorConditionGate::firstUnrenderable( [ 'value' => 'x' ], [ 'value' => 'x' ], $controls );

		$this->assertNotNull( $verdict );
		$this->assertSame( 'gate', $verdict[ ElementorConditionGate::VERDICT_COMPANION ] );
	}

	/**
	 * FAIL OPEN: the nested `conditions` form disqualifies the whole control.
	 *
	 * The classic half alone says "refuse"; the nested half is not modelled and
	 * an OR term in it might well rescue the control. Refusing on half the
	 * declaration is the false refusal the class forbids.
	 */
	public function test_a_control_declaring_the_nested_conditions_form_is_never_judged(): void {
		$controls                              = $this->backgroundControls();
		$controls['background_color']['conditions'] = [
			'relation' => 'or',
			'terms'    => [
				[
					'name'     => 'background_background',
					'operator' => '!=',
					'value'    => 'video',
				],
			],
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], [ 'background_color' => '#ff0000' ], $controls ),
			'The nested form takes precedence upstream and is not modelled here, so its presence ends the judgement.'
		);
	}

	/**
	 * FAIL OPEN: a `name[sub_key]` index is outside the understood grammar.
	 */
	public function test_a_condition_key_indexing_into_a_sub_key_is_not_judged(): void {
		$controls                                = $this->backgroundControls();
		$controls['background_color']['condition'] = [ 'background_background[size]' => 'cover' ];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], [ 'background_color' => '#ff0000' ], $controls )
		);
	}

	/**
	 * FAIL OPEN: an effective value that is not a string.
	 *
	 * Elementor compares loosely, so `0`, `''` and `false` have corners where a
	 * reimplementation and the original can disagree. Switcher vocabularies are
	 * strings, so restricting to them costs the gate nothing it was built for.
	 */
	public function test_a_non_string_effective_value_is_not_judged(): void {
		$effective = [
			'background_background' => [ 'unit' => 'px' ],
			'background_color'      => '#ff0000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], $effective, $this->backgroundControls() )
		);
	}

	/**
	 * FAIL OPEN: a condition value that is not a string or a list of strings.
	 */
	public function test_a_condition_value_that_is_not_string_shaped_is_not_judged(): void {
		$controls                                  = $this->backgroundControls();
		$controls['background_color']['condition'] = [ 'background_background' => [ 1, 2 ] ];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], [ 'background_color' => '#ff0000' ], $controls )
		);
	}

	/**
	 * FAIL OPEN: an empty condition value list is not a usable condition.
	 *
	 * Judged, it would refuse every positive condition and satisfy every negated
	 * one on nothing but the shape of the array.
	 */
	public function test_an_empty_condition_value_list_is_not_judged(): void {
		$controls                                  = $this->backgroundControls();
		$controls['background_color']['condition'] = [ 'background_background' => [] ];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], [ 'background_color' => '#ff0000' ], $controls )
		);
	}

	/**
	 * FAIL OPEN: the referenced key is bound to a dynamic tag or a global token.
	 *
	 * Its real value is resolved at render time and is not in this map, so the
	 * un-bound stored value would judge the control on a value it never has.
	 *
	 * @dataProvider provideBindingContainers
	 *
	 * @param string $member The binding container's member name.
	 */
	public function test_a_companion_supplied_at_render_time_is_not_judged( string $member ): void {
		$effective = [
			$member            => [ 'background_background' => 'anything' ],
			'background_color' => '#ff0000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], $effective, $this->backgroundControls() )
		);
	}

	/**
	 * The two members Elementor resolves at render time.
	 *
	 * @return array<string, array{string}> The cases.
	 */
	public static function provideBindingContainers(): array {
		return [
			'a dynamic tag'    => [ ElementorConditionGate::KEY_DYNAMIC ],
			'a global token'   => [ ElementorConditionGate::KEY_GLOBALS ],
		];
	}

	/**
	 * FAIL OPEN: a binding container of an unreadable shape counts as a binding.
	 *
	 * An unreadable container is not evidence that the control is unbound.
	 */
	public function test_an_unreadable_binding_container_is_treated_as_a_binding(): void {
		$effective = [
			ElementorConditionGate::KEY_DYNAMIC => 'not an array',
			'background_color'                  => '#ff0000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], $effective, $this->backgroundControls() )
		);
	}

	/**
	 * FAIL OPEN: the referenced control is not declared in the stack at all.
	 *
	 * Reading a control this plugin cannot see as null and comparing would refuse
	 * writes whose companion a third-party widget supplies in its own way.
	 */
	public function test_a_companion_the_stack_does_not_declare_is_not_judged(): void {
		$controls = [
			'background_color' => [
				'condition'  => [ 'background_background' => [ 'classic' ] ],
				'conditions' => null,
				'default'    => '',
			],
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'background_color' => '#ff0000' ], [ 'background_color' => '#ff0000' ], $controls )
		);
	}

	/**
	 * FAIL OPEN: an atomic widget arrives with an empty descriptor map.
	 *
	 * Read through the value object rather than by writing `[]` by hand, so the
	 * test would notice if `atomic()` ever started carrying descriptors that the
	 * classic evaluator has no business reading.
	 */
	public function test_an_atomic_widget_is_not_gated_at_all(): void {
		$schema = ElementorWidgetSchema::atomic( [ 'title' => [ 'type' => 'string' ] ] );

		$this->assertSame( [], $schema->controls(), 'The atomic vocabulary declares dependencies its own way and carries no gating descriptors.' );
		$this->assertNull(
			ElementorConditionGate::firstUnrenderable( [ 'title' => 'Hello' ], [ 'title' => 'Hello' ], $schema->controls() )
		);
	}

	/**
	 * A written key with no condition renders, which is what keeps the popover
	 * groups writable.
	 *
	 * Typography and box shadow have a starter control too —
	 * `typography_typography` — but it is `render_type: ui` and the siblings
	 * declare NO condition on it: a font size written without the starter renders
	 * perfectly. A rule shaped as "a `foo_foo` starter must accompany its group"
	 * would refuse this write on a pattern match with nothing upstream agreeing.
	 */
	public function test_a_popover_group_member_declaring_no_condition_is_written_freely(): void {
		$controls = [
			'typography_typography' => [
				'condition'  => null,
				'conditions' => null,
				'default'    => '',
			],
			'typography_font_size'  => [
				'condition'  => null,
				'conditions' => null,
				'default'    => [],
			],
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable(
				[ 'typography_font_size' => [ 'size' => 24 ] ],
				[ 'typography_font_size' => [ 'size' => 24 ] ],
				$controls
			)
		);
	}

	/**
	 * A stored setting the request does not touch is never re-litigated.
	 *
	 * A page written before this gate existed can hold an inert setting; refusing
	 * to save it would make the page unsaveable by the very operation that could
	 * repair it.
	 */
	public function test_an_inert_setting_the_request_does_not_write_is_left_alone(): void {
		$effective = [
			'background_color' => '#ff0000',
			'border_border'    => 'solid',
			'border_color'     => '#000000',
		];

		$this->assertNull(
			ElementorConditionGate::firstUnrenderable(
				[ 'border_color' => '#111111' ],
				$effective,
				$this->backgroundControls() + $this->borderControls()
			),
			'Only written keys are judged; the stored background_color is the site history and stays saveable.'
		);
	}

	/**
	 * The verdict is the caller's own iteration order, so preview and apply of
	 * the same request name the same key.
	 */
	public function test_the_first_unrenderable_key_is_the_callers_own_first(): void {
		$controls = $this->backgroundControls() + $this->borderControls();
		$written  = [
			'border_color'     => '#000000',
			'background_color' => '#ff0000',
		];

		$verdict = ElementorConditionGate::firstUnrenderable( $written, $written, $controls );

		$this->assertNotNull( $verdict );
		$this->assertSame( 'border_color', $verdict[ ElementorConditionGate::VERDICT_KEY ] );
	}

	/**
	 * DETERMINISM. `planChange()` evaluates this at preview and again at apply,
	 * and the two must be byte-identical or the digest taken over the plan moves
	 * between the phases of one request.
	 */
	public function test_two_identical_invocations_produce_identical_verdicts(): void {
		$controls = $this->backgroundControls() + $this->borderControls();
		$written  = [
			'background_color' => '#ff0000',
			'border_color'     => '#000000',
		];

		$this->assertSame(
			ElementorConditionGate::firstUnrenderable( $written, $written, $controls ),
			ElementorConditionGate::firstUnrenderable( $written, $written, $controls ),
			'No clock, no randomness and no registry read: the same three arrays must always answer the same verdict.'
		);
	}
}
