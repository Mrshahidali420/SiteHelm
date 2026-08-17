<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\ContentLinks;
use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: what the site itself can say about the links in its own content.
 *
 * The whole helper answers from the database. There is no HTTP stub in this file
 * and there must never be one: if a test here ever needs to fake a network call,
 * the production code has started making them.
 */
final class ContentLinksTest extends RedirectTestCase {

	private ContentLinks $links;

	/** @var array<string, int> Path to post id, for the url_to_postid double. */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();

		$this->posts = [];
		$this->links = new ContentLinks( $this->store );

		Functions\when( 'url_to_postid' )->alias(
			function ( string $url ): int {
				$path = parse_url( $url, PHP_URL_PATH );
				$path = is_string( $path ) ? $path : '/';

				return $this->posts[ rtrim( $path, '/' ) ] ?? $this->posts[ $path ] ?? 0;
			}
		);
	}

	private function home(): string {
		return rtrim( $this->homeUrl, '/' ) . '/';
	}

	public function test_extracts_every_href_in_document_order(): void {
		$html = '<p><a href="/first">one</a> <a href=\'/second\'>two</a> <a href=/third>three</a></p>';

		$this->assertSame( [ '/first', '/second', '/third' ], $this->links->extract( $html ) );
	}

	public function test_repeats_the_same_link_once(): void {
		$html = '<a href="/same">a</a><a href="/same">b</a>';

		$this->assertSame( [ '/same' ], $this->links->extract( $html ) );
	}

	public function test_decodes_entities_so_one_url_is_one_link(): void {
		$html = '<a href="/search?a=1&amp;b=2">go</a><a href="/search?a=1&b=2">again</a>';

		$this->assertSame( [ '/search?a=1&b=2' ], $this->links->extract( $html ) );
	}

	public function test_ignores_markup_that_is_not_a_link(): void {
		$html = '<img src="/photo.jpg" alt="a"><p>No links here.</p>';

		$this->assertSame( [], $this->links->extract( $html ) );
	}

	public function test_reads_an_href_with_attributes_before_it(): void {
		$html = '<a class="btn" rel="noopener" href="/target" target="_blank">go</a>';

		$this->assertSame( [ '/target' ], $this->links->extract( $html ) );
	}

	public function test_empty_document_has_no_links(): void {
		$this->assertSame( [], $this->links->extract( '   ' ) );
	}

	/**
	 * @dataProvider provide_kinds
	 *
	 * @param string $url      The link as written.
	 * @param string $expected The expected kind.
	 */
	public function test_classifies_the_kind_of_a_link( string $url, string $expected ): void {
		$this->assertSame( $expected, $this->links->kindOf( $url, $this->home() ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_kinds(): array {
		return [
			'fragment only'      => [ '#section', ContentLinks::KIND_OTHER ],
			'mail'               => [ 'mailto:hi@example.test', ContentLinks::KIND_OTHER ],
			'telephone'          => [ 'tel:+15551234', ContentLinks::KIND_OTHER ],
			'script uri'         => [ 'javascript:alert(1)', ContentLinks::KIND_OTHER ],
			'data uri'           => [ 'data:text/plain,x', ContentLinks::KIND_OTHER ],
			'ftp'                => [ 'ftp://files.test/x', ContentLinks::KIND_OTHER ],
			'own host'           => [ 'https://example.test/about', ContentLinks::KIND_INTERNAL ],
			'own host with www'  => [ 'https://www.example.test/about', ContentLinks::KIND_INTERNAL ],
			'own host uppercase' => [ 'https://EXAMPLE.test/about', ContentLinks::KIND_INTERNAL ],
			'another host'       => [ 'https://other.test/about', ContentLinks::KIND_EXTERNAL ],
			'protocol relative'  => [ '//other.test/about', ContentLinks::KIND_EXTERNAL ],
			'own protocol rel'   => [ '//example.test/about', ContentLinks::KIND_INTERNAL ],
			'absolute path'      => [ '/about', ContentLinks::KIND_INTERNAL ],
			'relative path'      => [ 'about/team', ContentLinks::KIND_INTERNAL ],
		];
	}

	public function test_drops_the_query_and_fragment_from_a_path(): void {
		$this->assertSame( '/about', $this->links->pathOf( '/about?utm=1#team', $this->home() ) );
		$this->assertSame(
			'/about',
			$this->links->pathOf( 'https://example.test/about?utm=1#team', $this->home() )
		);
	}

	public function test_a_relative_link_becomes_a_rooted_path(): void {
		$this->assertSame( '/about/team', $this->links->pathOf( 'about/team', $this->home() ) );
	}

	public function test_strips_the_subdirectory_a_site_is_installed_under(): void {
		$this->homeUrl = 'https://example.test/blog';

		$this->assertSame( '/about', $this->links->pathOf( 'https://example.test/blog/about', $this->home() ) );
		$this->assertSame( '/', $this->links->pathOf( 'https://example.test/blog', $this->home() ) );
	}

	public function test_the_site_root_is_a_path(): void {
		$this->assertSame( '/', $this->links->pathOf( 'https://example.test/', $this->home() ) );
	}

	public function test_a_link_resolving_to_a_post_is_ok(): void {
		$this->posts['/about'] = 42;

		$record = $this->links->classify( '/about', $this->home() );

		$this->assertSame( ContentLinks::STATUS_OK, $record['status'] );
		$this->assertSame( 42, $record['targetId'] );
		$this->assertSame( ContentLinks::KIND_INTERNAL, $record['kind'] );
		$this->assertSame( '/about', $record['path'] );
	}

	public function test_a_link_resolving_to_nothing_is_broken(): void {
		$record = $this->links->classify( '/gone-forever', $this->home() );

		$this->assertSame( ContentLinks::STATUS_BROKEN, $record['status'] );
		$this->assertArrayNotHasKey( 'targetId', $record );
	}

	public function test_a_link_a_redirect_catches_is_reported_as_a_redirect(): void {
		$this->seed( [ $this->row( '/old', '/new', 301, true ) ] );

		$record = $this->links->classify( '/old?utm=1', $this->home() );

		$this->assertSame( ContentLinks::STATUS_REDIRECT, $record['status'] );
		$this->assertSame( 301, $record['code'] );
		$this->assertSame( '/new', $record['goesTo'] );
	}

	public function test_a_retired_path_is_reported_as_gone(): void {
		$this->seed( [ $this->row( '/retired', null, RedirectStore::STATUS_GONE, false ) ] );

		$record = $this->links->classify( '/retired', $this->home() );

		$this->assertSame( ContentLinks::STATUS_GONE, $record['status'] );
		$this->assertNull( $record['goesTo'] );
		$this->assertSame( RedirectStore::STATUS_GONE, $record['code'] );
	}

	/**
	 * The router runs on template_redirect, which fires AFTER the query has
	 * found a post — so a redirect row wins over the post at its own path. The
	 * report says what happens, not what ought to.
	 */
	public function test_a_redirect_beats_a_live_post_at_the_same_path(): void {
		$this->posts['/old']     = 42;
		$this->seed( [ $this->row( '/old', '/new', 302, false ) ] );

		$record = $this->links->classify( '/old', $this->home() );

		$this->assertSame( ContentLinks::STATUS_REDIRECT, $record['status'] );
		$this->assertArrayNotHasKey( 'targetId', $record );
	}

	public function test_an_external_link_is_listed_and_never_resolved(): void {
		$record = $this->links->classify( 'https://other.test/page', $this->home() );

		$this->assertSame( ContentLinks::KIND_EXTERNAL, $record['kind'] );
		$this->assertSame( ContentLinks::STATUS_UNCHECKED, $record['status'] );
		$this->assertArrayNotHasKey( 'path', $record );
	}

	public function test_a_mail_link_is_listed_and_never_resolved(): void {
		$record = $this->links->classify( 'mailto:hi@example.test', $this->home() );

		$this->assertSame( ContentLinks::KIND_OTHER, $record['kind'] );
		$this->assertSame( ContentLinks::STATUS_UNCHECKED, $record['status'] );
	}

	public function test_the_front_page_answers_even_without_a_post(): void {
		$record = $this->links->classify( '/', $this->home() );

		$this->assertSame( ContentLinks::STATUS_OK, $record['status'] );
	}

	public function test_classifying_writes_nothing(): void {
		$this->seed( [ $this->row( '/old' ) ] );

		$this->links->classify( '/old', $this->home() );
		$this->links->classify( '/other', $this->home() );

		$this->assertSame( [], $this->writes, 'A report must not write to the site.' );
	}
}
