<?php
/**
 * Tests for PublicUrl.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use SiteHelm\Auth\PublicUrl;

/**
 * Tests the one class allowed to decide what this site's public address is.
 */
final class PublicUrlTest extends AuthTestCase {

	public function test_without_an_override_the_site_address_is_wordpress_own(): void {
		$this->assertSame( 'https://example.com', ( new PublicUrl() )->base() );
	}

	/**
	 * The whole reason this class exists: behind a proxy, a tunnel or a rename,
	 * `home_url()` is not the address a client can reach, and every URL the
	 * plugin publishes has to follow the override rather than WordPress.
	 */
	public function test_the_override_outranks_home_url_everywhere(): void {
		$this->options[ PublicUrl::OPTION ] = 'https://tunnel.example.net';

		$urls = new PublicUrl();

		$this->assertSame( 'https://tunnel.example.net', $urls->base() );
		$this->assertSame( 'https://tunnel.example.net/wp-json/sitehelm/v1/mcp', $urls->mcpEndpoint() );
		$this->assertSame( 'https://tunnel.example.net/wp-json/sitehelm/v1/oauth/token', $urls->restUrl( 'oauth/token' ) );
		$this->assertStringStartsWith( 'https://tunnel.example.net/wp-admin/admin-post.php', $urls->authorizeUrl() );
	}

	/**
	 * A subdirectory install is where the naive version of this breaks: the
	 * install path has to survive into the published URLs, and be readable on its
	 * own so discovery can strip it off an incoming request path.
	 */
	public function test_a_subdirectory_install_keeps_its_path(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );
		Functions\when( 'rest_url' )->alias(
			static fn( string $route = '' ): string => 'https://example.com/blog/wp-json/' . ltrim( $route, '/' )
		);

		$urls = new PublicUrl();

		$this->assertSame( '/blog', $urls->path() );
		$this->assertSame( 'https://example.com/blog/wp-json/sitehelm/v1/mcp', $urls->resource() );
	}

	public function test_rebase_moves_a_wordpress_url_onto_the_override(): void {
		$this->options[ PublicUrl::OPTION ] = 'https://tunnel.example.net';

		$this->assertSame(
			'https://tunnel.example.net/?rest_route=/sitehelm/v1/mcp',
			( new PublicUrl() )->rebase( 'https://example.com/?rest_route=/sitehelm/v1/mcp' )
		);
	}

	public function test_rebase_leaves_a_foreign_url_alone(): void {
		$this->options[ PublicUrl::OPTION ] = 'https://tunnel.example.net';

		$this->assertSame(
			'https://cdn.example.org/thing.png',
			( new PublicUrl() )->rebase( 'https://cdn.example.org/thing.png' )
		);
	}

	public function test_plain_http_is_not_secure(): void {
		$this->options[ PublicUrl::OPTION ] = 'http://example.com';

		$this->assertFalse( ( new PublicUrl() )->isSecure() );
	}

	/**
	 * A developer machine cannot hold a public certificate, and refusing there
	 * would stop people building against this without protecting anybody.
	 */
	public function test_a_local_address_counts_as_secure_over_plain_http(): void {
		foreach ( [ 'http://localhost:8080', 'http://mysite.test', 'http://127.0.0.1' ] as $address ) {
			$this->options[ PublicUrl::OPTION ] = $address;

			$this->assertTrue( ( new PublicUrl() )->isSecure(), $address );
		}
	}

	/**
	 * `.dev` is a real, HSTS-preloaded TLD. Treating it as local would hand a
	 * clear-text token to a genuinely public site.
	 */
	public function test_dot_dev_is_not_treated_as_local(): void {
		$this->options[ PublicUrl::OPTION ] = 'http://mysite.dev';

		$this->assertFalse( ( new PublicUrl() )->isSecure() );
	}

	public function test_save_normalises_and_refuses_what_it_cannot_use(): void {
		$urls = new PublicUrl();

		$this->assertTrue( $urls->save( 'HTTPS://Example.COM/Blog/' ) );
		$this->assertSame( 'https://example.com/Blog', $this->options[ PublicUrl::OPTION ] );

		$this->assertFalse( $urls->save( 'ftp://example.com' ) );
		$this->assertFalse( $urls->save( 'not a url' ) );
		$this->assertSame( 'https://example.com/Blog', $this->options[ PublicUrl::OPTION ] );
	}

	public function test_saving_an_empty_value_clears_the_override(): void {
		$this->options[ PublicUrl::OPTION ] = 'https://tunnel.example.net';

		$this->assertTrue( ( new PublicUrl() )->save( '' ) );
		$this->assertSame( '', $this->options[ PublicUrl::OPTION ] );
	}

	public function test_bare_host_drops_the_port_the_case_and_the_www(): void {
		$this->assertSame( 'example.com', PublicUrl::bareHost( 'https://WWW.Example.com:8443/x' ) );
	}
}
