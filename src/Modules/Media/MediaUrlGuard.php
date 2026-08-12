<?php
/**
 * The SSRF address policy for media import.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0052's security centrepiece: the single choke point that decides whether
 * this site will issue an outbound HTTP request to an address an API caller
 * chose.
 *
 * `media-import` is the first and only thing in this plugin that lets a caller
 * pick the destination of a request the SITE makes. The site's network position
 * is the asset under attack: a WordPress install can usually reach a cloud
 * metadata endpoint, an internal admin panel, a database port, and every other
 * host on its VPC — none of which the caller can reach directly. That is
 * server-side request forgery, and this class is the whole of the defence.
 *
 * THE POLICY IS A HARDENED PUBLIC FETCH. Any public host is reachable. There is
 * no allowlist, no site option, and deliberately NO FILTER HOOK: a
 * configuration escape hatch here would be a supported way to re-open the hole,
 * and a site that needs host restrictions has no supported way to ask for one
 * in this release.
 *
 * EVERY REFUSAL IS ErrorCode::InvalidInput. A URL this site will not fetch is a
 * bad request, never an execution failure.
 *
 * NO REFUSAL MESSAGE OR REMEDIATION IN THIS CLASS CONTAINS A DIGIT. That rule is
 * stricter than it needs to be on purpose. A resolved IP address, a resolution
 * result, or a port learned from one are exactly what an attacker harvests from
 * a blind SSRF probe, and leaking any of them turns a refused fetch into an
 * internal port scanner. "No digits" is trivially checkable, is asserted over
 * every refusal this class can produce, and leaves no judgement call for a
 * future edit.
 *
 * This class performs NO HTTP. It is pure policy plus one DNS lookup through the
 * HostResolver seam. MediaFetch owns the request, re-validates every redirect
 * hop through this same guard, and pins the connection to the `ip` returned
 * here — because without that pin an attacker's resolver can answer this
 * lookup with a public address and answer the transport's lookup, milliseconds
 * later, with a loopback one.
 *
 * @package SiteHelm
 */
final class MediaUrlGuard {

	/**
	 * The permitted schemes, as an ALLOWLIST rather than a deny list, so that
	 * `file:`, `gopher:`, `dict:` and whatever the next protocol turns out to be
	 * are all refused without anyone having to enumerate them first.
	 */
	private const ALLOWED_SCHEMES = [ 'http', 'https' ];

	/**
	 * The permitted ports. Refuses every non-web internal service in one line.
	 */
	private const ALLOWED_PORTS = [ 80, 443 ];

	/**
	 * The port assumed when the URL names none.
	 */
	private const DEFAULT_PORTS = [
		'http'  => 80,
		'https' => 443,
	];

	/**
	 * IPv4 ranges this site will not fetch from.
	 *
	 * This table is a FLOOR, not a ceiling, and it is deliberately redundant
	 * with `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`. Rows marked
	 * "defence in depth" are already refused by those flags on PHP 8.2; they
	 * stay listed because the flags' coverage is a PHP implementation detail
	 * that has changed before, and because a reviewer must be able to read this
	 * site's policy in one place rather than reconstruct it from a PHP
	 * changelog. Rows NOT so marked are the sole guard for their range.
	 */
	private const BLOCKED_V4 = [
		[ '0.0.0.0', 8 ],        // "This network"; 0.0.0.0 reaches localhost on Linux. Defence in depth.
		[ '10.0.0.0', 8 ],       // RFC1918. Defence in depth.
		[ '100.64.0.0', 10 ],    // CGNAT — carrier-internal, not public. SOLE GUARD.
		[ '127.0.0.0', 8 ],      // Loopback. Defence in depth.
		[ '169.254.0.0', 16 ],   // Link-local; 169.254.169.254 is the cloud metadata endpoint. Defence in depth.
		[ '172.16.0.0', 12 ],    // RFC1918. Defence in depth.
		[ '192.0.0.0', 24 ],     // IETF protocol assignments. SOLE GUARD.
		[ '192.168.0.0', 16 ],   // RFC1918. Defence in depth.
		[ '198.18.0.0', 15 ],    // Benchmarking. SOLE GUARD.
		[ '224.0.0.0', 4 ],      // Multicast. SOLE GUARD.
		[ '240.0.0.0', 4 ],      // Reserved. Defence in depth.
	];

	/**
	 * IPv6 ranges this site will not fetch from.
	 *
	 * Same rule as BLOCKED_V4: redundancy with the filter flags is intentional.
	 * The IPv4-mapped and NAT64 prefixes are NOT in this table — see
	 * MAPPED_V4_PREFIXES for why blocking them here would be the wrong shape.
	 */
	private const BLOCKED_V6 = [
		[ '::', 128 ],           // Unspecified. Defence in depth.
		[ '::1', 128 ],          // Loopback. Defence in depth.
		[ 'fc00::', 7 ],         // Unique-local. Defence in depth.
		[ 'fe80::', 10 ],        // Link-local. Defence in depth.
		[ 'ff00::', 8 ],         // Multicast. SOLE GUARD.
	];

