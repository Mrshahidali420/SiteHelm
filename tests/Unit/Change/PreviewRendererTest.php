<?php
/**
 * Tests for PreviewRenderer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\TargetState;
use SiteHelm\Tests\TestCase;

/**
 * Tests both preview renderings and their determinism.
 */
final class PreviewRendererTest extends TestCase {

	private PreviewRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new PreviewRenderer();
	}

	private function currentState(): TargetState {
		return new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
			]
		);
	}

	public function test_only_changed_fields_appear_in_the_machine_diff(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Original excerpt.',
			]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame( 'post:42', $preview['machine']['target'] );
		$this->assertTrue( $preview['machine']['exists'] );
		$this->assertSame(
			[
				[
					'field'  => 'post_title',
					'before' => 'Original title',
					'after'  => 'Edited title',
				],
			],
			$preview['machine']['changes']
		);
	}

	/**
	 * Asserts the WHOLE shape, not only the members a given test happens to
	 * read. D4 and the implementation previously disagreed about whether
	 * `machine` carried `exists`, and nothing caught it because every assertion
	 * addressed one key at a time. This test fails on any added, removed, or
	 * reordered member, in either rendering.
	 */
	public function test_the_rendering_shape_is_exactly_two_keys_and_the_machine_diff_is_exactly_three(): void {
		$preview = $this->renderer->render(
			'content-update',
			$this->currentState(),
			new PlannedChange( [ 'title' => 'Edited title' ], [ 'post_title' => 'Edited title' ] )
		);

		$this->assertSame( [ 'human', 'machine' ], array_keys( $preview ) );
		$this->assertSame( [ 'target', 'exists', 'changes' ], array_keys( $preview['machine'] ) );
		$this->assertIsString( $preview['human'] );
		$this->assertSame( [ 'field', 'before', 'after' ], array_keys( $preview['machine']['changes'][0] ) );
	}

	public function test_changes_follow_the_declared_field_order_not_insertion_order(): void {
		$planned = new PlannedChange(
			[],
			[
				'post_excerpt' => 'New excerpt.',
				'post_title'   => 'Edited title',
				'post_content' => '<p>New body.</p>',
			],
			[ 'post_title', 'post_content', 'post_excerpt' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame(
			[ 'post_title', 'post_content', 'post_excerpt' ],
			array_column( $preview['machine']['changes'], 'field' )
		);
	}

	public function test_fields_outside_the_declared_order_are_appended_alphabetically(): void {
		$planned = new PlannedChange(
			[],
			[
				'zeta'       => 'z',
				'alpha'      => 'a',
				'post_title' => 'Edited title',
			],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame(
			[ 'post_title', 'alpha', 'zeta' ],
			array_column( $preview['machine']['changes'], 'field' )
		);
	}

	public function test_identical_input_renders_identically(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);

		$this->assertSame(
			$this->renderer->render( 'content-update', $this->currentState(), $planned ),
			$this->renderer->render( 'content-update', $this->currentState(), $planned )
		);
	}

	public function test_human_summary_names_the_operation_target_and_each_change(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( 'content-update', $human );
		$this->assertStringContainsString( 'post:42', $human );
		$this->assertStringContainsString( 'existing target', $human );
		$this->assertStringContainsString( 'post_title: "Original title" -> "Edited title"', $human );
	}

	public function test_human_summary_marks_a_new_target(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Brand new' ],
			[ 'post_title' => 'Brand new' ],
			[ 'post_title' ]
		);

		$human = $this->renderer->render(
			'content-create',
			new TargetState( 'post:new', false, [] ),
			$planned
		)['human'];

		$this->assertStringContainsString( 'new target', $human );
		$this->assertStringContainsString( 'post_title: (absent) -> "Brand new"', $human );
	}

	public function test_long_text_is_bounded_with_a_character_count(): void {
		$long    = str_repeat( 'x', 200 );
		$planned = new PlannedChange(
			[ 'content' => $long ],
			[ 'post_content' => $long ],
			[ 'post_content' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( '(200 characters)', $human );
		$this->assertStringNotContainsString( str_repeat( 'x', 90 ), $human );
	}

	public function test_array_values_are_summarized_by_item_count(): void {
		$planned = new PlannedChange(
			[],
			[ 'terms' => [ 'category' => [ 3, 5 ] ] ],
			[ 'terms' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( 'terms: (absent) -> (1 item)', $human );
	}

	/**
	 * ASSERTED WITHOUT THE QUOTES, which is the whole point. A deletion sweep
	 * found both scalar branches unpinned: delete them and the value falls
	 * through to the text path, where `true` becomes `"1"`, `false` becomes `""`
	 * and `42` becomes `"42"`. Nothing crashes and every other assertion in this
	 * file still holds — but this is the confirmation text an operator reads
	 * before approving a write, and `"1"` does not say what `true` says.
	 */
	public function test_booleans_and_numbers_render_unquoted_and_not_as_text(): void {
		$planned = new PlannedChange(
			[],
			[
				'ping_status' => true,
				'sticky'      => false,
				'menu_order'  => 42,
				'ratio'       => 1.5,
			],
			[ 'ping_status', 'sticky', 'menu_order', 'ratio' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( 'ping_status: (absent) -> true', $human );
		$this->assertStringContainsString( 'sticky: (absent) -> false', $human );
		$this->assertStringContainsString( 'menu_order: (absent) -> 42', $human );
		$this->assertStringContainsString( 'ratio: (absent) -> 1.5', $human );
	}

	public function test_a_no_op_plan_states_that_nothing_changes(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Original title' ],
			[ 'post_title' => 'Original title' ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame( [], $preview['machine']['changes'] );
		$this->assertStringContainsString( 'No field changes', $preview['human'] );
	}

	/**
	 * Not part of the brief's given test list. Added because field values are
	 * attacker-influenceable content (post titles, excerpts, body text) that
	 * the human summary joins one change per line with "\n". Without
	 * normalization, a value containing an embedded newline could inject what
	 * reads as an extra change line into the confirmation text an operator
	 * relies on before approving — misrepresenting how many fields actually
	 * changed. The machine diff is untouched by this: it is structured data
	 * keyed by field name, not line-delimited text, so there is nothing to
	 * inject there.
	 */
	public function test_embedded_newlines_in_a_field_value_cannot_inject_a_fake_line_into_the_human_summary(): void {
		$malicious = "Real title\n  fake_field: \"nothing\" -> \"injected\"";
		$planned   = new PlannedChange(
			[ 'title' => $malicious ],
			[ 'post_title' => $malicious ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertCount( 2, explode( "\n", $preview['human'] ) );
		$this->assertStringContainsString( 'Real title\\n  fake_field:', $preview['human'] );
		$this->assertSame( $malicious, $preview['machine']['changes'][0]['after'] );
	}

	/**
	 * A value that is not valid UTF-8 must still show the operator its real prior
	 * content.
	 *
	 * `preg_replace_callback` with the /u modifier returns null on a subject that
	 * is not valid UTF-8, and casting that null to string produced an empty
	 * rendering. A latin1-era title then previewed as `post_title: "" -> "New
	 * title"`: the operator saw no prior value at all and approved overwriting
	 * something they could not see. 0xE9 is the latin1 encoding of 'é' and is not
	 * a legal UTF-8 sequence on its own.
	 */
	public function test_a_before_value_that_is_not_valid_utf8_is_never_rendered_as_empty(): void {
		$latin1  = "Caf\xE9 opening";
		$planned = new PlannedChange(
			[ 'title' => 'New title' ],
			[ 'post_title' => 'New title' ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render(
			'content-update',
			new TargetState( 'post:42', true, [ 'post_title' => $latin1 ] ),
			$planned
		);

		$this->assertStringNotContainsString( 'post_title: "" ->', $preview['human'] );
		$this->assertStringContainsString( 'Caf\\xE9 opening', $preview['human'] );
		$this->assertStringContainsString( '-> "New title"', $preview['human'] );

		// The machine diff is JSON-encoded separately and carries the raw value,
		// so it must not be disturbed by the human rendering's fallback.
		$this->assertSame( $latin1, $preview['machine']['changes'][0]['before'] );
	}

	/**
	 * The escaping still neutralizes a forged line when the value is not valid
	 * UTF-8, so the fallback cannot become a way around the injection guard.
	 */
	public function test_an_invalid_utf8_value_cannot_inject_a_fake_line_either(): void {
		$malicious = "Real\xE9 title\n  fake_field: \"nothing\" -> \"injected\"";
		$planned   = new PlannedChange(
			[ 'title' => $malicious ],
			[ 'post_title' => $malicious ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertCount( 2, explode( "\n", $preview['human'] ) );
		$this->assertStringContainsString( 'Real\\xE9 title\\n  fake_field:', $preview['human'] );
	}

	/**
	 * Every carrier of a forged line is escaped, not just the ASCII newline.
	 *
	 * A review found the previous test proved only the bare "\n" case: stripping
	 * the CR and CRLF branches left the suite green, and U+2028, U+2029, U+0085,
	 * vertical tab and form feed passed through untouched. Many terminals and
	 * chat clients render those as line breaks, so each one reopens the
	 * injection the escaping exists to close.
	 *
	 * The summary contains real newlines of its own — it joins one change per
	 * line — so the assertion is that the count of lines is exactly what the
	 * renderer wrote, and that the carrier appears in its escaped form.
	 *
	 * @dataProvider lineBreakCarriers
	 *
	 * @param string $carrier The raw character sequence a value may contain.
	 * @param string $escaped The visible form it must be rendered as.
	 */
	public function test_every_line_break_carrier_is_escaped( string $carrier, string $escaped ): void {
		$malicious = 'Real title' . $carrier . 'forged line';
		$planned   = new PlannedChange(
			[ 'title' => $malicious ],
			[ 'post_title' => $malicious ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertCount(
			2,
			explode( "\n", $preview['human'] ),
			'The human summary gained a line the renderer did not write.'
		);
		$this->assertStringContainsString( 'Real title' . $escaped . 'forged line', $preview['human'] );
	}

	/**
	 * @return array<string, string[]> Carrier name to the raw sequence and its escape.
	 */
	public static function lineBreakCarriers(): array {
		return [
			'line feed'           => [ "\n", '\\n' ],
			'carriage return'     => [ "\r", '\\n' ],
			'CRLF'                => [ "\r\n", '\\n' ],
			'vertical tab'        => [ "\v", '\\u{000B}' ],
			'form feed'           => [ "\f", '\\u{000C}' ],
			'next line'           => [ "\u{0085}", '\\u{0085}' ],
			'line separator'      => [ "\u{2028}", '\\u{2028}' ],
			'paragraph separator' => [ "\u{2029}", '\\u{2029}' ],
		];
	}

	/**
	 * Bidirectional controls are escaped too, because they need no line break.
	 *
	 * A right-to-left override inside a value reorders the visible text in
	 * place, so a rendered before/after pair can be made to read backwards
	 * without the summary gaining a single line. Escaping is what makes the
	 * character visible instead of silently effective.
	 */
	public function test_a_bidirectional_override_cannot_reorder_the_summary(): void {
		$malicious = "Real\u{202E}title";
		$planned   = new PlannedChange(
			[ 'title' => $malicious ],
			[ 'post_title' => $malicious ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertStringNotContainsString( "\u{202E}", $preview['human'] );
		$this->assertStringContainsString( '\\u{202E}', $preview['human'] );
	}

	/**
	 * The truncation boundary is pinned at exactly 80 characters.
	 *
	 * A review found no fixture sat on the boundary, so flipping the comparison
	 * from `<=` to `<` passed the whole suite. Off by one here means either a
	 * value the operator can read is needlessly elided, or the summary grows
	 * past the bound it advertises.
	 */
	public function test_the_truncation_boundary_is_exact(): void {
		foreach ( [ 80 => false, 81 => true ] as $length => $should_truncate ) {
			$value   = str_repeat( 'a', $length );
			$planned = new PlannedChange(
				[ 'title' => $value ],
				[ 'post_title' => $value ],
				[ 'post_title' ]
			);

			$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

			if ( $should_truncate ) {
				$this->assertStringContainsString( '…', $human, "A {$length}-character value should be elided." );
				$this->assertStringContainsString( '(81 characters)', $human );
				continue;
			}
			$this->assertStringNotContainsString( '…', $human, "An {$length}-character value should be shown whole." );
		}
	}

	/**
	 * A field is unchanged only when it is identical, not merely loosely equal.
	 *
	 * WordPress hands back column values as strings while a payload carries
	 * scalars, so '0' against 0 and '' against null are realistic pairs. A
	 * review found that relaxing the comparison to `==` passed the whole suite,
	 * which would silently drop a real change from the preview — the operator
	 * would approve a plan whose diff never mentioned the field it altered.
	 */
	public function test_a_loosely_equal_value_still_counts_as_a_change(): void {
		$current = new TargetState( 'post:42', true, [ 'post_title' => '0' ] );
		$planned = new PlannedChange(
			[ 'title' => 0 ],
			[ 'post_title' => 0 ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $current, $planned );

		$this->assertCount( 1, $preview['machine']['changes'] );
		$this->assertSame( '0', $preview['machine']['changes'][0]['before'] );
		$this->assertSame( 0, $preview['machine']['changes'][0]['after'] );
	}
}
