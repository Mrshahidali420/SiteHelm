<?php
/**
 * Tests for MediaFetch's redirect hop loop (REQ-0052).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;

/**
 * A `302` to `http://127.0.0.1:8080/` is DNS rebinding without the DNS: it needs
 * no hostile resolver, only a server willing to redirect. Everything in this
 * file exists to prove that a redirect is not a way around MediaUrlGuard.
 *
 * WHY THE LOOP LIVES IN THE CLASS AND NOT IN A FILTER. `http_request_args` fires
 * exactly once per `WP_Http::request()` (`class-wp-http.php:252`); redirects are
 * followed underneath it, inside Requests, and never re-enter that method. A
 * filter-based hop check therefore sees hop one and nothing else. The fake in
 * MediaFetchTestCase models that single fire exactly, so every hop after the
 * first in this file happened only because MediaFetch issued another request
 * itself — the fake cannot manufacture the property under test.
 */
final class MediaFetchRedirectTest extends MediaFetchTestCase {

	public function test_a_redirect_is_followed_to_the_final_response(): void {
		// Redirects are capped at two rather than disabled because the CDN
		// redirects that make imports work in practice are ordinary. This is the
		// test that fails if hop handling is tightened into hop refusal.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );

		$this->assertSame(
			[ 'https://cdn.example.com/a.png', 'https://images.example.net/a.png' ],
			$this->requestedUrls
		);
	}

	public function test_a_redirect_hop_is_re_validated_by_the_guard(): void {
		// The whole point. Without the hop revalidation the original URL passes
		// every check and a 302 walks the site straight into loopback.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];

		$this->respondWith(
			$this->redirectTo( 'https://evil.example.com/a.png' ),
			$this->responseWith( 200, 'SECRET' )
		);

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		// And it was refused BEFORE the request left, not after it came back.
		$this->assertSame( [ 'https://cdn.example.com/a.png' ], $this->requestedUrls );
	}

