<?php
/**
 * The shared WordPress stand-in for the MediaFetch tests (REQ-0052).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Media\HostResolver;
use SiteHelm\Modules\Media\MediaFetch;
use SiteHelm\Modules\Media\MediaUrlGuard;
use SiteHelm\Tests\TestCase;

/**
 * A miniature WordPress HTTP stack, faithful about the one thing that decides
 * whether the MediaFetch tests mean anything.
 *
 * THE FAKE FIRES `http_request_args` EXACTLY ONCE PER `wp_safe_remote_get()`
 * CALL, because that is what WordPress does: `WP_Http::request()` applies the
 * filter once at `class-wp-http.php:252` and redirects are followed further down
 * inside the Requests library, which never re-enters `WP_Http::request()`. The
 * first version of these tests had the fake re-apply the filter per redirect
 * hop, which is the behaviour the class under test needed in order to pass —
 * so the tests proved the design instead of testing it, and hop two was in
 * reality neither re-validated nor re-pinned. This fake gives the class no such
 * help: a redirect is a `3xx` handed back, and every hop after the first only
 * happens because MediaFetch made another request itself.
 *
 * The filters are applied in PRIORITY order, ascending, like `apply_filters`,
 * because "this class registers at PHP_INT_MAX so it has the last word" is
 * otherwise untestable.
 *
 * `http_api_curl` fires once per transport request, which for this class means
 * once per hop, each with its own handle — exactly as core does, where each
 * `WP_Http::request()` builds a fresh curl handle.
 *
 * THE TWO HOOKS ARE HANDED DIFFERENT STRINGS, because core hands them different
 * strings. `http_request_args` gets the URL as supplied
 * (`class-wp-http.php:252`); `class-wp-http.php:283-289` then rewrites it through
 * `wp_http_validate_url()` and `wp_kses_bad_protocol()`, which lower-cases the
 * scheme; and it is the rewritten string that `WP_HTTP_Requests_Hooks` carries
 * into `http_api_curl` (`class-wp-http-requests-hooks.php:58`). An earlier
 * version of this fake passed one identical `$url` to both, and that symmetry hid
 * a live security defect: MediaFetch compared raw strings, so `HTTPS://` made it
 * fail to recognise its own request and dial it with no address pin at all. The
 * fake now applies core's rewrite between the two hook points, which is what
 * makes that failure visible to a test.
 *
 * Every refusal is asserted on its specific ErrorCode inside a try/catch. Every
 * refusal in this codebase is OperationException, so a bare expectException()
 * would pass on a completely different refusal than the one the test aimed at.
 */
abstract class MediaFetchTestCase extends TestCase {

	use FakesWordPressHooks;

	/**
	 * The `@group` a test annotates itself with to be run with no curl fake.
	 *
	 * A group rather than a method name, so that renaming the test cannot silently
	 * detach it from the one condition it needs.
	 */
	protected const CURL_MUST_BE_ABSENT = 'curl-must-be-absent';

	/**
	 * The request argument this fetch's identifying token travels in.
	 *
	 * Spelt out here rather than read from the class under test, deliberately. It
	 * is a contract with WordPress's own `$parsed_args` array — the key survives
	 * `wp_parse_args()` and reaches both hook points under this exact name — so a
	 * test that took the constant from the class would agree with any rename,
	 * including one that collided with a key core or another plugin already uses.
	 */
	protected const TOKEN_ARG = 'sitehelm_fetch_token';

	/**
	 * Every curl option the class set, as [ option, value ], newest last.
	 *
	 * Only populated on a PHP with no ext-curl. See fakeCurl().
	 *
	 * @var array<int, array{0: int, 1: mixed}>
	 */
	protected array $curlOptions = [];

	/**
	 * What the class decided to pin, once per transport request, in order, with
	 * null for a request it declined to pin.
	 *
	 * Recorded from inside the in-flight request by a spy on `http_api_curl`, so
	 * it is the class's own decision read at the exact moment core would apply
	 * it — the state at that instant is the whole question a hop test asks. It
	 * exists because `curl_setopt()` itself is only observable on a PHP without
	 * ext-curl, which would leave every pinning assertion in these files
	 * unrunnable on the interpreters CI uses.
	 *
	 * @var array<int, string|null>
	 */
	protected array $pinDecisions = [];

	/**
	 * The responses the faked transport hands back, one per request, in order.
	 *
	 * @var array<int, mixed>
	 */
	protected array $responses = [];

