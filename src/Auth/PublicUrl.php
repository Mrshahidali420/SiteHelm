<?php
/**
 * The one address SiteHelm hands out to clients.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Resolves the site address every OAuth participant must agree on.
 *
 * Managed hosts routinely pin the Site Address to a domain that is not live yet
 * while the REST API answers on the real one, so `home_url()` alone can mint an
 * issuer no client can reach. An operator-set override therefore wins over
 * everything, and once it is set it governs the issuer, the resource, the
 * authorize and token endpoints, the discovery documents, the HTTPS decision and
 * the host guard's expectation — one value, so those six can never disagree.
 *
 * This is the only class in `src/Auth` permitted to call `home_url()`.
 *
 * @package SiteHelm
 */
final class PublicUrl {

	/**
	 * The operator-set server address. Empty means "derive it".
	 */
	public const OPTION = 'sitehelm_public_url';

	/**
	 * Hosts that get an HTTPS exemption because no public certificate can exist
	 * for them. `.dev` is deliberately absent: it is a real, HSTS-preloaded TLD,
	 * and treating it as local once handed a live domain a TLS bypass.
	 */
	private const LOCAL_SUFFIXES = [ '.test', '.local', '.localhost' ];

	/**
	 * Hosts that are exactly the local machine.
	 */
	private const LOCAL_HOSTS = [ 'localhost', '127.0.0.1', '::1', '[::1]' ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * The stored override exactly as saved, or an empty string.
	 *
	 * @return string The override, or ''.
	 */
	public function stored(): string {
		$raw = get_option( self::OPTION, '' );

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Stores an override after normalising it, or clears it when given ''.
	 *
	 * @param string $url The address an operator typed.
	 *
	 * @return bool True when the value was accepted and saved.
	 */
	public function save( string $url ): bool {
		$url = trim( $url );

		if ( '' === $url ) {
			return (bool) update_option( self::OPTION, '', false );
		}

		$normalized = self::normalize( $url );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) update_option( self::OPTION, $normalized, false );
	}

	/**
	 * The site's public origin plus install path, with no trailing slash.
	 *
	 * @return string For example `https://example.com` or `https://example.com/blog`.
	 */
	public function base(): string {
		$override = self::normalize( $this->stored() );

		return '' !== $override ? $override : $this->home();
	}

	/**
	 * The host clients will present in the `Host` header, lowercased and with
	 * any port and leading `www.` removed.
	 *
	 * @return string The bare host.
	 */
	public function host(): string {
		return self::bareHost( $this->base() );
	}

	/**
	 * The install path of the public address, '' at the document root.
	 *
	 * Discovery needs this to strip a subdirectory prefix before matching a
	 * request path against `/.well-known/…`, which is the bug that made every
	 * subdirectory install advertise a correct URL and then 404 on it.
	 *
	 * @return string For example '' or '/blog'.
	 */
	public function path(): string {
		$path = (string) wp_parse_url( $this->base(), PHP_URL_PATH );

		return '/' === $path ? '' : rtrim( $path, '/' );
	}

	/**
	 * Whether OAuth may run over this address.
	 *
	 * A bearer token travelling in clear text is a password in clear text, so
	 * the whole feature refuses plain HTTP — except on a host that could not
	 * hold a public certificate anyway, where refusing would only stop people
	 * developing.
	 *
	 * @return bool True when the address is HTTPS or unambiguously local.
	 */
	public function isSecure(): bool {
		if ( str_starts_with( $this->base(), 'https://' ) ) {
			return true;
		}

		return $this->isLocal();
	}

	/**
	 * Whether the public host is the developer's own machine.
	 *
	 * @return bool True for loopback and the local-development suffixes.
	 */
	public function isLocal(): bool {
		return self::isLocalHost( $this->host() );
	}

	/**
	 * Whether a bare host is the developer's own machine.
	 *
	 * Exposed separately so a screen can judge an address an operator has typed
	 * but not yet saved. One list, one answer: the form and the running site
	 * cannot disagree about what counts as local.
	 *
	 * @param string $host A bare host, as {@see self::bareHost()} returns one.
	 *
	 * @return bool True for loopback and the local-development suffixes.
	 */
	public static function isLocalHost( string $host ): bool {
		if ( in_array( $host, self::LOCAL_HOSTS, true ) ) {
			return true;
		}

		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The MCP endpoint a client posts JSON-RPC to. Also the OAuth resource.
	 *
	 * @return string The absolute endpoint URL.
	 */
	public function mcpEndpoint(): string {
		return $this->rebase( rest_url( 'sitehelm/v1/mcp' ) );
	}

	/**
	 * The namespace root the REST discovery aliases hang from.
	 *
	 * @param string $route A route below the namespace, without a leading slash.
	 *
	 * @return string The absolute URL.
	 */
	public function restUrl( string $route ): string {
		return $this->rebase( rest_url( 'sitehelm/v1/' . ltrim( $route, '/' ) ) );
	}

	/**
	 * The issuer identifier both metadata documents publish.
	 *
	 * @return string The issuer.
	 */
	public function issuer(): string {
		return $this->base();
	}

	/**
	 * The protected-resource identifier. Identical to the MCP endpoint, because
	 * that endpoint is the thing a token is minted to reach.
	 *
	 * @return string The resource identifier.
	 */
	public function resource(): string {
		return $this->mcpEndpoint();
	}

	/**
	 * The consent screen's address.
	 *
	 * It is reached through `admin-post.php` rather than a front-end path so
	 * WordPress performs the login redirect itself, and so no rewrite rule has
	 * to exist for the flow to work.
	 *
	 * @return string The absolute authorize URL.
	 */
	public function authorizeUrl(): string {
		return add_query_arg(
			'action',
			AuthorizeEndpoint::ACTION,
			$this->rebase( admin_url( 'admin-post.php' ) )
		);
	}

	/**
	 * Moves a WordPress-generated URL onto the public address.
	 *
	 * Everything WordPress builds — `rest_url()`, `admin_url()` — is rooted at
	 * `home_url()`. When an override is in force those URLs are unreachable, so
	 * the home prefix is swapped for the override and the rest of the path,
	 * including a `?rest_route=` fallback on a site without pretty permalinks,
	 * is carried across untouched.
	 *
	 * @param string $url A URL WordPress generated.
	 *
	 * @return string The same URL under the public address.
	 */
	public function rebase( string $url ): string {
		$base = $this->base();
		$home = $this->home();

		if ( $base === $home || '' === $home ) {
			return $url;
		}

		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}

		return $base . substr( $url, strlen( $home ) );
	}

	/**
	 * WordPress's own idea of the site address, normalised.
	 *
	 * @return string The home URL with no trailing slash.
	 */
	private function home(): string {
		return self::normalize( (string) home_url() );
	}

	/**
	 * Reduces an address to scheme, host, port and path, or '' when it is not
	 * an address this plugin will hand to a client.
	 *
	 * @param string $url The candidate address.
	 *
	 * @return string The normalised address, or '' when unusable.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * The comparable host of an address: lowercased, port dropped, `www.` dropped.
	 *
	 * @param string $url An absolute URL.
	 *
	 * @return string The bare host, or '' when there is none.
	 */
	public static function bareHost( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
