<?php
/**
 * Tests for MediaFetch (REQ-0052): pinning, hooks, statuses and body limits.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Modules\Media\MediaMimeGuard;

/**
 * MediaUrlGuard decides WHETHER an address may be fetched. This class tests the
 * transport that makes sure the request actually goes to the address the guard
 * approved.
 *
 * The redirect hop loop — the part of that promise that survives a `3xx` — is
 * tested in MediaFetchRedirectTest. See MediaFetchTestCase for the WordPress
 * stand-in both files share, and for why it is deliberately unhelpful.
 */
final class MediaFetchTest extends MediaFetchTestCase {

	/**
	 * Declared FIRST and process-isolated on purpose. It is the only test in
	 * either MediaFetch file that needs `function_exists( 'curl_setopt' )` to be
	 * FALSE, and Brain Monkey leaks a fake's definition — though not its
	 * behaviour — to every later test in the same process.
	 *
	 * What it pins: a site on WordPress's streams transport has no
	 * CURLOPT_RESOLVE. Delete the function_exists guard and the fetch dies on an
	 * undefined function instead of proceeding under the other guards, which is
	 * the accepted cost in spec §7 item 3 turning into an outage.
	 *
	 * IT SKIPS WHERE ext-curl IS PRESENT, and that is a genuine gap rather than
	 * a convenience. `function_exists()` is a fact about the running PHP, not a
	 * value a test can arrange, so the branch is unreachable on any interpreter
	 * that has the extension — which includes CI. It is exercised on this
	 * project's toolchain PHP, which has no ext-curl. See the fix-round-1 notes
	 * in the task report.
	 *
	 * @group curl-must-be-absent
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_fetch_proceeds_when_curl_is_unavailable(): void {
		if ( function_exists( 'curl_setopt' ) ) {
			$this->markTestSkipped( 'ext-curl is loaded in this PHP, so the streams-transport branch cannot be reached here.' );
		}

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	public function test_the_fetch_pins_the_request_to_the_validated_address(): void {
		// THE DNS-rebinding defence, end to end: the decision is read mid-request
		// from the hook core would apply it on, and the directive must name the
		// address the guard approved — so the address that was validated is the
		// address dialled.
		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( [ 'cdn.example.com:443:93.184.216.34' ], $this->pinnedDirectives() );
	}

	public function test_the_pin_reaches_curl_setopt(): void {
		// The last link: that the decision above is actually handed to curl,
		// under CURLOPT_RESOLVE, as a one-element list. Only observable on a PHP
		// without ext-curl — see requireObservableCurl() for why that cannot be
		// arranged on one that has it.
		$this->requireObservableCurl();

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame(
			[ [ CURLOPT_RESOLVE, [ 'cdn.example.com:443:93.184.216.34' ] ] ],
			$this->curlOptions
		);
	}

	public function test_the_faked_curl_constant_matches_the_extensions_own(): void {
		// The fake defines CURLOPT_RESOLVE itself, so a wrong value there would
		// make every pin assertion agree with itself and with nothing else.
		if ( ! extension_loaded( 'curl' ) ) {
			$this->markTestSkipped( 'No ext-curl here to check the constant against.' );
		}

		$this->assertSame( 10203, CURLOPT_RESOLVE );
	}

	public function test_an_ip_literal_target_is_not_pinned(): void {
		// A literal is its own resolution, so there is no name lookup for an
		// attacker to rebind and a CURLOPT_RESOLVE directive would be inert.
		// That is only safe because since 9e3801f MediaUrlGuard refuses octal,
		// hex, decimal-integer and non-ASCII hosts outright — so a literal that
		// reaches here is one curl will parse the same way this class did.
		// Delete the branch and the pin becomes a silently useless directive
		// that reads like a working defence.
		$target = [
			'url'    => 'https://93.184.216.34/a.png',
			'scheme' => 'https',
			'host'   => '93.184.216.34',
			'port'   => 443,
			'ip'     => '93.184.216.34',
		];

		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $target, 'corr-1' ) );

		// Reached the hook, and declined — not "never got there".
		$this->assertSame( [ null ], $this->pinDecisions );
	}

	public function test_a_successful_fetch_returns_the_body_bytes(): void {
		$this->respondWith( $this->responseWith( 200, 'PNGBYTES' ) );

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	public function test_the_resolve_directive_pins_host_port_and_ip(): void {
		// This exact string is what CURLOPT_RESOLVE understands, and it is the
		// whole of the DNS-rebinding defence: it tells curl not to ask a
		// resolver at all for this host and port.
		$this->assertSame(
			'cdn.example.com:443:93.184.216.34',
			$this->fetcher()->resolveDirective( $this->validated() )
		);
	}

	public function test_a_transport_error_is_refused_without_naming_it(): void {
		$this->respondWith(
			new class() {
				public function get_error_message(): string {
					return 'cURL error 7: Failed to connect to 127.0.0.1 port 8080';
				}
			}
		);

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		// The transport's own message is the single richest blind-SSRF oracle in
		// the whole feature: it names the address dialled, the port, and whether
		// the connection was refused or timed out. It goes to error_log under
		// the correlation id and never into the envelope.
		$this->assertStringNotContainsString( 'curl', strtolower( $refusal->getMessage() ) );
		$this->assertStringNotContainsString( '127.0.0.1', $refusal->getMessage() );
		$this->assertStringNotContainsString( '8080', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'Failed to connect', $refusal->getMessage() );
	}

	public function test_a_transport_error_is_logged_under_the_correlation_id(): void {
		// The other half of the same rule. Detail that must not reach the
		// envelope must still reach SOMEWHERE, or a refused import becomes
		// undiagnosable — and it has to carry the correlation id or it cannot be
		// tied back to the request that produced it.
		$logged = [];

		Functions\when( 'error_log' )->alias(
			function ( string $line ) use ( &$logged ): bool {
				$logged[] = $line;

				return true;
			}
		);

		$this->respondWith(
			new class() {
				public function get_error_message(): string {
					return 'cURL error 7: Failed to connect to 127.0.0.1 port 8080';
				}
			}
		);

		$this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'corr-1', $logged[0] );
		$this->assertStringContainsString( '127.0.0.1', $logged[0] );
	}

	public function test_a_404_is_refused_naming_only_the_status(): void {
		$this->respondWith( $this->responseWith( 404, 'Not Found' ) );

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( '404', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_500_is_refused(): void {
		$this->respondWith( $this->responseWith( 500, 'boom' ) );

		$this->assertStringContainsString(
			'500',
			$this->assertFetchRefused( ErrorCode::ExecutionFailed )->getMessage()
		);
	}

	public function test_a_204_is_refused(): void {
		// A 204 is not an error to a naive `>= 400` check, and it carries no
		// body. Refusing anything other than 200 is what makes it fail here
		// rather than three lines later with a confusing empty-body diagnosis.
		$this->respondWith( $this->responseWith( 204, '' ) );

		$this->assertStringContainsString(
			'204',
			$this->assertFetchRefused( ErrorCode::ExecutionFailed )->getMessage()
		);
	}

	public function test_a_304_is_refused_rather_than_followed(): void {
		// A 304 looks like a 3xx and is not a redirect: it is a cache response
		// with no Location. Treating it as one would produce a "redirect with no
		// destination" diagnosis for something that is simply not the asset.
		$this->respondWith( $this->responseWith( 304, '' ) );

		$this->assertStringContainsString(
			'304',
			$this->assertFetchRefused( ErrorCode::ExecutionFailed )->getMessage()
		);
	}

	public function test_an_empty_body_is_refused(): void {
		// A 200 with nothing in it. MediaMimeGuard would refuse the empty string
		// later, but the diagnosis belongs here where the remote server's
		// behaviour is what went wrong.
		$this->respondWith( $this->responseWith( 200, '' ) );

		$this->assertFetchRefused( ErrorCode::ExecutionFailed );
	}

	public function test_a_body_over_the_size_cap_is_refused(): void {
		// `limit_response_size` is set to the cap PLUS ONE precisely so that an
		// over-cap response arrives one byte over and is recognisable here,
		// rather than arriving truncated to exactly the cap and being accepted
		// as a valid, silently corrupted file.
		$this->respondWith( $this->responseWith( 200, str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES + 1 ) ) );

		$this->assertFetchRefused( ErrorCode::InvalidInput );
	}

	public function test_a_body_at_the_size_cap_is_allowed(): void {
		// The boundary in the allowing direction. Without it, an off-by-one that
		// refuses everything at the cap would pass every other test in this file.
		$bytes = str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES );

		$this->respondWith( $this->responseWith( 200, $bytes ) );

		$this->assertSame( $bytes, $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	public function test_both_hooks_are_registered_at_the_last_word_priority(): void {
		// Named AND at a stated priority. The removal tests below compare added
		// against removed, so they stay green if a hook is never added at all —
		// this is what pins each registration, and PHP_INT_MAX is what makes the
		// forcing filter the last one to run.
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertContains( [ 'http_request_args', PHP_INT_MAX ], $this->added );
		$this->assertContains( [ 'http_api_curl', PHP_INT_MAX ], $this->added );
	}

	public function test_the_hooks_are_removed_after_a_successful_fetch(): void {
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertNotSame( [], $this->added, 'The class registered no hooks at all.' );
		$this->assertSame( [], $this->leakedHooks() );
	}

	public function test_the_hooks_are_removed_after_a_failed_fetch(): void {
		// THE TEST THAT MATTERS MOST IN THIS FILE. A leaked CURLOPT_RESOLVE pin
		// re-points unrelated HTTP requests made later in the same process, so a
		// refused import would turn into a defect in every other plugin on the
		// site. Delete the `finally` and this fails while every happy-path test
		// still passes.
		$this->respondWith( $this->responseWith( 500, 'boom' ) );

		$this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertNotSame( [], $this->added, 'The class registered no hooks at all.' );
		$this->assertSame( [], $this->leakedHooks() );
	}

	public function test_the_request_arguments_force_the_safe_settings(): void {
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$args = $this->sentArgs[0];

		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( MediaMimeGuard::MAX_DECODED_BYTES + 1, $args['limit_response_size'] );
		$this->assertIsInt( $args['timeout'] );
		$this->assertGreaterThan( 0, $args['timeout'] );
		$this->assertStringContainsString( 'SiteHelm', (string) $args['user-agent'] );
		$this->assertFalse( $args['stream'] );
	}

	public function test_the_transport_is_told_not_to_follow_redirects_itself(): void {
		// The keystone of the whole redirect design. `http_request_args` fires
		// once per WP_Http::request() and redirects are followed below it, inside
		// Requests — so a hop WordPress follows is a hop this class never sees,
		// never re-validates and never re-pins. Zero here is what forces the 3xx
		// back up to fetch()'s loop, where it can be.
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( 0, $this->sentArgs[0]['redirection'] );
	}

	public function test_a_competing_filter_at_a_high_priority_does_not_win(): void {
		// Another plugin's http_request_args filter runs alongside this one.
		// Registering at PHP_INT_MAX and re-forcing unconditionally is what
		// makes this class the last word: a safety setting a third party can
		// switch off is not a safety setting. The competing filter here is
		// registered at a priority far above the WordPress default and still
		// loses, because the fake runs filters in priority order like core.
		add_filter(
			'http_request_args',
			static function ( array $args ): array {
				return array_merge(
					$args,
					[
						'reject_unsafe_urls'  => false,
						'redirection'         => 20,
						'limit_response_size' => PHP_INT_MAX,
						'timeout'             => 600,
						'stream'              => true,
					]
				);
			},
			9999,
			2
		);

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$args = $this->sentArgs[0];

		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( MediaMimeGuard::MAX_DECODED_BYTES + 1, $args['limit_response_size'] );
		$this->assertLessThan( 600, $args['timeout'] );
		$this->assertFalse( $args['stream'] );
	}

	public function test_an_unrelated_request_argument_survives(): void {
		// The merge must FORCE the safety arguments, not replace the whole
		// argument set: a cookie or header another plugin added has nothing to
		// do with this policy and must still be there.
		add_filter(
			'http_request_args',
			static function ( array $args ): array {
				$args['headers'] = [ 'X-Trace' => 'abc' ];

				return $args;
			},
			10,
			2
		);

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( [ 'X-Trace' => 'abc' ], $this->sentArgs[0]['headers'] );
	}

	public function test_the_forcing_filter_ignores_a_request_this_class_did_not_make(): void {
		// WordPress hooks are global. While a fetch is in flight, an update
		// check or another plugin's API call passes through this same filter,
		// and forcing this import's timeout, user-agent and redirect ban onto it
		// would make this feature a defect in unrelated code.
		$fetch = $this->fetcher();

		$stranger = [ 'timeout' => 600 ];

		$this->respondWith( $this->responseWith( 200, 'PNGBYTES' ) );

		add_filter(
			'http_request_args',
			function ( array $args, string $url ) use ( $fetch, &$stranger ): array {
				unset( $url );

				$stranger = $fetch->filterRequestArgs( $stranger, 'https://elsewhere.example.org/ping' );

				return $args;
			},
			1,
			2
		);

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( [ 'timeout' => 600 ], $stranger );
	}

	public function test_the_curl_pin_ignores_a_request_this_class_did_not_make(): void {
		// The same hazard on the more dangerous hook: pinning somebody else's
		// handle re-points THEIR connection at THIS import's address. Asked
		// mid-fetch, while a pin genuinely is in force, because asking when
		// nothing is pinned would pass on the null-pin branch instead.
		$fetch    = $this->fetcher();
		$stranger = 'unset';
		$mine     = 'unset';

		add_action(
			'http_api_curl',
			function () use ( $fetch, &$stranger, &$mine ): void {
				$stranger = $fetch->pinDirectiveFor( 'https://elsewhere.example.org/ping' );
				$mine     = $fetch->pinDirectiveFor( 'https://cdn.example.com/a.png' );
			},
			1,
			3
		);

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertNull( $stranger );
		$this->assertSame( 'cdn.example.com:443:93.184.216.34', $mine );
	}

	public function test_the_pin_recognises_every_equivalent_spelling_of_its_own_url(): void {
		// THE ATTACKER PICKS THE SPELLING. WordPress hands `http_request_args` the
		// url as supplied and `http_api_curl` the url after core has rewritten it
		// through wp_kses_bad_protocol(), which lower-cases the scheme — and on a
		// redirect the whole string comes from the remote server. A comparison by
		// raw string identity therefore had a silent off switch: one capital letter
		// and the class stopped recognising its own request, applied no
		// CURLOPT_RESOLVE, and let the transport resolve the name for itself. Every
		// spelling below addresses the same host and port and must be recognised;
		// every spelling in the second group addresses something else and must not.
		$fetch  = $this->fetcher();
		$asked  = [];
		$expect = 'cdn.example.com:443:93.184.216.34';

		add_action(
			'http_api_curl',
			function () use ( $fetch, &$asked ): void {
				foreach (
					[
						'same'          => 'https://cdn.example.com/a.png',
						'scheme case'   => 'HTTPS://cdn.example.com/a.png',
						'host case'     => 'https://CDN.Example.COM/a.png',
						'trailing dot'  => 'https://cdn.example.com./a.png',
						'default port'  => 'https://cdn.example.com:443/a.png',
						'other path'    => 'https://cdn.example.com/other%2Fpath.png',
						'other port'    => 'https://cdn.example.com:80/a.png',
						'other scheme'  => 'http://cdn.example.com/a.png',
						// Scheme differing while host AND port still agree, so the
						// scheme comparison is the only thing that can reject it.
						'scheme alone'  => 'http://cdn.example.com:443/a.png',
						'other host'    => 'https://other.example.com/a.png',
						'not a url'     => 'not a url at all',
						// parse_url() refuses this outright. A url this class could
						// not parse must never be taken for the one in flight.
						'unparsable'    => 'https://cdn.example.com:port/a.png',
					] as $label => $url
				) {
					$asked[ $label ] = $fetch->pinDirectiveFor( $url );
				}
			},
			1,
			3
		);

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( $expect, $asked['same'] );
		$this->assertSame( $expect, $asked['scheme case'] );
		$this->assertSame( $expect, $asked['host case'] );
		$this->assertSame( $expect, $asked['trailing dot'] );
		$this->assertSame( $expect, $asked['default port'] );
		$this->assertSame( $expect, $asked['other path'] );

		$this->assertNull( $asked['other port'] );
		$this->assertNull( $asked['other scheme'] );
		$this->assertNull( $asked['scheme alone'] );
		$this->assertNull( $asked['other host'] );
		$this->assertNull( $asked['not a url'] );
		$this->assertNull( $asked['unparsable'] );
	}

	public function test_an_http_target_recognises_its_own_default_port(): void {
		// The other half of the default-port rule. Reading an absent port as 443
		// unconditionally would leave every plain-http import unrecognised at the
		// pinning hook and therefore unpinned — and, now, refused outright.
		$this->dns['plain.example.com'] = [ '93.184.216.37' ];

		$target = [
			'url'    => 'http://plain.example.com/a.png',
			'scheme' => 'http',
			'host'   => 'plain.example.com',
			'port'   => 80,
			'ip'     => '93.184.216.37',
		];

		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $target, 'corr-1' ) );
		$this->assertSame( [ 'plain.example.com:80:93.184.216.37' ], $this->pinnedDirectives() );
	}

	public function test_a_later_hop_that_goes_out_unpinned_is_refused(): void {
		// The per-hop half of the fail-closed check. Carrying the first hop's
		// "yes, pinned" verdict into the second would make the check pass for a
		// chain whose LAST request — the one that actually returns the bytes —
		// went out with no address pin at all, which is the exact shape of the
		// stale-pin bug this class was rewritten to close.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$this->rewriteCurlUrl = static function ( string $url ): string {
			return false === strpos( $url, 'images.example.net' ) ? $url : 'https://somewhere-else.example.org/a.png';
		};

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_target_spelt_with_an_uppercase_scheme_is_still_pinned(): void {
		// Defence in depth against the same defect. MediaUrlGuard now hands back
		// core's normalised url, so in the assembled feature the two hook points
		// see the same string — but this class must not be left depending on that,
		// because the guard's normalisation is one edit away from being lost and
		// the failure it would cause here is silent. The fixture applies core's
		// scheme rewrite between the two hooks, exactly as WP_Http does, so the
		// filter sees `HTTPS://` and the pinning action sees `https://`.
		$target        = $this->validated();
		$target['url'] = 'HTTPS://cdn.example.com/a.png';

		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $target, 'corr-1' ) );
		$this->assertSame( [ 'cdn.example.com:443:93.184.216.34' ], $this->pinnedDirectives() );
	}

	public function test_a_fetch_whose_connection_was_not_pinned_is_refused(): void {
		// THE FAIL-CLOSED HALF. "This url is not mine, leave it alone" and "this
		// url IS mine and I did not recognise it, so it went out unpinned" are the
		// same silence at the hook. The first is correct and constant; the second
		// is the DNS-rebinding hole reopened. Here the pinning hook is fired with a
		// url the class cannot match — which is what any future gap in the matching
		// rule would look like from inside — and the fetch must refuse rather than
		// hand back bytes it cannot vouch for.
		$this->rewriteCurlUrl = static function (): string {
			return 'https://somewhere-else.example.org/a.png';
		};

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_the_failed_pin_refusal_is_logged_under_the_correlation_id(): void {
		// The refusal above says nothing about the address by design, so the
		// diagnosis has to exist server-side or the most security-relevant failure
		// in this class would be the least debuggable one.
		$logged = [];

		Functions\when( 'error_log' )->alias(
			function ( string $line ) use ( &$logged ): bool {
				$logged[] = $line;

				return true;
			}
		);

		$this->rewriteCurlUrl = static function (): string {
			return 'https://somewhere-else.example.org/a.png';
		};

		$this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'corr-1', $logged[0] );
		$this->assertStringContainsString( 'not pinned', $logged[0] );
	}

	public function test_a_streams_transport_fetch_is_not_refused_for_want_of_a_pin(): void {
		// The fail-closed check must not become an outage on every site whose HTTP
		// transport is not curl. Such a site never fires `http_api_curl`, has no
		// CURLOPT_RESOLVE to apply and never did — the accepted cost in spec §7
		// item 3 — so the absence of a pin there is not a failure to detect.
		$this->curlTransport = false;

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
		$this->assertSame( [], $this->pinDecisions );
	}

	public function test_an_ipv6_address_is_bracketed_in_the_directive(): void {
		// curl documents the bracketed form for IPv6 in --resolve, and the
		// unbracketed one is only unambiguous by accident of where curl's parser
		// stops splitting on colons. HostResolver returns AAAA answers, so this is
		// a live case.
		$target = [
			'url'    => 'https://cdn6.example.com/a.png',
			'scheme' => 'https',
			'host'   => 'cdn6.example.com',
			'port'   => 443,
			'ip'     => '2606:4700::1111',
		];

		$this->assertSame(
			'cdn6.example.com:443:[2606:4700::1111]',
			$this->fetcher()->resolveDirective( $target )
		);
	}

	public function test_an_ipv6_target_is_pinned_under_its_bracketed_address(): void {
		// And the same through the hook, so the bracketing is proven on the path
		// the pin actually takes rather than only on the helper.
		$this->dns = [ 'cdn6.example.com' => [ '2606:4700::1111' ] ];

		$target = [
			'url'    => 'https://cdn6.example.com/a.png',
			'scheme' => 'https',
			'host'   => 'cdn6.example.com',
			'port'   => 443,
			'ip'     => '2606:4700::1111',
		];

		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $target, 'corr-1' ) );
		$this->assertSame( [ 'cdn6.example.com:443:[2606:4700::1111]' ], $this->pinnedDirectives() );
	}

	public function test_the_pin_is_cleared_after_a_fetch(): void {
		// The pin is torn down alongside the hooks. Leaving it set would arm the
		// next accidental invocation of the action callback with a stale target.
		$fetch = $this->fetcher();

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertNull( $fetch->pinDirectiveFor( 'https://cdn.example.com/a.png' ) );
	}

	public function test_the_curl_pin_is_a_no_op_when_nothing_is_pinned(): void {
		// The action can only fire while a fetch is in flight, but WordPress
		// hooks are global and this callback is public. With no pin set it must
		// touch nothing rather than dereference a null target.
		$fetch  = $this->fetcher();
		$handle = 'curl-handle';

		$fetch->pinCurlHandle( $handle, [], 'https://cdn.example.com/a.png' );

		$this->assertNull( $fetch->pinDirectiveFor( 'https://cdn.example.com/a.png' ) );
		$this->assertSame( [], $this->curlOptions );
	}

	/**
	 * The envelope-discipline invariant, read from every refusal this file can
	 * produce rather than sampled. See assertRefusalLeaksNothing() for the four
	 * oracles being swept for and why each one matters.
	 */
	public function test_no_refusal_message_contains_an_ip_address(): void {
		$refusals = [];

		$this->respondWith(
			new class() {
				public function get_error_message(): string {
					return 'cURL error 7: Failed to connect to 169.254.169.254 port 80';
				}
			}
		);

		$refusals[] = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		foreach ( [ 204, 304, 404, 500 ] as $code ) {
			$this->respondWith( $this->responseWith( $code, '' ) );

			$refusals[] = $this->assertFetchRefused( ErrorCode::ExecutionFailed );
		}

		$this->respondWith( $this->responseWith( 200, '' ) );
		$refusals[] = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->respondWith( $this->responseWith( 200, str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES + 1 ) ) );
		$refusals[] = $this->assertFetchRefused( ErrorCode::InvalidInput );

		foreach ( $refusals as $refusal ) {
			$this->assertRefusalLeaksNothing( $refusal );
		}
	}
}
