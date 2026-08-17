<?php
/**
 * The host a gateway request arrived on, and how it compares to the site's own.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Policy;

/**
 * REQ-0076: whether this request reached the site by the address the site
 * believes it lives at.
 *
 * A domain that has been retired usually keeps resolving for a while — parked at
 * the old registrar, aliased in a vhost, cached in someone's client config. The
 * site answers on it, WordPress serves the REST route on it, and a connector
 * nobody remembered to update goes on writing to what its operator believes is
 * the old site. Nothing about the request is malformed; it is simply pointed at
 * an address the site has stopped claiming.
 *
 * Two decisions here are deliberate and easy to get wrong:
 *
 * - A REQUEST WITH NO HOST IS NOT A MISMATCH. WP-CLI, cron, and an internal REST
 *   dispatch all arrive with no `Host` header at all. Failing closed would refuse
 *   every write from those, which is a working setup broken to defend against a
 *   case that cannot occur: there is no stale client config in a process that
 *   never left the server.
 * - A LEADING `www.` IS NOT A MISMATCH EITHER. WordPress serves the REST route on
 *   both spellings without redirecting, so an install whose home URL drops the
 *   `www` still answers on it, and refusing that would break connectors that have
 *   worked since the day they were set up. The requirement is about a domain the
 *   site no longer answers as, not about a spelling of the one it does.
 *
 * @package SiteHelm
 */
final class RequestHost {

	/**
	 * The host this request arrived on, normalized, or null when there is none.
	 *
	 * The port is dropped: a site reachable on more than one port is still the
	 * same site, and `home_url()` records the port inconsistently enough that
	 * comparing it would produce false mismatches on local installs.
	 */
	public static function current(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize() below reduces this to a lowercase host with no port; the value is only ever compared, never output, stored, or interpolated.
		$raw = $_SERVER['HTTP_HOST'] ?? '';

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$normalized = self::normalize( $raw );

		return '' === $normalized ? null : $normalized;
	}

	/**
	 * Whether this request arrived on an address the site still claims.
	 *
	 * @param string $siteHost The host the site records as its own.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public static function matches( string $siteHost ): bool {
		$arrived = self::current();
		$claimed = self::normalize( $siteHost );

		if ( null === $arrived || '' === $claimed ) {
			return true;
		}

		return $arrived === $claimed;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Reduces a host to the form the comparison is made in: lower case, without a
	 * port, without a trailing root dot, and without a leading `www.`.
	 *
	 * @param string $host The host as written.
	 */
	private static function normalize( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		$host = rtrim( $host, '.' );

		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}
}