	/**
	 * IPv6 prefixes that CARRY an IPv4 address in their low 32 bits.
	 *
	 * These are UNMAPPED AND RE-CHECKED AS IPv4, never merely blocked as IPv6
	 * ranges, and the distinction is the point rather than a refinement.
	 * `::ffff:127.0.0.1` must be refused BECAUSE IT IS LOOPBACK — a reader has
	 * to be able to see that reasoning in the code instead of trusting that
	 * somebody remembered to add a row. Blocking the prefix wholesale would also
	 * refuse `::ffff:<public address>`, which is a perfectly ordinary way to
	 * name a public host, and would leave the NEXT embedding scheme silently
	 * unhandled.
	 *
	 * It matters concretely: on PHP 8.2 `filter_var()` reports
	 * `::ffff:127.0.0.1` as a fully public IPv6 address. Without the unmapping,
	 * loopback walks straight through the address check.
	 */
	private const MAPPED_V4_PREFIXES = [
		[ '::ffff:0:0', 96 ],    // IPv4-mapped IPv6.
		[ '64:ff9b::', 96 ],     // NAT64 well-known prefix.
	];

	/**
	 * Constructs the guard.
	 *
	 * @param HostResolver $resolver The DNS seam. See HostResolver for why it exists.
	 */
	public function __construct( private readonly HostResolver $resolver ) {}

	/**
	 * Decides whether this site will fetch the supplied URL, and pins the
	 * address it may be fetched from.
	 *
	 * The order of the checks is the design, not an accident: the cheapest and
	 * most conclusive refusals come first, core's own baseline comes before this
	 * plugin's additions so the plugin is never WEAKER than the platform, and
	 * nothing is handed to a resolver until it has been established that the
	 * URL is one this site would fetch at all.
	 *
	 * @param string $url The caller-supplied URL.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string}
	 *         The validated URL, its normalised host, its effective port, and the
	 *         address the transport must be pinned to.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput on every refusal.
	 */
	public function validate( string $url ): array {
		// 1. Core's own baseline, first, so this plugin can only ever be
		// STRICTER than the platform. It is not kept as the only gate: it misses
		// link-local (the cloud metadata range on AWS, GCP and Azure) and does
		// not consider IPv6 at all.
		if ( ! wp_http_validate_url( $url ) ) {
			$this->refuse(
				'The supplied address was refused by this site before it was examined.',
				'Supply a complete, publicly reachable http or https URL to the asset.'
			);
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			$this->refuse(
				'The supplied value could not be read as a URL.',
				'Supply a complete http or https URL to the asset, including its host.'
			);
		}

		// 2. Scheme allowlist.
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			$this->refuse(
				'Only http and https addresses can be imported from.',
				'Supply an http or https URL to the asset.'
			);
		}

