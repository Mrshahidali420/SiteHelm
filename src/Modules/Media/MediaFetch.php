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
 * THE GUARD ALONE IS NOT ENOUGH, and the reason is the whole design of this
 * class. Between the guard's DNS lookup and the transport's own lookup there is
 * a window of milliseconds in which an attacker's resolver can change its mind:
 * answer the guard with a public address, answer the transport with `127.0.0.1`.
 * Every check the guard performed would then have validated an address the site
 * never connects to. That is DNS rebinding, and it is closed here by pinning the
 * connection to the address the guard returned, through `CURLOPT_RESOLVE`, so
 * that the address that was validated is the address that is dialled.
 *
 * A REDIRECT IS THE SAME ATTACK BY ANOTHER ROUTE. A `302` to
 * `http://127.0.0.1:8080/` needs no hostile resolver at all.
 *
 * THIS CLASS THEREFORE FOLLOWS REDIRECTS ITSELF, and that is not a stylistic
 * choice. `http_request_args` fires exactly ONCE per `WP_Http::request()`
 * (`class-wp-http.php:252`); redirects are followed deeper down, inside the
 * Requests library (`Requests.php`), and never re-enter `WP_Http::request()`.
 * A filter-based hop check would therefore see the FIRST url and nothing else:
 * hop two would be neither re-validated nor re-pinned, and the handle would
 * still carry hop one's `CURLOPT_RESOLVE` directive while dialling hop two's
 * host — the rebinding hole reopening one level down, with a stale pin on top.
 * So `redirection` is forced to zero, WordPress hands the `3xx` back, and the
 * loop in fetch() validates each hop through the same MediaUrlGuard the
 * original url passed and MOVES the pin to that hop's approved address before
 * the next request leaves. Redirects are capped at two rather than disabled
 * because the CDN redirects that make imports work in practice are ordinary.
 *
 * BOTH HOOKS ARE REMOVED IN A `finally`, on every path out of the hop loop
 * included. See fetch() for why that single line is the most important one in
 * the file.
 *
 * BOTH HOOKS ALSO CHECK THAT THE REQUEST IS THIS CLASS'S OWN. WordPress hooks
 * are process-global, and any other code that makes an HTTP request while a
 * fetch is in flight would otherwise be handed this fetch's `CURLOPT_RESOLVE`
 * pin and this fetch's forced arguments. Both callbacks compare the url they
 * are given against the url being fetched and do nothing when it differs.
 *
 * THE PIN IS CURL-ONLY, and that cost is accepted rather than hidden (spec §7
 * item 3). `CURLOPT_RESOLVE` has no equivalent in WordPress's streams transport,
 * so a site whose HTTP transport is not curl gets every other guard here — the
 * scheme, port, credential and address policy, the hop re-validation, the size
 * cap, `reject_unsafe_urls` — and keeps a narrow residual DNS-rebinding window.
 *
 * NO RESPONSE HEADER, NO REDIRECT TARGET, NO RESOLVED ADDRESS AND NO TRANSPORT
 * ERROR STRING EVER REACHES THE ENVELOPE. Those four are exactly what an
 * attacker harvests from a blind SSRF probe, and leaking any one of them turns a
 * refused fetch into an internal port scanner. Detail goes to `error_log`
 * correlated by the correlation id, exactly as MediaUpload's sideload failures
 * do. A status number and the redirect limit are the only numbers a refusal here
 * names, and neither is an oracle for anything about this site's network.
 *
 * The bytes this class returns GET NO TRUST FROM HAVING BEEN FETCHED. They go
 * straight into MediaMimeGuard::inspectBytes(), which sniffs the content. The
 * response's `Content-Type` is not consulted anywhere in this class, for the
 * same reason the upload path has no `mimeType` input property: a declared type
 * is a second source of truth that can disagree with the bytes. `Location` is
 * read, and only because a redirect cannot be followed without it — and what it
 * names is re-validated from scratch before it is dialled.
 *
 * @package SiteHelm
 */
final class MediaFetch {

	/**
	 * The number of redirect hops permitted.
	 *
	 * Not zero, because CDN redirects are how a great many real asset URLs
	 * work, and each hop passes the same policy the original URL passed. Small,
	 * because a redirect chain is also a way to spend this site's time.
	 */
	private const MAX_REDIRECTS = 2;

	/**
	 * The seconds allowed for the whole transfer.
	 */
	private const TIMEOUT_SECONDS = 15;

