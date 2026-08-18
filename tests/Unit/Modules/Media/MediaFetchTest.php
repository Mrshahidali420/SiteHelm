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
use SiteHelm\Modules\Media\MediaFetch;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaUrlGuard;

/**
 * MediaUrlGuard decides WHETHER an address may be fetched. This class tests the
 * transport that makes sure the request actually goes to the address the guard
 * approved.
 *
 * The redirect hop loop — the part of that promise that survives a `3xx` — is
 * tested in MediaFetchRedirectTest, and "is this request even mine?", the
 * question both hook callbacks ask before anything else, in
 * MediaFetchOwnershipTest. See MediaFetchTestCase for the WordPress stand-in all
 * three files share, and for why it is deliberately unhelpful.
 */
final class MediaFetchTest extends MediaFetchTestCase {

	/**
	 * Declared FIRST and process-isolated on purpose. It is the only test in
	 * either MediaFetch file that needs `function_exists( 'curl_setopt' )` to be
	 * FALSE, and Brain Monkey leaks a fake's definition — though not its
	 * behaviour — to every later test in the same process.
	 *
	 * What it pins: `curl_setopt()` is called unguarded nowhere. Delete the
	 * function_exists() test and this dies on an undefined function — a fatal, not
	 * a refusal, and one raised from inside a WordPress hook callback where nothing
	 * above it can turn it back into an envelope.
	 *
	 * IT IS A REFUSAL, NOT A FETCH, and that changed with the fail-closed
	 * assertion. `http_api_curl` firing means core chose the curl transport, so a
	 * PHP that fires it while having no curl functions is a contradiction: there is
	 * nothing to pin with, and returning bytes from a connection that could not be
	 * pinned is the DNS-rebinding hole this class exists to close. The state is
	 * unreachable in production — core fires this action from the curl transport
	 * only, and that transport requires the extension — so what is being pinned
	 * here is that the impossible case fails closed rather than fatally. A streams
	 * site, which is the reachable case, never fires the action at all and is
	 * covered by test_a_streams_transport_fetch_is_not_refused_for_want_of_a_pin().
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
	public function test_a_fetch_that_cannot_call_curl_setopt_is_refused_not_fatal(): void {
		if ( function_exists( 'curl_setopt' ) ) {
			$this->markTestSkipped( 'ext-curl is loaded in this PHP, so the missing-curl branch cannot be reached here.' );
		}

		// Swallowed, and only in this test. A refusal logs its detail, and this is
		// the one test that runs in a child process, where PHPUnit reads the result
		// back off that process's own stdout — an unfaked error_log() writes into
		// the middle of it and the run dies on a result it cannot parse, naming
		// neither the test nor the reason.
		Functions\when( 'error_log' )->justReturn( true );

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	/**
	 * THE SAME BRANCH AS THE TEST ABOVE, PROVED WHERE EXT-CURL IS LOADED.
	 *
	 * That one skips on every interpreter that has the extension, CI included, so
	 * the probe could be deleted outright — `if ( true )` written in place of the
	 * `function_exists()` test — and the whole suite would still pass. A mutation
	 * sweep over every `function_exists()` guard in the plugin found exactly that:
	 * this security branch, the one deciding whether an unpinnable fetch is refused
	 * or completed, was the only unpinned guard anywhere on the pin path.
	 *
	 * Overriding the probe seam stages the same state with the extension present.
	 * What is asserted is a pair, not just the refusal: no directive was offered to
	 * the transport, AND no bytes came back.
	 */
	public function test_a_php_that_cannot_set_curl_options_refuses_rather_than_fetching_unpinned(): void {
		$offered = [];

		$fetch = new class( $this->cannedGuard(), $offered ) extends MediaFetch {
			/**
			 * @param MediaUrlGuard      $guard   The address policy.
			 * @param array<int, string> $offered Collects every directive offered, by reference.
			 */
			public function __construct( MediaUrlGuard $guard, private array &$offered ) {
				parent::__construct( $guard );
			}

			/**
			 * Reports the state a PHP built without ext-curl is in.
			 *
			 * @return bool Always false.
			 */
			protected function curlOptionsAvailable(): bool {
				return false;
			}

			/**
			 * Records any directive that reached the transport regardless.
			 *
			 * Answers true, so that a pin reaching here in spite of the probe would
			 * be counted as applied and the refusal below would NOT happen. The
			 * failing direction is the useful one.
			 *
			 * @param mixed  $handle    The handle, unused: nothing is set on it.
			 * @param string $directive The directive offered.
			 *
			 * @return bool Always true.
			 */
			protected function applyResolveDirective( &$handle, string $directive ): bool {
				unset( $handle );

				$this->offered[] = $directive;

				return true;
			}
		};

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $fetch->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );

		// Nothing was pinned and nothing pretended to be. Without this the test
		// would also pass over an implementation that offered the directive anyway
		// and then refused for some unrelated reason.
		$this->assertSame( [], $offered );
	}

	public function test_the_fetch_pins_the_request_to_the_validated_address(): void {
		// THE DNS-rebinding defence, end to end: the decision is read mid-request
		// from the hook core would apply it on, and the directive must name the
		// address the guard approved — so the address that was validated is the
		// address dialled.
		//
		// IT ASSERTS THE RETURNED BYTES AS WELL, and that is not padding. Since
		// `$pin_applied` became `curl_setopt()`'s own return value, deleting the
		// `curl_setopt()` call leaves the flag false and every pinned fetch refuses
		// — so this line is what kills that deletion on an interpreter WITH
		// ext-curl, where the call itself cannot be observed and
		// test_the_pin_reaches_curl_setopt() is skipped.
		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );
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

	public function test_a_directive_curl_would_not_take_is_not_counted_as_a_pin(): void {
		// The reason `$pin_applied` is `curl_setopt()`'s RETURN VALUE rather than a
		// flag set beside the call. curl_setopt() answers false when it will not
		// take the option — a malformed directive, an option this build does not
		// know — and a fetch that read "I called it" instead of "it took it" would
		// hand back bytes from a connection resolved by whatever the transport
		// asked, which is the whole of the hole. Only stageable where the function
		// is faked, but the coupling it pins holds everywhere.
		$this->requireObservableCurl();

		$this->curlSetoptSucceeds = false;

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_transport_that_will_not_take_the_directive_is_not_counted_as_a_pin(): void {
		// THE SAME COUPLING AS THE TEST ABOVE, PROVED WHERE EXT-CURL IS LOADED.
		// That one can only be staged where curl_setopt() is a double, which is
		// nowhere the extension is present — so on every interpreter this plugin
		// is checked on it is SKIPPED, and `$pin_applied = true;` written above the
		// call would survive the entire suite with nothing to catch it. Overriding
		// the transport seam stages the same refusal with the extension loaded.
		$seen = [];

		$fetch = new class( $this->cannedGuard(), $seen ) extends MediaFetch {
			/**
			 * @param MediaUrlGuard      $guard The address policy.
			 * @param array<int, string> $seen  Collects every directive offered, by reference.
			 */
			public function __construct( MediaUrlGuard $guard, private array &$seen ) {
				parent::__construct( $guard );
			}

			/**
			 * Refuses the directive the way curl does when it will not take one.
			 *
			 * @param mixed  $handle    The handle, unused: nothing is set on it.
			 * @param string $directive The directive offered.
			 *
			 * @return bool Always false.
			 */
			protected function applyResolveDirective( &$handle, string $directive ): bool {
				unset( $handle );

				$this->seen[] = $directive;

				return false;
			}
		};

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $fetch->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );

		// The seam was actually reached with the real directive. Without this, a
		// fetch refused before it ever got as far as pinning would pass the
		// assertions above while proving nothing about the coupling.
		$this->assertSame( [ 'cdn.example.com:443:93.184.216.34' ], $seen );
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

		// Reached the hook, and declined — not "never got there". The returned
		// bytes are the other half: the IP-literal branch has to record the pin as
		// APPLIED, because "correctly nothing to pin" and "failed to pin" are the
		// same silence to the fail-closed assertion. Delete that one line and this
		// fetch is refused.
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

	public function test_the_wire_read_stops_at_the_site_limit_not_the_built_in_ceiling(): void {
		// The defect this pins: `limit_response_size` used to be the built-in
		// ceiling on every site, so a site that accepts 2 MiB still pulled 8 MiB
		// across the network before MediaMimeGuard refused it for size. Four times
		// the transfer and four times the peak memory, for the same refusal.
		$this->uploadLimit = 2097152;

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( 2097153, $this->sentArgs[0]['limit_response_size'] );
	}

	public function test_a_body_over_the_site_limit_is_refused_though_it_is_under_the_ceiling(): void {
		// Under MAX_DECODED_BYTES, so only the site's own limit can refuse it —
		// and it must be refused HERE rather than carried on to the guard, or the
		// transport is handing on bytes it already knows are unusable.
		$this->uploadLimit = 2097152;

		$this->respondWith( $this->responseWith( 200, str_repeat( 'a', 2097153 ) ) );

		$this->assertFetchRefused( ErrorCode::InvalidInput );
	}

	public function test_a_site_reporting_no_limit_falls_back_to_the_built_in_ceiling(): void {
		// The fallback is the whole reason the cap is not just wp_max_upload_size().
		// Taking a non-positive report at face value would set the wire limit to one
		// byte and refuse every import on a site whose ini pair cannot be read.
		$this->uploadLimit = 0;

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( MediaMimeGuard::MAX_DECODED_BYTES + 1, $this->sentArgs[0]['limit_response_size'] );
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
		$this->assertStringContainsString( 'SiteHelm', (string) $args['user-agent'] );
		$this->assertFalse( $args['stream'] );

		// PINNED TO THEIR VALUES, not to a range. `assertIsInt` plus "greater than
		// zero" left every timeout from one second to ten minutes passing, and
		// `httpversion` was asserted nowhere at all — deleting the line survived
		// the suite. Both are forced arguments the class advertises as deliberate,
		// so both are worth exactly one equality each.
		$this->assertSame( 15, $args['timeout'] );
		$this->assertSame( '1.1', $args['httpversion'] );
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
		$this->assertSame( 15, $args['timeout'] );
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
		//
		// THE SPY IS INSTALLED so that the empty list below means something. Left
		// uninstalled, `$this->pinDecisions` is empty by construction and the
		// assertion holds however the class behaves — including if the transport
		// flag stopped being honoured and `http_api_curl` fired after all.
		$this->curlTransport = false;

		$fetch = $this->fetcher();

		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );
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
		//
		// THE ARGUMENTS CARRY THE TOKEN, and that is the whole point of the test.
		// An empty `$args` array is rejected one line into the callback, on the
		// ownership check, and never reaches the no-pin branch this test is named
		// for. The token an idle fetch holds is the empty string — see
		// carries_fetch_token() for why that is safe and why no early return
		// guards it — so this is exactly the stranger's request the docblock
		// there describes, arriving while nothing is in flight.
		$fetch  = $this->fetcher();
		$handle = 'curl-handle';

		$fetch->pinCurlHandle( $handle, [ 'sitehelm_fetch_token' => '' ], 'https://cdn.example.com/a.png' );

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
