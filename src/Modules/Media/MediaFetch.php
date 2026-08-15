<?php
/**
 * The bounded, pinned remote fetch for media import.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0052's transport. MediaUrlGuard decides WHETHER an address may be fetched;
 * this class makes sure the request actually goes to the address the guard
 * approved — and to nothing else, on any redirect hop.
 *
 * THE GUARD ALONE IS NOT ENOUGH, and that is the whole design of this class.
 * Between the guard's DNS lookup and the transport's own there is a window of
 * milliseconds in which an attacker's resolver can change its mind: a public
 * address for the guard, `127.0.0.1` for the transport, so that every check the
 * guard performed validated an address the site never connects to. That is DNS
 * rebinding, closed by pinning the connection to the guard's address through
 * `CURLOPT_RESOLVE`.
 *
 * A REDIRECT IS THE SAME ATTACK BY ANOTHER ROUTE. A `302` to
 * `http://127.0.0.1:8080/` needs no hostile resolver at all.
 *
 * THIS CLASS THEREFORE FOLLOWS REDIRECTS ITSELF, and that is not a stylistic
 * choice. `http_request_args` fires exactly ONCE per `WP_Http::request()`
 * (`class-wp-http.php:252`); redirects are followed inside the Requests library
 * and never re-enter it, so a filter-based hop check would see the FIRST url and
 * nothing else — hop two neither re-validated nor re-pinned, dialled on a handle
 * still carrying hop one's `CURLOPT_RESOLVE`. So `redirection` is forced to zero,
 * WordPress hands the `3xx` back, and the loop in fetch() re-validates each hop
 * through the same MediaUrlGuard and MOVES the pin before the next request leaves.
 * Capped at two rather than disabled: CDN redirects are ordinary in real imports.
 * Where a hop GOES is MediaRedirectResolver's; that the connection is re-pinned
 * before it is dialled is this class's.
 *
 * BOTH HOOKS ARE REMOVED IN A `finally`, every path out of the hop loop included.
 * See fetch() for why that single line is the most important one in the file.
 *
 * BOTH HOOKS ALSO CHECK THAT THE REQUEST IS THIS CLASS'S OWN, TWICE OVER.
 * WordPress hooks are process-global, so any other code making an HTTP request
 * mid-fetch would otherwise be handed this fetch's `CURLOPT_RESOLVE` pin and its
 * forced arguments. Each callback therefore asks two independent questions:
 *
 * 1. IS THIS REQUEST MINE? A per-fetch token is passed into
 *    `wp_safe_remote_get()` as a request argument and read back out of the
 *    arguments both hooks are given. It identifies the request itself, and
 *    nothing an attacker writes can produce it. See carries_fetch_token().
 * 2. IS IT THE HOP I VALIDATED? The url is compared on NORMALISED SCHEME, HOST
 *    AND PORT rather than as a whole string, because core hands the two hooks
 *    different spellings of the same url and the difference is
 *    attacker-choosable. See matches_pinned_request() for the hole a raw
 *    comparison left open.
 *
 * Both, not either: the token alone would pin whatever url the request carried,
 * and the components alone would act on a stranger's request to the same origin.
 *
 * AND THE MATCH FAILS CLOSED. A callback that declines is silent, and "not my
 * request" and "my request, unrecognised, therefore dialled with no pin" are the
 * same silence — the second being the rebinding defence switched off without a
 * trace. So each request records whether `curl_setopt()` actually took the
 * directive, and fetch() refuses rather than return bytes from a curl connection
 * it cannot prove it pinned. See assert_connection_was_pinned().
 *
 * THE PIN IS CURL-ONLY, and that cost is accepted rather than hidden (spec §7
 * item 3). `CURLOPT_RESOLVE` has no equivalent in WordPress's streams transport,
 * so a non-curl site gets every other guard here — the scheme, port, credential
 * and address policy, the hop re-validation, the size cap, `reject_unsafe_urls`
 * — and keeps a narrow residual DNS-rebinding window.
 *
 * NO RESPONSE HEADER, NO REDIRECT TARGET, NO RESOLVED ADDRESS AND NO TRANSPORT
 * ERROR STRING EVER REACHES THE ENVELOPE. Those four are what an attacker
 * harvests from a blind SSRF probe, and leaking one turns a refused fetch into an
 * internal port scanner. Detail goes to `error_log` under the correlation id, as
 * MediaUpload's sideload failures do. A status number and the redirect limit are
 * the only numbers a refusal names, and neither is an oracle for this network.
 *
 * The bytes this class returns GET NO TRUST FROM HAVING BEEN FETCHED. They go
 * straight into MediaMimeGuard::inspectBytes(), which sniffs the content; the
 * response's `Content-Type` is consulted nowhere here, for the same reason the
 * upload path has no `mimeType` input property. `Location` is read, and only
 * because a redirect cannot be followed without it — and what it names is
 * re-validated from scratch before it is dialled.
 *
 * @package SiteHelm
 */
