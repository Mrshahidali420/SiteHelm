<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\StyleQuery;
use SiteHelm\Tests\TestCase;

/**
 * Selector matching and media evaluation.
 *
 * Two of these tests exist because of mistakes this class made on the way in.
 * `.menu-toggle .icon` must not answer a question about `.menu-toggle`, and
 * `.menuToggle` must not answer a question about `.menutoggle`: both would be
 * reported as a rule that reaches the element, and in both cases the page does
 * not agree. The rest pin the answer this class is allowed to refuse to give —
 * a condition it cannot evaluate comes back as null, never as false.
 */
final class StyleQueryTest extends TestCase {

	private function query(): StyleQuery {
		return new StyleQuery();
	}

	public function test_a_rule_written_for_the_element_itself_matches(): void {
		$this->assertTrue( $this->query()->matches( '.menu-toggle', '.menu-toggle' ) );
	}

	public function test_a_descendant_rule_whose_subject_is_the_element_matches(): void {
		$this->assertTrue( $this->query()->matches( '.menu-toggle', '.site-header .menu-toggle:hover' ) );
	}

	public function test_a_rule_whose_subject_is_something_inside_the_element_does_not_match(): void {
		$this->assertFalse( $this->query()->matches( '.menu-toggle', '.menu-toggle .icon' ) );
	}

	public function test_a_child_combinator_is_read_the_same_way_as_a_space(): void {
		$this->assertTrue( $this->query()->matches( '.menu-toggle', 'header > .menu-toggle' ) );
		$this->assertFalse( $this->query()->matches( '.menu-toggle', '.menu-toggle > svg' ) );
	}

	public function test_the_request_must_be_carried_in_full_by_the_subject(): void {
		$this->assertTrue( $this->query()->matches( '.btn.primary', 'a.btn.primary' ) );
		$this->assertFalse( $this->query()->matches( '.btn.primary', 'a.btn' ) );
	}

	public function test_a_class_is_matched_case_sensitively_the_way_a_browser_matches_it(): void {
		$this->assertFalse( $this->query()->matches( '.menutoggle', '.menuToggle' ) );
	}

	public function test_an_element_name_is_matched_without_regard_to_case(): void {
		$this->assertTrue( $this->query()->matches( 'a', 'A' ) );
	}

	public function test_an_empty_request_matches_nothing(): void {
		$this->assertFalse( $this->query()->matches( '   ', '.a' ) );
	}

	public function test_specificity_counts_identifiers_classes_and_types_separately(): void {
		$this->assertSame( [ 1, 2, 1 ], $this->query()->specificity( '#main .a li.b' ) );
		$this->assertSame( [ 0, 1, 1 ], $this->query()->specificity( 'a:hover' ) );
		$this->assertSame( [ 0, 1, 1 ], $this->query()->specificity( 'a[href]' ) );
		$this->assertSame( [ 0, 0, 2 ], $this->query()->specificity( 'li::before' ) );
	}

	public function test_a_rule_with_no_media_condition_always_applies(): void {
		$this->assertTrue( $this->query()->appliesAt( null, 390 ) );
	}

	public function test_a_min_width_query_is_evaluated_at_the_requested_width(): void {
		$this->assertFalse( $this->query()->appliesAt( '(min-width: 768px)', 390 ) );
		$this->assertTrue( $this->query()->appliesAt( '(min-width: 768px)', 1280 ) );
	}

	public function test_a_max_width_query_is_evaluated_at_the_requested_width(): void {
		$this->assertTrue( $this->query()->appliesAt( '(max-width: 600px)', 390 ) );
		$this->assertFalse( $this->query()->appliesAt( '(max-width: 600px)', 1280 ) );
	}

	public function test_an_em_length_is_resolved_against_the_initial_font_size(): void {
		$this->assertTrue( $this->query()->appliesAt( '(min-width: 48em)', 768 ) );
		$this->assertFalse( $this->query()->appliesAt( '(min-width: 48em)', 767 ) );
	}

	public function test_a_screen_media_type_is_kept_and_a_print_one_is_not(): void {
		$this->assertTrue( $this->query()->appliesAt( 'screen and (max-width: 600px)', 390 ) );
		$this->assertFalse( $this->query()->appliesAt( 'print', 390 ) );
	}

	public function test_a_comma_list_applies_when_any_one_of_its_queries_does(): void {
		$this->assertTrue( $this->query()->appliesAt( 'print, (max-width: 600px)', 390 ) );
	}

	public function test_a_feature_this_reader_cannot_evaluate_comes_back_unevaluated(): void {
		$this->assertNull( $this->query()->appliesAt( '(prefers-color-scheme: dark)', 390 ) );
		$this->assertNull( $this->query()->appliesAt( '(orientation: portrait)', 390 ) );
	}

	public function test_a_negated_query_is_unevaluated_rather_than_inverted(): void {
		$this->assertNull( $this->query()->appliesAt( 'not screen', 390 ) );
	}

	public function test_a_width_query_alongside_an_unknown_one_still_refuses_when_the_width_fails(): void {
		// The width settles it: whatever the unknown feature answers, a query
		// with a failing `and` term cannot apply, so this is false and not null.
		$this->assertFalse( $this->query()->appliesAt( '(min-width: 768px) and (orientation: portrait)', 390 ) );
	}

	public function test_a_width_query_alongside_an_unknown_one_is_unevaluated_when_the_width_holds(): void {
		$this->assertNull( $this->query()->appliesAt( '(min-width: 768px) and (orientation: portrait)', 1280 ) );
	}

	public function test_the_range_form_is_read_in_both_directions(): void {
		$this->assertTrue( $this->query()->appliesAt( '(width <= 600px)', 390 ) );
		$this->assertFalse( $this->query()->appliesAt( '(width >= 600px)', 390 ) );
		$this->assertTrue( $this->query()->appliesAt( '(400px <= width)', 500 ) );
	}

	public function test_a_two_sided_range_holds_only_inside_the_band(): void {
		$this->assertTrue( $this->query()->appliesAt( '(400px <= width <= 800px)', 600 ) );
		$this->assertFalse( $this->query()->appliesAt( '(400px <= width <= 800px)', 390 ) );
		$this->assertFalse( $this->query()->appliesAt( '(400px <= width <= 800px)', 900 ) );
	}

	public function test_a_length_in_a_unit_this_reader_does_not_know_is_unevaluated(): void {
		$this->assertNull( $this->query()->appliesAt( '(min-width: 50vw)', 390 ) );
	}
}