	/**
	 * The status this class will accept, and the only one.
	 *
	 * An allowlist of one rather than a `>= 400` test: a `204` is not an error
	 * to such a test and carries no body, so it would otherwise fail three lines
	 * later with an empty-body diagnosis that misdescribes what happened.
	 */
	private const REQUIRED_STATUS = 200;

	/**
	 * The statuses this class follows as a redirect.
	 *
	 * `304` is deliberately absent: it is a cache response, not a redirect, and
	 * carries no `Location`. It falls through to the status check and is refused
	 * there, which is the accurate diagnosis.
	 */
	private const REDIRECT_STATUSES = [ 301, 302, 303, 307, 308 ];

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
	 * The target the transport is currently pinned to, or null when no fetch is
	 * in flight.
	 *
	 * Held as state because `http_api_curl` hands the callback a curl handle and
	 * a URL but no way to pass the validated target alongside them. It is
	 * re-assigned before each hop's request and cleared in the same `finally`
	 * that removes the hooks, so that an accidental later invocation of either
	 * callback finds nothing to act on rather than a stale target.
	 *
	 * THIS MAKES fetch() NON-REENTRANT. One instance cannot run two fetches at
	 * once, and must not: the second would overwrite the first's pin mid-flight.
	 * Nothing in this plugin calls it re-entrantly — MediaImport constructs the
	 * fetcher, calls fetch() once and discards the result — and PHP's
	 * single-threaded request model means the only way in would be for a hook
	 * fired from inside the request to call fetch() again.
	 *
	 * @var array{url: string, scheme: string, host: string, port: int, ip: string}|null
	 */
	private ?array $pinned = null;

