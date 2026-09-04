<?php
/**
 * Pins the shape Elementor's atomic rich-text props are stored in.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use PHPUnit\Framework\TestCase;
use SiteHelm\Modules\Elementor\ElementorRichText;

/**
 * The class that stops a text update leaving a widget uneditable.
 *
 * TWO FAILURES ARE PINNED HERE AND THEY FAIL DIFFERENTLY. Writing the words as
 * a bare string produces a value Elementor's editor throws on the next time
 * anybody presses update — the page still renders, so nothing reports it.
 * Writing them as a correct envelope with the `children` tree thrown away
 * renders and saves perfectly, and quietly deletes the links and bold runs
 * somebody put in the paragraph. Each has a test whose failure names which one
 * came back.
 */
final class ElementorRichTextTest extends TestCase {

	/**
	 * A stored value carrying words and an editor tree.
	 *
	 * @param string            $content  The words.
	 * @param array<int, mixed> $children The editor tree.
	 *
	 * @return array<string, mixed> The stored value.
	 */
	private function stored( string $content, array $children ): array {
		return [
			'$$type' => ElementorRichText::TYPE_HTML_V3,
			'value'  => [
				ElementorRichText::KEY_CONTENT  => [
					'$$type' => ElementorRichText::TYPE_STRING,
					'value'  => $content,
				],
				ElementorRichText::KEY_CHILDREN => $children,
			],
		];
	}

	/**
	 * An editor tree with one link in it.
	 *
	 * @return array<int, mixed> The children.
	 */
	private function link_children(): array {
		return [
			[
				'type'  => 'link',
				'props' => [ 'href' => 'https://example.com/' ],
				'range' => [ 4, 11 ],
			],
		];
	}

	/**
	 * A bare string becomes the nested shape the editor requires.
	 *
	 * This is the case that produced "Settings validation failed" on the first
	 * edit after a write: the words were stored where the editor expects an
	 * object.
	 */
	public function test_a_bare_string_is_shaped_into_the_stored_form(): void {
		$shaped = ElementorRichText::shape( ElementorRichText::TYPE_HTML_V3, 'Our services' );

		$this->assertSame(
			[
				'$$type' => ElementorRichText::TYPE_HTML_V3,
				'value'  => [
					ElementorRichText::KEY_CONTENT  => [
						'$$type' => ElementorRichText::TYPE_STRING,
						'value'  => 'Our services',
					],
					ElementorRichText::KEY_CHILDREN => [],
				],
			],
			$shaped,
			'A bare string has to reach the document as the object the editor parses.'
		);
	}

	/**
	 * The stored editor tree survives an update that only changes the words.
	 *
	 * The expensive silent failure: the page renders, the write reports
	 * success, and the links in the paragraph are gone.
	 */
	public function test_stored_children_survive_a_bare_string_update(): void {
		$shaped = ElementorRichText::shape(
			ElementorRichText::TYPE_HTML_V3,
			'Call our team today',
			$this->stored( 'Call us today', $this->link_children() )
		);

		$this->assertSame(
			$this->link_children(),
			$shaped['value'][ ElementorRichText::KEY_CHILDREN ],
			'Nobody asked for the formatting to be discarded, so it stays.'
		);
		$this->assertSame(
			'Call our team today',
			$shaped['value'][ ElementorRichText::KEY_CONTENT ]['value'],
			'The words are still the ones the caller asked for.'
		);
	}

	/**
	 * Children the request names win over the ones the document holds.
	 *
	 * A caller reproducing the editor's tree deliberately is not to be
	 * second-guessed.
	 */
	public function test_requested_children_replace_the_stored_tree(): void {
		$shaped = ElementorRichText::shape(
			ElementorRichText::TYPE_HTML_V3,
			[
				ElementorRichText::KEY_CONTENT  => 'Call us today',
				ElementorRichText::KEY_CHILDREN => [],
			],
			$this->stored( 'Call us today', $this->link_children() )
		);

		$this->assertSame(
			[],
			$shaped['value'][ ElementorRichText::KEY_CHILDREN ],
			'An explicitly empty tree is a request to clear the formatting.'
		);
	}

