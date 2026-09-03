<?php
/**
 * Tests for AuthorizeEndpoint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use SiteHelm\Auth\AuthorizationCodes;
use SiteHelm\Auth\AuthorizeEndpoint;
use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\ConsentView;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Auth\Pkce;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Auth\RedirectUriPolicy;
use SiteHelm\Auth\TokenFactory;

/**
 * Tests the consent leg: what it refuses on the page, what it bounces back to
 * the app, and what an approval actually writes.
 *
 * The distinction under test throughout is where a failure is reported. Until
 * the client and its redirect URI are both known good, a failure has to render
 * here; sending it onward would be handing information to whoever asked.
 */
final class AuthorizeEndpointTest extends AuthTestCase {

	private const NOW      = 1700000000;
	private const CLIENT   = 'shc_someclient';
	private const REDIRECT = 'http://127.0.0.1:33418/callback';

	/** @var string[] */
	private array $redirects = [];

	private bool $isAdmin = true;

	protected function setUp(): void {
		parent::setUp();

		$this->redirects = [];
		$this->isAdmin   = true;

		Functions\when( 'current_user_can' )->alias( fn(): bool => $this->isAdmin );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Some Site' );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'user_login' => 'owner' ] );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input name="_wpnonce" />' );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_login_url' )->alias(
			static fn( string $to = '' ): string => self::SITE . '/wp-login.php?redirect_to=' . rawurlencode( $to )
		);
	}

	protected function tearDown(): void {
		$_GET    = [];
		$_POST   = [];
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	private function endpoint(): AuthorizeEndpoint {
		$urls = new PublicUrl();

		return new AuthorizeEndpoint(
			new OAuthStore(),
			new RedirectUriPolicy(),
			new AuthorizationCodes( new TokenFactory() ),
			new MetadataDocument( $urls ),
			$urls,
			new Pkce(),
			new ConsentView(),
			function ( string $url ): void {
				$this->redirects[] = $url;
			},
			static fn(): int => self::NOW
		);
	}

	/**
	 * A well-formed consent request, before whatever the individual test breaks.
	 *
	 * @return array<string, string> The query parameters.
	 */
	private function request(): array {
		return [
			'client_id'             => self::CLIENT,
			'redirect_uri'          => self::REDIRECT,
			'state'                 => 'st-123',
			'response_type'         => 'code',
			'code_challenge'        => str_repeat( 'a', 43 ),
			'code_challenge_method' => 'S256',
			'resource'              => 'https://example.com/wp-json/sitehelm/v1/mcp',
			'scope'                 => 'mcp',
		];
	}

	/**
	 * Queues the registered client the store will find.
	 */
	private function knownClient(): void {
		$this->wpdb->rowQueue = [
			[
				'client_id'     => self::CLIENT,
				'client_name'   => 'Some Desktop App',
				'redirect_uris' => (string) json_encode( [ self::REDIRECT ] ),
				'created_at'    => self::NOW - 60,
				'authorized_at' => 0,
			],
		];
	}

	/**
	 * @param array<string, string> $params The request parameters.
	 */
	private function get( array $params ): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $params;
		$_POST                     = [];

		ob_start();
		$this->endpoint()->handle();

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, string> $params The request parameters.
	 */
	private function post( array $params ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = [];
		$_POST                     = $params;

		ob_start();
		$this->endpoint()->handle();

		return (string) ob_get_clean();
	}

	public function test_a_valid_request_shows_the_app_and_the_account_being_connected(): void {
		$this->knownClient();

		$page = $this->get( $this->request() );

		$this->assertStringContainsString( 'Some Desktop App', $page );
		$this->assertStringContainsString( 'Some Site', $page );
		$this->assertStringContainsString( 'owner', $page );
		$this->assertStringContainsString( '_wpnonce', $page );
		$this->assertSame( [], $this->redirects );
	}

	public function test_the_feature_being_switched_off_is_explained_on_the_page(): void {
		$this->options[ AuthSettings::OPTION ] = '0';

		$page = $this->get( $this->request() );

		$this->assertStringContainsString( 'switched off', $page );
		$this->assertStringContainsString( 'application password', $page );
		$this->assertSame( [], $this->redirects );
	}

	/**
	 * Approving on a plain-HTTP site would put a bearer token on the wire in
	 * clear text, so the flow stops rather than warns.
	 */
	public function test_a_site_without_https_cannot_approve_anything(): void {
		$this->options[ PublicUrl::OPTION ]    = 'http://public.example.com';
		$this->options[ AuthSettings::OPTION ] = '1';

		$page = $this->get( $this->request() );

		$this->assertStringContainsString( 'HTTPS', $page );
		$this->assertSame( [], $this->redirects );
		$this->assertSame( [], $this->wpdb->queries );
	}

	/**
	 * The flag is left unset on most sites, so its default is the real policy:
	 * a site nobody has configured is only on when a token could travel safely.
	 */
	public function test_an_unconfigured_plain_http_site_is_off_by_default(): void {
		$this->options[ PublicUrl::OPTION ] = 'http://public.example.com';

		$this->assertFalse( ( new AuthSettings() )->enabled() );
	}

	public function test_a_non_administrator_cannot_approve_a_connection(): void {
		$this->isAdmin = false;

		$page = $this->get( $this->request() );

		$this->assertStringContainsString( 'administrator', $page );
		$this->assertSame( [], $this->redirects );
	}

	public function test_an_unregistered_app_is_refused_on_the_page(): void {
		$this->wpdb->rowQueue = [ null ];

		$page = $this->get( $this->request() );

		$this->assertStringContainsString( 'does not recognise', $page );
		$this->assertSame( [], $this->redirects );
	}

	/**
	 * The single most important refusal here. An app that asks to be sent to an
	 * address it never registered is asking for a code to be delivered
	 * somewhere the site owner never agreed to, so nothing may be sent onward.
	 */
	public function test_an_unregistered_redirect_uri_is_refused_without_redirecting(): void {
		$this->knownClient();

		$params                 = $this->request();
		$params['redirect_uri'] = 'https://attacker.example.net/collect';

		$page = $this->get( $params );

		$this->assertStringContainsString( 'never registered', $page );
		$this->assertSame( [], $this->redirects );
	}

	/**
	 * Once the target is verified, a protocol error does travel back to the app,
	 * which is what lets a client show a useful message instead of hanging.
	 *
	 * @dataProvider provideProtocolErrors
	 *
	 * @param array<string, string> $overrides The parameters to break.
	 * @param string                $expected  The RFC 6749 error code.
	 */
	public function test_a_protocol_error_bounces_back_to_the_verified_app( array $overrides, string $expected ): void {
		$this->knownClient();

		$this->get( array_merge( $this->request(), $overrides ) );

		$this->assertCount( 1, $this->redirects );
		$this->assertStringStartsWith( self::REDIRECT . '?', $this->redirects[0] );
		$this->assertStringContainsString( 'error=' . $expected, $this->redirects[0] );
		$this->assertStringContainsString( 'state=st-123', $this->redirects[0] );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function provideProtocolErrors(): array {
		return [
			'implicit flow'        => [ [ 'response_type' => 'token' ], 'unsupported_response_type' ],
			'plain pkce'           => [ [ 'code_challenge_method' => 'plain' ], 'invalid_request' ],
			'no pkce at all'       => [
				[
					'code_challenge'        => '',
					'code_challenge_method' => '',
				],
				'invalid_request',
			],
			'malformed challenge'  => [ [ 'code_challenge' => 'too short' ], 'invalid_request' ],
			'someone else"s token' => [ [ 'resource' => 'https://other.example.com/wp-json/sitehelm/v1/mcp' ], 'invalid_target' ],
		];
	}

	public function test_an_approval_issues_a_code_and_records_that_the_app_was_authorized(): void {
		$this->knownClient();

		$this->post(
			array_merge(
				$this->request(),
				[ 'sitehelm_decision' => AuthorizeEndpoint::DECISION_APPROVE ]
			)
		);

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( 'code=', $this->redirects[0] );
		$this->assertStringContainsString( 'state=st-123', $this->redirects[0] );

		$this->assertCount( 1, $this->transients );
		$grant = array_values( $this->transients )[0];
		$this->assertSame( self::CLIENT, $grant['client_id'] );
		$this->assertSame( 7, $grant['user_id'] );
		$this->assertSame( self::REDIRECT, $grant['redirect_uri'] );

		// The code on the redirect is the secret; only its fingerprint is stored.
		$this->assertStringNotContainsString( array_key_first( $this->transients ), $this->redirects[0] );

		$this->assertCount( 1, $this->wpdb->updates );
		$this->assertSame( self::NOW, $this->wpdb->updates[0]['data']['authorized_at'] );
	}

	public function test_declining_sends_the_app_away_empty_handed(): void {
		$this->knownClient();

		$this->post(
			array_merge(
				$this->request(),
				[ 'sitehelm_decision' => AuthorizeEndpoint::DECISION_DENY ]
			)
		);

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( 'error=access_denied', $this->redirects[0] );
		$this->assertStringNotContainsString( 'code=', $this->redirects[0] );
		$this->assertSame( [], $this->transients );
		$this->assertSame( [], $this->wpdb->updates );
	}

	/**
	 * A POST that submits nothing is not an approval. Treating a missing field
	 * as consent is how a cross-site form becomes a connection.
	 */
	public function test_a_post_with_no_decision_is_not_an_approval(): void {
		$this->knownClient();

		$this->post( $this->request() );

		$this->assertStringContainsString( 'error=access_denied', $this->redirects[0] );
		$this->assertSame( [], $this->transients );
	}

	/**
	 * A signed-out visitor arriving from an app must come back to the same
	 * request afterwards, or the connection restarts and the app looks broken.
	 */
	public function test_a_signed_out_visitor_is_sent_to_log_in_and_come_back(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $this->request();

		$this->endpoint()->requireLogin();

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( '/wp-login.php', $this->redirects[0] );
		$this->assertStringContainsString( rawurlencode( 'admin-post.php' ), $this->redirects[0] );
		$this->assertStringContainsString( rawurlencode( self::CLIENT ), $this->redirects[0] );
	}
}
