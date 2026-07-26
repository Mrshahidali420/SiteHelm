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
}
