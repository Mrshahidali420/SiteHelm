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
