<?php
/**
 * Tests for MediaUrlGuard (REQ-0052).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Media\HostResolver;
use SiteHelm\Modules\Media\MediaUrlGuard;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0052's security centrepiece: the only thing standing between an API caller
 * and this site's cloud metadata endpoint, its VPC neighbours, and its localhost
 * admin panels.
 *
 * Every refusal here is asserted on its specific ErrorCode inside a try/catch,
 * never with expectException(). Every refusal in this codebase is
 * OperationException, so a bare expectException() would pass on a completely
 * different refusal than the one the test aimed at.
 *
 * The resolver is a seam, not a mock: dns_get_record() is a PHP internal that
 * Brain Monkey cannot redefine, so without the seam the whole address policy
 * would be untestable. The fake returns whatever the test hands it, which is
 * exactly the attacker's capability being modelled — a hostile resolver.
 *
 * Several tests below assert that a PUBLIC address is ALLOWED. Those are not
 * decoration: they are what pins the guards that a broader-than-intended range
 * check would silently swallow, and they fail the moment a policy edit starts
 * refusing the public internet.
 */
final class MediaUrlGuardTest extends TestCase {

	/**
	 * Everything the guard wrote to the server log during one test.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();

		// The server log is CAPTURED, not silenced. The refusal messages below are
		// deliberately uninformative, and that is only defensible while the detail
		// they drop is still recorded somewhere an operator can read it.
		$this->logged = [];

		Functions\when( 'error_log' )->alias(
			function ( string $line ): bool {
				$this->logged[] = $line;

				return true;
			}
		);

		// Core's own baseline gate. Faked as "accepts everything" by default so
		// that each test below exercises THIS class's policy rather than core's;
		// one test flips it to false to pin the gate itself.
		//
		// IT RETURNS THE NORMALISED URL, NOT THE INPUT, because that is what core
		// does: `wp_http_validate_url()` hands back the string
		// `wp_kses_bad_protocol()` produced, and `wp_kses_bad_protocol_once2()`
		// lower-cases the scheme. A fake that echoed its argument would model the
		// one property this guard's caller depends on as absent, and MediaFetch's
		// address pin was silently disabled by exactly that difference.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( string $url ) {
				return preg_replace_callback(
					'#\A[a-zA-Z][a-zA-Z0-9+.\-]*:#',
					static function ( array $matches ): string {
						return strtolower( $matches[0] );
					},
					$url,
					1
				);
			}
		);

		// wp_parse_url() delegates to parse_url() on every supported PHP, so the
		// faithful fake is parse_url() itself. Faking it as anything simpler
		// would decide the answers to the credential, port and host questions
		// that this class exists to ask.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
	}

	/**
	 * A guard wired to a resolver that answers with exactly these addresses.
	 *
	 * @param array<int, string> $addresses What DNS is made to say for any host.
	 */
	private function guard( array $addresses = [ '93.184.216.34' ] ): MediaUrlGuard {
		return new MediaUrlGuard(
			new class( $addresses ) implements HostResolver {
				/**
				 * @param array<int, string> $addresses The canned answer.
				 */
				public function __construct( private array $addresses ) {}

				/**
				 * @param string $host Ignored: a hostile resolver need not agree with itself.
				 *
				 * @return array<int, string> The canned answer.
				 */
				public function resolve( string $host ): array {
					unset( $host );

					return $this->addresses;
				}
			}
		);
	}

	/**
	 * Runs $act, asserts it refused with InvalidInput, and hands back the
	 * exception so a caller can read its message.
	 *
	 * A URL this site will not fetch is a bad request, never an execution
	 * failure, so InvalidInput is asserted here once for every refusal test.
	 */
	private function refusal( callable $act ): OperationException {
		try {
			$act();
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );

			return $refusal;
		}

