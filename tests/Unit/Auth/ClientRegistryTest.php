<?php
/**
 * Tests for ClientRegistry.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use SiteHelm\Auth\ClientRegistry;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Auth\RedirectUriPolicy;
use SiteHelm\Auth\TokenFactory;
use SiteHelm\Tests\Doubles\FakeWpRestRequest;

/**
 * Tests dynamic client registration: what it accepts, and what it will not.
 */
final class ClientRegistryTest extends AuthTestCase {

	private function registry(): ClientRegistry {
		return new ClientRegistry(
			new OAuthStore(),
			new RedirectUriPolicy(),
			new TokenFactory(),
			static fn(): int => 1700000000
		);
	}

	/**
	 * @param array<string, mixed> $body The registration body.
	 */
	private function register( array $body ): array {
		$response = $this->registry()->handle( new FakeWpRestRequest( (string) json_encode( $body ) ) );

		return [
			'status' => $response->get_status(),
			'body'   => $response->get_data(),
		];
	}

	/**
	 * No existing registration matches, and nothing is over a ceiling.
	 */
	private function freshSite(): void {
		$this->wpdb->rowQueue = [ null ];
		$this->wpdb->varQueue = [ 0 ];
	}

	public function test_a_registration_is_stored_and_returned_with_no_secret(): void {
		$this->freshSite();

		$result = $this->register(
			[
				'client_name'   => 'Some Desktop App',
				'redirect_uris' => [ 'http://127.0.0.1:33418/callback' ],
			]
		);

		$this->assertSame( 201, $result['status'] );
		$this->assertStringStartsWith( 'shc_', $result['body']['client_id'] );
		$this->assertSame( 'Some Desktop App', $result['body']['client_name'] );
		$this->assertSame( [ 'http://127.0.0.1:33418/callback' ], $result['body']['redirect_uris'] );
		$this->assertSame( 'none', $result['body']['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $result['body'] );

		$this->assertCount( 1, $this->wpdb->inserts );
		$this->assertSame( 0, $this->wpdb->inserts[0]['data']['authorized_at'] );
	}

	/**
	 * A client that registers twice with the same shape gets the same identifier
	 * back. Minting a second one for every restart is how a site accumulates a
	 * hundred registrations for one app.
	 */
	public function test_an_identical_re_registration_returns_the_existing_client(): void {
		$this->wpdb->rowQueue = [
			[
				'client_id'     => 'shc_alreadyhere',
				'client_name'   => 'Some Desktop App',
				'redirect_uris' => '["http:\/\/127.0.0.1:33418\/callback"]',
				'created_at'    => 1699999999,
				'authorized_at' => 1699999999,
			],
		];

		$result = $this->register(
			[
				'client_name'   => 'Some Desktop App',
				'redirect_uris' => [ 'http://127.0.0.1:33418/callback' ],
			]
		);

		$this->assertSame( 201, $result['status'] );
		$this->assertSame( 'shc_alreadyhere', $result['body']['client_id'] );
		$this->assertSame( [], $this->wpdb->inserts );
	}

	/**
	 * The redirect URI is the address a token ends up at. Every one of these is a
	 * way to send that token somewhere the site owner never agreed to.
	 *
	 * @dataProvider provideRefusedRedirectUris
	 *
	 * @param string $uri  The redirect URI to refuse.
	 * @param string $hint A word the refusal has to contain.
	 */
	public function test_a_dangerous_redirect_uri_is_refused( string $uri, string $hint ): void {
		$this->freshSite();

		$result = $this->register(
			[
				'client_name'   => 'Some App',
				'redirect_uris' => [ $uri ],
			]
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_redirect_uri', $result['body']['error'] );
		$this->assertStringContainsStringIgnoringCase( $hint, $result['body']['error_description'] );
		$this->assertSame( [], $this->wpdb->inserts );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideRefusedRedirectUris(): array {
		return [
			'javascript'      => [ 'javascript:alert(1)', 'javascript' ],
			'data'            => [ 'data:text/html,<script>x</script>', 'data' ],
			'plain http host' => [ 'http://evil.example.com/callback', 'http' ],
			'no scheme'       => [ '/callback', 'absolute' ],
			'fragment'        => [ 'https://app.example.com/cb#token', 'fragment' ],
		];
	}

	public function test_registration_without_any_redirect_uri_is_refused(): void {
		$this->freshSite();

		$result = $this->register( [ 'client_name' => 'Some App' ] );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_redirect_uri', $result['body']['error'] );
	}

	public function test_more_redirect_uris_than_allowed_are_refused(): void {
		$this->freshSite();

		$result = $this->register(
			[
				'client_name'   => 'Some App',
				'redirect_uris' => array_fill( 0, ClientRegistry::MAX_REDIRECT_URIS + 1, 'https://app.example.com/cb' ),
			]
		);

		$this->assertSame( 400, $result['status'] );
	}

	public function test_a_body_that_is_not_an_object_is_refused(): void {
		$response = $this->registry()->handle( new FakeWpRestRequest( 'not json' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_client_metadata', $response->get_data()['error'] );
	}

	public function test_a_nameless_app_still_registers_under_a_readable_name(): void {
		$this->freshSite();

		$result = $this->register( [ 'redirect_uris' => [ 'https://app.example.com/cb' ] ] );

		$this->assertSame( 201, $result['status'] );
		$this->assertSame( 'Unnamed app', $result['body']['client_name'] );
	}

	/**
	 * A site holding thousands of registrations nobody ever approved is being
	 * used as free storage. It stops accepting new ones and says why.
	 */
	public function test_a_site_full_of_unapproved_registrations_stops_accepting_them(): void {
		$this->wpdb->rowQueue = [ null ];
		$this->wpdb->varQueue = [ ClientRegistry::UNAUTHORIZED_CEILING ];

		$result = $this->register(
			[
				'client_name'   => 'Some App',
				'redirect_uris' => [ 'https://app.example.com/cb' ],
			]
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertSame( [], $this->wpdb->inserts );
	}
}
