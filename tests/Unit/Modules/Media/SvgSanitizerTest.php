<?php
/**
 * Tests for the SVG sanitiser.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Media\SvgSanitizer;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0105: the unit that decides what an SVG may contain.
 *
 * These tests are the whole security argument for `media-svg-upload`. Each one
 * asserts on the OUTPUT DOCUMENT rather than on a warning, because a sanitiser
 * that reports a removal it did not make is worse than one that says nothing.
 */
final class SvgSanitizerTest extends TestCase {

	private SvgSanitizer $sanitizer;

	protected function setUp(): void {
		parent::setUp();

		$this->sanitizer = new SvgSanitizer();
	}

	/**
	 * One document with the given body inside an svg root.
	 *
	 * @param string $body       The child markup.
	 * @param string $attributes Extra attributes on the root.
	 *
	 * @return string The document.
	 */
	private function svg( string $body, string $attributes = '' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"' . $attributes . '>' . $body . '</svg>';
	}

	private function path(): string {
		return '<path d="M4 12l6 6L20 6" fill="none" stroke="currentColor"/>';
	}

	public function test_an_ordinary_icon_passes_through_intact(): void {
		$result = $this->sanitizer->sanitize( $this->svg( $this->path() ) );

		$this->assertStringContainsString( '<path', $result['svg'] );
		$this->assertStringContainsString( 'd="M4 12l6 6L20 6"', $result['svg'] );
		$this->assertSame( [], $result['warnings'] );
		$this->assertSame( [], $result['removedElements'] );
		$this->assertSame( [], $result['removedAttributes'] );
	}

