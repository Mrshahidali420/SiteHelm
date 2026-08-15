<?php
/**
 * The redirect-hop half of the bounded, pinned remote fetch for media import.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Turns a `3xx` response into THE NEXT VALIDATED TARGET, or refuses it.
 *
 * Extracted from MediaFetch, which had reached the file-size ceiling, and
 * extracted along this seam rather than an arbitrary one: deciding where a
 * redirect goes is a question about an ADDRESS an attacker's server supplied,
 * answerable without any of MediaFetch's transport state — no pin, no token, no
 * hook. Everything about the pin stayed behind, because the pin is what
 * MediaFetch is.
 *
 * A REDIRECT IS A DNS-REBINDING ATTACK BY ANOTHER ROUTE, which is why this class
 * exists at all. A `302` to `http://127.0.0.1:8080/` needs no hostile resolver:
 * it simply asks the site to dial an address nobody checked. So the destination
 * goes through MediaUrlGuard::validate() as a BRAND-NEW URL, not as a variation
 * on the one that redirected. Everything the guard checks for an address a
 * caller supplied — scheme, port, credentials, host form, every resolved address
 * being public — is exactly what must be checked for an address an attacker's
 * server supplied, and rather more urgently.
 *
 * MediaFetch, not this class, is what makes that re-validation reachable: it
 * forces `redirection => 0` so WordPress hands the `3xx` back rather than
 * following it inside Requests, and it re-pins the connection to each hop
 * before dialling it. See MediaFetch's own docblock for that half.
 *
 * NO RESPONSE HEADER, NO REDIRECT TARGET AND NO RESOLVED ADDRESS EVER REACHES
 * THE ENVELOPE. Those are what an attacker harvests from a blind SSRF probe, so
 * every refusal here names nothing but what the caller can act on, and the
 * detail goes to `error_log` under the correlation id instead.
 *
 * @package SiteHelm
 */
final class MediaRedirectResolver {

	/**
	 * The statuses this class follows as a redirect.
	 *
	 * `304` is deliberately absent: it is a cache response, not a redirect, and
	 * carries no `Location`. It falls through to MediaFetch's status check and is
	 * refused there, which is the accurate diagnosis.
	 */
	private const REDIRECT_STATUSES = [ 301, 302, 303, 307, 308 ];

	/**
	 * Constructs the resolver.
	 *
	 * @param MediaUrlGuard $guard The address policy every hop is re-validated through.
	 */
	public function __construct( private readonly MediaUrlGuard $guard ) {}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's public surface is camelCase because it is called from camelCase collaborators, and the ruleset's snake_case rule is a WordPress-core convention this plugin's own classes do not follow.
	/**
	 * Whether a response status is one this site follows as a redirect.
	 *
	 * @param int $status The response status.
	 *
	 * @return bool True when the response is a redirect to be followed.
	 */
	public function isRedirect( int $status ): bool {
		return in_array( $status, self::REDIRECT_STATUSES, true );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Validates the destination of a redirect and returns it as the next target.
	 *
	 * The destination goes through MediaUrlGuard::validate() as a brand-new URL,
	 * not as a variation on the one that redirected. Everything the guard checks
	 * for the address a caller supplied — scheme, port, credentials, host form,
	 * every resolved address being public — is exactly what must be checked for
	 * an address an attacker's server supplied, and rather more urgently.
	 *
	 * @param mixed                                                                   $response    The 3xx response.
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $from        The hop that produced it.
	 * @param string                                                                  $correlation The id detail is logged under.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string} The validated next hop.
	 *
	 * @throws OperationException When there is no destination, or the guard refuses it.
	 */
	public function next( $response, array $from, string $correlation ): array {
		$location = wp_remote_retrieve_header( $response, 'location' );

		// Not a string when the header repeated, in which case core hands back
		// an array and there is no single destination to follow. Empty when it
		// was absent. Neither is followed, and neither is guessed at.
		if ( ! is_string( $location ) || '' === trim( $location ) ) {
			$this->log( $correlation, sprintf( 'redirect with no single usable Location from: %s', $from['url'] ) );

			$this->refuse(
				ErrorCode::ExecutionFailed,
				'The remote server sent a redirect with no destination.',
				'Supply the address the file is finally served from.'
			);
		}

		return $this->guard->validate( $this->absolute_hop( trim( $location ), $from, $correlation ), $correlation );
	}

	/**
	 * Resolves a `Location` value against the hop that produced it.
	 *
	 * Against THAT hop, not against the original URL: a chain that redirects to
	 * another host and then sends a root-relative `Location` means a path on the
	 * second host, and resolving it against the first would dial an address
	 * nobody chose.
	 *
	 * ONLY THREE FORMS ARE RESOLVED — absolute, protocol-relative and
	 * root-relative — and anything else is refused. Path-relative and dot-segment
	 * forms are legal in RFC 3986 and essentially absent from real asset redirects,
	 * and implementing them would mean a home-grown path normaliser whose
	 * disagreements with curl's would land exactly where this class's disagreements
	 * are most expensive. Refusing is the safe direction: a refused import, not a
	 * request to an address this class computed differently from its transport.
	 *
	 * IT IS LOGGED WHEN IT REFUSES. A path-relative `Location` was accepted as a
	 * non-gap in review because it is rare, but "rare" is not "never", and a
	 * refusal whose cause is invisible on the envelope by design must be visible
	 * somewhere.
	 *
	 * @param string                                                                  $location    The `Location` value, trimmed and non-empty.
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $from        The hop that produced it.
	 * @param string                                                                  $correlation The id detail is logged under.
	 *
	 * @return string The absolute URL.
	 *
	 * @throws OperationException When the form is not one this class resolves.
	 */
	private function absolute_hop( string $location, array $from, string $correlation ): string {
		if ( 1 === preg_match( '#\A[a-z][a-z0-9+.\-]*:#i', $location ) ) {
			return $location;
		}

		if ( str_starts_with( $location, '//' ) ) {
			return $from['scheme'] . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return sprintf( '%s://%s:%d%s', $from['scheme'], $from['host'], $from['port'], $location );
		}

		$this->log( $correlation, sprintf( 'unresolvable Location "%s" from: %s', $location, $from['url'] ) );

		$this->refuse(
			ErrorCode::ExecutionFailed,
			'The remote server redirected to a destination this site cannot resolve.',
			'Supply the address the file is finally served from.'
		);
	}

	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log is the only sink available to a plugin for detail that must not reach the envelope; the alternative is losing the diagnosis entirely.
	/**
	 * Records detail server-side that must never reach the envelope.
	 *
	 * @param string $correlation The correlation id the detail is filed under.
	 * @param string $detail      The unsafe detail.
	 */
	private function log( string $correlation, string $detail ): void {
		error_log( sprintf( 'SiteHelm media import (%s): %s', $correlation, $detail ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- every message and remediation reaching this method is a literal written in this file for end users, and escaping them would put HTML entities into a JSON envelope.
	/**
	 * Refuses the fetch.
	 *
	 * Every message and remediation passed here names the redirect problem and
	 * nothing else. See the class docblock for why a header, a redirect target or
	 * a resolved address may never appear in one.
	 *
	 * @param ErrorCode $code        The stable public error code.
	 * @param string    $message     Safe, human-readable explanation.
	 * @param string    $remediation What the caller can do about it.
	 *
	 * @return never
	 *
	 * @throws OperationException Always.
	 */
	private function refuse( ErrorCode $code, string $message, string $remediation ): never {
		throw new OperationException( $code, $message, $remediation );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
