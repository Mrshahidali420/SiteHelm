<?php
/**
 * Tests for Discovery and MetadataDocument.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use SiteHelm\Auth\Discovery;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\PublicUrl;

/**
 * Tests the two metadata documents and the paths that serve them.
 */
final class DiscoveryTest extends AuthTestCase {

	/** @var array<int, array{document: array<string, mixed>, status: int}> */
	private array $emitted = [];

	private function discovery(): Discovery {
		$urls = new PublicUrl();

		return new Discovery(
			new MetadataDocument( $urls ),
			$urls,
			function ( array $document, int $status ): void {
				$this->emitted[] = [
					'document' => $document,
					'status'   => $status,
				];
			}
		);
	}

	public function test_the_protected_resource_document_names_this_site_and_its_endpoint(): void {
		$document = ( new MetadataDocument( new PublicUrl() ) )->protectedResource();

		$this->assertSame( 'https://example.com/wp-json/sitehelm/v1/mcp', $document['resource'] );
		$this->assertSame( [ 'https://example.com' ], $document['authorization_servers'] );
		$this->assertSame( [ 'header' ], $document['bearer_methods_supported'] );
		$this->assertSame( [ 'mcp' ], $document['scopes_supported'] );
	}

	/**
	 * Every client here is public and no secret is ever issued, so the document
	 * must say `none` and nothing else. Advertising a secret method invites a
	 * client to send credentials this site would not know what to do with.
	 */
	public function test_the_authorization_server_document_advertises_pkce_and_no_client_secret(): void {
		$document = ( new MetadataDocument( new PublicUrl() ) )->authorizationServer();

		$this->assertSame( 'https://example.com', $document['issuer'] );
		$this->assertSame( 'https://example.com/wp-json/sitehelm/v1/oauth/token', $document['token_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/sitehelm/v1/oauth/register', $document['registration_endpoint'] );
		$this->assertSame( 'https://example.com/wp-json/sitehelm/v1/oauth/revoke', $document['revocation_endpoint'] );
		$this->assertSame( [ 'S256' ], $document['code_challenge_methods_supported'] );
		$this->assertSame( [ 'none' ], $document['token_endpoint_auth_methods_supported'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $document['grant_types_supported'] );
		$this->assertStringContainsString( '/wp-admin/admin-post.php', (string) $document['authorization_endpoint'] );
	}

	public function test_same_identifier_ignores_a_trailing_slash_and_host_case(): void {
		$metadata = new MetadataDocument( new PublicUrl() );

		$this->assertTrue( $metadata->sameIdentifier( 'https://Example.com/mcp/', 'https://example.com/mcp' ) );
		$this->assertFalse( $metadata->sameIdentifier( 'https://example.com/mcp', 'https://example.net/mcp' ) );
		$this->assertFalse( $metadata->sameIdentifier( '', 'https://example.com/mcp' ) );
	}

	public function test_both_well_known_paths_are_recognised(): void {
		$discovery = $this->discovery();

		$this->assertSame( Discovery::KIND_RESOURCE, $discovery->kindFor( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( Discovery::KIND_SERVER, $discovery->kindFor( '/.well-known/oauth-authorization-server' ) );
	}

	/**
	 * The resource-scoped form. A client that knows the endpoint appends its path
	 * to the well-known path, and a server that only matched the bare path sends
	 * that client a 404 with nothing to explain it.
	 */
	public function test_the_resource_scoped_path_serves_the_same_document(): void {
		$this->assertSame(
			Discovery::KIND_RESOURCE,
			$this->discovery()->kindFor( '/.well-known/oauth-protected-resource/wp-json/sitehelm/v1/mcp' )
		);
	}

	public function test_a_path_that_merely_starts_the_same_is_not_a_match(): void {
		$this->assertNull( $this->discovery()->kindFor( '/.well-known/oauth-protected-resource-two' ) );
		$this->assertNull( $this->discovery()->kindFor( '/.well-known/openid-configuration' ) );
		$this->assertNull( $this->discovery()->kindFor( '/' ) );
	}

	/**
	 * On a subdirectory install the request path carries the install prefix and
	 * the well-known path sits below it. Matching without stripping the prefix is
	 * what makes a site publish a correct URL and then 404 on it.
	 */
	public function test_the_install_prefix_is_stripped_before_matching(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/blog' );

		$this->assertSame(
			Discovery::KIND_SERVER,
			$this->discovery()->kindFor( '/blog/.well-known/oauth-authorization-server' )
		);
	}

	public function test_a_well_known_request_is_answered_and_anything_else_is_left_alone(): void {
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';

		$this->discovery()->onParseRequest();

		$this->assertCount( 1, $this->emitted );
		$this->assertSame( 200, $this->emitted[0]['status'] );
		$this->assertArrayHasKey( 'resource', $this->emitted[0]['document'] );

		$_SERVER['REQUEST_URI'] = '/about-us/';

		$this->discovery()->onParseRequest();

		$this->assertCount( 1, $this->emitted );

		unset( $_SERVER['REQUEST_URI'] );
	}
}
