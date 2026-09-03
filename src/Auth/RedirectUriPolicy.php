<?php
/**
 * Which redirect URIs may be registered, and when a presented one matches.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * The rules deciding where an authorization code may be delivered.
 *
 * This class knows nothing about WordPress, the database or the request, so it
 * can be reasoned about — and tested — as pure logic. That matters more here
 * than anywhere else in the module: a redirect URI is the address a credential
 * is posted to, and every mistake in these rules is an account takeover.
 *
 * The shape of the allowance is deliberate. MCP clients are desktop apps. They
 * come back either on a loopback HTTP port they picked at launch, or on a
 * private scheme the operating system routes to them (`claude://`, `cursor://`).
 * Both are accommodated; the wider internet is not.
 *
 * @package SiteHelm
 */
final class RedirectUriPolicy {

	/**
	 * The longest redirect URI that may be registered.
	 */
	public const MAX_LENGTH = 512;

	/**
	 * Schemes that can execute in a browsing context or reach the local disk.
	 * A code delivered to one of these is a code handed to script.
	 */
	private const DENIED_SCHEMES = [
		'javascript',
		'data',
		'file',
		'blob',
		'about',
		'view-source',
		'chrome',
		'chrome-extension',
		'moz-extension',
		'ms-appx',
		'jar',
	];

	/**
	 * Schemes with established, unrelated meanings on the network. A client
	 * asking for one of these has misunderstood the flow, and honouring it
	 * would post a credential somewhere no browser is listening.
	 */
	private const RESERVED_SCHEMES = [
		'ftp',
		'ftps',
		'sftp',
		'mailto',
		'tel',
		'sms',
		'ws',
		'wss',
		'smb',
		'nfs',
		'gopher',
		'ldap',
		'ldaps',
	];

	/**
	 * The three spellings of the local machine. A CLI client uses whichever its
	 * platform prefers, and may not use the same one twice.
	 */
	private const LOOPBACK_HOSTS = [ '127.0.0.1', '::1', 'localhost' ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Whether a URI may be registered at all.
	 *
	 * @param string $uri The redirect URI a client offered.
	 *
	 * @return bool True when it may be stored.
	 */
	public function accepts( string $uri ): bool {
		return '' === $this->refusalReason( $uri );
	}

	/**
	 * Why a URI was refused, in words an operator can act on.
	 *
	 * @param string $uri The redirect URI a client offered.
	 *
	 * @return string The reason, or '' when the URI is acceptable.
	 */
	public function refusalReason( string $uri ): string {
		if ( '' === $uri || strlen( $uri ) > self::MAX_LENGTH ) {
			return 'A redirect URI must be between 1 and ' . self::MAX_LENGTH . ' characters long.';
		}

		if ( str_contains( $uri, '#' ) ) {
			return 'A redirect URI may not carry a fragment.';
		}

		if ( preg_match( '/[[:cntrl:]\s]/', $uri ) ) {
			return 'A redirect URI may not contain spaces or control characters.';
		}

		$scheme = $this->scheme( $uri );

		if ( '' === $scheme ) {
			return 'A redirect URI must be absolute and begin with a scheme, such as https:// or myapp://.';
		}

		if ( in_array( $scheme, self::DENIED_SCHEMES, true ) ) {
			return sprintf( 'The %s scheme can run code in a browser, so it may not receive an authorization code.', $scheme );
		}

		if ( 'http' === $scheme ) {
			return $this->isLoopback( $uri )
				? ''
				: 'Plain http is only accepted on 127.0.0.1, ::1 or localhost. Use https for anything else.';
		}

		if ( 'https' === $scheme ) {
			return '' === (string) wp_parse_url( $uri, PHP_URL_HOST )
				? 'An https redirect URI must name a host.'
				: '';
		}

		if ( in_array( $scheme, self::RESERVED_SCHEMES, true ) ) {
			return sprintf( 'The %s scheme already means something else on the network and cannot receive an authorization code.', $scheme );
		}

		return '';
	}

	/**
	 * Whether a presented URI matches one that was registered.
	 *
	 * Exact, byte for byte, with one carve-out: two loopback HTTP URIs match
	 * when their paths agree, whatever host spelling and port each carries. A
	 * command-line client binds a fresh ephemeral port every launch and spells
	 * the local machine however its platform does, so demanding a byte match
	 * there would mean re-registering on every run. HTTPS URIs and private
	 * schemes get no such latitude.
	 *
	 * @param string $registered The URI stored at registration.
	 * @param string $presented  The URI the client sent now.
	 *
	 * @return bool True when the presented URI may be redirected to.
	 */
	public function matches( string $registered, string $presented ): bool {
		if ( hash_equals( $registered, $presented ) ) {
			return true;
		}

		if ( ! $this->isLoopback( $registered ) || ! $this->isLoopback( $presented ) ) {
			return false;
		}

		return $this->loopbackPath( $registered ) === $this->loopbackPath( $presented );
	}

	/**
	 * Finds the registered URI a presented one matches.
	 *
	 * @param string[] $registered Every URI stored for the client.
	 * @param string   $presented  The URI the client sent now.
	 *
	 * @return string|null The matching registered URI, or null.
	 */
	public function match( array $registered, string $presented ): ?string {
		foreach ( $registered as $candidate ) {
			if ( $this->matches( (string) $candidate, $presented ) ) {
				return (string) $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether a URI is plain HTTP on the local machine.
	 *
	 * @param string $uri The URI to inspect.
	 *
	 * @return bool True for loopback HTTP.
	 */
	private function isLoopback( string $uri ): bool {
		if ( 'http' !== $this->scheme( $uri ) ) {
			return false;
		}

		$host = strtolower( trim( (string) wp_parse_url( $uri, PHP_URL_HOST ), '[]' ) );

		return in_array( $host, self::LOOPBACK_HOSTS, true );
	}

	/**
	 * The comparable path of a loopback URI: its path and query, with a
	 * trailing slash treated as no difference.
	 *
	 * @param string $uri A loopback URI.
	 *
	 * @return string The comparable remainder.
	 */
	private function loopbackPath( string $uri ): string {
		$path  = rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * The lowercased scheme of a URI, or '' when it is not well formed.
	 *
	 * @param string $uri The URI to inspect.
	 *
	 * @return string The scheme.
	 */
	private function scheme( string $uri ): string {
		$scheme = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );

		return 1 === preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ? $scheme : '';
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