		// 3. No credentials. Never needed for a public asset, and it is how a
		// caller gets the site to replay a credential at a host of their
		// choosing. A username with no password is still a credential, so the
		// absence of `pass` proves nothing on its own.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$this->refuse(
				'An address carrying a username or password cannot be imported from.',
				'Supply a URL without embedded credentials.'
			);
		}

		// 4. Port must be absent, or one of the two web ports.
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : self::DEFAULT_PORTS[ $scheme ];

		if ( ! in_array( $port, self::ALLOWED_PORTS, true ) ) {
			$this->refuse(
				'Only the standard web ports can be imported from.',
				'Supply a URL with no port, or on the standard http or https port.'
			);
		}

		// 5. Host must exist and must not be this machine by name.
		$host = $this->normalise_host( (string) ( $parts['host'] ?? '' ) );

		if ( '' === $host ) {
			$this->refuse(
				'The supplied address does not name a host.',
				'Supply a complete http or https URL to the asset, including its host.'
			);
		}

		// Refused BY NAME, before any resolution. Relying on `localhost`
		// resolving to loopback would be relying on the resolver, and a resolver
		// that answers however it likes is the entire threat this class exists
		// to contain.
		if ( 'localhost' === $host ) {
			$this->refuse(
				'This site will not import from its own machine.',
				'Supply a URL on a publicly reachable host.'
			);
		}

		// 6. Resolve. An IP literal is its own resolution.
		$addresses = $this->addresses_for( $host );

		if ( [] === $addresses ) {
			$this->refuse(
				'The host in the supplied address could not be resolved.',
				'Check the host name and request a fresh preview.'
			);
		}

		// 7. EVERY address must be public. A host answering with one public and
		// one private address is an attack, not a misconfiguration: accepting it
		// would mean the address actually dialled is decided by resolver
		// ordering, so one bad answer refuses the whole URL.
		foreach ( $addresses as $address ) {
			$this->assert_public_address( $address );
		}

		// 8. The first address is the pin.
		return [
			'url'    => $url,
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'ip'     => $addresses[0],
		];
	}

	/**
	 * Normalises a host for comparison and for pinning.
	 *
	 * Lower-cased because DNS is case-insensitive; the surrounding brackets of
	 * an IPv6 literal are stripped because they are URL syntax rather than part
	 * of the address, and `filter_var()` rejects a bracketed literal outright —
	 * a guard that forgot to strip them would fail to recognise `[::1]` as a
	 * literal and hand it to the resolver. Trailing dots go because
	 * `localhost.` is the same name as `localhost`.
	 *
	 * @param string $host The raw host component.
	 *
	 * @return string The normalised host.
	 */
	private function normalise_host( string $host ): string {
		return rtrim( trim( strtolower( $host ), '[]' ), '.' );
	}

	/**
	 * The addresses a host stands for.
	 *
	 * An IP literal is checked DIRECTLY and never sent through the resolver.
	 * Resolving it would give a hostile resolver the chance to launder a
	 * loopback literal into a public answer, which is the reverse of what the
	 * resolver is here for.
	 *
	 * @param string $host The normalised host.
	 *
	 * @return array<int, string> The addresses to judge.
	 */
	private function addresses_for( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}

		return $this->resolver->resolve( $host );
	}

	/**
	 * Refuses any address that is not one this site will fetch from.
	 *
	 * Two independent tests, both required. `filter_var()`'s flags are the
	 * platform's own idea of what is private or reserved, and they are the sole
	 * guard for anything `inet_pton()` cannot even parse — a resolver answer
	 * that is not an address at all would match no range in the table and would
	 * otherwise become the pin the transport is told to dial. The explicit table
	 * is the sole guard for the ranges the flags miss, CGNAT and multicast among
	 * them.
	 *
	 * @param string $address One resolved address.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the address is not public.
	 */
	private function assert_public_address( string $address ): void {
		$candidate = $this->unmap( $address );

		$public = filter_var(
			$candidate,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( false === $public || $this->is_blocked( $candidate ) ) {
			$this->refuse(
				'The requested address is not a public internet address this site will fetch from.',
				'Use a URL on a publicly reachable host and request a fresh preview.'
			);
		}
	}

	/**
	 * Extracts the IPv4 address embedded in a mapped IPv6 address.
	 *
	 * See MAPPED_V4_PREFIXES for why these prefixes are unmapped and re-checked
	 * as IPv4 rather than simply listed as blocked ranges.
	 *
	 * @param string $address The address to unmap.
	 *
	 * @return string The embedded IPv4 address, or the input unchanged.
	 *
	 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
	 */
	private function unmap( string $address ): string {
		$packed = @inet_pton( $address );

		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $address;
		}

		foreach ( self::MAPPED_V4_PREFIXES as [ $network, $bits ] ) {
			if ( ! $this->in_range( $address, $network, $bits ) ) {
				continue;
			}

			$embedded = @inet_ntop( substr( $packed, 12, 4 ) );

			if ( is_string( $embedded ) ) {
				return $embedded;
			}
		}

		return $address;
	}
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	/**
	 * Whether an address falls in any explicitly blocked range.
	 *
	 * Both tables are walked for every address. The length check inside
	 * in_range() is what keeps that safe: an IPv4 address can never match an
	 * IPv6 range, or the reverse.
	 *
	 * @param string $address The address to test.
	 *
	 * @return bool True when the address is inside a blocked range.
	 */
	private function is_blocked( string $address ): bool {
		foreach ( array_merge( self::BLOCKED_V4, self::BLOCKED_V6 ) as [ $network, $bits ] ) {
			if ( $this->in_range( $address, $network, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address falls inside a network, compared bit by bit on PACKED
	 * BINARY.
	 *
	 * Never string prefixes. `10.0.0.5` and `100.64.0.1` share a textual prefix
	 * and are in unrelated networks; `1.2.3.4` and `1.2.3.40` do too. Only the
	 * packed form makes a prefix length mean what it says.
	 *
	 * THE LENGTH CHECK IS LOAD BEARING. `inet_pton()` returns four bytes for
	 * IPv4 and sixteen for IPv6; without the check, `substr_compare()` would
	 * compare a four-byte address against a sixteen-byte network, and an
	 * ordinary public IPv6 address beginning with the bytes of an IPv4 network
	 * would be refused as private — or, worse in the other direction, an IPv4
	 * address would match an IPv6 range.
	 *
	 * @param string $address The address to test.
	 * @param string $network The network base address.
	 * @param int    $bits    The prefix length in bits.
	 *
	 * @return bool True when the address is inside the network.
	 *
	 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
	 */
	private function in_range( string $address, string $network, int $bits ): bool {
		$a = @inet_pton( $address );
		$n = @inet_pton( $network );

		if ( false === $a || false === $n || strlen( $a ) !== strlen( $n ) ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $whole > 0 && 0 !== substr_compare( $a, $n, 0, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;

		return ( ord( $a[ $whole ] ) & $mask ) === ( ord( $n[ $whole ] ) & $mask );
	}
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	/**
	 * Refuses the URL.
	 *
	 * Every message and remediation passed here is digit-free by rule; see the
	 * class docblock for why that rule is stricter than "no IP addresses".
	 *
	 * @param string $message     Safe, human-readable explanation.
	 * @param string $remediation What the caller can do about it.
	 *
	 * @return never
	 *
	 * @throws OperationException Always, with ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function refuse( string $message, string $remediation ): never {
		throw new OperationException( ErrorCode::InvalidInput, $message, $remediation );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
