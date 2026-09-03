<?php
/**
 * Tests for BearerAuthenticator and HostGuard.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use SiteHelm\Auth\BearerAuthenticator;
use SiteHelm\Auth\HostGuard;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Auth\TokenFactory;

/**
 * Tests the two request-time guards: the one that turns a bearer token into a
 * user, and the one that refuses OAuth traffic on the wrong hostname.
 */
final class BearerAuthenticatorTest extends AuthTestCase {

	private const NOW   = 1700000000;
	private const TOKEN = 'a-token-that-looks-like-the-real-thing';
	private const MCP   = '/wp-json/sitehelm/v1/mcp';

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/Doubles/wordpress-error.php';

		BearerAuthenticator::forget();
		$_SERVER['REQUEST_URI'] = self::MCP;
	}

	protected function tearDown(): void {
		BearerAuthenticator::forget();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_HOST'] );
		$_GET = [];

		parent::tearDown();
	}

	private function authenticator(): BearerAuthenticator {
		$urls = new PublicUrl();

		return new BearerAuthenticator(
			new OAuthStore(),
			new TokenFactory(),
			new MetadataDocument( $urls ),
			$urls,
			static fn(): int => self::NOW
		);
	}

	/**
	 * Queues the token row the store will find, then the client row behind it.
	 *
	 * @param array<string, mixed> $overrides Fields to change on the token row.
	 */
	private function storedToken( array $overrides = [] ): void {
		$this->wpdb->rowQueue = [
			array_merge(
				[
					'token_hash' => hash( 'sha256', self::TOKEN ),
					'token_type' => OAuthStore::TYPE_ACCESS,
					'client_id'  => 'shc_someclient',
					'user_id'    => 7,
					'scopes'     => 'mcp',
					'resource'   => 'https://example.com/wp-json/sitehelm/v1/mcp',
					'refresh_of' => '',
					'created_at' => self::NOW - 60,
					'expires_at' => self::NOW + 3600,
				],
				$overrides
			),
			[
				'client_id'   => 'shc_someclient',
				'client_name' => 'Some Desktop App',
			],
		];
	}

	public function test_a_live_token_signs_in_the_account_that_approved_it(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$this->storedToken();

		$this->assertSame( 7, $this->authenticator()->determineUser( false ) );
		$this->assertTrue( BearerAuthenticator::authenticated() );
		$this->assertSame( 'Some Desktop App', BearerAuthenticator::clientName() );
	}

	/**
	 * Some hosts running PHP as CGI strip `Authorization` and re-present it
	 * under this name. Reading only the first spelling works everywhere except
	 * the hosts that most need it.
	 */
	public function test_the_rewritten_header_some_hosts_send_is_read_too(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$this->storedToken();

		$this->assertSame( 7, $this->authenticator()->determineUser( false ) );
	}

	public function test_an_expired_token_is_refused_rather_than_ignored(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$this->storedToken( [ 'expires_at' => self::NOW - 1 ] );

		$this->assertSame( 0, $this->authenticator()->determineUser( false ) );
		$this->assertFalse( BearerAuthenticator::authenticated() );
	}

	public function test_an_unknown_token_is_refused(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$this->wpdb->rowQueue          = [ null ];

		$this->assertSame( 0, $this->authenticator()->determineUser( false ) );
	}

	/**
	 * A token minted for another site is not a token for this one, however
	 * valid it is in itself. This is the whole point of the resource indicator.
	 */
	public function test_a_token_minted_for_another_resource_is_refused(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$this->storedToken( [ 'resource' => 'https://other.example.com/wp-json/sitehelm/v1/mcp' ] );

		$this->assertSame( 0, $this->authenticator()->determineUser( false ) );
	}

	/**
	 * A bearer token grants nothing outside the MCP endpoint. `determine_current_user`
	 * runs on every request the site serves, including wp-admin.
	 */
	public function test_a_bearer_token_signs_nobody_in_anywhere_else(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$_SERVER['REQUEST_URI']        = '/wp-admin/users.php';
		$this->storedToken();

		$this->assertFalse( $this->authenticator()->determineUser( false ) );
		$this->assertSame( [], $this->wpdb->queries );
	}

	/**
	 * The plain-permalink spelling of the same route. A site without pretty
	 * permalinks reaches MCP through a query parameter, and a guard that only
	 * reads the path silently refuses every request there.
	 */
	public function test_the_query_parameter_form_of_the_route_counts_as_mcp(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
		$_SERVER['REQUEST_URI']        = '/index.php';
		$_GET['rest_route']            = '/sitehelm/v1/mcp';
		$this->storedToken();

		$this->assertSame( 7, $this->authenticator()->determineUser( false ) );
	}

	/**
	 * The invariant that keeps application passwords working: with no bearer
	 * header the authenticator returns what it was handed, untouched.
	 */
	public function test_a_request_with_no_bearer_header_is_left_completely_alone(): void {
		$this->assertSame( 41, $this->authenticator()->determineUser( 41 ) );
		$this->assertFalse( $this->authenticator()->determineUser( false ) );
		$this->assertSame( [], $this->wpdb->queries );
	}

	public function test_a_malformed_authorization_header_is_not_treated_as_a_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

		$this->assertSame( 41, $this->authenticator()->determineUser( 41 ) );
		$this->assertSame( [], $this->wpdb->queries );
	}

	/**
	 * The challenge is what tells a client where to go and start a connection.
	 * It points at this plugin's own alias rather than `/.well-known/…`, which
	 * is shared ground another plugin or a CDN can own.
	 */
	public function test_a_refused_mcp_response_carries_a_challenge_naming_our_own_metadata(): void {
		$response = $this->response( 401 );

		$this->authenticator()->addChallenge( $response, null, $this->request( '/sitehelm/v1/mcp' ) );

		$this->assertArrayHasKey( 'WWW-Authenticate', $response->headers );
		$this->assertStringContainsString(
			'resource_metadata="https://example.com/wp-json/sitehelm/v1/oauth/protected-resource"',
			$response->headers['WWW-Authenticate']
		);
		$this->assertStringContainsString( 'scope="mcp"', $response->headers['WWW-Authenticate'] );
	}

	public function test_a_successful_response_and_another_route_get_no_challenge(): void {
		$authenticator = $this->authenticator();

		$ok = $this->response( 200 );
		$authenticator->addChallenge( $ok, null, $this->request( '/sitehelm/v1/mcp' ) );
		$this->assertSame( [], $ok->headers );

		$elsewhere = $this->response( 401 );
		$authenticator->addChallenge( $elsewhere, null, $this->request( '/wp/v2/posts' ) );
		$this->assertSame( [], $elsewhere->headers );
	}

	public function test_the_host_guard_refuses_oauth_on_a_hostname_the_site_does_not_publish(): void {
		$_SERVER['HTTP_HOST'] = 'temp-1234.hosting.example.net';

		$result = ( new HostGuard( new PublicUrl() ) )->guard( null, null, $this->request( '/sitehelm/v1/oauth/token' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sitehelm_wrong_host', $result->get_error_code() );
		$this->assertSame( 421, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'https://example.com', $result->get_error_message() );
	}

	public function test_the_host_guard_allows_the_published_host_and_ignores_other_routes(): void {
		$guard = new HostGuard( new PublicUrl() );

		$_SERVER['HTTP_HOST'] = 'WWW.Example.com';
		$this->assertNull( $guard->guard( null, null, $this->request( '/sitehelm/v1/oauth/token' ) ) );

		$_SERVER['HTTP_HOST'] = 'temp-1234.hosting.example.net';
		$this->assertNull( $guard->guard( null, null, $this->request( '/sitehelm/v1/mcp' ) ) );
	}

	/**
	 * Something upstream removing the header is not an attack, and refusing on
	 * that basis breaks working sites to defend against nothing.
	 */
	public function test_a_request_with_no_host_header_is_allowed_through(): void {
		$this->assertNull(
			( new HostGuard( new PublicUrl() ) )->guard( null, null, $this->request( '/sitehelm/v1/oauth/token' ) )
		);
	}

	/**
	 * A minimal REST request that knows its route.
	 *
	 * @param string $route The route.
	 */
	private function request( string $route ): object {
		return new class( $route ) {

			/**
			 * @param string $route The route.
			 */
			public function __construct( private string $route ) {
			}

			public function get_route(): string {
				return $this->route;
			}
		};
	}

	/**
	 * A minimal REST response that records the headers set on it.
	 *
	 * @param int $status The HTTP status.
	 */
	private function response( int $status ): object {
		return new class( $status ) {

			/** @var array<string, string> */
			public array $headers = [];

			/**
			 * @param int $status The HTTP status.
			 */
			public function __construct( private int $status ) {
			}

			public function get_status(): int {
				return $this->status;
			}

			/**
			 * @param string $name  The header name.
			 * @param string $value The header value.
			 */
			public function header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
	}
}
