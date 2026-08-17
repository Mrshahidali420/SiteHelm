<?php
/**
 * The front-end half of REQ-0079: the code that actually serves a redirect.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * Serves the stored redirect table to front-end traffic.
 *
 * A REDIRECT NOBODY SERVES IS A ROW IN A TABLE. The three operations beside this
 * class let an operator read and change the table through the gateway, and none
 * of them makes a single visitor land anywhere: without this class the whole
 * requirement is a database feature. It is deliberately the smallest thing that
 * can make the table load-bearing.
 *
 * THE LOOKUP KEY COMES FROM RedirectStore::normalizePath(), not from a second
 * normaliser written to match it. That is the one defect this class exists to
 * not have: the store and the router are the two sides of a lookup, they are
 * reached by completely different callers months apart, and a disagreement
 * between them produces a redirect that is stored, listed, verified — and never
 * fires, with nothing anywhere reporting a fault.
 *
 * IT RUNS ON `template_redirect` AND MATCHES ANY PATH, not only a path that was
 * about to 404. A redirect whose source is still a live page therefore shadows
 * that page. That is the operator's instruction taken literally, and the
 * alternative — firing only on a 404 — silently does nothing on exactly the
 * request an operator most often tests first, which is worse than doing what
 * they said.
 *
 * @package SiteHelm
 */
final class RedirectRouter {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Constructs the router.
	 *
	 * @param RedirectStore $store The redirect table.
	 */
	public function __construct( private readonly RedirectStore $store ) {
	}

	/**
	 * Serves a redirect for the current request, if one is stored for it.
	 *
	 * The `exit` is what a redirect is: continuing to render after sending a
	 * `Location` header would emit a page body into a response the browser
	 * discards, and on a 410 it would emit the moved page's own content under a
	 * status that says the page is gone.
	 *
	 * A 410 IS SERVED THROUGH THE THEME'S 404 TEMPLATE with the status
	 * overridden. A bare header with no body is technically correct and looks to
	 * a human visitor like a broken site, and the whole point of choosing 410
	 * over a 301 to the home page is to be honest to both audiences at once.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */
	public function handle(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		$decision = $this->resolve( $uri );

		if ( null === $decision ) {
			return;
		}

		nocache_headers();

		if ( null === $decision['location'] ) {
			status_header( RedirectStore::STATUS_GONE );

			global $wp_query;

			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}

			$template = get_404_template();

			if ( is_string( $template ) && '' !== $template && file_exists( $template ) ) {
				require $template;
			}

			exit;
		}

