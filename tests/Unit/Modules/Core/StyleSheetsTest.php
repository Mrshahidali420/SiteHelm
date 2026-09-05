<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\StyleSheets;
use SiteHelm\Tests\TestCase;

/**
 * Finding the style a page carries.
 *
 * The load-bearing assertions are the two that decide what gets fetched. A
 * sheet on another host must come back marked off-site, because this class is
 * the only thing between "read this page" and "make a request to whatever host
 * the markup names"; and a `data:` or `javascript:` href must resolve to
 * nothing at all rather than to a candidate address.
 */
final class StyleSheetsTest extends TestCase {

	private const PAGE = 'https://example.test/blog/post/';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
	}

	private function sheets(): StyleSheets {
		return new StyleSheets();
	}

	/**
	 * @param string $body The markup inside the document.
	 *
	 * @return array<int, array<string, mixed>> The sources found.
	 */
	private function collect( string $body ): array {
		return $this->sheets()->collect( '<html><head>' . $body . '</head><body></body></html>', self::PAGE, 'example.test' );
	}

	public function test_it_reports_an_inline_block_with_its_css(): void {
		$found = $this->collect( '<style>.a { color: red }</style>' );

		$this->assertCount( 1, $found );
		$this->assertSame( 'inline', $found[0]['type'] );
		$this->assertNull( $found[0]['url'] );
		$this->assertStringContainsString( 'color: red', (string) $found[0]['css'] );
		$this->assertTrue( $found[0]['sameSite'] );
	}

	public function test_an_empty_inline_block_is_not_reported(): void {
		$this->assertSame( [], $this->collect( '<style>   </style>' ) );
	}

	public function test_it_reports_a_linked_sheet_on_this_site_as_one_to_fetch(): void {
		$found = $this->collect( '<link rel="stylesheet" href="/wp-content/theme/style.css">' );

		$this->assertSame( 'link', $found[0]['type'] );
		$this->assertSame( 'https://example.test/wp-content/theme/style.css', $found[0]['url'] );
		$this->assertNull( $found[0]['css'] );
		$this->assertTrue( $found[0]['sameSite'] );
	}

	public function test_a_sheet_on_another_host_is_reported_and_marked_off_site(): void {
		$found = $this->collect( '<link rel="stylesheet" href="https://fonts.example.com/x.css">' );

		$this->assertCount( 1, $found );
		$this->assertFalse( $found[0]['sameSite'] );
		$this->assertNull( $found[0]['css'] );
	}

	public function test_a_link_that_is_not_a_stylesheet_is_ignored(): void {
		$body = '<link rel="preload" href="/a.css"><link rel="icon" href="/i.png">'
			. '<link rel="dns-prefetch" href="https://fonts.example.com">';

		$this->assertSame( [], $this->collect( $body ) );
	}

	public function test_sources_come_back_in_document_order_because_order_decides_the_cascade(): void {
		$body = '<link rel="stylesheet" href="/one.css"><style>.a{color:red}</style>'
			. '<link rel="stylesheet" href="/two.css">';

		$found = $this->collect( $body );

		$this->assertSame( [ 'link', 'inline', 'link' ], array_column( $found, 'type' ) );
		$this->assertSame( 'https://example.test/one.css', $found[0]['url'] );
		$this->assertSame( 'https://example.test/two.css', $found[2]['url'] );
	}

	public function test_a_protocol_relative_href_takes_the_page_scheme(): void {
		$this->assertSame(
			'https://cdn.example.com/x.css',
			$this->sheets()->absolute( '//cdn.example.com/x.css', self::PAGE )
		);
	}

	public function test_a_relative_href_resolves_against_the_page_directory(): void {
		$this->assertSame(
			'https://example.test/blog/post/style.css',
			$this->sheets()->absolute( 'style.css', self::PAGE )
		);
	}

	public function test_a_root_relative_href_resolves_against_the_host(): void {
		$this->assertSame(
			'https://example.test/style.css',
			$this->sheets()->absolute( '/style.css', self::PAGE )
		);
	}

	public function test_a_scheme_that_is_not_http_resolves_to_nothing(): void {
		$this->assertNull( $this->sheets()->absolute( 'data:text/css,.a{}', self::PAGE ) );
		$this->assertNull( $this->sheets()->absolute( 'javascript:alert(1)', self::PAGE ) );
		$this->assertNull( $this->sheets()->absolute( 'file:///etc/passwd', self::PAGE ) );
	}

	public function test_markup_that_will_not_parse_reads_as_no_sources(): void {
		$this->assertSame( [], $this->sheets()->collect( '   ', self::PAGE, 'example.test' ) );
	}

	public function test_malformed_markup_still_yields_the_sheets_it_does_carry(): void {
		$html  = '<html><head><link rel="stylesheet" href="/a.css"><p><div></head>';
		$found = $this->sheets()->collect( $html, self::PAGE, 'example.test' );

		$this->assertSame( 'https://example.test/a.css', $found[0]['url'] );
	}
}