	/**
	 * Every URL the faked transport was actually asked for, in order.
	 *
	 * @var array<int, string>
	 */
	protected array $requestedUrls = [];

	/**
	 * The post-filter request arguments of each request, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $sentArgs = [];

	/**
	 * What DNS is made to say, per host. A host absent from this map resolves to
	 * nothing, which MediaUrlGuard refuses.
	 *
	 * @var array<string, array<int, string>>
	 */
	protected array $dns = [];

	/**
	 * The transport handles handed to `http_api_curl`, held so a real CurlHandle
	 * outlives the request it was made for.
	 *
	 * @var array<int, mixed>
	 */
	private array $handles = [];

	/**
	 * Whether the faked transport fires `http_api_curl` at all.
	 *
	 * False models WordPress's streams transport, which fires no such action and
	 * on which no pin exists to apply — the accepted limitation in spec §7 item 3,
	 * and the one case the fail-closed check must NOT refuse.
	 */
	protected bool $curlTransport = true;

	/**
	 * An optional rewrite applied to the URL `http_api_curl` is fired with, on top
	 * of core's own.
	 *
	 * Exists so a test can stage the exact failure the fail-closed check is for:
	 * a request that IS this class's own but arrives at the pinning hook under a
	 * spelling the class does not recognise, and therefore goes out unpinned.
	 *
	 * @var callable|null
	 */
	protected $rewriteCurlUrl = null;

	/**
	 * An optional rewrite applied to the request ARGUMENTS `http_api_curl` is
	 * fired with, on top of what the filters returned.
	 *
	 * The counterpart of $rewriteCurlUrl for the other half of the ownership test:
	 * it stages a request that IS this class's own but reaches the pinning hook
	 * with its token missing or altered, which is the only way a test can tell a
	 * token check that works from one that is not there.
	 *
	 * @var callable|null
	 */
	protected $rewriteCurlArgs = null;

	/**
	 * What the faked `curl_setopt()` reports back about the directive.
	 *
	 * False models the one thing the real function can say that the class must act
	 * on: it would not take the option. Only settable where curl is faked — see
	 * requireObservableCurl() — which is also the only place the call is visible at
	 * all.
	 */
	protected bool $curlSetoptSucceeds = true;

