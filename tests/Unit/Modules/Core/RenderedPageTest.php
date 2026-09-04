<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\ContentLinks;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Modules\Core\RenderedPage;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0108: the reading half, exercised against fixture markup with no HTTP
 * anywhere in the file.
 *
 * Every assertion here is about a page that has already been fetched, so a
 * failure names a fault in how the markup was read and never in how it was
 * requested. The fetching half is pinned separately in ContentRenderedReadTest.
 */
final class RenderedPageTest extends TestCase {

	private const HOME = 'https://example.test/';

	private RenderedPage $reader;

	private ContentLinks $links;

	protected function setUp(): void {
		parent::setUp();

		$this->reader = new RenderedPage();
		$this->links  = new ContentLinks( new RedirectStore() );

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'get_option' )->justReturn( [] );
	}

	/**
	 * @param string $html The markup to read.
	 *
	 * @return array<string, mixed> The reading.
	 */
	private function read( string $html ): array {
		return $this->reader->summarize( $html, self::HOME, $this->links );
	}

	public function test_an_empty_body_still_answers_with_every_member(): void {
		$this->assertSame( RenderedPage::emptyRecord(), $this->read( '   ' ) );
	}

	public function test_it_reads_the_head_tags_a_search_engine_reads(): void {
		$record = $this->read(
			'<html lang="en-GB"><head><title>  Landing   page </title>'
			. '<meta name="Description" content="What the page is about">'
			. '<meta name="robots" content="noindex, follow">'
			. '<link rel="Canonical" href="https://example.test/landing/">'
			. '</head><body></body></html>'
		);

		$this->assertSame( 'en-GB', $record['lang'] );
		$this->assertSame( 'Landing page', $record['title'] );
		$this->assertSame( 'What the page is about', $record['metaDescription'] );
		$this->assertSame( 'noindex, follow', $record['robots'] );
		$this->assertSame( 'https://example.test/landing/', $record['canonical'] );
	}

	/**
	 * A tag the page does not emit and a tag it emits empty are different
	 * findings: one is missing, the other is broken.
	 */
	public function test_a_missing_tag_is_null_and_an_empty_one_is_the_empty_string(): void {
		$record = $this->read( '<html><head><title></title></head><body></body></html>' );

		$this->assertSame( '', $record['title'] );
		$this->assertNull( $record['metaDescription'] );
		$this->assertNull( $record['canonical'] );
		$this->assertNull( $record['robots'] );
		$this->assertNull( $record['lang'] );
	}

	public function test_it_collects_the_social_tags_under_their_own_families(): void {
		$record = $this->read(
			'<html><head>'
			. '<meta property="og:title" content="First">'
			. '<meta property="OG:Title" content="Second">'
			. '<meta property="og:image" content="https://example.test/a.png">'
			. '<meta name="twitter:card" content="summary">'
			. '<meta name="description" content="not social">'
			. '</head><body></body></html>'
		);

		$this->assertSame(
			[
				'og:title' => 'First',
				'og:image' => 'https://example.test/a.png',
			],
			$record['openGraph']
		);
		$this->assertSame( [ 'twitter:card' => 'summary' ], $record['twitter'] );
	}

	public function test_the_heading_outline_keeps_document_order(): void {
		$record = $this->read(
			'<body><h1>Top</h1><h2>One</h2><h3>Deep</h3><h2>Two</h2></body>'
		);

		$this->assertSame(
			[
				[
					'level' => 1,
					'text'  => 'Top',
				],
				[
					'level' => 2,
					'text'  => 'One',
				],
				[
					'level' => 3,
					'text'  => 'Deep',
				],
				[
					'level' => 2,
					'text'  => 'Two',
				],
			],
			$record['headings']
		);
		$this->assertFalse( $record['headingsTruncated'] );
		$this->assertSame( 1, $record['h1Count'] );
	}

	/**
	 * "Is there exactly one H1" is the question this figure exists to answer,
	 * so a cap on the reported outline must not be able to hide the second one.
	 */
	public function test_every_h1_is_counted_even_past_the_outline_cap(): void {
		$record = $this->read( '<body>' . str_repeat( '<h1>x</h1>', RenderedPage::MAX_HEADINGS + 5 ) . '</body>' );

		$this->assertCount( RenderedPage::MAX_HEADINGS, $record['headings'] );
		$this->assertTrue( $record['headingsTruncated'] );
		$this->assertSame( RenderedPage::MAX_HEADINGS + 5, $record['h1Count'] );
	}

	public function test_an_absent_alt_and_an_empty_one_are_both_missing(): void {
		$record = $this->read(
			'<body><img src="a.png" alt="Described"><img src="b.png"><img src="c.png" alt="   "></body>'
		);

		$this->assertSame( 3, $record['imageCount'] );
		$this->assertSame( 2, $record['imagesMissingAlt'] );
	}

	public function test_links_are_split_by_whether_they_lead_back_here(): void {
		$record = $this->read(
			'<body><a href="/about">a</a><a href="https://example.test/x">b</a>'
			. '<a href="https://elsewhere.test/y">c</a><a href="mailto:hi@example.test">d</a>'
			. '<a href="">e</a></body>'
		);

		$this->assertSame( 4, $record['linkCount'] );
		$this->assertSame( 2, $record['internalLinkCount'] );
		$this->assertSame( 1, $record['externalLinkCount'] );
	}

	public function test_the_word_count_ignores_what_a_visitor_never_reads(): void {
		$record = $this->read(
			'<body><script>var one = two;</script><style>.a{b:c}</style>'
			. '<noscript>hidden words here</noscript><p>Four visible words only</p></body>'
		);

		$this->assertSame( 4, $record['wordCount'] );
	}

	/**
	 * A truncated body is the normal case once the fetch ceiling bites, so an
	 * unclosed tag has to read as far as it can rather than throwing.
	 */
	public function test_markup_that_stops_mid_tag_still_reads(): void {
		$record = $this->read( '<html><head><title>Cut</title></head><body><p>Words here<div class="' );

		$this->assertSame( 'Cut', $record['title'] );
	}

	/**
	 * LIBXML_NONET is off the table and LIBXML_NOENT is never passed, so a
	 * declared entity is left alone rather than expanded into the reading.
	 */
	public function test_the_parser_refuses_network_and_leaves_entities_alone(): void {
		$record = $this->read(
			'<!DOCTYPE html [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<html><head><title>&xxe;</title></head><body></body></html>'
		);

		$this->assertIsNotArray( $record['title'] );
		$this->assertStringNotContainsString( 'root:', (string) $record['title'] );
	}
}
