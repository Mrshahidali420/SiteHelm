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
 * `http://127.0.0.1:8080/` needs no hostile resolver at all. Every hop is
 * therefore re-validated through the same MediaUrlGuard the original URL passed,
 * and the pin is MOVED to that hop's approved address — a hop that is validated
 * but not re-pinned reopens the rebinding hole one level down. Redirects are
 * capped at two rather than disabled because the CDN redirects that make imports
 * work in practice are ordinary and legitimate.
 *
 * BOTH HOOKS ARE REMOVED IN A `finally`. See fetch() for why that single line is
 * the most important one in the file.
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
 * do. A status number is the one number a caller genuinely needs and is no
 * oracle for anything about this site's network, so it is the only detail a
 * refusal here names.
 *
 * The bytes this class returns GET NO TRUST FROM HAVING BEEN FETCHED. They go
 * straight into MediaMimeGuard::inspectBytes(), which sniffs the content. The
 * response's `Content-Type` is not consulted anywhere in this class, for the
 * same reason the upload path has no `mimeType` input property: a declared type
 * is a second source of truth that can disagree with the bytes.
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
	 * An allowlist of one rather than a `>= 400` test: a `204` carries no body
	 * and a `304` carries no body either, and both would otherwise fail three
	 * lines later with an empty-body diagnosis that misdescribes what happened.
	 */
	private const REQUIRED_STATUS = 200;

	/**
	 * The target the transport is currently pinned to, or null when no fetch is
	 * in flight.
	 *
	 * Held as state because `http_api_curl` hands the callback a curl handle and
	 * a URL but no way to pass the validated target alongside them. It is
	 * cleared in the same `finally` that removes the hooks, so that an
	 * accidental later invocation of the callback finds nothing to pin rather
	 * than a stale target.
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

	/**
	 * Fetches the bytes at an already-validated target.
	 *
	 * THE `finally` IS THE MOST IMPORTANT LINE IN THIS CLASS. WordPress hooks
	 * are process-global. A `CURLOPT_RESOLVE` pin left registered would re-point
	 * unrelated HTTP requests made later in the same request cycle — a licence
	 * check, an update check, another plugin's API call — at whatever address
	 * this import happened to pin, silently. That would make this feature a
	 * defect in every other plugin on the site. The removal must therefore
	 * happen on EVERY path out of this method, including a refusal thrown from
	 * inside the filter callback itself while the request is in flight, which is
	 * precisely what a `finally` guarantees and what a removal placed after the
	 * checks would miss.
	 *
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated     The guard's approved target.
	 * @param string                                                                  $correlationId The id detail is logged under.
	 *
	 * @return string The raw response body.
	 *
	 * @throws OperationException On a transport failure, a non-200 status, an empty body, or an over-cap body.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $correlationId matches the context property name.
	 */
	public function fetch( array $validated, string $correlationId ): string {
		$this->pinned = $validated;

		add_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], 10, 2 );
		add_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], 10, 3 );

		try {
			// wp_safe_remote_get(), never wp_remote_get(): core's own SSRF
			// baseline is kept underneath this class's policy so the plugin can
			// only ever be stricter than the platform, never weaker.
			$response = wp_safe_remote_get( $validated['url'] );

			if ( is_wp_error( $response ) ) {
				$this->log( $correlationId, 'transport failure: ' . $response->get_error_message() );

				$this->refuse(
					ErrorCode::ExecutionFailed,
					'The asset could not be retrieved from the remote server.',
					'Check that the address serves the file and request a fresh preview.'
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

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
			// over-cap response arrives one byte over and is recognisable here.
			// Set to the cap exactly, an oversized file would arrive truncated
			// to exactly the cap and be accepted as a valid but silently
			// corrupted image.
			if ( strlen( $body ) > MediaMimeGuard::MAX_DECODED_BYTES ) {
				$this->refuse(
					ErrorCode::InvalidInput,
					'The asset at the supplied address is larger than this site will import.',
					'Import a smaller file, or add it through the upload operation instead.'
				);
			}

			return $body;
		} finally {
			remove_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], 10 );
			remove_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], 10 );

			$this->pinned = null;
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The `CURLOPT_RESOLVE` directive for one validated target.
	 *
	 * The three parts are the whole of the pin: for THIS host on THIS port, use
	 * THIS address and do not ask a resolver at all. Public so a test can read
	 * the directive back, because the `curl_setopt()` call that consumes it is
	 * not observable from a unit test.
	 *
	 * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated The guard's approved target.
	 *
	 * @return string The directive, as `host:port:address`.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function resolveDirective( array $validated ): string {
		return sprintf( '%s:%d:%s', $validated['host'], $validated['port'], $validated['ip'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The target currently pinned, or null when no fetch is in flight.
	 *
	 * Public for testing only. It is what lets a test prove that a redirect hop
	 * MOVED the pin rather than merely passing the policy check — a hop that is
	 * validated but not re-pinned is the rebinding hole reopening, and nothing
	 * else about the class's behaviour would reveal it.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string}|null The pinned target.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function pinnedTarget(): ?array {
		return $this->pinned;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Re-validates one hop and forces the safe request arguments.
	 *
	 * Public because it is a filter callback, not because it is part of this
	 * class's interface.
	 *
	 * WordPress applies `http_request_args` to the primary request AND to every
	 * redirect target before following it, which is what makes this the right
	 * place — and the only place — to hold a redirect chain to the same policy
	 * the original URL passed. The guard throws on refusal, and the throw
	 * propagates out of the in-flight request so the outer fetch reports a
	 * refusal rather than a success.
	 *
	 * The forced values come SECOND in the array_merge deliberately. Another
	 * plugin's `http_request_args` filter runs alongside this one, and whichever
	 * set comes second wins: a safety setting a third party can switch off is
	 * not a safety setting. Everything else in `$args` survives untouched,
	 * because a header or cookie another plugin added has nothing to do with
	 * this policy.
	 *
	 * @param array<string, mixed> $args The request arguments so far.
	 * @param string               $url  The URL of this hop.
	 *
	 * @return array<string, mixed> The arguments with the safety settings forced.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when this hop is not an address this site will fetch.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function filterRequestArgs( array $args, string $url ): array {
		// Re-pinned, not merely re-validated. Without this assignment the
		// connection would still be pinned to the previous hop's address while
		// dialling this hop's host, and curl would fall back to a resolver for
		// the name it has no directive for.
		$this->pinned = $this->guard->validate( $url );

		return array_merge(
			$args,
			[
				'reject_unsafe_urls'  => true,
				'redirection'         => self::MAX_REDIRECTS,
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
	 * Pins the curl handle to the validated address.
	 *
	 * This is the line that closes DNS rebinding: `CURLOPT_RESOLVE` tells curl
	 * to use the supplied address for this host and port and to skip name
	 * resolution entirely, so the address the guard validated is the address
	 * actually dialled.
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
	 * @param string               $url    The URL being requested. Unused: the pin comes
	 *                                     from the validated target, never from the URL,
	 *                                     so an unvalidated URL can never become a pin.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function pinCurlHandle( &$handle, array $args, string $url ): void {
		unset( $args, $url );

		if ( null === $this->pinned || ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE has no WordPress API equivalent; it is the whole DNS-rebinding defence.
		curl_setopt( $handle, CURLOPT_RESOLVE, [ $this->resolveDirective( $this->pinned ) ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Records detail server-side that must never reach the envelope.
	 *
	 * @param string $correlation The correlation id the detail is filed under.
	 * @param string $detail      The unsafe detail.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function log( string $correlation, string $detail ): void {
		error_log( sprintf( 'SiteHelm media import (%s): %s', $correlation, $detail ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Refuses the fetch.
	 *
	 * Every message and remediation passed here names, at most, a status number.
	 * See the class docblock for why a header, a redirect target, an address or
	 * a transport string may never appear in one.
	 *
	 * @param ErrorCode $code        The stable public error code.
	 * @param string    $message     Safe, human-readable explanation.
	 * @param string    $remediation What the caller can do about it.
	 *
	 * @return never
	 *
	 * @throws OperationException Always.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function refuse( ErrorCode $code, string $message, string $remediation ): never {
		throw new OperationException( $code, $message, $remediation );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