		$this->fail( 'validate() accepted a URL it must refuse.' );
	}

	/**
	 * Refuses $url against a resolver answering $addresses.
	 *
	 * @param array<int, string> $addresses What DNS is made to say.
	 */
	private function assertRefused( string $url, array $addresses = [ '93.184.216.34' ] ): OperationException {
		return $this->refusal(
			fn() => $this->guard( $addresses )->validate( $url )
		);
	}

	/**
	 * Refuses a single resolved address, holding everything else public and
	 * ordinary. Used for the whole blocked-range table.
	 */
	private function assertAddressRefused( string $address ): OperationException {
		return $this->assertRefused( 'https://cdn.example.com/a.png', [ $address ] );
	}

	public function test_a_public_https_url_is_allowed(): void {
		$validated = $this->guard( [ '93.184.216.34' ] )->validate( 'https://cdn.example.com/a.png' );

		$this->assertSame( 'https://cdn.example.com/a.png', $validated['url'] );
		$this->assertSame( 'https', $validated['scheme'] );
		$this->assertSame( 'cdn.example.com', $validated['host'] );
		$this->assertSame( 443, $validated['port'] );
		$this->assertSame( '93.184.216.34', $validated['ip'] );
	}

	public function test_the_returned_url_is_cores_normalised_form_not_the_input(): void {
		// The caller's spelling is NOT what comes back. `WP_Http::request()`
		// rewrites the request to this same normalised string before the
		// transport sees it, so a guard that returned the raw input would hand
		// MediaFetch one spelling while the transport hooks were handed another —
		// and an attacker picks the difference by typing `HTTPS://`. MediaFetch's
		// address pin then silently does not apply.
		$validated = $this->guard( [ '93.184.216.34' ] )->validate( 'HTTPS://cdn.example.com/a.png' );

		$this->assertSame( 'https://cdn.example.com/a.png', $validated['url'] );
		$this->assertSame( 'https', $validated['scheme'] );
		$this->assertSame( 'cdn.example.com', $validated['host'] );
		$this->assertSame( 443, $validated['port'] );
	}

	public function test_a_url_core_rejects_is_refused(): void {
		// Core's gate is kept as the FIRST check so this plugin is never weaker
		// than the platform. Delete it and this URL sails through every check
		// below it, because nothing else about it is wrong.
		Functions\when( 'wp_http_validate_url' )->justReturn( false );

		$this->assertRefused( 'https://cdn.example.com/a.png' );
	}

	public function test_a_value_that_is_not_a_url_at_all_is_refused(): void {
		// parse_url() returns false rather than a partial array. Without its own
		// guard the scheme check would read an array offset off a bool and
		// diagnose a scheme fault, which is the wrong advice for the operator.
		$refusal = $this->assertRefused( 'https:///a.png' );

		$this->assertStringContainsString( 'could not be read as a URL', $refusal->getMessage() );
	}

	public function test_a_parse_that_yields_no_host_is_refused(): void {
		// PHP's parse_url() refuses `https:///a.png` outright today, so the
		// hostless-array shape is reached through the seam rather than through a
		// literal string. The guard still has to exist: wp_parse_url() is a
		// wrapper whose behaviour has changed across core releases, and a
		// hostless array here would otherwise be handed to the resolver.
		Functions\when( 'wp_parse_url' )->justReturn(
			[
				'scheme' => 'https',
				'path'   => '/a.png',
			]
		);

		$refusal = $this->assertRefused( 'https:///a.png' );

		$this->assertStringContainsString( 'does not name a host', $refusal->getMessage() );
	}

	public function test_a_file_scheme_is_refused(): void {
		// Asserted on its message, not merely on the refusal: `file:///etc/passwd`
		// parses to no host at all, so deleting the scheme allowlist leaves the
		// host guard refusing it anyway with the wrong diagnosis.
		$refusal = $this->assertRefused( 'file:///etc/passwd' );

		$this->assertStringContainsString( 'http and https', $refusal->getMessage() );
	}

	public function test_a_gopher_scheme_is_refused(): void {
		// This is the case that makes the scheme allowlist load bearing: the URL
		// has a host, that host resolves publicly, and every other check passes.
		// Delete the allowlist and a gopher:// URL is accepted.
		$this->assertRefused( 'gopher://x/1' );
	}

	public function test_credentials_in_the_url_are_refused(): void {
		$this->assertRefused( 'https://user:pw@example.com/a.png' );
	}

	public function test_a_username_alone_is_refused(): void {
		// A bare username with no password is still a credential replayed at a
		// host of the caller's choosing, and `pass` is absent from the parse, so
		// checking only for a password would let this through.
		$this->assertRefused( 'https://user@example.com/a.png' );
	}

	public function test_a_non_web_port_is_refused(): void {
		$this->assertRefused( 'http://example.com:3306/a.png' );
	}

	public function test_port_80_and_443_are_allowed(): void {
		$eighty = $this->guard()->validate( 'http://example.com:80/a.png' );
		$secure = $this->guard()->validate( 'https://example.com:443/a.png' );

		$this->assertSame( 80, $eighty['port'] );
		$this->assertSame( 443, $secure['port'] );
	}

	public function test_a_bare_http_url_defaults_to_port_eighty(): void {
		$validated = $this->guard()->validate( 'http://example.com/a.png' );

		$this->assertSame( 80, $validated['port'] );
	}

	// A `test_an_empty_host_is_refused` case stood here and was removed in fix
	// round one. It drove `https:///a.png`, which parse_url() refuses outright, so
	// it never reached the empty-host guard at all: it was a duplicate of
	// test_a_value_that_is_not_a_url_at_all_is_refused wearing a name that lied
	// about which branch it pinned. The empty-host guard is pinned, honestly and
	// by name, by test_a_parse_that_yields_no_host_is_refused above.

	public function test_localhost_is_refused(): void {
		// The resolver is made to answer with a PUBLIC address on purpose. A
		// hostile resolver can say anything it likes about `localhost`, so this
		// name is refused BEFORE resolution or not at all. Delete the name check
		// and this URL is accepted on the strength of the resolver's answer.
		$this->assertRefused( 'http://localhost/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_host_written_with_a_trailing_dot_is_refused(): void {
		// THE PIN HOLE THIS CLOSES: `cdn.example.com.` and `cdn.example.com` are
		// one name to DNS and two strings to curl, which keys its resolve cache on
		// the host exactly as written. Pin the normalised spelling, hand the
		// transport the dotted one, and the directive never matches — curl
		// resolves the name itself, the rebinding window reopens, and the
		// fail-closed check downstream still passes because applying the directive
		// succeeded. Everything else about this URL is ordinary and public, so
		// nothing but this refusal stops it.
		$refusal = $this->assertRefused( 'https://cdn.example.com./a.png', [ '93.184.216.34' ] );

		$this->assertStringContainsString( 'ends in a dot', $refusal->getMessage() );
	}

	public function test_a_trailing_dot_does_not_smuggle_localhost_past_the_name_check(): void {
		// `localhost.` is the same name in DNS. Refused for its dot before the
		// name check is ever reached, which is why the message is asserted: were
		// the dot rule deleted, normalise_host()'s rtrim would still catch this
		// one URL and the refusal would silently change identity.
		$refusal = $this->assertRefused( 'http://localhost./a.png', [ '93.184.216.34' ] );

		$this->assertStringContainsString( 'ends in a dot', $refusal->getMessage() );
	}

	public function test_an_uppercase_localhost_is_refused(): void {
		// DNS is case-insensitive, so a case-sensitive comparison against the
		// name would be no comparison at all.
		$this->assertRefused( 'http://LOCALHOST/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_host_that_resolves_to_nothing_is_refused(): void {
		$this->assertRefused( 'https://cdn.example.com/a.png', [] );
	}

	public function test_a_name_that_does_not_resolve_and_one_that_resolves_privately_are_indistinguishable(): void {
		// THE ENVELOPE MUST NOT BE A DNS ORACLE. Two different messages let an
		// unauthenticated caller tell a name that does not exist from one that
		// exists and points inside the network, and map the network one refused
		// preview at a time without ever seeing a response body. Asserted as
		// equality between the two refusals rather than against a literal, so the
		// test cannot be satisfied by editing one message to match a fixture.
		$unresolved = $this->refusal(
			fn() => $this->guard( [] )->validate( 'https://cdn.example.com/a.png' )
		);

		$private = $this->refusal(
			fn() => $this->guard( [ '10.0.0.5' ] )->validate( 'https://cdn.example.com/a.png' )
		);

		$this->assertSame( $unresolved->getMessage(), $private->getMessage() );
		$this->assertSame( $unresolved->remediation, $private->remediation );
	}

	public function test_the_distinction_the_envelope_drops_is_kept_in_the_server_log(): void {
		// The collapsed message is only defensible while the operator can still
		// tell the two apart somewhere, under the correlation id, as everything
		// else in this module does with detail that must not be returned.
		$this->refusal(
			fn() => $this->guard( [] )->validate( 'https://cdn.example.com/a.png', 'corr-unresolved' )
		);

		$this->refusal(
			fn() => $this->guard( [ '10.0.0.5' ] )->validate( 'https://cdn.example.com/a.png', 'corr-private' )
		);

		$this->assertCount( 2, $this->logged );
		$this->assertStringContainsString( 'corr-unresolved', $this->logged[0] );
		$this->assertStringContainsString( 'did not resolve', $this->logged[0] );
		$this->assertStringContainsString( 'corr-private', $this->logged[1] );
		$this->assertStringContainsString( 'non-public address', $this->logged[1] );
		$this->assertStringContainsString( '10.0.0.5', $this->logged[1] );
	}

	public function test_a_loopback_address_is_refused(): void {
		$this->assertAddressRefused( '127.0.0.1' );
	}

	public function test_a_private_ten_address_is_refused(): void {
		$this->assertAddressRefused( '10.0.0.5' );
	}

	public function test_a_private_one_seven_two_address_is_refused(): void {
		$this->assertAddressRefused( '172.16.0.1' );
	}

	public function test_a_private_one_nine_two_address_is_refused(): void {
		$this->assertAddressRefused( '192.168.1.1' );
	}

	public function test_the_cloud_metadata_address_is_refused(): void {
		$this->assertAddressRefused( '169.254.169.254' );
	}

	public function test_a_cgnat_address_is_refused(): void {
		// filter_var()'s reserved-range flag does NOT cover 100.64.0.0/10, so
		// the explicit table is the only thing refusing this. Delete the table
		// lookup and a carrier-internal address is accepted.
		$this->assertAddressRefused( '100.64.0.1' );
	}

	public function test_the_zero_network_is_refused(): void {
		$this->assertAddressRefused( '0.0.0.0' );
	}

	public function test_the_protocol_assignments_range_is_refused(): void {
		// 192.0.0.0/24 is not covered by filter_var()'s flags either.
		$this->assertAddressRefused( '192.0.0.1' );
	}

	public function test_the_benchmarking_range_is_refused(): void {
		// 198.18.0.0/15 is not covered by filter_var()'s flags either.
		$this->assertAddressRefused( '198.18.0.1' );
	}

	public function test_a_multicast_address_is_refused(): void {
		// 224.0.0.0/4 is not covered by filter_var()'s flags either.
		$this->assertAddressRefused( '224.0.0.1' );
	}

	public function test_a_reserved_class_e_address_is_refused(): void {
		$this->assertAddressRefused( '240.0.0.1' );
	}

	public function test_ipv6_loopback_is_refused(): void {
		$this->assertAddressRefused( '::1' );
	}

	public function test_the_ipv6_unspecified_address_is_refused(): void {
		$this->assertAddressRefused( '::' );
	}

	public function test_ipv6_unique_local_is_refused(): void {
		$this->assertAddressRefused( 'fc00::1' );
	}

	public function test_ipv6_link_local_is_refused(): void {
		$this->assertAddressRefused( 'fe80::1' );
	}

	public function test_ipv6_multicast_is_refused(): void {
		// ff00::/8 is not covered by filter_var()'s flags either.
		$this->assertAddressRefused( 'ff02::1' );
	}

	public function test_an_ipv4_mapped_loopback_is_refused(): void {
		// THE case for the unmap path. filter_var() reports `::ffff:127.0.0.1`
		// as a perfectly public IPv6 address on PHP 8.2, and no IPv6 range in
		// the table covers it. It is refused because, once unmapped, it IS
		// loopback — which is why the mapped prefixes are unmapped and
		// re-checked as IPv4 rather than merely listed as blocked IPv6 ranges.
		//
		// Delete the ::ffff:0:0/96 arm of unmap() and this address is accepted.
		$this->assertAddressRefused( '::ffff:127.0.0.1' );
	}

	public function test_a_nat64_embedded_private_address_is_refused(): void {
		// 64:ff9b::a00:5 embeds 10.0.0.5. Same reasoning, second prefix: delete
		// the 64:ff9b::/96 arm of unmap() and a NAT64-wrapped RFC1918 address is
		// accepted.
		$this->assertAddressRefused( '64:ff9b::a00:5' );
	}

	public function test_an_ipv4_compatible_ipv6_loopback_is_refused(): void {
		// `::127.0.0.1` is the deprecated IPv4-COMPATIBLE form, distinct from the
		// IPv4-mapped `::ffff:127.0.0.1` above and reported just as public by
		// filter_var() on PHP 8.2. Delete the `::/96` arm of MAPPED_V4_PREFIXES
		// and loopback walks through: no IPv6 row covers it either, because the
		// ::/128 and ::1/128 rows are exact addresses.
		$this->assertAddressRefused( '::127.0.0.1' );
	}

	public function test_an_ipv4_compatible_ipv6_private_address_is_refused(): void {
		// The same prefix carrying RFC1918 rather than loopback, which is what
		// shows the arm unmaps an address rather than matching a special case.
		// (`::0.0.0.0` needs no case of its own: it IS `::`, already covered by
		// test_the_ipv6_unspecified_address_is_refused.)
		$this->assertAddressRefused( '::10.0.0.5' );
	}

	public function test_a_6to4_address_embedding_loopback_is_refused(): void {
		// 2002:7f00:1:: is 6to4 for 127.0.0.1. The embedded address sits at bytes
		// two to five, NOT in the low thirty-two bits, so this prefix is blocked
		// wholesale instead of unmapped. filter_var() calls it public.
		$this->assertAddressRefused( '2002:7f00:1::' );
	}

	public function test_the_6to4_relay_anycast_address_is_refused(): void {
		// 192.88.99.0/24 is the IPv4 side of the same tunnel and is not covered by
		// filter_var()'s flags.
		$this->assertAddressRefused( '192.88.99.1' );
	}

	public function test_a_teredo_address_is_refused(): void {
		// 2001::/32. Teredo carries an obfuscated IPv4 address and a UDP port,
		// which is why it is blocked rather than unmapped. Note the range is
		// exactly /32: the test below proves it does not swallow 2001:4860::.
		$this->assertAddressRefused( '2001::1' );
	}

	public function test_a_public_address_next_to_the_teredo_range_is_allowed(): void {
		// 2001:4860:4860::8888 is a public resolver inside 2001::/16 but OUTSIDE
		// 2001::/32. This pins the prefix length: widen the Teredo row by a byte
		// and a large slice of the routed IPv6 internet becomes unfetchable.
		$validated = $this->guard( [ '2001:4860:4860::8888' ] )->validate( 'https://cdn.example.com/a.png' );

		$this->assertSame( '2001:4860:4860::8888', $validated['ip'] );
	}

	public function test_the_nat64_local_use_prefix_is_refused(): void {
		// 64:ff9b:1::/48, RFC 8215. Distinct from the well-known 64:ff9b::/96
		// above: the embedding length is an operator's choice here, so there is no
		// fixed offset to unmap from and the whole prefix is refused.
		$this->assertAddressRefused( '64:ff9b:1::a00:5' );
	}

	public function test_ipv6_site_local_is_refused(): void {
		// fec0::/10 is deprecated but still routed on some networks, and
		// filter_var()'s flags do not cover it. fe80::/10 stops at febf::, so the
		// existing link-local row does not reach this.
		$this->assertAddressRefused( 'fec0::1' );
	}

	public function test_a_decimal_ipv4_host_is_refused(): void {
		// 2130706433 is 127.0.0.1 to inet_aton(), and therefore to curl — but it
		// is NOT an address to filter_var(), so without the final-label rule it
		// takes the hostname path, the resolver's public answer becomes the pin,
		// and curl then ignores the pin because it parsed the host as a literal
		// after all. The resolver is armed with a public address to model exactly
		// that. Delete the rule and this URL is accepted.
		$this->assertRefused( 'http://2130706433/a.png', [ '93.184.216.34' ] );
	}

	public function test_an_octal_ipv4_host_is_refused(): void {
		$this->assertRefused( 'http://0177.0.0.1/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_hexadecimal_ipv4_host_is_refused(): void {
		$this->assertRefused( 'http://0x7f000001/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_short_form_ipv4_host_is_refused(): void {
		// 127.1 — the two-part form inet_aton() expands to 127.0.0.1.
		$this->assertRefused( 'http://127.1/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_non_ascii_host_is_refused(): void {
		// The IDN answer: a host outside ASCII letters, digits, hyphens and dots
		// is refused rather than converted, because converting would leave the
		// guard judging one spelling of the host while the transport dialled
		// another. This particular string also looks like `localhost` to a human
		// and does not compare equal to it.
		$refusal = $this->assertRefused( 'http://ⓛocalhost/a.png', [ '93.184.216.34' ] );

		$this->assertStringContainsString( 'not a valid host name', $refusal->getMessage() );
	}

	public function test_a_host_with_an_underscore_is_refused(): void {
		// Same shape rule, second arm: an underscore is legal in some DNS records
		// and not in a host name, and parsers disagree about it.
		$this->assertRefused( 'http://cdn_1.example.com/a.png', [ '93.184.216.34' ] );
	}

	public function test_an_ordinary_host_name_with_digits_and_hyphens_is_allowed(): void {
		// The shape and final-label rules must not cost the public internet.
		// Digits inside labels, a hyphen inside a label, and a multi-part suffix
		// are all ordinary, and a rule written slightly too tight would refuse
		// this while every refusal test above still passed.
		$validated = $this->guard()->validate( 'https://cdn2.example-1.co.uk/a.png' );

		$this->assertSame( 'cdn2.example-1.co.uk', $validated['host'] );
	}

	public function test_a_punycode_host_is_allowed(): void {
		// The supported way to fetch from an internationalised domain: punycode it
		// before sending it. This pins that the ASCII rule refuses non-ASCII input
		// rather than refusing IDNs as such.
		$validated = $this->guard()->validate( 'https://xn--80ak6aa92e.example.com/a.png' );

		$this->assertSame( 'xn--80ak6aa92e.example.com', $validated['host'] );
	}

	public function test_a_public_ipv6_address_is_allowed(): void {
		$validated = $this->guard( [ '2606:4700:4700::1111' ] )->validate( 'https://cdn.example.com/a.png' );

		$this->assertSame( '2606:4700:4700::1111', $validated['ip'] );
	}

	public function test_a_public_ipv6_address_sharing_a_leading_byte_with_a_v4_range_is_allowed(): void {
		// 6440::1 begins with the bytes 0x64 0x40, which are exactly the first
		// two bytes of 100.64.0.0/10. This is what pins the length check in
		// in_range(): without it, substr_compare() would compare a 16-byte
		// address against a 4-byte network, this ordinary public IPv6 address
		// would be refused as CGNAT, and the mirror-image mistake would let an
		// IPv4 address match an IPv6 range.
		$validated = $this->guard( [ '6440::1' ] )->validate( 'https://cdn.example.com/a.png' );

		$this->assertSame( '6440::1', $validated['ip'] );
	}

	public function test_a_resolver_answer_that_is_not_an_address_is_refused(): void {
		// The case that makes filter_var() the sole guard. inet_pton() cannot
		// parse this, so every range comparison in the table returns false and
		// the table refuses nothing. Delete the filter_var() arm and this string
		// becomes the pin that the transport is later told to dial.
		$this->assertAddressRefused( 'not-an-ip' );
	}

	public function test_one_private_record_refuses_a_host_with_a_public_one(): void {
		// A host answering with one public and one loopback address is an
		// attack, not a misconfiguration: accepting it means the connection is
		// decided by resolver ordering. EVERY record must pass, so this pins
		// that the loop does not stop at the first public answer.
		$this->assertRefused( 'https://cdn.example.com/a.png', [ '93.184.216.34', '127.0.0.1' ] );
	}

	public function test_an_ip_literal_host_is_checked_directly(): void {
		// The resolver is armed with a public address it must never be asked
		// for. A literal host is its own resolution; sending it through DNS
		// would let a resolver launder a loopback literal into a public answer.
		$this->assertRefused( 'http://127.0.0.1/a.png', [ '93.184.216.34' ] );
	}

	public function test_an_ipv6_literal_host_is_checked_directly(): void {
		// The brackets are URL syntax, not part of the address, and
		// filter_var() rejects `[::1]` outright — so a guard that forgot to
		// strip them would fail to recognise this as a literal, hand `[::1]` to
		// the resolver, and accept the resolver's public answer.
		$this->assertRefused( 'http://[::1]/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_public_ip_literal_host_is_allowed_and_pins_itself(): void {
		$validated = $this->guard( [ '127.0.0.1' ] )->validate( 'https://93.184.216.34/a.png' );

		$this->assertSame( '93.184.216.34', $validated['host'] );
		$this->assertSame( '93.184.216.34', $validated['ip'] );
	}

	public function test_the_first_public_address_is_returned_as_the_pin(): void {
		$validated = $this->guard( [ '93.184.216.34', '93.184.216.35' ] )->validate( 'https://cdn.example.com/a.png' );

		$this->assertSame( '93.184.216.34', $validated['ip'] );
	}

	/**
	 * The envelope-discipline invariant, read from every refusal this class can
	 * produce rather than sampled.
	 *
	 * A resolved IP address, a hostname's resolution result, or a port derived
	 * from one are exactly what an attacker harvests from a blind SSRF probe.
	 * Leaking any of them turns a refused fetch into an internal port scanner.
	 *
	 * The invariant asserted is stronger and simpler than "no IP address": NO
	 * DIGIT AT ALL may appear in any message or remediation. That is trivially
	 * checkable, cannot be satisfied by a message that names a port or an
	 * address, and leaves no judgement call for a future edit.
	 */
	public function test_no_refusal_message_names_an_address(): void {
		$addresses = [
			'127.0.0.1',
			'10.0.0.5',
			'169.254.169.254',
			'100.64.0.1',
			'::1',
			'fe80::1',
			'::ffff:127.0.0.1',
			'64:ff9b::a00:5',
			'::127.0.0.1',
			'2002:7f00:1::',
			'192.88.99.1',
			'2001::1',
			'64:ff9b:1::a00:5',
			'fec0::1',
			'not-an-ip',
		];

		$refusals = [];

		foreach ( $addresses as $address ) {
			$refusals[] = $this->assertAddressRefused( $address );
		}

		$refusals[] = $this->assertRefused( 'file:///etc/passwd' );
		$refusals[] = $this->assertRefused( 'gopher://x/1' );
		$refusals[] = $this->assertRefused( 'https://user:pw@example.com/a.png' );
		$refusals[] = $this->assertRefused( 'http://example.com:3306/a.png' );
		$refusals[] = $this->assertRefused( 'https:///a.png' );
		$refusals[] = $this->assertRefused( 'http://localhost/a.png' );
		$refusals[] = $this->assertRefused( 'https://cdn.example.com/a.png', [] );
		$refusals[] = $this->assertRefused( 'http://cdn_1.example.com/a.png' );
		$refusals[] = $this->assertRefused( 'http://2130706433/a.png' );

		// Collected last, because reaching the hostless-array branch means
		// replacing the faithful parse fake for the rest of this test.
		Functions\when( 'wp_parse_url' )->justReturn(
			[
				'scheme' => 'https',
				'path'   => '/a.png',
			]
		);

		$refusals[] = $this->assertRefused( 'https:///a.png' );

		foreach ( $refusals as $refusal ) {
			foreach ( [ $refusal->getMessage(), (string) $refusal->remediation ] as $text ) {
				$this->assertDoesNotMatchRegularExpression(
					'/\d/',
					$text,
					'A refusal from MediaUrlGuard carries a digit, which is how an address or a port leaks.'
				);

				foreach ( $addresses as $address ) {
					$this->assertStringNotContainsString( $address, $text );
				}
			}
		}
	}
}