	public function test_a_script_element_is_removed_and_reported(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<script>alert(1)</script>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'script', $result['svg'] );
		$this->assertStringNotContainsString( 'alert', $result['svg'] );
		$this->assertSame( [ 'script' ], $result['removedElements'] );
		$this->assertStringContainsString( 'script', $result['warnings'][0] );
	}

	/**
	 * A `<script>` nested inside a permitted element is the case a sanitiser
	 * that only looks at the root's direct children would miss.
	 */
	public function test_a_script_nested_below_a_permitted_element_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<g><g>' . $this->path() . '<script>alert(1)</script></g></g>' )
		);

		$this->assertStringNotContainsString( 'alert', $result['svg'] );
		$this->assertStringContainsString( '<path', $result['svg'] );
	}

	/**
	 * Removing a node from a live DOMNodeList while iterating it skips the node
	 * that follows. Two adjacent scripts is the shape that catches it: a walk
	 * with that defect removes the first and leaves the second.
	 */
	public function test_two_adjacent_disallowed_elements_are_both_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<script>a()</script><script>b()</script>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'a()', $result['svg'] );
		$this->assertStringNotContainsString( 'b()', $result['svg'] );
	}

	public function test_foreign_object_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<foreignObject><b xmlns="http://www.w3.org/1999/xhtml">hi</b></foreignObject>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'foreignObject', $result['svg'] );
		$this->assertSame( [ 'foreignObject' ], $result['removedElements'] );
	}

	public function test_a_style_element_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<style>@import url(https://evil.test/x.css);</style>' . $this->path() )
		);

		$this->assertStringNotContainsString( '@import', $result['svg'] );
	}

	public function test_the_image_element_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<image href="https://evil.test/pixel.png"/>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'evil.test', $result['svg'] );
	}

	public function test_an_animation_element_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<set attributeName="href" to="javascript:alert(1)"/>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'javascript', $result['svg'] );
	}

	public function test_an_event_handler_attribute_is_removed_and_reported(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<circle cx="1" cy="1" r="1" onload="alert(1)"/>' )
		);

		$this->assertStringNotContainsString( 'onload', $result['svg'] );
		$this->assertStringContainsString( '<circle', $result['svg'] );
		$this->assertSame( [ 'onload' ], $result['removedAttributes'] );
	}

	public function test_an_event_handler_on_the_root_element_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( $this->path(), ' onload="alert(1)"' )
		);

		$this->assertStringNotContainsString( 'onload', $result['svg'] );
	}

	/**
	 * A reference may point inside the document and nowhere else. This is one
	 * rule rather than a list of schemes, so it covers the scheme nobody has
	 * thought of yet.
	 */
	public function test_a_fragment_reference_survives_and_an_external_one_does_not(): void {
		$kept = $this->sanitizer->sanitize(
			$this->svg( '<defs><path id="a" d="M0 0"/></defs><use href="#a"/>' )
		);
		$this->assertStringContainsString( 'href="#a"', $kept['svg'] );

		$dropped = $this->sanitizer->sanitize(
			$this->svg( '<use href="https://evil.test/x.svg#a"/>' . $this->path() )
		);
		$this->assertStringNotContainsString( 'evil.test', $dropped['svg'] );
	}

	public function test_a_javascript_scheme_reference_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<use href="javascript:alert(1)"/>' . $this->path() )
		);

		$this->assertStringNotContainsString( 'javascript', $result['svg'] );
	}

	/**
	 * The obfuscation that a naive substring check misses: a newline inside the
	 * scheme, which the browser ignores and a comparison does not.
	 */
	public function test_a_script_scheme_split_by_whitespace_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<path d="M0 0" fill="java&#10;script:alert(1)"/>' )
		);

		$this->assertStringNotContainsString( 'alert', $result['svg'] );
	}

	public function test_a_plain_style_attribute_survives(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<circle cx="1" cy="1" r="1" style="fill:#f00;opacity:.5"/>' )
		);

		$this->assertStringContainsString( 'fill:#f00', $result['svg'] );
		$this->assertSame( [], $result['removedAttributes'] );
	}

	public function test_a_style_attribute_that_fetches_is_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<circle cx="1" cy="1" r="1" style="fill:url(https://evil.test/x)"/>' )
		);

		$this->assertStringNotContainsString( 'evil.test', $result['svg'] );
		$this->assertSame( [ 'style' ], $result['removedAttributes'] );
	}

	public function test_comments_and_processing_instructions_are_removed(): void {
		$result = $this->sanitizer->sanitize(
			$this->svg( '<!-- smuggled -->' . $this->path() )
		);

		$this->assertStringNotContainsString( 'smuggled', $result['svg'] );
	}

	/**
	 * The check that runs on the raw text. A parser that has already read an
	 * entity declaration has already done what it asked for, so this refusal
	 * must not depend on the parse.
	 */
	public function test_an_entity_declaration_is_refused_rather_than_cleaned(): void {
		$this->expectException( OperationException::class );

		try {
			$this->sanitizer->sanitize(
				'<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
				. $this->svg( '<text>&xxe;</text>' )
			);
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'document type', $e->getMessage() );
			throw $e;
		}
	}

	public function test_a_document_that_is_not_an_svg_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'not an SVG image' );

		$this->sanitizer->sanitize( '<html><body>hello</body></html>' );
	}

	public function test_content_that_is_not_well_formed_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'well-formed' );

		$this->sanitizer->sanitize( '<svg><path d="M0 0"' );
	}

	public function test_an_empty_submission_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'empty' );

		$this->sanitizer->sanitize( "   \n " );
	}

	public function test_a_document_over_the_ceiling_is_refused_before_it_is_parsed(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'larger than this site accepts' );

		$this->sanitizer->sanitize(
			$this->svg( '<path d="' . str_repeat( 'M0 0', SvgSanitizer::MAX_BYTES ) . '"/>' )
		);
	}

	/**
	 * A file whose only content was the part that had to go is refused. Storing
	 * the empty canvas that remains would report a success for a change that did
	 * not do what was asked.
	 */
	public function test_a_document_with_nothing_drawable_left_is_refused(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionMessage( 'Nothing this site can store' );

		$this->sanitizer->sanitize( $this->svg( '<title>Icon</title><script>alert(1)</script>' ) );
	}

	/**
	 * The sanitiser runs inside planChange(), which the engine executes at
	 * preview and again at apply. Two different answers for one input would make
	 * the approved plan and the applied one disagree.
	 */
	public function test_the_same_document_sanitizes_to_the_same_bytes_every_time(): void {
		$svg = $this->svg( '<script>alert(1)</script><g onclick="x()">' . $this->path() . '</g>' );

		$this->assertSame(
			$this->sanitizer->sanitize( $svg ),
			$this->sanitizer->sanitize( $svg )
		);
	}

	/**
	 * A machine-generated file can carry hundreds of stray attributes. The
	 * warning is for a person to read.
	 */
	public function test_a_long_list_of_removals_is_capped_in_the_warning(): void {
		$attributes = '';
		for ( $i = 0; $i < 14; $i++ ) {
			$attributes .= sprintf( ' onx%02d="a()"', $i );
		}

		$result = $this->sanitizer->sanitize( $this->svg( '<circle cx="1" cy="1" r="1"' . $attributes . '/>' ) );

		$this->assertCount( 14, $result['removedAttributes'] );
		$this->assertStringContainsString( 'and 4 more', $result['warnings'][0] );
	}
}
