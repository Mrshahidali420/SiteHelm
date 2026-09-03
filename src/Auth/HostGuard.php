<?php
/**
 * Refusing OAuth traffic that arrived on the wrong hostname.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Compares the host a request arrived on with the host this site publishes.
 *
 * A site reachable on two names — the real domain and a hosting provider's
 * temporary one — will happily answer OAuth on both, and the result is a token
 * bound to an identifier the client will never present again. This guard turns
 * that into an immediate, readable **421 Misdirected Request** naming the
 * address to use, instead of a connection that works once and then stops.
 *
 * A request with no `Host` header at all is allowed through. Something upstream
 * has removed it, and refusing on that basis breaks working sites to defend
 * against nothing.
 *
 * @package SiteHelm
 */
final class HostGuard {

	/**
	 * The REST route prefix this guard covers.
	 */
	private const GUARDED_PREFIX = '/sitehelm/v1/oauth';

	/**
	 * Constructs the guard.
	 *
	 * @param PublicUrl $urls The address resolver.
	 */
	public function __construct( private readonly PublicUrl $urls ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Hooks the pre-dispatch check.
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'guard' ], 10, 3 );
	}

	/**
	 * Refuses an OAuth request that arrived on an unexpected host.
	 *
	 * @param mixed $result   Whatever an earlier filter decided.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The request.
	 *
	 * @return mixed The result, or a 421 error.
	 */
	public function guard( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		if ( ! str_starts_with( $route, self::GUARDED_PREFIX ) ) {
			return $result;
		}

		if ( $this->hostMatches() ) {
			return $result;
		}

		return new \WP_Error(
			'sitehelm_wrong_host',
			sprintf(
				/* translators: the address the site publishes for OAuth. */
				__( 'This request reached the site on a different hostname from the one SiteHelm publishes. Point the app at %s instead, or correct the Server URL on the SiteHelm Connect screen.', 'sitehelm' ),
				$this->urls->base()
			),
			[ 'status' => 421 ]
		);
	}

	/**
	 * Whether the request's host is the published one.
	 *
	 * @return bool True on a match, and true when there is no host to compare.
	 */
	public function hostMatches(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Lowercased and compared against a stored value; never stored, printed or used in SQL.
		$raw = isset( $_SERVER['HTTP_HOST'] ) ? trim( (string) $_SERVER['HTTP_HOST'] ) : '';

		if ( '' === $raw ) {
			return true;
		}

		return PublicUrl::bareHost( 'https://' . $raw ) === $this->urls->host();
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
