<?php
/**
 * Turning a bearer token into the WordPress user who approved it.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Resolves `Authorization: Bearer …` on the MCP route, and nowhere else.
 *
 * Three properties are load-bearing.
 *
 * **It fails closed.** A request carrying a bearer token that is invalid,
 * expired, unknown, or minted for another resource is refused outright. It is
 * never quietly downgraded to whatever other credential the request might also
 * carry, because that turns an expired token into a silent fallback nobody can
 * reason about.
 *
 * **It is inert without a bearer header.** A request with no bearer token at all
 * is left completely alone, so Application Passwords, cookies and everything
 * else keep working exactly as before OAuth existed.
 *
 * **It grants nothing.** The token acts as the administrator who approved it and
 * no more: every operation still runs its own capability check. OAuth here is a
 * way of proving who you are, not a way of becoming someone else.
 *
 * @package SiteHelm
 */
final class BearerAuthenticator {

	/**
	 * The name of the connected app on the current request, once a bearer token
	 * has resolved. Read by the transport so the activity log records the app
	 * that registered rather than a header anyone can set.
	 *
	 * @var string
	 */
	private static string $client_name = '';

	/**
	 * Whether the current request was authenticated by a bearer token.
	 *
	 * @var bool
	 */
	private static bool $authenticated = false;

	/**
	 * Constructs the authenticator.
	 *
	 * @param OAuthStore       $store    The token store.
	 * @param TokenFactory     $factory  The fingerprint source.
	 * @param MetadataDocument $metadata The identifier comparer.
	 * @param PublicUrl        $urls     The address resolver.
	 * @param callable|null    $clock    Returns the current time; null for time().
	 */
	public function __construct(
		private readonly OAuthStore $store,
		private readonly TokenFactory $factory,
		private readonly MetadataDocument $metadata,
		private readonly PublicUrl $urls,
		private $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Hooks the resolver and the challenge header.
	 */
	public function register(): void {
		add_filter( 'determine_current_user', [ $this, 'determineUser' ], 20 );
		add_filter( 'rest_post_dispatch', [ $this, 'addChallenge' ], 10, 3 );
	}

	/**
	 * Resolves the current user from a bearer token, or leaves it alone.
	 *
	 * @param int|false $user_id Whatever the previous filter decided.
	 *
	 * @return int|false The resolved user, 0 to refuse, or the value unchanged.
	 */
	public function determineUser( $user_id ) {
		$token = $this->presentedToken();

		if ( '' === $token || ! $this->isMcpRequest() ) {
			return $user_id;
		}

		$row = $this->store->findToken( $this->factory->fingerprint( $token ), OAuthStore::TYPE_ACCESS );

		if ( null === $row || (int) $row['expires_at'] <= ( $this->clock )() ) {
			return 0;
		}

		// A token minted to reach some other resource is not a token for this
		// one, however valid it is in itself.
		$resource = (string) $row['resource'];

		if ( '' !== $resource && ! $this->metadata->sameIdentifier( $resource, $this->urls->resource() ) ) {
			return 0;
		}

		$client = $this->store->findClient( (string) $row['client_id'] );

		self::$client_name   = is_array( $client ) ? (string) $client['client_name'] : '';
		self::$authenticated = true;

		return (int) $row['user_id'];
	}

	/**
	 * Adds the RFC 9728 challenge to a refused MCP response.
	 *
	 * The `resource_metadata` parameter points at this plugin's own REST alias,
	 * never at `/.well-known/…`. That path is shared ground: a CDN can cache it
	 * and another plugin can own it, and a challenge pointing there can send a
	 * client to somebody else's authorization server.
	 *
	 * @param mixed $response The response object.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The request.
	 *
	 * @return mixed The response, unchanged.
	 */
	public function addChallenge( $response, $server, $request ) {
		unset( $server );

		if ( ! is_object( $response ) || ! method_exists( $response, 'get_status' ) ) {
			return $response;
		}

		if ( ! in_array( $response->get_status(), [ 401, 403 ], true ) ) {
			return $response;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		if ( ! str_contains( $route, '/sitehelm/v1/mcp' ) ) {
			return $response;
		}

		if ( method_exists( $response, 'header' ) ) {
			$response->header(
				'WWW-Authenticate',
				sprintf(
					'Bearer resource_metadata="%s", scope="%s"',
					$this->urls->restUrl( 'oauth/protected-resource' ),
					MetadataDocument::SCOPE
				)
			);
		}

		return $response;
	}

	/**
	 * The registered name of the app on the current request, or ''.
	 *
	 * @return string The client name.
	 */
	public static function clientName(): string {
		return self::$client_name;
	}

	/**
	 * Whether the current request was authenticated by a bearer token.
	 *
	 * @return bool True after a token resolved.
	 */
	public static function authenticated(): bool {
		return self::$authenticated;
	}

	/**
	 * Clears the per-request state. For tests, and for long-running workers.
	 */
	public static function forget(): void {
		self::$client_name   = '';
		self::$authenticated = false;
	}

	/**
	 * The bearer token on this request, or ''.
	 *
	 * Three headers are read, in order. Apache running PHP as CGI strips
	 * `Authorization` and re-presents it as `REDIRECT_HTTP_AUTHORIZATION`, and a
	 * server that only reads the first spelling works everywhere except the
	 * hosts that most need it.
	 *
	 * @return string The raw token.
	 */
	private function presentedToken(): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Matched against a fixed pattern below; never stored or printed.
			$header = isset( $_SERVER[ $key ] ) ? (string) $_SERVER[ $key ] : '';

			if ( 1 === preg_match( '/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', trim( $header ), $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	/**
	 * Whether this request is aimed at the MCP endpoint.
	 *
	 * Scoping matters: `determine_current_user` runs for every request on the
	 * site, and a bearer token minted for MCP must not sign anyone in anywhere
	 * else.
	 *
	 * @return bool True for the MCP route.
	 */
	private function isMcpRequest(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Substring-matched against a fixed literal; never stored or printed.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( str_contains( $uri, '/sitehelm/v1/mcp' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';

		return str_contains( $route, '/sitehelm/v1/mcp' );
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