		wp_redirect( $decision['location'], $decision['status'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- An external target is an administrator's explicit instruction; see targetUrl().
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/**
	 * The redirect to serve for one request URI, or null to serve the request.
	 *
	 * Separated from handle() because handle() ends in `exit` and this does not:
	 * every rule worth pinning — what matches, what the target resolves to,
	 * which query string survives, which redirect is refused for looping — is
	 * decided here, where a test can read the answer instead of watching a
	 * process end.
	 *
	 * @param string $requestUri The request URI, as the server delivered it.
	 *
	 * @return array<string, mixed>|null The status and location, or null.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function resolve( string $requestUri ): ?array {
		if ( is_admin() || wp_doing_cron() || $this->isRestRequest() ) {
			return null;
		}

		$path = $this->store->normalizePath( $this->stripBase( $requestUri ) );

		if ( null === $path ) {
			return null;
		}

		$record = $this->store->find( $path );

		if ( null === $record ) {
			return null;
		}

		$status = (int) $record['status'];

		if ( RedirectStore::STATUS_GONE === $status ) {
			return [
				'status'   => $status,
				'location' => null,
			];
		}

		$location = $this->targetUrl( (string) $record['target'], $requestUri, (bool) $record['forwardQuery'] );

		// A redirect to the path that was just requested is a loop, and the
		// browser reports it as the site being broken rather than as a
		// misconfigured redirect. It is refused here as well as at write time,
		// because the two can disagree: a target of `/new` stops looping the
		// moment somebody renames the page again so that `/new` is what the
		// visitor asked for.
		if ( $this->isSelf( $location, $path ) ) {
			return null;
		}

		return [
			'status'   => $status,
			'location' => $location,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Resolves a stored target into an absolute URL.
	 *
	 * A stored target is either an absolute `http`/`https` URL or a path on this
	 * site. AN EXTERNAL TARGET IS ALLOWED, and `wp_redirect()` rather than
	 * `wp_safe_redirect()` is what serves it: consolidating a retired
	 * microsite onto a client's main domain is the ordinary reason an agency
	 * writes a redirect at all, and `wp_safe_redirect()` would silently rewrite
	 * every one of those to this site's home page. The value can only have been
	 * set by a user holding `manage_options`, which is the same authority that
	 * could edit the theme.
	 *
	 * A target that is neither — a `javascript:` URI, a protocol-relative
	 * `//host` — never reaches here: redirect-set refuses it, and RedirectStore
	 * drops it on read.
	 *
	 * @param string $target     The stored target.
	 * @param string $requestUri The request URI, for its query string.
	 * @param bool   $forward    Whether to carry the request's query string over.
	 *
	 * @return string The absolute URL to send.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function targetUrl( string $target, string $requestUri, bool $forward ): string {
		$url = $this->isAbsolute( $target ) ? $target : home_url( '/' . ltrim( $target, '/' ) );

		if ( ! $forward ) {
			return $url;
		}

		$query = (string) wp_parse_url( $requestUri, PHP_URL_QUERY );

		if ( '' === $query ) {
			return $url;
		}

		// The target's own query wins where the two name the same argument,
		// because the target was written by an operator for this redirect and
		// the request's arguments arrived with a visitor. add_query_arg() would
		// do the reverse.
		$existing = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return '' === $existing ? $url . '?' . $query : $url . '&' . $query;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Whether a resolved location points back at the path just requested.
	 *
	 * Only a target on THIS site can loop, so a target whose host differs is
	 * never self even when its path matches.
	 *
	 * @param string $location The resolved absolute URL.
	 * @param string $path     The canonical requested path.
	 *
	 * @return bool True when serving this would loop.
	 */
	private function isSelf( string $location, string $path ): bool {
		$host = (string) wp_parse_url( $location, PHP_URL_HOST );
		$home = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );

		if ( '' !== $host && '' !== $home && strtolower( $host ) !== strtolower( $home ) ) {
			return false;
		}

		$target_path = $this->store->normalizePath( $this->stripBase( (string) wp_parse_url( $location, PHP_URL_PATH ) ) );

		return $target_path === $path;
	}

	/**
	 * Removes the site's own base path from a request path.
	 *
	 * A WordPress install in a subdirectory receives `/blog/old-page` for what an
	 * operator wrote as `/old-page`, and without this the table would only ever
	 * match on a site installed at the domain root. It lives here rather than in
	 * RedirectStore::normalizePath() so that the normaliser stays a pure function
	 * of its argument: a stored source must key identically whatever the install
	 * looks like, or moving a site into a subdirectory would strand every row.
	 *
	 * @param string $path The path as delivered.
	 *
	 * @return string The path relative to the site root.
	 */
	private function stripBase( string $path ): string {
		$base = (string) wp_parse_url( (string) home_url(), PHP_URL_PATH );
		$base = rtrim( $base, '/' );

		if ( '' === $base || '/' === $base ) {
			return $path;
		}

		if ( $path === $base ) {
			return '/';
		}

		return str_starts_with( $path, $base . '/' ) ? substr( $path, strlen( $base ) ) : $path;
	}

	/**
	 * Whether a stored target is already an absolute `http` or `https` URL.
	 *
	 * @param string $target The stored target.
	 *
	 * @return bool True when the target is absolute.
	 */
	private function isAbsolute( string $target ): bool {
		return 1 === preg_match( '#^https?://#i', $target );
	}

	/**
	 * Whether this request is a REST request.
	 *
	 * `template_redirect` does not fire for a REST request, so this is defence
	 * for a caller reaching resolve() by another route rather than a live gate —
	 * including the gateway's own route, where redirecting a JSON-RPC call would
	 * turn a client's write into a mystery.
	 *
	 * @return bool True when this is a REST request.
	 */
	private function isRestRequest(): bool {
		return defined( 'REST_REQUEST' ) && true === constant( 'REST_REQUEST' );
	}
}
