<?php
/**
 * The production DNS resolver behind the media import address policy.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

/**
 * Resolves host names with the platform resolver, via `dns_get_record()`.
 *
 * THIS CLASS BODY IS THE ONLY UNIT-UNCOVERED CODE THIS COMPONENT CONTRIBUTES,
 * and that is by construction rather than by omission. `dns_get_record()` is a
 * PHP internal function; Brain Monkey redefines userland functions only, so a
 * unit test cannot make it answer. The HostResolver seam exists precisely so
 * that the POLICY — every range, every unmapping, every refusal in
 * MediaUrlGuard — is exhaustively testable without it. What is left uncovered
 * here is the translation of core's record arrays into a flat address list, and
 * nothing that decides whether an address may be fetched from.
 *
 * @package SiteHelm
 */
final class SystemHostResolver implements HostResolver {

	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a host that does not resolve is an ordinary refusal on this path, not a warning for the site operator's log; the empty result is handled by the caller.
	/**
	 * Resolves a host to every A and AAAA address it answers with.
	 *
	 * Errors are silenced deliberately. A host that does not resolve is an
	 * ORDINARY REFUSAL on this path — the caller named a URL this site cannot
	 * reach — and not a PHP warning for the site operator to read in a log.
	 * MediaUrlGuard turns the empty result into an InvalidInput refusal with
	 * remediation the caller can act on.
	 *
	 * @param string $host The host name to resolve.
	 *
	 * @return array<int, string> Every address, de-duplicated, in resolver order.
	 */
	public function resolve( string $host ): array {
		$records = [];
		foreach ( [ DNS_A, DNS_AAAA ] as $type ) {
			$answer = @dns_get_record( $host, $type );
			if ( is_array( $answer ) ) {
				$records = array_merge( $records, $answer );
			}
		}

		$addresses = [];
		foreach ( $records as $record ) {
			$address = $record['ip'] ?? ( $record['ipv6'] ?? null );
			if ( is_string( $address ) && '' !== $address ) {
				$addresses[] = $address;
			}
		}

		return array_values( array_unique( $addresses ) );
	}
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
}