	public function test_a_redirect_hop_to_the_metadata_endpoint_is_refused(): void {
		$this->dns['meta.example.com'] = [ '169.254.169.254' ];

		$this->respondWith( $this->redirectTo( 'https://meta.example.com/latest/meta-data/' ) );

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	public function test_a_redirect_hop_to_an_unresolvable_host_is_refused(): void {
		$this->respondWith( $this->redirectTo( 'https://nowhere.example.org/a.png' ) );

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	public function test_a_redirect_hop_re_pins_the_curl_handle_to_the_new_address(): void {
		// A hop that is validated but not RE-PINNED is the rebinding hole
		// reopening one level down: the handle would still carry the first hop's
		// directive while dialling the second hop's host, and curl would fall
		// back to a resolver for the name it has no directive for.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$fetch = $this->fetcher();
		$this->recordPins( $fetch );

		$fetch->fetch( $this->validated(), 'corr-1' );

		// One directive per hop, each naming ONLY that hop's host: the second
		// pin is not the first pin with something added to it, which is what a
		// stale CURLOPT_RESOLVE entry would look like.
		$this->assertSame(
			[
				'cdn.example.com:443:93.184.216.34',
				'images.example.net:443:93.184.216.35',
			],
			$this->pinnedDirectives()
		);
	}

	public function test_a_hop_whose_location_carries_a_trailing_dot_is_refused(): void {
		// THE ATTACKER WRITES THE `Location`, so the second entry point into the
		// trailing-dot pin hole is this one, and it is the more dangerous of the
		// two: the site has already been persuaded to make one request. The dotted
		// spelling is what curl keys its resolve cache on, while the directive
		// would name the undotted one — a hop dialled unpinned, its name resolved
		// by whoever answers for it, and no refusal anywhere, because applying the
		// directive succeeded. Everything else about this hop is public and
		// ordinary, so nothing but the guard's dot rule stops it.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net./a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$refusal = $this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'ends in a dot', $refusal->getMessage() );

		// And refused before the second request left, not after it came back.
		$this->assertSame( [ 'https://cdn.example.com/a.png' ], $this->requestedUrls );
	}

	public function test_a_hop_whose_location_names_an_uppercase_scheme_is_still_pinned(): void {
		// THE ATTACKER WRITES THE `Location`, so the spelling of the next hop is
		// entirely theirs to choose. `HTTPS://` is a legal, equivalent spelling
		// that core lower-cases on its way to the transport, and against the
		// previous raw-string match that difference was enough to make the class
		// fail to recognise its own request, apply no CURLOPT_RESOLVE, and let the
		// attacker's resolver answer the second lookup — with no refusal and no log
		// line. The hop must be pinned exactly as the lower-case spelling is.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'HTTPS://images.example.net/a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$fetch = $this->fetcher();
		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );

		$this->assertSame(
			[
				'cdn.example.com:443:93.184.216.34',
				'images.example.net:443:93.184.216.35',
			],
			$this->pinnedDirectives()
		);
	}

	public function test_two_redirect_hops_are_allowed(): void {
		$this->dns['images.example.net'] = [ '93.184.216.35' ];
		$this->dns['assets.example.org'] = [ '93.184.216.36' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->redirectTo( 'https://assets.example.org/a.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$fetch = $this->fetcher();
		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );

		$this->assertSame(
			[
				'cdn.example.com:443:93.184.216.34',
				'images.example.net:443:93.184.216.35',
				'assets.example.org:443:93.184.216.36',
			],
			$this->pinnedDirectives()
		);
	}

	public function test_a_third_redirect_is_refused_naming_only_the_limit(): void {
		// A redirect chain is a way to spend this site's time, and an unbounded
		// loop is a way to spend all of it. Without the cap the two responses
		// queued after these would never be reached and the fake would fail on
		// an empty queue instead — which is why the refusal is asserted by code
		// and message rather than by "it stopped".
		$this->dns['images.example.net'] = [ '93.184.216.35' ];
		$this->dns['assets.example.org'] = [ '93.184.216.36' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->redirectTo( 'https://assets.example.org/a.png' ),
			$this->redirectTo( 'https://cdn.example.com/a.png' )
		);

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( '2', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_redirect_without_a_location_is_refused(): void {
		// A 3xx with nowhere to go is not followed and not guessed at. Guessing
		// would mean re-requesting the same URL, which is an infinite loop the
		// hop cap would merely shorten.
		$this->respondWith( $this->redirectTo( null ) );

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		// The message is asserted, not just the code: "no destination" and "a
		// destination this site cannot resolve" are both ExecutionFailed, so a
		// code-only assertion passes just as happily when the missing-Location
		// branch is gone and the empty string falls through to the resolver.
		$this->assertStringContainsString( 'no destination', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );
	}

	public function test_a_blank_location_is_refused(): void {
		$this->respondWith( $this->redirectTo( '   ' ) );

		$this->assertStringContainsString(
			'no destination',
			$this->refusal(
				ErrorCode::ExecutionFailed,
				fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
			)->getMessage()
		);
	}

	public function test_a_repeated_location_header_is_refused(): void {
		// Core hands back an array when a header repeated. There is no single
		// destination in that, and picking one would be picking which of two
		// servers' instructions to obey.
		$this->respondWith(
			$this->redirectTo( [ 'https://images.example.net/a.png', 'https://evil.example.com/a.png' ] )
		);

		$this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	public function test_a_protocol_relative_location_takes_the_current_scheme(): void {
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( '//images.example.net/b.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );

		$this->assertSame( 'https://images.example.net/b.png', $this->requestedUrls[1] );
	}

	public function test_a_root_relative_location_is_resolved_against_the_hop_that_sent_it(): void {
		// Against THAT hop, not against the original URL. A chain that moves to
		// another host and then sends a root-relative Location means a path on
		// the second host; resolving it against the first would dial an address
		// nobody chose — and would dial it under the FIRST host's pin.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/x/' ),
			$this->redirectTo( '/final.png' ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$fetch = $this->fetcher();
		$this->recordPins( $fetch );

		$this->assertSame( 'PNGBYTES', $fetch->fetch( $this->validated(), 'corr-1' ) );

		$this->assertSame( 'https://images.example.net:443/final.png', $this->requestedUrls[2] );
		$this->assertSame( 'images.example.net:443:93.184.216.35', $this->pinnedDirectives()[2] );
	}

	public function test_a_path_relative_location_is_refused(): void {
		// Legal in RFC 3986, essentially absent from real asset redirects, and
		// implementable only as a home-grown path normaliser whose disagreements
		// with curl's would land exactly where this class's disagreements are
		// most expensive. Refusing is the safe direction.
		$this->respondWith( $this->redirectTo( '../elsewhere/b.png' ) );

		$refusal = $this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertStringContainsString( 'cannot resolve', $refusal->getMessage() );
		$this->assertRefusalLeaksNothing( $refusal );

		// And it was refused rather than dialled under some guessed-at form.
		$this->assertSame( [ 'https://cdn.example.com/a.png' ], $this->requestedUrls );
	}

	/**
	 * @dataProvider redirectStatuses
	 */
	public function test_every_redirect_status_is_followed( int $status ): void {
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png', $status ),
			$this->responseWith( 200, 'PNGBYTES' )
		);

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	/**
	 * The five statuses a client follows. 303 and 308 are the ones a `>= 301 &&
	 * <= 302` shortcut would miss, and a missed redirect is an unvalidated
	 * refusal rather than an unvalidated request — but it is still wrong.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function redirectStatuses(): array {
		return [
			'301 moved permanently'  => [ 301 ],
			'302 found'              => [ 302 ],
			'303 see other'          => [ 303 ],
			'307 temporary redirect' => [ 307 ],
			'308 permanent redirect' => [ 308 ],
		];
	}

	public function test_the_status_after_a_redirect_is_still_checked(): void {
		// The hop loop must not become a way past the status check: the response
		// that ends the chain gets exactly the scrutiny the first one would.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$this->respondWith(
			$this->redirectTo( 'https://images.example.net/a.png' ),
			$this->responseWith( 404, 'Not Found' )
		);

		$this->assertStringContainsString(
			'404',
			$this->refusal(
				ErrorCode::ExecutionFailed,
				fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
			)->getMessage()
		);
	}

	public function test_the_hooks_are_removed_when_a_redirect_hop_is_refused(): void {
		// The throwing path that leaves from INSIDE the hop loop rather than
		// from the straight-line checks after it. A `finally` catches both; a
		// removal placed after the checks catches neither.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];

		$this->respondWith( $this->redirectTo( 'https://evil.example.com/a.png' ) );

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertNotSame( [], $this->added, 'The class registered no hooks at all.' );
		$this->assertSame( [], $this->leakedHooks() );
	}

	/**
	 * The other half of the envelope-discipline rule, for all three redirect
	 * refusals. Each of them says nothing about the destination BY DESIGN, so if
	 * nothing were written server-side there would be no way at all to find out why
	 * a real import failed — the refusal would be correct and useless at once.
	 *
	 * @dataProvider redirectRefusalsThatMustBeLogged
	 */
	public function test_a_redirect_refusal_is_logged_under_the_correlation_id( array $responses, string $expected ): void {
		$logged = [];

		Functions\when( 'error_log' )->alias(
			function ( string $line ) use ( &$logged ): bool {
				$logged[] = $line;

				return true;
			}
		);

		$this->dns['images.example.net'] = [ '93.184.216.35' ];
		$this->dns['assets.example.org'] = [ '93.184.216.36' ];

		$this->respondWith( ...$responses );

		$this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( 'corr-1', $logged[0] );
		$this->assertStringContainsString( $expected, $logged[0] );
	}

	/**
	 * @return array<string, array{0: array<int, mixed>, 1: string}>
	 */
	public function redirectRefusalsThatMustBeLogged(): array {
		return [
			'no location'   => [
				[ $this->redirectTo( null ) ],
				'no single usable Location',
			],
			'path relative' => [
				[ $this->redirectTo( '../elsewhere/b.png' ) ],
				'unresolvable Location',
			],
			'over the cap'  => [
				[
					$this->redirectTo( 'https://images.example.net/a.png' ),
					$this->redirectTo( 'https://assets.example.org/a.png' ),
					$this->redirectTo( 'https://cdn.example.com/b.png' ),
				],
				'redirect chain exceeded',
			],
		];
	}

	public function test_no_redirect_refusal_names_the_destination(): void {
		// The destination of a refused redirect is the single most useful thing
		// an attacker could be told: it confirms which internal address the
		// probe reached before the guard stopped it.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];

		$this->respondWith( $this->redirectTo( 'https://evil.example.com/a.png' ) );

		$this->assertRefusalLeaksNothing(
			$this->refusal(
				ErrorCode::InvalidInput,
				fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
			)
		);
	}
}
