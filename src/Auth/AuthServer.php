<?php
/**
 * Boot point for the OAuth authorization server.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use SiteHelm\Storage\Installer;
use WP_REST_Request;

/**
 * Decides whether this site offers OAuth at all, and wires everything up when
 * it does.
 *
 * Availability has three conditions and all three must hold. The operator's
 * switch must be on; the two OAuth tables must exist, because a token endpoint
 * that advertises itself and then cannot write is worse than one that is simply
 * absent; and nothing is registered on a request that is not going to touch it.
 *
 * When OAuth is unavailable the routes are not registered — so they 404 rather
 * than erroring — and {@see BearerAuthenticator} is never hooked, so a bearer
 * header is ignored completely and Application Passwords behave exactly as they
 * did before this module existed.
 *
 * @package SiteHelm
 */
final class AuthServer {

	/**
	 * The REST namespace every OAuth route hangs from. The same namespace the
	 * MCP endpoint uses, so a site that allows one allows all of them.
	 */
	public const ROUTE_NAMESPACE = 'sitehelm/v1';

	public const ROUTE_REGISTER = '/oauth/register';
	public const ROUTE_TOKEN    = '/oauth/token';
	public const ROUTE_REVOKE   = '/oauth/revoke';

	/**
	 * Constructs the server with its whole object graph.
	 *
	 * @param OAuthStore|null   $store    The OAuth store; null for a fresh one.
	 * @param PublicUrl|null    $urls     The address resolver; null for a fresh one.
	 * @param AuthSettings|null $settings The enable flag; null for a fresh one.
	 */
	public function __construct(
		private ?OAuthStore $store = null,
		private ?PublicUrl $urls = null,
		private ?AuthSettings $settings = null
	) {
		$this->store    = $store ?? new OAuthStore();
		$this->urls     = $urls ?? new PublicUrl();
		$this->settings = $settings ?? new AuthSettings( $this->urls );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Whether this site offers OAuth right now.
	 *
	 * @return bool True when the routes should exist.
	 */
	public function available(): bool {
		return $this->settings->enabled() && ( new Installer() )->isAvailable();
	}

	/**
	 * Wires every hook, or none.
	 */
	public function register(): void {
		if ( ! $this->available() ) {
			return;
		}

		$factory  = new TokenFactory();
		$metadata = new MetadataDocument( $this->urls );
		$policy   = new RedirectUriPolicy();
		$codes    = new AuthorizationCodes( $factory );
		$pkce     = new Pkce();

		( new Discovery( $metadata, $this->urls ) )->register();
		( new HostGuard( $this->urls ) )->register();
		( new BearerAuthenticator( $this->store, $factory, $metadata, $this->urls ) )->register();

		$registry = new ClientRegistry( $this->store, $policy, $factory );
		$token    = new TokenEndpoint( $this->store, $codes, $factory, $pkce, $metadata, $this->urls );
		$revoke   = new RevokeEndpoint( $this->store, $factory );

		add_action(
			'rest_api_init',
			function () use ( $registry, $token, $revoke ): void {
				$this->route( self::ROUTE_REGISTER, [ $registry, 'handle' ] );
				$this->route( self::ROUTE_TOKEN, [ $token, 'handle' ] );
				$this->route( self::ROUTE_REVOKE, [ $revoke, 'handle' ] );
			}
		);

		$authorize = new AuthorizeEndpoint(
			$this->store,
			$policy,
			$codes,
			$metadata,
			$this->urls,
			$pkce,
			new ConsentView()
		);

		add_action( 'admin_post_' . AuthorizeEndpoint::ACTION, [ $authorize, 'handle' ] );
		add_action( 'admin_post_nopriv_' . AuthorizeEndpoint::ACTION, [ $authorize, 'requireLogin' ] );
	}

	/**
	 * Registers one public POST route.
	 *
	 * `permission_callback` is `__return_true` on purpose and is not an
	 * oversight: these three endpoints are reached by a client that has no
	 * credential yet, which is the whole point of them. What protects them is
	 * PKCE, single-use five-minute codes, hashed-at-rest tokens, the redirect
	 * rules and the consent screen — not an access check that could not
	 * possibly pass.
	 *
	 * @param string   $route    The route below the namespace.
	 * @param callable $callback The handler.
	 */
	private function route( string $route, callable $callback ): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			$route,
			[
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ) => $callback( $request ),
				'permission_callback' => '__return_true',
			]
		);
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
