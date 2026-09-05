<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\CssRules;
use SiteHelm\Tests\TestCase;

/**
 * The CSS reader.
 *
 * The assertions that matter here are the ones about what the reader refuses
 * to guess at. A reader that quietly treats a rule inside `@supports` as though
 * it were unconditional, or that reports the rules of an `@import` it never
 * fetched, produces an answer that is wrong in exactly the way an operator
 * cannot check — so every one of those cases is pinned by name.
 */
final class CssRulesTest extends TestCase {

	private function reader(): CssRules {
		return new CssRules();
	}

	public function test_it_reads_a_plain_rule_with_its_declarations(): void {
		$rules = $this->reader()->read( '.a { color: red; margin: 0 auto }', 'main.css' );

		$this->assertCount( 1, $rules );
		$this->assertSame( '.a', $rules[0]['selector'] );
		$this->assertSame( 'main.css', $rules[0]['sheet'] );
		$this->assertNull( $rules[0]['media'] );
		$this->assertSame( [], $rules[0]['conditions'] );
		$this->assertSame(
			[
				[
					'property'  => 'color',
					'value'     => 'red',
					'important' => false,
				],
				[
					'property'  => 'margin',
					'value'     => '0 auto',
					'important' => false,
				],
			],
			$rules[0]['declarations']
		);
	}

	public function test_it_splits_a_selector_list_into_one_rule_each(): void {
		$rules = $this->reader()->read( '.a, .b > .c { color: red }', 'main.css' );

		$this->assertSame( [ '.a', '.b > .c' ], array_column( $rules, 'selector' ) );
	}

	public function test_it_records_important(): void {
		$rules = $this->reader()->read( '.a { color: red !important }', 'main.css' );

		$this->assertTrue( $rules[0]['declarations'][0]['important'] );
		$this->assertSame( 'red', $rules[0]['declarations'][0]['value'] );
	}

	public function test_a_comment_cannot_smuggle_a_rule_in_or_out(): void {
		$rules = $this->reader()->read( '/* .ghost { color: red } */ .a { color: /* not */ blue }', 'main.css' );

		$this->assertSame( [ '.a' ], array_column( $rules, 'selector' ) );
		$this->assertSame( 'blue', $rules[0]['declarations'][0]['value'] );
	}

	public function test_it_carries_the_media_condition_down_to_the_rule(): void {
		$rules = $this->reader()->read( '@media (max-width: 600px) { .a { color: red } }', 'main.css' );

		$this->assertSame( '(max-width: 600px)', $rules[0]['media'] );
	}

	public function test_nested_media_conditions_are_joined_rather_than_replaced(): void {
		$css   = '@media screen { @media (max-width: 600px) { .a { color: red } } }';
		$rules = $this->reader()->read( $css, 'main.css' );

		$this->assertSame( 'screen and (max-width: 600px)', $rules[0]['media'] );
	}

	public function test_a_supports_block_is_reported_as_a_condition_not_as_a_media_query(): void {
		$rules = $this->reader()->read( '@supports (display: grid) { .a { color: red } }', 'main.css' );

		$this->assertNull( $rules[0]['media'] );
		$this->assertNotSame( [], $rules[0]['conditions'] );
	}

	public function test_an_import_is_recorded_and_not_followed(): void {
		$reader = $this->reader();
		$rules  = $reader->read( '@import url("other.css"); .a { color: red }', 'main.css' );

		$this->assertSame( [ '.a' ], array_column( $rules, 'selector' ) );
		$this->assertCount( 1, $reader->imports() );
	}

	public function test_a_keyframes_block_contributes_no_rules(): void {
		$css   = '@keyframes spin { from { transform: rotate(0deg) } to { transform: rotate(360deg) } } .a { color: red }';
		$rules = $this->reader()->read( $css, 'main.css' );

		$this->assertSame( [ '.a' ], array_column( $rules, 'selector' ) );
	}

	public function test_a_font_face_block_contributes_no_rules(): void {
		$rules = $this->reader()->read( '@font-face { font-family: X; src: url(x.woff2) } .a { color: red }', 'main.css' );

		$this->assertSame( [ '.a' ], array_column( $rules, 'selector' ) );
	}

	public function test_a_semicolon_inside_a_quoted_value_does_not_end_the_declaration(): void {
		$rules = $this->reader()->read( '.a { content: "a;b"; color: red }', 'main.css' );

		$this->assertSame( [ 'content', 'color' ], array_column( $rules[0]['declarations'], 'property' ) );
	}

	public function test_a_colon_inside_a_url_does_not_split_the_declaration(): void {
		$rules = $this->reader()->read( '.a { background: url(https://example.test/x.png) }', 'main.css' );

		$this->assertSame( 'background', $rules[0]['declarations'][0]['property'] );
		$this->assertStringContainsString( 'https://example.test/x.png', $rules[0]['declarations'][0]['value'] );
	}

	public function test_it_stops_at_the_rule_ceiling_rather_than_reading_a_whole_framework(): void {
		$css = str_repeat( '.a { color: red }', CssRules::MAX_RULES + 50 );

		$this->assertLessThanOrEqual( CssRules::MAX_RULES, count( $this->reader()->read( $css, 'main.css' ) ) );
	}

	public function test_unterminated_css_does_not_hang_or_throw(): void {
		$rules = $this->reader()->read( '.a { color: red', 'main.css' );

		$this->assertIsArray( $rules );
	}

	public function test_empty_css_reads_as_no_rules(): void {
		$this->assertSame( [], $this->reader()->read( '   ', 'main.css' ) );
	}
}
