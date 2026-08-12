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

	protected function setUp(): void {
		parent::setUp();

		// Core's own baseline gate. Faked as "accepts everything" by default so
		// that each test below exercises THIS class's policy rather than core's;
		// one test flips it to false to pin the gate itself.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( string $url ) {
				return $url;
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

	public function test_an_empty_host_is_refused(): void {
		$this->assertRefused( 'https:///a.png' );
	}

	public function test_localhost_is_refused(): void {
		// The resolver is made to answer with a PUBLIC address on purpose. A
		// hostile resolver can say anything it likes about `localhost`, so this
		// name is refused BEFORE resolution or not at all. Delete the name check
		// and this URL is accepted on the strength of the resolver's answer.
		$this->assertRefused( 'http://localhost/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_trailing_dot_does_not_smuggle_localhost_past_the_name_check(): void {
		// `localhost.` is the same name in DNS. Comparing the raw host string
		// would miss it.
		$this->assertRefused( 'http://localhost./a.png', [ '93.184.216.34' ] );
	}

	public function test_an_uppercase_localhost_is_refused(): void {
		// DNS is case-insensitive, so a case-sensitive comparison against the
		// name would be no comparison at all.
		$this->assertRefused( 'http://LOCALHOST/a.png', [ '93.184.216.34' ] );
	}

	public function test_a_host_that_resolves_to_nothing_is_refused(): void {
		$this->assertRefused( 'https://cdn.example.com/a.png', [] );
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
