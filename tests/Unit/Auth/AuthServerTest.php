<?php
/**
 * Tests for AuthServer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use SiteHelm\Auth\AuthServer;
use SiteHelm\Auth\AuthorizeEndpoint;
use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\Discovery;
use SiteHelm\Storage\Installer;

/**
 * Tests what the boot point puts on the site, and — more importantly — what it
 * leaves off when OAuth is not available.
 */
final class AuthServerTest extends AuthTestCase {

	/** @var array<int, array{namespace: string, route: string, args: array<string, mixed>}> */
	private array $routes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->routes = [];

		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_READY;

		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ): bool {
				$this->routes[] = compact( 'namespace', 'route', 'args' );

				return true;
			}
		);
	}

	public function test_every_oauth_route_is_registered_under_the_plugin_namespace(): void {
		// Brain Monkey records hooks rather than running them, so the callback
		// registered on `rest_api_init` is invoked here as WordPress would.
		Actions\expectAdded( 'rest_api_init' )->atLeast()->once()->whenHappen(
			static function ( callable $callback ): void {
				$callback();
			}
		);

		( new AuthServer() )->register();

		$paths = array_column( $this->routes, 'route' );

		// Both discovery documents get a REST alias as well as their well-known
		// path. `/.well-known/` is shared ground: another plugin can own it, a
		// CDN can cache it, and a client that cannot read it there has nowhere
		// else to look unless these exist.
		$this->assertSame(
			[
				Discovery::ROUTE_RESOURCE,
				Discovery::ROUTE_SERVER,
				AuthServer::ROUTE_REGISTER,
				AuthServer::ROUTE_TOKEN,
				AuthServer::ROUTE_REVOKE,
			],
			$paths
		);

		foreach ( $this->routes as $route ) {
			$this->assertSame( AuthServer::ROUTE_NAMESPACE, $route['namespace'] );

			// These endpoints are reached by a client that has no credential
			// yet, which is the whole point of them. PKCE, single-use codes and
			// hashed-at-rest tokens are what protect them, not a permission
			// callback.
			$this->assertSame( '__return_true', $route['args']['permission_callback'] );
		}

		$this->assertSame( 'GET', $this->routes[0]['args']['methods'] );
		$this->assertSame( 'POST', $this->routes[2]['args']['methods'] );
	}

	public function test_the_consent_screen_is_wired_for_signed_in_and_signed_out_visitors(): void {
		( new AuthServer() )->register();

		$this->assertNotFalse( has_action( 'admin_post_' . AuthorizeEndpoint::ACTION ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . AuthorizeEndpoint::ACTION ) );
	}

	public function test_the_request_time_hooks_are_all_registered(): void {
		( new AuthServer() )->register();

		$this->assertNotFalse( has_action( 'parse_request' ) );
		$this->assertNotFalse( has_filter( 'determine_current_user' ) );
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch' ) );
		$this->assertNotFalse( has_filter( 'rest_post_dispatch' ) );
	}

	/**
	 * Switched off means gone, not hidden: the routes 404 because they were
	 * never registered, and no bearer token resolves because nothing is
	 * listening for one.
	 */
	public function test_nothing_is_registered_when_the_feature_is_switched_off(): void {
		$this->options[ AuthSettings::OPTION ] = '0';

		$server = new AuthServer();

		$this->assertFalse( $server->available() );

		$server->register();

		$this->assertFalse( has_action( 'rest_api_init' ) );
		$this->assertFalse( has_filter( 'determine_current_user' ) );
		$this->assertSame( [], $this->routes );
	}

	/**
	 * A site whose tables failed to create must not advertise endpoints it
	 * cannot serve. Discovery would publish an authorization server that errors
	 * on every request, which is a worse failure than having none.
	 */
	public function test_nothing_is_registered_when_the_tables_are_not_there(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$server = new AuthServer();

		$this->assertFalse( $server->available() );

		$server->register();

		$this->assertFalse( has_action( 'rest_api_init' ) );
		$this->assertSame( [], $this->routes );
	}
}