final class MediaFetch {

	/**
	 * The number of redirect hops permitted.
	 *
	 * Not zero, because CDN redirects are how a great many real asset URLs work,
	 * and each hop passes the same policy the original URL passed. Small, because
	 * a redirect chain is also a way to spend this site's time.
	 */
	private const MAX_REDIRECTS = 2;

	/**
	 * The seconds allowed for the whole transfer.
	 */
	private const TIMEOUT_SECONDS = 15;

	/**
	 * The status this class will accept, and the only one.
	 *
	 * An allowlist of one rather than a `>= 400` test: a `204` is not an error to
	 * such a test and carries no body, so it would fail three lines later with an
	 * empty-body diagnosis that misdescribes what happened.
	 */
	private const REQUIRED_STATUS = 200;

	/**
	 * The priority both callbacks are registered at.
	 *
	 * `PHP_INT_MAX` so that this class has the LAST word. Another plugin's
	 * `http_request_args` filter runs alongside this one and whichever runs last
	 * wins; a safety setting a third party can switch off after the fact is not a
	 * safety setting. The forced values are re-applied unconditionally on every
	 * invocation for the same reason.
	 */
	private const HOOK_PRIORITY = PHP_INT_MAX;

	/**
	 * The request argument this fetch's identifying token travels in.
	 *
	 * Prefixed, because `$parsed_args` is a shared namespace every plugin on the
	 * site can write into.
	 */
	private const TOKEN_ARG = 'sitehelm_fetch_token';

	/**
	 * The target the transport is currently pinned to, or null when no fetch is
	 * in flight.
	 *
	 * Held as state because `http_api_curl` hands the callback a curl handle and
	 * a URL but no way to pass the validated target alongside them. It is
	 * re-assigned before each hop's request and cleared in the same `finally`
	 * that removes the hooks, so that an accidental later invocation of either
	 * callback finds nothing to act on rather than a stale target.
	 *
	 * THIS MAKES fetch() NON-REENTRANT, and must: a second fetch on the same
	 * instance would overwrite the first's pin mid-flight. The instance IS SHARED —
	 * one fetcher, one fetch per preview and one per apply — so the invariant is
	 * SEQUENTIAL use, not single use: every call re-pins, regenerates the token, and
	 * clears both in fetch()'s own `finally`, and PHP is single-threaded, so only a
	 * hook re-entering fetch() mid-request could overlap two.
	 *
	 * @var array{url: string, scheme: string, host: string, port: int, ip: string}|null
	 */
	private ?array $pinned = null;

	/**
	 * Whether `http_api_curl` fired at all for the request just issued.
	 *
	 * Core fires that action only from the curl transport, so this is how the class
	 * tells "the connection was made by curl" from "WordPress is on its streams
	 * transport, where no pin exists to apply" — only the first is a failure when
	 * no pin went on.
	 *
	 * @var bool
	 */
	private bool $curl_transport_used = false;