	protected function setUp(): void {
		parent::setUp();

		$this->fakeHookRegistration();

		$this->curlOptions        = [];
		$this->curlSetoptSucceeds = true;

		$this->pinDecisions    = [];
		$this->handles         = [];
		$this->requestedUrls   = [];
		$this->sentArgs        = [];
		$this->curlTransport   = true;
		$this->rewriteCurlUrl  = null;
		$this->rewriteCurlArgs = null;
		$this->dns             = [ 'cdn.example.com' => [ '93.184.216.34' ] ];
		$this->responses       = [ $this->responseWith( 200, 'PNGBYTES' ) ];

		// MediaUrlGuard's own dependencies. Faked exactly as MediaUrlGuardTest
		// fakes them, because the guard is exercised for real here rather than
		// mocked: the hop revalidation IS the guard running.
		// Not an echo of its argument: core returns the URL after
		// `wp_kses_bad_protocol()` has been over it, which lower-cases the scheme.
		// That return value is what MediaUrlGuard now hands back as the validated
		// url, and modelling it here is what keeps the guard's idea of the address
		// and the transport's idea of the address the same string.
		Functions\when( 'wp_http_validate_url' )->alias(
			function ( string $url ) {
				return $this->coreRewrittenUrl( $url );
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
			}
		);

		// One WP_Http::request(). See this class's docblock for what it refuses
		// to do for the code under test.
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url, array $args = [] ) {
				$this->requestedUrls[] = $url;

				$args             = $this->applyRequestArgFilters( $args, $url );
				$this->sentArgs[] = $args;

				if ( $this->curlTransport ) {
					$handle = $this->transportHandle();

					// The rewritten url, not $url: see this class's docblock for the
					// asymmetry core has here and the defect the symmetric version hid.
					$curlUrl = $this->coreRewrittenUrl( $url );

					if ( null !== $this->rewriteCurlUrl ) {
						$curlUrl = ( $this->rewriteCurlUrl )( $curlUrl );
					}

					// The POST-filter arguments, because that is the array core
					// carries to this hook: `class-wp-http.php:344` builds
					// WP_HTTP_Requests_Hooks from the array `http_request_args`
					// returned, and `class-wp-http-requests-hooks.php:58` fires the
					// action with it.
					$curlArgs = $args;

					if ( null !== $this->rewriteCurlArgs ) {
						$curlArgs = ( $this->rewriteCurlArgs )( $curlArgs );
					}

					foreach ( $this->orderedByPriority( $this->curlActions ) as $action ) {
						$action( $handle, $curlArgs, $curlUrl );
					}
				}

				if ( [] === $this->responses ) {
					$this->fail( 'The transport was asked for more requests than the test queued responses for.' );
				}

				return array_shift( $this->responses );
			}
		);

		// There is no WP_Error class in this test suite, so a transport failure
		// is modelled by any object that answers get_error_message() — the one
		// member of WP_Error's surface this class must be proven NOT to read
		// into an envelope.
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return is_object( $thing ) && method_exists( $thing, 'get_error_message' );
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? '';
			}
		);

		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ): string {
				return (string) ( $response['body'] ?? '' );
			}
		);

		// Core returns '' for a header that is absent, and an array when the
		// header repeated. Both shapes matter: MediaFetch must refuse to follow
		// either rather than guess a destination.
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $response, string $header ) {
				return $response['headers'][ strtolower( $header ) ] ?? '';
			}
		);

		// Installed for every test but one, and installed HERE rather than in
		// the tests that read it, because Brain Monkey leaks a fake's
		// DEFINITION to every later test in the process while resetting its
		// BEHAVIOUR. Installed per-test, the first test to call fakeCurl() would
		// leave `function_exists( 'curl_setopt' )` true for every test after it
		// with no expectation behind it, and each of those would die on a
		// missing expectation rather than on anything it was testing.
		//
		// The one exception needs the function genuinely absent and runs in its own
		// process, so neither the exclusion nor any ordering between the two
		// MediaFetch test classes can leak into it. It opts out by GROUP rather
		// than by name: a name literal here would go on matching nothing after a
		// rename, the fake would be installed over the test that needs it absent,
		// and the test would then pass while proving the opposite of its title.
		//
		// fakeCurl() is itself a no-op where ext-curl is loaded — see there.
		if ( ! $this->isInGroup( self::CURL_MUST_BE_ABSENT ) ) {
			$this->fakeCurl();
		}
	}

	/**
	 * Whether the running test declares a given `@group`.
	 *
	 * Read from the method's own docblock rather than from `$this->getGroups()`,
	 * because the groups the suite assigned do not survive into the child process a
	 * `@runInSeparateProcess` test runs in — and the one test that uses this is
	 * process-isolated, so `getGroups()` would come back empty exactly where the
	 * answer matters. `getName( false )` is the method name as it is at runtime,
	 * which keeps the whole mechanism rename-proof.
	 *
	 * READ WITH REFLECTION RATHER THAN `PHPUnit\Util\Test::getGroups()`, which is
	 * `@internal` in PHPUnit 9 and does not exist in PHPUnit 10 — an upgrade would
	 * have turned this into a fatal. The annotation itself is the stable thing;
	 * `ReflectionMethod::getDocComment()` is PHP, not PHPUnit.
	 *
	 * @param string $group The group to look for.
	 *
	 * @return bool True when the running test is in that group.
	 */
	private function isInGroup( string $group ): bool {
		$docblock = ( new \ReflectionMethod( static::class, $this->getName( false ) ) )->getDocComment();

		if ( ! is_string( $docblock ) ) {
			return false;
		}

		return 1 === preg_match( '/^\s*\*\s*@group\s+' . preg_quote( $group, '/' ) . '\s*$/m', $docblock );
	}

	/**
	 * Watches what $fetch decides to pin, from inside each request in flight.
	 *
	 * Registered at priority 1 so it runs BEFORE the class's own callback at
	 * PHP_INT_MAX, and reads the class's own decision rather than recomputing
	 * one — a spy that worked out the expected directive itself would agree with
	 * a broken implementation as happily as with a correct one.
	 */
	protected function recordPins( MediaFetch $fetch ): void {
		add_action(
			'http_api_curl',
			function ( $handle, array $args, string $url ) use ( $fetch ): void {
				unset( $handle, $args );

				$this->pinDecisions[] = $fetch->pinDirectiveFor( $url );
			},
			1,
			3
		);
	}

	/**
	 * The handle core would hand `http_api_curl` for one transport request.
	 *
	 * A real one where ext-curl is loaded, because the class then calls the real
	 * `curl_setopt()`, which type-errors on anything but a CurlHandle — a string
	 * placeholder would turn every pinning test into a TypeError on exactly the
	 * interpreters CI runs. Setting CURLOPT_RESOLVE on a handle that is never
	 * executed does nothing but record the option, which is the point.
	 *
	 * @return mixed The handle.
	 */
	private function transportHandle() {
		if ( function_exists( 'curl_init' ) && ! defined( 'SITEHELM_CURL_IS_FAKE' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Standing in for the real curl handle core builds; no HTTP is performed.
			$this->handles[] = curl_init();

			return end( $this->handles );
		}

		return 'curl-handle-' . count( $this->requestedUrls );
	}

	/**
	 * A URL as `WP_Http::request()` rewrites it before the transport sees it.
	 *
	 * `class-wp-http.php:283-289` passes the URL through `wp_http_validate_url()`
	 * and `wp_kses_bad_protocol()`, and `wp_kses_bad_protocol_once2()` lower-cases
	 * the scheme. That single transformation is enough to break a raw string
	 * comparison, which is exactly why it is modelled.
	 *
	 * @param string $url The URL as supplied.
	 *
	 * @return string The URL as core would rewrite it.
	 */
	private function coreRewrittenUrl( string $url ): string {
		return (string) preg_replace_callback(
			'#\A[a-zA-Z][a-zA-Z0-9+.\-]*:#',
			static function ( array $matches ): string {
				return strtolower( $matches[0] );
			},
			$url,
			1
		);
	}

	/**
	 * One canned HTTP response in core's array shape.
	 *
	 * @param int    $code The status code.
	 * @param string $body The response body.
	 *
	 * @return array<string, mixed> The response.
	 */
	protected function responseWith( int $code, string $body ): array {
		return [
			'response' => [
				'code'    => $code,
				'message' => 'canned',
			],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => $body,
		];
	}

	/**
	 * A canned redirect response.
	 *
	 * @param mixed $location The Location header value, or null for none at all.
	 * @param int   $code     The redirect status.
	 *
	 * @return array<string, mixed> The response.
	 */
	protected function redirectTo( $location, int $code = 302 ): array {
		$headers = [ 'content-type' => 'text/html' ];

		if ( null !== $location ) {
			$headers['location'] = $location;
		}

		return [
			'response' => [
				'code'    => $code,
				'message' => 'canned',
			],
			'headers'  => $headers,
			'body'     => '',
		];
	}

	/**
	 * Queues the responses the transport will hand back, one per request.
	 *
	 * @param mixed ...$responses The queue, in order.
	 */
	protected function respondWith( ...$responses ): void {
		$this->responses = $responses;
	}

	/**
	 * A fetcher wired to a real MediaUrlGuard over a resolver that answers from
	 * $this->dns.
	 *
	 * The guard is real rather than doubled on purpose: "every redirect hop
	 * passes the same policy the original URL passed" is only true if the same
	 * policy object runs, and a doubled guard would assert the design instead of
	 * testing it.
	 */
	protected function fetcher(): MediaFetch {
		return new MediaFetch(
			new MediaUrlGuard(
				new class( $this->dns ) implements HostResolver {
					/**
					 * @param array<string, array<int, string>> $dns The canned zone.
					 */
					public function __construct( private array $dns ) {}

					/**
					 * @param string $host The host to resolve.
					 *
					 * @return array<int, string> The canned answer.
					 */
					public function resolve( string $host ): array {
						return $this->dns[ $host ] ?? [];
					}
				}
			)
		);
	}

	/**
	 * The validated-target shape MediaUrlGuard hands MediaFetch.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, ip: string}
	 */
	protected function validated(): array {
		return [
			'url'    => 'https://cdn.example.com/a.png',
			'scheme' => 'https',
			'host'   => 'cdn.example.com',
			'port'   => 443,
			'ip'     => '93.184.216.34',
		];
	}

	/**
	 * Runs $act, asserts it refused with $expected, and hands back the exception
	 * so a caller can read its message.
	 */
	protected function refusal( ErrorCode $expected, callable $act ): OperationException {
		try {
			$act();
		} catch ( OperationException $refusal ) {
			$this->assertSame( $expected, $refusal->errorCode );

			return $refusal;
		}

		$this->fail( 'MediaFetch accepted a response it must refuse.' );
	}

	/**
	 * Fetches the standard target and expects a refusal with $expected.
	 */
	protected function assertFetchRefused( ErrorCode $expected ): OperationException {
		return $this->refusal(
			$expected,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	/**
	 * Asserts that a refusal names none of the four blind-SSRF oracles.
	 *
	 * The four things an attacker harvests from a blind SSRF probe are a
	 * resolved IP address, a redirect target URL, a response header and a
	 * transport error string. Leaking any one of them turns a refused fetch into
	 * an internal port scanner.
	 *
	 * Digits are NOT banned outright, unlike MediaUrlGuard: a status number and
	 * the redirect limit are the only numbers this class names, and neither is
	 * an oracle for anything about this site's network.
	 */
	protected function assertRefusalLeaksNothing( OperationException $refusal ): void {
		$forbidden = [
			'93.184.216.34',
			'169.254.169.254',
			'127.0.0.1',
			'curl',
			'content-type',
			'cdn.example.com',
			'evil.example.com',
			'images.example.net',
			'/a.png',
			'https://',
			'failed to connect',
		];

		foreach ( [ $refusal->getMessage(), (string) $refusal->remediation ] as $text ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
				$text,
				'A refusal from MediaFetch carries an IP address.'
			);

			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, strtolower( $text ) );
			}
		}
	}

	/**
	 * The directives actually pinned, in order, one per hop.
	 *
	 * @return array<int, string> The directives, with declined requests dropped.
	 */
	protected function pinnedDirectives(): array {
		return array_values(
			array_filter(
				$this->pinDecisions,
				static function ( $directive ): bool {
					return null !== $directive;
				}
			)
		);
	}

	/**
	 * Skips the calling test unless `curl_setopt()` can be observed.
	 *
	 * Brain Monkey can define a function that does not exist, which is how the
	 * one test that watches the real `curl_setopt()` call works — but it cannot
	 * redefine a loaded extension's function, and listing it in patchwork.json
	 * as a redefinable internal breaks the reverse case, because Patchwork then
	 * reroutes every call site to a stub it can only build for a function that
	 * exists. The two regimes cannot both be satisfied by a static config, so
	 * the last link in the chain is asserted where it can be and skipped where
	 * it cannot. Everything the class DECIDES about the pin is covered
	 * everywhere, through pinDirectiveFor().
	 */
	protected function requireObservableCurl(): void {
		if ( ! defined( 'SITEHELM_CURL_IS_FAKE' ) ) {
			$this->markTestSkipped( 'ext-curl is loaded in this PHP, so curl_setopt() cannot be observed here.' );
		}
	}

	/**
	 * Installs a `curl_setopt` fake that records what the pin sets, where the
	 * curl extension is absent and such a fake is therefore possible.
	 *
	 * The suite's toolchain PHP has no ext-curl, so `function_exists(
	 * 'curl_setopt' )` is false by default and the pin would be dead code under
	 * test — leaving the single line that closes DNS rebinding unexercised.
	 * Defining it both flips that guard true and records the call.
	 *
	 * CURLOPT_RESOLVE comes from the same missing extension and is defined here
	 * for the same reason, at its real value of 10203 (CURLOPTTYPE_SLIST + 203),
	 * which is asserted against the extension's own value where it is loaded.
	 */
	protected function fakeCurl(): void {
		// CURLOPT_RESOLVE undefined means ext-curl is absent AND nothing has faked
		// it yet in this process, which is the one moment the fake can be built.
		// `function_exists( 'curl_setopt' )` cannot stand in for that test: Brain
		// Monkey leaves the function DEFINED for every later test in the process
		// while resetting its behaviour, so from the second test onward it is true
		// on a PHP with no curl at all, and an early return on it would leave the
		// pin calling a defined-but-unmocked function.
		if ( ! defined( 'CURLOPT_RESOLVE' ) ) {
			define( 'CURLOPT_RESOLVE', 10203 );
			define( 'SITEHELM_CURL_IS_FAKE', true );
		}

		if ( ! defined( 'SITEHELM_CURL_IS_FAKE' ) ) {
			return;
		}

		Functions\when( 'curl_setopt' )->alias(
			function ( $handle, int $option, $value ): bool {
				unset( $handle );

				$this->curlOptions[] = [ $option, $value ];

				return $this->curlSetoptSucceeds;
			}
		);
	}
}
