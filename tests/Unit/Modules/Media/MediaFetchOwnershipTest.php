<?php
/**
 * Tests for the one question MediaFetch's hook callbacks ask first (REQ-0052):
 * is this request mine, and is it the hop I validated?
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use SiteHelm\Contracts\ErrorCode;

/**
 * WordPress hooks are global, so both of this class's callbacks are fired for
 * every HTTP request the whole site makes while a fetch is in flight — an update
 * check, another plugin's API call, a webhook. Acting on one of those is a real
 * defect in both directions: `http_request_args` would force this import's
 * timeout, user-agent and redirect ban onto a stranger's in-flight request, and
 * `http_api_curl` would re-point a stranger's connection at THIS import's
 * address.
 *
 * SO EACH CALLBACK ASKS TWO INDEPENDENT QUESTIONS, and this file is the whole of
 * both. A per-fetch TOKEN, put into the arguments at the `wp_safe_remote_get()`
 * call site and read back out of the arguments core carries to both hooks,
 * answers "is this request mine?". The NORMALISED SCHEME, HOST AND PORT
 * comparison answers "is it the hop I validated?". Neither is sufficient alone:
 * the token by itself would pin whatever url the request happened to carry, and
 * the components by themselves would act on a stranger's request to the same
 * origin — which is the case the token tests below stage, because it is the one
 * an address comparison can never see.
 *
 * Split out of MediaFetchTest, which holds the transport outcomes — statuses,
 * bodies, size caps — and had reached the line ceiling this project holds every
 * file to. See MediaFetchTestCase for the WordPress stand-in, and
 * MediaFetchRedirectTest for the same questions asked again on every hop.
 */
final class MediaFetchOwnershipTest extends MediaFetchTestCase {

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

	public function test_the_forcing_filter_ignores_a_stranger_bound_for_its_own_address(): void {
		// THE CASE THE ADDRESS COMPARISON CANNOT SEE, and the reason the token
		// exists. A request to the very host, port and scheme this fetch validated
		// is indistinguishable from this fetch's own by any comparison of the url:
		// a CDN this site already uses is exactly where an unrelated plugin's
		// request is most likely to be going. Without the token check this filter
		// forces `redirection => 0`, a response-size cap and this class's
		// user-agent onto that request — silently breaking somebody else's paged
		// API call from inside an image import.
		$fetch = $this->fetcher();

		$stranger = [ 'timeout' => 600 ];

		add_filter(
			'http_request_args',
			function ( array $args, string $url ) use ( $fetch, &$stranger ): array {
				unset( $url );

				$stranger = $fetch->filterRequestArgs( $stranger, 'https://cdn.example.com/somebody-elses.json' );

				return $args;
			},
			1,
			2
		);

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );
		$this->assertSame( [ 'timeout' => 600 ], $stranger );
	}

	public function test_the_forcing_filter_ignores_its_own_token_on_another_url(): void {
		// THE OTHER HALF OF THE PAIR, and why the address comparison stayed when the
		// token arrived. The token says the request is this class's; it does not say
		// WHICH hop. Anything holding a live argument array — another plugin's
		// `http_request_args` filter, a retry wrapper, a queued job — can carry this
		// fetch's token to a different address, and forcing this hop's settings onto
		// that request is the same defect as forcing them onto a stranger's. Only
		// the scheme/host/port comparison tells those two requests apart.
		$fetch     = $this->fetcher();
		$elsewhere = 'unset';

		add_filter(
			'http_request_args',
			function ( array $args, string $url ) use ( $fetch, &$elsewhere ): array {
				unset( $url );

				$elsewhere = $fetch->filterRequestArgs(
					[
						self::TOKEN_ARG => $args[ self::TOKEN_ARG ] ?? '',
						'timeout'       => 600,
					],
					'https://elsewhere.example.org/ping'
				);

				return $args;
			},
			1,
			2
		);

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );

		$token = $this->sentArgs[0][ self::TOKEN_ARG ];

		// Without this the test would pass on a token that was never minted, the
		// stranger's copy of '' matching an absent one.
		$this->assertNotSame( '', $token );
		$this->assertSame(
			[
				self::TOKEN_ARG => $token,
				'timeout'       => 600,
			],
			$elsewhere
		);
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

	public function test_a_request_whose_arguments_lost_the_token_is_not_pinned(): void {
		// The token read at the PINNING hook, which is the half with teeth. The
		// arguments arrive stripped of the token — what a stranger's request to
		// this same address looks like from inside the callback — so the pin must
		// not go on. And because it did not, the fail-closed assertion must refuse
		// rather than return the bytes: "I did not recognise this as mine" and "I
		// failed to pin my own request" are the same silence at the hook, and only
		// the refusal tells them apart.
		$this->rewriteCurlArgs = static function ( array $args ): array {
			unset( $args[ self::TOKEN_ARG ] );

			return $args;
		};

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_token_from_an_earlier_fetch_is_not_this_fetchs_own(): void {
		// THE TOKEN IS PER FETCH, NOT PER OBJECT. Minted once — in the constructor,
		// or on first use — it would still identify this class's requests, and every
		// other test in this file would still pass; but any request that had ever
		// carried it would go on matching, including one replayed by another plugin
		// that had captured the arguments from a hook of its own. The second fetch
		// here is handed the first fetch's token and must treat it as a stranger's.
		$fetch    = $this->fetcher();
		$captured = null;

		$this->rewriteCurlArgs = static function ( array $args ) use ( &$captured ): array {
			$captured = $args[ self::TOKEN_ARG ] ?? null;

			return $args;
		};

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );
		$this->assertIsString( $captured );
		$this->assertNotSame( '', $captured );

		$this->respondWith( $this->responseWith( 200, 'PNGBYTES' ) );

		$this->rewriteCurlArgs = static function ( array $args ) use ( &$captured ): array {
			$args[ self::TOKEN_ARG ] = $captured;

			return $args;
		};

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $fetch->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'pinned', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_the_token_never_reaches_the_transport_as_a_header(): void {
		// The token is an identity for this process's own hooks, not a credential,
		// and it must not leave the site. Core builds the transport options from an
		// explicit list of keys (`class-wp-http.php:340-345`), so an extra argument
		// stays in `$parsed_args` — but the arguments this class FORCES are its own
		// doing, and quietly copying the token into a header or a query string
		// would hand every remote server a stable per-request identifier.
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame( [], $this->sentArgs[0]['headers'] ?? [] );
		$this->assertStringNotContainsString( $this->sentArgs[0][ self::TOKEN_ARG ], $this->requestedUrls[0] );
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
						'same'         => 'https://cdn.example.com/a.png',
						'scheme case'  => 'HTTPS://cdn.example.com/a.png',
						'host case'    => 'https://CDN.Example.COM/a.png',
						'trailing dot' => 'https://cdn.example.com./a.png',
						'default port' => 'https://cdn.example.com:443/a.png',
						'other path'   => 'https://cdn.example.com/other%2Fpath.png',
						'other port'   => 'https://cdn.example.com:80/a.png',
						'other scheme' => 'http://cdn.example.com/a.png',
						// Scheme differing while host AND port still agree, so the
						// scheme comparison is the only thing that can reject it.
						'scheme alone' => 'http://cdn.example.com:443/a.png',
						'other host'   => 'https://other.example.com/a.png',
						'not a url'    => 'not a url at all',
						// parse_url() refuses this outright. A url this class could
						// not parse must never be taken for the one in flight.
						'unparsable'   => 'https://cdn.example.com:port/a.png',
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
}