	/**
	 * Whether the pin was actually applied to the request just issued.
	 *
	 * THE FAIL-CLOSED HALF OF THE MATCHING RULE. "This url is not mine, leave it
	 * alone" and "this url IS mine but I did not recognise it, so I dialled it with
	 * no pin" are the same silence at the hook; the second is the DNS-rebinding
	 * hole reopened. Recording whether the pin went on lets fetch() tell them apart
	 * afterwards. See assert_connection_was_pinned() for what the flag proves.
	 *
	 * @var bool
	 */
	private bool $pin_applied = false;

	/**
	 * The token identifying the requests this fetch itself issued, or '' when no
	 * fetch is in flight.
	 *
	 * THIS IS WHAT MAKES "IS THIS REQUEST MINE?" A DIFFERENT QUESTION FROM "IS THIS
	 * URL THE ONE I AM FETCHING?". Recognising the request by its address alone means
	 * recognising every OTHER request to the same host and port — an update check,
	 * another plugin's API call to the same CDN — and forcing `redirection => 0`, a
	 * size cap, this class's timeout and its user-agent onto a request that is none
	 * of its business — a real defect in unrelated code.
	 *
	 * The token closes that off because core carries the request arguments THROUGH
	 * to the hooks: `class-wp-http.php:243` merges the caller's arguments into the
	 * defaults, `:252` fires `http_request_args` with the merged array, `:344`
	 * hands that same array to `WP_HTTP_Requests_Hooks`, and
	 * `class-wp-http-requests-hooks.php:58` fires `http_api_curl` with it. So an
	 * argument put in at the call site arrives at both hook points, and only this
	 * class's own requests carry it. It is random PER FETCH so that a stale copy in
	 * another plugin's cached argument array cannot impersonate a later one.
	 *
	 * IT IS NOT A SECRET, and nothing rests on it being unguessable: any code in this
	 * process can already read `$parsed_args` from `pre_http_request`. It is an
	 * identity, not an authenticator — hence the address check alongside it.
	 *
	 * @var string
	 */
	private string $fetch_token = '';

	/**
	 * Where a `3xx` goes next, re-validated from scratch.
	 *
	 * Constructed here rather than injected because it is an implementation
	 * detail of following a hop, not a collaborator any caller chooses: every
	 * construction site of this class passes the guard and nothing else.
	 *
	 * @var MediaRedirectResolver
	 */
	private readonly MediaRedirectResolver $redirects;