	/**
	 * Constructs the fetcher.
	 *
	 * @param MediaUrlGuard $guard The address policy every hop is re-validated through.
	 */
	public function __construct( private readonly MediaUrlGuard $guard ) {}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $correlationId is the parameter name this operation's collaborators already use, and renaming it here alone would make the call sites disagree.
	/**
	 * Fetches the bytes at an already-validated target, following redirects.
	 *
	 * THE `finally` IS THE MOST IMPORTANT LINE IN THIS CLASS. WordPress hooks
	 * are process-global. A `CURLOPT_RESOLVE` pin left registered would re-point
	 * unrelated HTTP requests made later in the same request cycle — a licence
	 * check, an update check, another plugin's API call — at whatever address
	 * this import happened to pin, silently. That would make this feature a
	 * defect in every other plugin on the site. The removal must therefore
	 * happen on EVERY path out of this method, including every path out of the
	 * redirect loop, which is precisely what a `finally` guarantees and what a
	 * removal placed after the checks would miss.
	 *
	 * THE LOOP IS THE HOP RE-VALIDATION. Each pass validates the target through
	 * the guard, points the pin at it, and only then issues the request. A hop
	 * that were validated but not re-pinned would leave the connection pinned to
	 * the previous hop's address while dialling this hop's host, and curl would
	 * fall back to a resolver for the name it has no directive for — which is the
	 * whole attack this class exists to stop, one level down.
	 *
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated     The guard's approved target.
	 * @param string                                                                  $correlationId The id detail is logged under.
	 *
	 * @return string The raw response body.
	 *
	 * @throws OperationException On a transport failure, a redirect this site will not follow, a non-200 status, an empty body, or an over-cap body.
	 */
	public function fetch( array $validated, string $correlationId ): string {
		add_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], self::HOOK_PRIORITY, 2 );
		add_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], self::HOOK_PRIORITY, 3 );

		try {
			$target = $validated;
			$hops   = 0;

			while ( true ) {
				// Assigned HERE, inside the loop, so the pin in force is always
				// the hop about to be dialled and never the one before it.
				$this->pinned = $target;

				// wp_safe_remote_get(), never wp_remote_get(): core's own SSRF
				// baseline is kept underneath this class's policy so the plugin
				// can only ever be stricter than the platform, never weaker.
				$response = wp_safe_remote_get( $target['url'] );

				if ( is_wp_error( $response ) ) {
					$this->log( $correlationId, 'transport failure: ' . $response->get_error_message() );

					$this->refuse(
						ErrorCode::ExecutionFailed,
						'The asset could not be retrieved from the remote server.',
						'Check that the address serves the file and request a fresh preview.'
					);
				}

				$status = (int) wp_remote_retrieve_response_code( $response );

				if ( in_array( $status, self::REDIRECT_STATUSES, true ) ) {
					++$hops;

					if ( $hops > self::MAX_REDIRECTS ) {
						$this->refuse(
							ErrorCode::ExecutionFailed,
							sprintf( 'The remote server redirected more than %d times.', self::MAX_REDIRECTS ),
							'Supply the address the file is finally served from.'
						);
					}

					$target = $this->next_hop( $response, $target );

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

			$this->pinned = null;
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
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated The guard's approved target.
	 *
	 * @return string The directive, as `host:port:address`.
	 */
	public function resolveDirective( array $validated ): string {
		return sprintf( '%s:%d:%s', $validated['host'], $validated['port'], $validated['ip'] );
	}

	/**
	 * Forces the safe request arguments on this class's own request.
	 *
	 * Public because it is a filter callback, not because it is part of this
	 * class's interface.
	 *
	 * IT DOES NOT VALIDATE ANYTHING, and an earlier draft that did was wrong.
	 * `http_request_args` fires once per `WP_Http::request()`, before the first
	 * byte leaves and never again for a redirect, so a hop check placed here
	 * would only ever see hop one. Hop validation lives in fetch()'s loop.
	 *
	 * IT ALSO DOES NOTHING TO ANYONE ELSE'S REQUEST. Any code that makes an HTTP
	 * request while a fetch is in flight — an update check, another plugin's API
	 * call — would otherwise be silently handed this fetch's forced timeout,
	 * user-agent and redirect ban. Comparing the url against the one being
	 * fetched keeps this filter's blast radius to exactly one request.
	 *
	 * `redirection` is forced to ZERO so WordPress returns the `3xx` rather than
	 * following it inside Requests, where this class cannot re-validate or
	 * re-pin the next hop. The forced values come SECOND in the array_merge and
	 * are re-applied on every invocation, and the filter runs at `PHP_INT_MAX`:
	 * three parts of the same rule, that whatever any other filter did, these
	 * values are what the request is made with.
	 *
	 * @param array<string, mixed> $args The request arguments so far.
	 * @param string               $url  The URL of the request being prepared.
	 *
	 * @return array<string, mixed> The arguments, with the safety settings forced when the request is this class's own.
	 */
	public function filterRequestArgs( array $args, string $url ): array {
		if ( null === $this->pinned || $url !== $this->pinned['url'] ) {
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

	/**
	 * The `CURLOPT_RESOLVE` directive a given request should be pinned to, or
	 * null when it should not be pinned at all.
	 *
	 * SPLIT OUT FROM pinCurlHandle() DELIBERATELY. Every decision about the pin
	 * lives here, where it is an ordinary return value; the method below is left
	 * with the single `curl_setopt()` call and no branching of its own. That
	 * matters because `curl_setopt()` can only be observed in a test on a PHP
	 * that has no ext-curl — Brain Monkey can define a missing function but
	 * cannot redefine a loaded extension's — so anything decided inside the
	 * effect would be untestable on the interpreters CI actually runs.
	 *
	 * IT REFUSES TO PIN A REQUEST THAT IS NOT THIS FETCH'S. The url is compared
	 * against the hop currently in flight, because a pin applied to somebody
	 * else's request would re-point THEIR connection at THIS import's address.
	 *
	 * AN IP LITERAL IS NOT PINNED, and does not need to be. Since `9e3801f`
	 * MediaUrlGuard refuses octal, hex, decimal-integer and non-ASCII hosts
	 * outright and sends only genuine literals down the literal path, where a
	 * literal is its own resolution — so `$pinned['host']` and `$pinned['ip']`
	 * are the same string, curl parses that host as an address and performs no
	 * name resolution, and there is no lookup for an attacker to rebind. A
	 * directive here would be inert; returning null says so out loud rather than
	 * leaving a reader to wonder whether the pin is doing anything.
	 *
	 * @param string $url The URL of the request being prepared.
	 *
	 * @return string|null The directive, or null when this request must not be pinned.
	 */
	public function pinDirectiveFor( string $url ): ?string {
		if ( null === $this->pinned || $url !== $this->pinned['url'] ) {
			return null;
		}

		if ( $this->pinned['host'] === $this->pinned['ip'] ) {
			return null;
		}

		return $this->resolveDirective( $this->pinned );
	}

	/**
	 * Pins the curl handle to the address the guard validated for this hop.
	 *
	 * This is the line that closes DNS rebinding: `CURLOPT_RESOLVE` tells curl
	 * to use the supplied address for this host and port and to skip name
	 * resolution entirely, so the address the guard validated is the address
	 * actually dialled. The directive replaces the whole `CURLOPT_RESOLVE` list
	 * on the handle, and each hop is a fresh `wp_safe_remote_get()` call with a
	 * fresh handle, so no previous hop's directive can survive into this one.
	 *
	 * Guarded on `function_exists( 'curl_setopt' )` because WordPress may be
	 * using its streams transport, where no equivalent exists. On such a site
	 * the fetch still proceeds under every other guard and a narrow
	 * DNS-rebinding window remains — an accepted cost, recorded in spec §7 item
	 * 3 rather than papered over.
	 *
	 * The handle is taken by reference because core fires this action with
	 * `do_action_ref_array()`.
	 *
	 * @param mixed                $handle The curl handle, by reference.
	 * @param array<string, mixed> $args   The request arguments. Unused.
	 * @param string               $url    The URL being requested, used only to tell this class's own request from anybody else's.
	 */
	public function pinCurlHandle( &$handle, array $args, string $url ): void {
		unset( $args );

		$directive = $this->pinDirectiveFor( $url );

		if ( null === $directive || ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE has no WordPress API equivalent; it is the whole DNS-rebinding defence.
		curl_setopt( $handle, CURLOPT_RESOLVE, [ $directive ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Validates the destination of a redirect and returns it as the next target.
	 *
	 * The destination goes through MediaUrlGuard::validate() as a brand-new URL,
	 * not as a variation on the one that redirected. Everything the guard checks
	 * for the address a caller supplied — scheme, port, credentials, host form,
	 * every resolved address being public — is exactly what must be checked for
	 * an address an attacker's server supplied, and rather more urgently.
	 *
	 * @param mixed                                                                   $response The 3xx response.
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $from     The hop that produced it.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string} The validated next hop.
	 *
	 * @throws OperationException When there is no destination, or the guard refuses it.
	 */
	private function next_hop( $response, array $from ): array {
		$location = wp_remote_retrieve_header( $response, 'location' );

		// Not a string when the header repeated, in which case core hands back
		// an array and there is no single destination to follow. Empty when it
		// was absent. Neither is followed, and neither is guessed at.
		if ( ! is_string( $location ) || '' === trim( $location ) ) {
			$this->refuse(
				ErrorCode::ExecutionFailed,
				'The remote server sent a redirect with no destination.',
				'Supply the address the file is finally served from.'
			);
		}

		return $this->guard->validate( $this->absolute_hop( trim( $location ), $from ) );
	}

	/**
	 * Resolves a `Location` value against the hop that produced it.
	 *
	 * Against THAT hop, not against the original URL: a chain that redirects to
	 * another host and then sends a root-relative `Location` means a path on the
	 * second host, and resolving it against the first would dial an address
	 * nobody chose.
	 *
	 * ONLY THREE FORMS ARE RESOLVED — absolute, protocol-relative and
	 * root-relative — and anything else is refused. Path-relative and dot-segment
	 * forms are legal in RFC 3986 and essentially absent from real asset
	 * redirects, and implementing them would mean a home-grown path normaliser
	 * whose disagreements with curl's would land exactly where this class's
	 * disagreements are most expensive. Refusing is the safe direction: the
	 * failure is a refused import, not a request to an address this class
	 * computed differently from the transport that dials it.
	 *
	 * @param string                                                                  $location The `Location` value, trimmed and non-empty.
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $from     The hop that produced it.
	 *
	 * @return string The absolute URL.
	 *
	 * @throws OperationException When the form is not one this class resolves.
	 */
	private function absolute_hop( string $location, array $from ): string {
		if ( 1 === preg_match( '#\A[a-z][a-z0-9+.\-]*:#i', $location ) ) {
			return $location;
		}

		if ( str_starts_with( $location, '//' ) ) {
			return $from['scheme'] . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return sprintf( '%s://%s:%d%s', $from['scheme'], $from['host'], $from['port'], $location );
		}

		$this->refuse(
			ErrorCode::ExecutionFailed,
			'The remote server redirected to a destination this site cannot resolve.',
			'Supply the address the file is finally served from.'
		);
	}

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