	/**
	 * A string envelope is lifted rather than nested a second time.
	 */
	public function test_a_string_envelope_is_lifted_into_the_content_member(): void {
		$shaped = ElementorRichText::shape(
			ElementorRichText::TYPE_HTML_V3,
			[
				'$$type' => ElementorRichText::TYPE_STRING,
				'value'  => 'Our services',
			]
		);

		$this->assertSame(
			'Our services',
			$shaped['value'][ ElementorRichText::KEY_CONTENT ]['value'],
			'The words arrive already enveloped often enough to be worth reading.'
		);
	}

	/**
	 * Shaping an already-shaped value changes nothing.
	 *
	 * `planChange()` runs twice over the same tree and the two runs have to
	 * agree, or the write is refused as non-deterministic.
	 */
	public function test_shaping_is_idempotent(): void {
		$once  = ElementorRichText::shape( ElementorRichText::TYPE_HTML_V3, 'Our services' );
		$twice = ElementorRichText::shape( ElementorRichText::TYPE_HTML_V3, $once );

		$this->assertSame( $once, $twice, 'The plan pass and the apply pass have to produce the same tree.' );
	}

	/**
	 * An unreadable request keeps the words the document already holds.
	 *
	 * A value this class cannot read is not a request to empty the heading, and
	 * treating it as one would delete content while reporting success.
	 */
	public function test_an_unreadable_request_falls_back_to_the_stored_words(): void {
		$shaped = ElementorRichText::shape(
			ElementorRichText::TYPE_HTML_V3,
			[ 'unexpected' => true ],
			$this->stored( 'Call us today', [] )
		);

		$this->assertSame(
			'Call us today',
			$shaped['value'][ ElementorRichText::KEY_CONTENT ]['value'],
			'Silence is not an instruction to erase.'
		);
	}

	/**
	 * An empty string asked for deliberately is honoured.
	 *
	 * The other half of the fallback above: emptying a heading is a thing an
	 * operator is allowed to do.
	 */
	public function test_an_empty_string_is_written_as_asked(): void {
		$shaped = ElementorRichText::shape(
			ElementorRichText::TYPE_HTML_V3,
			'',
			$this->stored( 'Call us today', [] )
		);

		$this->assertSame( '', $shaped['value'][ ElementorRichText::KEY_CONTENT ]['value'], 'An explicit empty string is a request.' );
	}

	/**
	 * The predecessor prop type is shaped the same way.
	 *
	 * `html-v2` is the stricter validator of the two, so a value it accepts is
	 * accepted by `html-v3` as well.
	 */
	public function test_the_predecessor_type_is_shaped_too(): void {
		$this->assertTrue( ElementorRichText::isRichText( ElementorRichText::TYPE_HTML_V2 ) );

		$shaped = ElementorRichText::shape( ElementorRichText::TYPE_HTML_V2, 'Our services' );

		$this->assertSame( ElementorRichText::TYPE_HTML_V2, $shaped['$$type'], 'The declared type is carried, not replaced.' );
		$this->assertArrayHasKey(
			ElementorRichText::KEY_CHILDREN,
			$shaped['value'],
			'`html-v2` requires the member to exist even when it is empty.'
		);
	}

	/**
	 * Types this class does not own are not claimed.
	 *
	 * The shaping is destructive to anything that is not rich text, so the
	 * predicate is what keeps it away from the rest of the schema.
	 */
	public function test_other_prop_types_are_not_claimed(): void {
		$this->assertFalse( ElementorRichText::isRichText( 'string' ) );
		$this->assertFalse( ElementorRichText::isRichText( 'image' ) );
		$this->assertFalse( ElementorRichText::isRichText( '' ) );
	}

	/**
	 * The readers see through every form the shaper accepts.
	 */
	public function test_the_readers_read_each_accepted_form(): void {
		$value = $this->stored( 'Call us today', $this->link_children() );

		$this->assertSame( 'Call us today', ElementorRichText::content( $value ) );
		$this->assertSame( $this->link_children(), ElementorRichText::children( $value ) );
		$this->assertSame( 'Our services', ElementorRichText::content( 'Our services' ) );
		$this->assertSame( [], ElementorRichText::children( 'Our services' ), 'A bare string carries no tree.' );
		$this->assertNull( ElementorRichText::content( null ), 'An absent value holds no words.' );
	}
}