	/**
	 * Constructs the fetcher.
	 *
	 * @param MediaUrlGuard $guard The address policy every hop is re-validated through.
	 */
	public function __construct( MediaUrlGuard $guard ) {
		$this->redirects = new MediaRedirectResolver( $guard );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $correlationId is the parameter name this operation's collaborators already use, and renaming it here alone would make the call sites disagree.
	/**
	 * Fetches the bytes at an already-validated target, following redirects.
	 *
	 * THE `finally` IS THE MOST IMPORTANT LINE IN THIS CLASS. WordPress hooks are
	 * process-global, so a callback left registered would silently re-point
	 * unrelated HTTP requests made later in the same request cycle — a licence
	 * check, an update check, another plugin's API call — making this feature a
	 * defect in every other plugin on the site. The removal must happen on EVERY
	 * path out of this method, including every path out of the redirect loop, which
	 * is what a `finally` guarantees and what a removal placed after the checks
	 * would miss.
	 *
	 * THE LOOP IS THE HOP RE-VALIDATION. Each pass validates the target through the
	 * guard, points the pin at it, and only then issues the request. A hop that was
	 * validated but not re-pinned would leave the connection pinned to the previous
	 * hop's address while dialling this hop's host, and curl would fall back to a
	 * resolver for the name it has no directive for — the whole attack this class
	 * exists to stop, one level down.
	 *
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated     The guard's approved target.
	 * @param string                                                                  $correlationId The id detail is logged under.
	 *
	 * @return string The raw response body.
	 *
	 * @throws OperationException On a transport failure, a redirect this site will not follow, a non-200 status, an empty body, or an over-cap body.
	 */
	public function fetch( array $validated, string $correlationId ): string {
		// Fresh per fetch, before either hook can fire. See $fetch_token.
		$this->fetch_token = bin2hex( random_bytes( 16 ) );

		add_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], self::HOOK_PRIORITY, 2 );
		add_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], self::HOOK_PRIORITY, 3 );

		try {
			$target = $validated;
			$hops   = 0;

			while ( true ) {
				// Assigned HERE, inside the loop, so the pin in force is always
				// the hop about to be dialled and never the one before it.
				$this->pinned              = $target;
				$this->curl_transport_used = false;
				$this->pin_applied         = false;

				// wp_safe_remote_get(), never wp_remote_get(): core's own SSRF
				// baseline is kept underneath this class's policy so the plugin
				// can only ever be stricter than the platform, never weaker.
				//
				// The token goes in HERE, at the call site: the only place a
				// request can be marked as this class's own before any hook sees
				// it. Core carries it to both hook points — see $fetch_token.
				$response = wp_safe_remote_get( $target['url'], [ self::TOKEN_ARG => $this->fetch_token ] );

				$this->assert_connection_was_pinned( $correlationId );

				if ( is_wp_error( $response ) ) {
					$this->log( $correlationId, 'transport failure: ' . $response->get_error_message() );

					$this->refuse(
						ErrorCode::ExecutionFailed,
						'The asset could not be retrieved from the remote server.',
						'Check that the address serves the file and request a fresh preview.'
					);
				}

				$status = (int) wp_remote_retrieve_response_code( $response );

				if ( $this->redirects->isRedirect( $status ) ) {
					++$hops;

					if ( $hops > self::MAX_REDIRECTS ) {
						$this->log( $correlationId, sprintf( 'redirect chain exceeded %d hops at: %s', self::MAX_REDIRECTS, $target['url'] ) );

						$this->refuse(
							ErrorCode::ExecutionFailed,
							sprintf( 'The remote server redirected more than %d times.', self::MAX_REDIRECTS ),
							'Supply the address the file is finally served from.'
						);
					}

					$target = $this->redirects->next( $response, $target, $correlationId );

					continue;
				}

				if ( self::REQUIRED_STATUS !== $status ) {
					$this->refuse(
						ErrorCode::ExecutionFailed,
						sprintf( 'The remote server answered with status %d instead of the asset.', $status ),
						'Check that the address serves the file and request a fresh preview.'
					);
				}

				$body = (string) wp_remote_retrieve_body( $response );

				if ( '' === $body ) {
					$this->refuse(
						ErrorCode::ExecutionFailed,
						'The remote server returned no content for the asset.',
						'Check that the address serves the file and request a fresh preview.'
					);
				}

				// `limit_response_size` is set to the cap PLUS ONE so that an
				// over-cap response arrives one byte over and is recognisable
				// here. Set to the cap exactly, an oversized file would arrive
				// truncated to exactly the cap and be accepted as a valid but
				// silently corrupted image.
				if ( strlen( $body ) > MediaMimeGuard::MAX_DECODED_BYTES ) {
					$this->refuse(
						ErrorCode::InvalidInput,
						'The asset at the supplied address is larger than this site will import.',
						'Import a smaller file, or add it through the upload operation instead.'
					);
				}

				return $body;
			}
		} finally {
			remove_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], self::HOOK_PRIORITY );
			remove_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], self::HOOK_PRIORITY );

			$this->pinned      = null;
			$this->fetch_token = '';
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's public surface is camelCase because it is called from camelCase collaborators, and the ruleset's snake_case rule is a WordPress-core convention this plugin's own classes do not follow.
	/**
	 * The `CURLOPT_RESOLVE` directive for one validated target.
	 *
	 * The three parts are the whole of the pin: for THIS host on THIS port, use
	 * THIS address and do not ask a resolver at all.
	 *
	 * AN IPv6 ADDRESS IS BRACKETED. curl's `--resolve` parser takes everything
	 * after the second colon as the address list, so a bare `2606:4700::1111`
	 * happens to work — but the bracketed form is the documented one, and the only
	 * one that stays unambiguous if that parser is tightened or a second address is
	 * appended. `HostResolver` returns AAAA answers, so this is a live case.
	 *
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated The guard's approved target.
	 *
	 * @return string The directive, as `host:port:address`.
	 */
	public function resolveDirective( array $validated ): string {
		$address = $validated['ip'];

		if ( str_contains( $address, ':' ) ) {
			$address = '[' . $address . ']';
		}

		return sprintf( '%s:%d:%s', $validated['host'], $validated['port'], $address );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's public surface is camelCase because it is called from camelCase collaborators, and the ruleset's snake_case rule is a WordPress-core convention this plugin's own classes do not follow.
	/**
	 * Forces the safe request arguments on this class's own request.
	 *
	 * Public because it is a filter callback, not because it is part of this
	 * class's interface.
	 *
	 * IT DOES NOT VALIDATE ANYTHING, and an earlier draft that did was wrong.
	 * `http_request_args` fires once per `WP_Http::request()` and never again for a
	 * redirect, so a hop check here would only ever see hop one. Hop validation
	 * lives in fetch()'s loop.
	 *
	 * IT ALSO DOES NOTHING TO ANYONE ELSE'S REQUEST. The token keeps the blast radius
	 * to exactly the requests this class issued: an address check alone would catch
	 * every OTHER request to the same host and port too, and forcing
	 * `redirection => 0` and a truncating size cap onto one of those is a real defect
	 * in unrelated code, not a cosmetic over-reach.
	 *
	 * `redirection` is forced to ZERO so WordPress returns the `3xx` rather than
	 * following it inside Requests, where this class cannot re-validate or re-pin the
	 * next hop. The forced values come SECOND in the array_merge, are re-applied on
	 * every invocation, and the filter runs at `PHP_INT_MAX`: three parts of one rule
	 * — whatever any other filter did, these values are what the request is made with.
	 *
	 * @param array<string, mixed> $args The request arguments so far.
	 * @param string               $url  The URL of the request being prepared.
	 *
	 * @return array<string, mixed> The arguments, with the safety settings forced when the request is this class's own.
	 */
	public function filterRequestArgs( array $args, string $url ): array {
		if ( ! $this->carries_fetch_token( $args ) ) {
			return $args;
		}

		if ( ! $this->matches_pinned_request( $url ) ) {
			return $args;
		}

		return array_merge(
			$args,
			[
				'reject_unsafe_urls'  => true,
				'redirection'         => 0,
				'timeout'             => self::TIMEOUT_SECONDS,
				'httpversion'         => '1.1',
				'stream'              => false,
				'limit_response_size' => MediaMimeGuard::MAX_DECODED_BYTES + 1,
				'user-agent'          => 'SiteHelm/' . SITEHELM_VERSION,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether the request arguments a hook was fired with are this fetch's own.
	 *
	 * The token is put into the arguments at the `wp_safe_remote_get()` call site
	 * and read back here; core carries it to both hook points untouched (see
	 * $fetch_token for the four lines of core that make that true). It is the
	 * answer to "is this request mine?" that an address comparison cannot give —
	 * an address is shared by every other request to the same origin.
	 *
	 * AN ABSENT KEY NEVER MATCHES, because the coalesce yields `null` and the token
	 * is always a string. An `'' === $this->fetch_token` early return was written
	 * here too — against a stranger passing an empty string under this key while no
	 * fetch is in flight — and deleted again for being provably unable to change
	 * any answer: `$fetch_token` is only ever '' while `$pinned` is null, the two
	 * being assigned together in fetch() and cleared together in its `finally`, so
	 * both callbacks reject that stranger on the pin comparison regardless. The
	 * `is_array()` test in matches_pinned_request() went for the same reason.
	 *
	 * @param array<string, mixed> $args The request arguments the hook was fired with.
	 *
	 * @return bool True when the arguments carry this fetch's token.
	 */
	private function carries_fetch_token( array $args ): bool {
		return ( $args[ self::TOKEN_ARG ] ?? null ) === $this->fetch_token;
	}

	/**
	 * Whether a URL handed to one of this class's hook callbacks is the request
	 * this class currently has in flight.
	 *
	 * IT COMPARES NORMALISED COMPONENTS, NEVER WHOLE STRINGS, and the previous
	 * draft's `$url !== $this->pinned['url']` was a security defect rather than a
	 * style one. WordPress does not hand the same string to both hook points:
	 * `http_request_args` receives the URL as given (`class-wp-http.php:252`),
	 * then `class-wp-http.php:283-289` rewrites it through
	 * `wp_http_validate_url()` and `wp_kses_bad_protocol()` — which lower-cases the
	 * scheme — and that REWRITTEN string is what reaches `http_api_curl`
	 * (`class-wp-http-requests-hooks.php:58`). A single capital letter in `HTTPS://`
	 * therefore made the two strings differ, so a raw comparison failed to recognise
	 * the class's own request, applied no `CURLOPT_RESOLVE`, and let the transport
	 * resolve the name itself — the rebinding defence disabled, silently, by a
	 * spelling the attacker chooses. An attacker-supplied `Location` does the same.
	 *
	 * Scheme, host and port are compared because they are the only things the pin
	 * is about: `CURLOPT_RESOLVE` is a `host:port:address` triplet and knows
	 * nothing of paths, and leaving the path out means percent-encoding and
	 * query-string rewriting cannot produce a mismatch. The host goes through
	 * MediaUrlGuard's OWN normaliser and the port default comes from the guard's
	 * own table — the same code, not a copy of it, because the fail-closed check
	 * turns any disagreement between the two into a refused import.
	 *
	 * THIS IS NOT ON ITS OWN A TEST OF WHOSE REQUEST IT IS. Every other request to
	 * the same host and port matches it too, which is why the callbacks require the
	 * token as well. An earlier draft narrowed by address alone and recorded that
	 * narrowing further "would mean comparing paths"; untrue, since `$args` reaches
	 * both hooks. See carries_fetch_token().
	 *
	 * @param string $url The URL the hook was fired with.
	 *
	 * @return bool True when the URL addresses the target currently pinned.
	 */
	private function matches_pinned_request( string $url ): bool {
		if ( null === $this->pinned ) {
			return false;
		}

		// A url core cannot parse comes back as `false`, and needs no guard: every
		// read below is null-coalesced, so `false` yields an empty scheme, which can
		// never equal the pinned one — only ever `http` or `https`, MediaUrlGuard
		// having refused everything else. An explicit `is_array()` test was tried
		// here and deleted again for being unable to change any answer.
		$parts = wp_parse_url( $url );

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( $scheme !== $this->pinned['scheme'] ) {
			return false;
		}

		$host = MediaUrlGuard::normalise_host( (string) ( $parts['host'] ?? '' ) );

		if ( $host !== $this->pinned['host'] ) {
			return false;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( MediaUrlGuard::DEFAULT_PORTS[ $scheme ] ?? 0 );

		return $port === $this->pinned['port'];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's public surface is camelCase because it is called from camelCase collaborators, and the ruleset's snake_case rule is a WordPress-core convention this plugin's own classes do not follow.
	/**
	 * The `CURLOPT_RESOLVE` directive a given request should be pinned to, or
	 * null when it should not be pinned at all.
	 *
	 * SPLIT OUT FROM pinCurlHandle() DELIBERATELY, so that every decision about the
	 * pin is an ordinary return value here rather than a branch inside the effect.
	 * `curl_setopt()` can only be observed in a test on a PHP with no ext-curl —
	 * Brain Monkey can define a missing function but cannot redefine a loaded
	 * extension's — so anything decided in there would be untestable where CI runs.
	 *
	 * IT REFUSES TO PIN A REQUEST THAT IS NOT THIS FETCH'S HOP, because a pin
	 * applied to somebody else's request would re-point THEIR connection at THIS
	 * import's address.
	 *
	 * AN IP LITERAL IS NOT PINNED, and does not need to be. Since `9e3801f`
	 * MediaUrlGuard refuses octal, hex, decimal-integer and non-ASCII hosts
	 * outright, so a literal reaching here is one curl parses as an address just
	 * as this class did: `$pinned['host']` and `$pinned['ip']` are the same
	 * string, no name resolution happens, and there is no lookup to rebind. A
	 * directive would be inert; returning null says so out loud.
	 *
	 * @param string $url The URL of the request being prepared.
	 *
	 * @return string|null The directive, or null when this request must not be pinned.
	 */
	public function pinDirectiveFor( string $url ): ?string {
		if ( ! $this->matches_pinned_request( $url ) ) {
			return null;
		}

		if ( $this->pinned['host'] === $this->pinned['ip'] ) {
			return null;
		}

		return $this->resolveDirective( $this->pinned );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's public surface is camelCase because it is called from camelCase collaborators, and the ruleset's snake_case rule is a WordPress-core convention this plugin's own classes do not follow.
	/**
	 * Pins the curl handle to the address the guard validated for this hop.
	 *
	 * This is the line that closes DNS rebinding: `CURLOPT_RESOLVE` tells curl to
	 * use the supplied address for this host and port and to skip name resolution
	 * entirely. The directive replaces the whole list on the handle, and each hop
	 * is a fresh `wp_safe_remote_get()` call with a fresh handle, so no previous
	 * hop's directive can survive into this one.
	 *
	 * Guarded on `function_exists( 'curl_setopt' )` so that a PHP without ext-curl
	 * gets a refusal rather than a fatal raised from inside a hook callback. Core
	 * fires this action from the curl transport only, so a streams site never
	 * reaches the method and keeps every other guard here plus its narrow
	 * DNS-rebinding window — the accepted cost in spec §7 item 3. The guard covers
	 * the contradiction in between: this action firing on a PHP with no curl
	 * functions to pin with.
	 *
	 * The handle is taken by reference because core fires this action with
	 * `do_action_ref_array()`.
	 *
	 * `$pin_applied` IS SET FROM `curl_setopt()`'s OWN RETURN VALUE, never before the
	 * call. Set on recognising the url instead, it would record that the handle was
	 * RECOGNISED rather than PINNED — the `curl_setopt()` line could then be deleted
	 * outright with the fail-closed assertion downstream still passing, the exact
	 * failure it exists to prevent.
	 *
	 * @param mixed                $handle The curl handle, by reference.
	 * @param array<string, mixed> $args   The request arguments, carrying this fetch's token when the request is its own.
	 * @param string               $url    The URL being requested, used to tell the hop that was validated from any other.
	 */
	public function pinCurlHandle( &$handle, array $args, string $url ): void {
		// Recorded before anything else, for every request including a stranger's:
		// core fires this action only from the curl transport, so reaching this line
		// proves a curl connection is being made and a missing pin is then a failure
		// rather than a streams site's accepted limitation. Deliberately NOT gated on
		// the token, so that a request of this class's own arriving without one is
		// caught downstream rather than waved through unpinned.
		$this->curl_transport_used = true;

		if ( ! $this->carries_fetch_token( $args ) ) {
			return;
		}

		if ( ! $this->matches_pinned_request( $url ) ) {
			return;
		}

		$directive = $this->pinDirectiveFor( $url );

		if ( null === $directive ) {
			// An IP literal, where no pin is the correct outcome and there is
			// nothing for curl to accept or reject. Recorded as applied because
			// the connection IS constrained to the validated address — it is the
			// address — and the fetch must not be refused for it.
			$this->pin_applied = true;

			return;
		}

		if ( ! function_exists( 'curl_setopt' ) ) {
			// No curl on this PHP, so nothing can be pinned. Unreachable in
			// production, where only the curl transport fires this action at all;
			// the flag is left false so that if it ever WERE reached, the fetch
			// would be refused rather than completed unpinned.
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE has no WordPress API equivalent; it is the whole DNS-rebinding defence.
		$this->pin_applied = curl_setopt( $handle, CURLOPT_RESOLVE, [ $directive ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $correlationId is the parameter name this operation's collaborators already use, and renaming it here alone would make the call sites disagree.
	/**
	 * Refuses the fetch unless the connection just made was actually pinned.
	 *
	 * THIS IS THE FAIL-CLOSED HALF OF THE PIN, and it exists because the previous
	 * design could not tell its two silences apart: "this url is not mine" and "this
	 * url IS mine and I failed to recognise it, so it went out with no
	 * `CURLOPT_RESOLVE` and the transport resolved the name itself". The second is
	 * the whole DNS-rebinding hole, and it produced no refusal, log line or test
	 * failure. A MediaFetch that cannot prove it pinned the connection must not
	 * return bytes, so this runs after every request, on every hop.
	 *
	 * WHAT IT PROVES IS THAT `curl_setopt()` TOOK THE DIRECTIVE, not merely that
	 * the handle was recognised: `$pin_applied` is that call's own return value.
	 * The one case where no directive is correct — an IP literal, which is its own
	 * resolution — sets the flag explicitly, so "nothing to pin" is a decision
	 * this class made rather than a step it silently skipped.
	 *
	 * IT FIRES ONLY WHEN CURL WAS USED. A streams-transport site never fires
	 * `http_api_curl` and has no pin to apply — the accepted cost in spec §7 item 3
	 * — so `$curl_transport_used` stays false and the fetch proceeds under every
	 * other guard. Refused is only the case where curl WAS the transport and the pin
	 * did not go on. The refusal names nothing about address, response or network.
	 *
	 * @param string $correlationId The id detail is logged under.
	 *
	 * @throws OperationException When curl made the request and no pin was applied.
	 */
	private function assert_connection_was_pinned( string $correlationId ): void {
		if ( ! $this->curl_transport_used || $this->pin_applied ) {
			return;
		}

		$this->log( $correlationId, 'connection was not pinned to the validated address for: ' . ( $this->pinned['url'] ?? '' ) );

		$this->refuse(
			ErrorCode::ExecutionFailed,
			'The connection to the remote server could not be pinned to the address that was checked.',
			'Request a fresh preview and try the import again.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log is the only sink available to a plugin for detail that must not reach the envelope; the alternative is losing the diagnosis entirely.
	/**
	 * Records detail server-side that must never reach the envelope.
	 *
	 * @param string $correlation The correlation id the detail is filed under.
	 * @param string $detail      The unsafe detail.
	 */
	private function log( string $correlation, string $detail ): void {
		error_log( sprintf( 'SiteHelm media import (%s): %s', $correlation, $detail ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- every message and remediation reaching this method is a literal written in this file for end users, and escaping them would put HTML entities into a JSON envelope.
	/**
	 * Refuses the fetch.
	 *
	 * Every message and remediation passed here names, at most, a status number
	 * or the redirect limit. See the class docblock for why a header, a redirect
	 * target, an address or a transport string may never appear in one.
	 *
	 * @param ErrorCode $code        The stable public error code.
	 * @param string    $message     Safe, human-readable explanation.
	 * @param string    $remediation What the caller can do about it.
	 *
	 * @return never
	 *
	 * @throws OperationException Always.
	 */
	private function refuse( ErrorCode $code, string $message, string $remediation ): never {
		throw new OperationException( $code, $message, $remediation );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
