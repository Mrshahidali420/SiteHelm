<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\ReleaseNotes;
use SiteHelm\Tests\TestCase;

final class ReleaseNotesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The real escaping, not a passthrough: what this class promises is
		// that release notes cannot introduce HTML, and a stub that returns its
		// argument unchanged would let that promise pass untested.
		Functions\when( 'esc_html' )->alias(
			static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'esc_url' )->alias(
			static fn( string $url ): string => 1 === preg_match( '#^https?://#', $url ) ? $url : ''
		);
	}

	public function testAnEmptyBodyRendersNothing(): void {
		$this->assertSame( '', ReleaseNotes::html( '' ) );
	}

	public function testHeadingsBulletsAndParagraphsBecomeTheirTags(): void {
		$html = ReleaseNotes::html(
			"An opening line.\n\n### Fixed\n- One thing.\n- Another thing.\n"
		);

		$this->assertSame(
			'<p>An opening line.</p><h4>Fixed</h4><ul><li>One thing.</li><li>Another thing.</li></ul>',
			$html
		);
	}

	public function testAWrappedBulletStaysOneBullet(): void {
		// Our notes wrap long items across lines rather than writing them out
		// on one; a continuation must not become a paragraph of its own.
		$html = ReleaseNotes::html( "- The first half of a sentence\n  and the second half.\n" );

		$this->assertSame( '<ul><li>The first half of a sentence and the second half.</li></ul>', $html );
	}

	public function testBoldCodeAndLinksAreRendered(): void {
		$html = ReleaseNotes::html( '**Bold**, `code`, and [a link](https://wpsitehelm.com/).' );

		$this->assertSame(
			'<p><strong>Bold</strong>, <code>code</code>, and <a href="https://wpsitehelm.com/">a link</a>.</p>',
			$html
		);
	}

	public function testALinkToAnUnacceptableSchemeKeepsTheWordsAndLosesTheLink(): void {
		$html = ReleaseNotes::html( '[click me](javascript:alert(1))' );

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( 'click me', $html );
	}

	public function testMarkupInTheReleaseBodyIsShownAsWordsNotRun(): void {
		$html = ReleaseNotes::html( '- <script>alert(1)</script> and <b>bold</b>' );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function testAListAndAParagraphDoNotSwallowEachOther(): void {
		$html = ReleaseNotes::html( "- A bullet.\nA following line." );

		$this->assertSame( '<ul><li>A bullet.</li></ul><p>A following line.</p>', $html );
	}
}
