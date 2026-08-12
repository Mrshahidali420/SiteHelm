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
		[ '192.88.99.0', 24 ],   // 6to4 relay anycast — the IPv4 side of the 2002::/16 tunnel. SOLE GUARD.
		[ '192.168.0.0', 16 ],   // RFC1918. Defence in depth.
		[ '198.18.0.0', 15 ],    // Benchmarking. SOLE GUARD.
		[ '224.0.0.0', 4 ],      // Multicast. SOLE GUARD.
		[ '240.0.0.0', 4 ],      // Reserved. Defence in depth.
	];

	/**
	 * IPv6 ranges this site will not fetch from.
	 *
	 * Same rule as BLOCKED_V4: redundancy with the filter flags is intentional.
	 * The IPv4-mapped and NAT64 well-known prefixes are NOT in this table — see
	 * MAPPED_V4_PREFIXES for why blocking them here would be the wrong shape.
	 *
	 * THE TRANSITION PREFIXES BELOW ARE BLOCKED WHOLESALE RATHER THAN UNMAPPED,
	 * and that asymmetry is deliberate. A prefix earns a place in
	 * MAPPED_V4_PREFIXES only when the embedded IPv4 address sits at a FIXED
	 * offset, which is true of a /96 and of nothing else. 6to4 carries its IPv4
	 * address at bytes two to five; Teredo carries an obfuscated one at the end;
	 * RFC 8215's local-use NAT64 prefix is a /48 whose embedding may be at /48,
	 * /56, /64 or /96 depending on how the operator configured it, so there is no
	 * single offset to read. Guessing an offset would produce a confident,
	 * wrong IPv4 address and check THAT instead of the real destination. Since
	 * none of these prefixes routes to an ordinary public host, refusing the
	 * whole prefix costs nothing a caller can legitimately want.
	 */
	private const BLOCKED_V6 = [
		[ '::', 128 ],           // Unspecified. Defence in depth; also shadowed by the ::/96 unmapping.
		[ '::1', 128 ],          // Loopback. Defence in depth; also shadowed by the ::/96 unmapping.
		[ '64:ff9b:1::', 48 ],   // NAT64 local-use prefix, RFC 8215. Variable embedding offset. SOLE GUARD.
		[ '2001::', 32 ],        // Teredo tunnelling. SOLE GUARD.
		[ '2002::', 16 ],        // 6to4 tunnelling; 2002:7f00:1:: reaches 127.0.0.1. SOLE GUARD.
		[ 'fc00::', 7 ],         // Unique-local. Defence in depth.
		[ 'fe80::', 10 ],        // Link-local. Defence in depth.
		[ 'fec0::', 10 ],        // Site-local, deprecated but still routed on some networks. SOLE GUARD.
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
	 * It matters concretely, AND IT MATTERS PER PHP VERSION. On PHP 8.1 and 8.2
	 * `filter_var()` reports `::ffff:127.0.0.1` and `::127.0.0.1` as fully public
	 * IPv6 addresses; PHP 8.3 unmaps them itself before applying the range flags
	 * and reports both as private. This unmapping is therefore the SOLE GUARD for
	 * embedded IPv4 on two of the three PHP versions this plugin supports, and
	 * redundant on the third. That split is exactly why the table exists rather
	 * than a trust in the platform: "the flags cover it" was true of one release
	 * and false of the one before it, and will be re-decided by a PHP changelog
	 * nobody here will read in time.
	 *
	 * Every prefix here is a /96, and that is a rule rather than a coincidence:
	 * only a /96 puts the embedded address at a fixed offset. Prefixes that carry
	 * IPv4 somewhere else are blocked outright in BLOCKED_V6 instead.
	 *
	 * `::/96` IS LISTED LAST AND MUST STAY LAST. It is the widest of the three,
	 * and while it cannot in fact swallow the other two — an IPv4-mapped address
	 * has `ff ff` at bytes ten and eleven, and the NAT64 well-known prefix has
	 * `00 64 ff 9b` at bytes zero to three, so neither has the twelve leading zero
	 * bytes `::/96` requires — a future prefix added above it would be shadowed
	 * silently. Ordering by decreasing specificity keeps that trap shut.
	 */
	private const MAPPED_V4_PREFIXES = [
		[ '::ffff:0:0', 96 ],    // IPv4-mapped IPv6.
		[ '64:ff9b::', 96 ],     // NAT64 well-known prefix.
		[ '::', 96 ],            // IPv4-compatible IPv6, deprecated but still parsed. Keep last.
	];

	/**
	 * The shape of a host name this site is willing to hand to a resolver.
	 *
	 * ASCII letters, digits, hyphens and dots only; labels of one to sixty-three
	 * characters that neither begin nor end with a hyphen; no empty label; two
	 * hundred and fifty-three characters overall. `\A` and `\z` rather than `^`
	 * and `$` because `$` also matches before a trailing newline, and a host name
	 * with a newline welded to its end is precisely the sort of thing that gets
	 * split differently by two parsers.
	 *
	 * The ASCII restriction is also the IDN answer: an internationalised host must
	 * be supplied already punycoded. Converting it here with idn_to_ascii() was
	 * rejected because `intl` is not guaranteed present on a WordPress host, and
	 * because a conversion would give the guard and the transport two different
	 * spellings of the same host — the guard would judge one name while curl
	 * dialled the other, which is the divergence this whole class exists to close.
	 */
	private const HOST_NAME = '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/';

	/**
	 * The final label of a host name must begin with an ASCII letter.
	 *
	 * This one line is what closes the alternative-notation hole. `2130706433`,
	 * `0177.0.0.1`, `0x7f000001` and `127.1` are all loopback to inet_aton(), and
	 * therefore to curl, but NONE of them is an IP address to
	 * `filter_var( FILTER_VALIDATE_IP )` — so without this rule they take the
	 * hostname path, a resolver answers with a public address, that public address
	 * becomes the pin, and curl then ignores the pin entirely because it parsed
	 * the host as a literal after all. The pin would be inert and the fetch would
	 * hit loopback.
	 *
	 * They are REFUSED rather than normalised into dotted quads. Normalising would
	 * make this class a second, home-grown implementation of address parsing that
	 * has to agree with curl's forever, and would return a host string the caller
	 * never wrote. Refusing costs nothing real: no legitimate asset URL names its
	 * host in octal, and a genuine numeric address can always be written as one.
	 */
	private const HOST_NAME_FINAL_LABEL = '/\A[a-z]/';

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

		// 6. A host that is not an IP literal must be a host NAME, and not merely
		// a string a resolver might answer for. See HOST_NAME_FINAL_LABEL: the
		// numeric notations refused here are addresses to curl and names to
		// filter_var(), and that disagreement is what makes the pin inert.
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$this->assert_fetchable_name( $host );
		}

		// 7. Resolve. An IP literal is its own resolution.
		//
		// array_values() because everything downstream indexes this list from
		// zero, and a resolver seam is free to hand back any array shape it likes.
		$addresses = array_values( $this->addresses_for( $host ) );

		if ( [] === $addresses ) {
			$this->refuse(
				'The host in the supplied address could not be resolved.',
				'Check the host name and request a fresh preview.'
			);
		}

		// 8. EVERY address must be public. A host answering with one public and
		// one private address is an attack, not a misconfiguration: accepting it
		// would mean the address actually dialled is decided by resolver
		// ordering, so one bad answer refuses the whole URL.
		foreach ( $addresses as $address ) {
			$this->assert_public_address( $address );
		}

		// 9. The first address is the pin.
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
	 * Refuses any non-literal host that is not a host NAME this site would ask a
	 * resolver about.
	 *
	 * Two rules, kept separate because they close different holes and each must
	 * be able to fail on its own: the shape rule (see HOST_NAME) refuses anything
	 * that is not an ASCII LDH domain name, and the final-label rule (see
	 * HOST_NAME_FINAL_LABEL) refuses the numeric notations that pass the shape
	 * rule but are addresses to every C library on the machine.
	 *
	 * @param string $host The normalised, non-literal host.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the host is not a name.
	 */
	private function assert_fetchable_name( string $host ): void {
		if ( 1 !== preg_match( self::HOST_NAME, $host ) ) {
			$this->refuse(
				'The host in the supplied address is not a valid host name.',
				'Supply a URL whose host is an ordinary domain name, written in ASCII or punycode.'
			);
		}

		$labels = explode( '.', $host );

		if ( 1 !== preg_match( self::HOST_NAME_FINAL_LABEL, (string) end( $labels ) ) ) {
			$this->refuse(
				'The host in the supplied address is written as a number rather than as a name.',
				'Supply a URL whose host is a domain name, or an address written in the ordinary dotted or colon-separated form.'
			);
		}
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

	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() and inet_ntop() are deliberately fed strings that may not be addresses at all, since judging a hostile resolver's answer is this class's job; their diagnostic warning carries that answer into the site's error log, and the false return is handled on the line below every silenced call.
	/**
	 * Extracts the IPv4 address embedded in a mapped IPv6 address.
	 *
	 * See MAPPED_V4_PREFIXES for why these prefixes are unmapped and re-checked
	 * as IPv4 rather than simply listed as blocked ranges.
	 *
	 * @param string $address The address to unmap.
	 *
	 * @return string The embedded IPv4 address, or the input unchanged.
	 */
	private function unmap( string $address ): string {
		$packed = @inet_pton( $address );

		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $address;
		}

		foreach ( self::MAPPED_V4_PREFIXES as [ $network, $bits ] ) {
			if ( ! $this->in_range( $packed, $network, $bits ) ) {
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

	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() is deliberately fed a string that may not be an address at all; the false return is handled on the line below the silenced call.
	/**
	 * Whether an address falls in any explicitly blocked range.
	 *
	 * Both tables are walked for every address. The length check inside
	 * in_range() is what keeps that safe: an IPv4 address can never match an
	 * IPv6 range, or the reverse. They are walked as two tables rather than
	 * merged into one, because array_merge() would rebuild the same combined
	 * array on every single address for no gain.
	 *
	 * The address is packed ONCE here rather than once per row, and a string that
	 * is no address at all simply matches nothing: this method is not the guard
	 * for that case, filter_var() in assert_public_address() is, and duplicating
	 * the refusal here would leave two arms that can only ever fire together and
	 * therefore neither of which any test could pin alone.
	 *
	 * @param string $address The address to test.
	 *
	 * @return bool True when the address is inside a blocked range.
	 */
	private function is_blocked( string $address ): bool {
		$packed = @inet_pton( $address );

		if ( false !== $packed ) {
			foreach ( [ self::BLOCKED_V4, self::BLOCKED_V6 ] as $table ) {
				foreach ( $table as [ $network, $bits ] ) {
					if ( $this->in_range( $packed, $network, $bits ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() is deliberately fed a network base address from this class's own tables; the false return is handled on the line below the silenced call, so a mistyped row fails closed rather than warning on every request.
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
	 * @param string $packed  The address to test, already in packed binary form.
	 * @param string $network The network base address, in text form.
	 * @param int    $bits    The prefix length in bits.
	 *
	 * @return bool True when the address is inside the network.
	 */
	private function in_range( string $packed, string $network, int $bits ): bool {
		$n = @inet_pton( $network );

		if ( false === $n || strlen( $packed ) !== strlen( $n ) ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $whole > 0 && 0 !== substr_compare( $packed, $n, 0, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;

		return ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $n[ $whole ] ) & $mask );
	}
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- every message and remediation reaching this method is a literal written in this file for end users, and escaping them would put HTML entities into a JSON envelope.
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
	 */
	private function refuse( string $message, string $remediation ): never {
		throw new OperationException( ErrorCode::InvalidInput, $message, $remediation );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
