<?php
/**
 * Tests for MediaFetch (REQ-0052).
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
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaUrlGuard;
use SiteHelm\Tests\TestCase;

/**
 * MediaUrlGuard decides WHETHER an address may be fetched. This class tests the
 * transport that makes sure the request actually goes to the address the guard
 * approved — and to nothing else, on any redirect hop.
 *
 * THE FAKES HERE ARE FAITHFUL ABOUT THE ARGUMENTS THEY RECEIVE, not merely about
 * the responses they return. `wp_safe_remote_get()` is faked as a miniature
 * WP_Http: it applies every registered `http_request_args` filter to the request
 * arguments, for the primary request AND for each queued redirect hop, exactly as
 * core's redirect loop does. A fake that skipped that would return the right
 * bytes while proving nothing about the hop revalidation, which is the whole
 * security property of this class.
 *
 * Every refusal is asserted on its specific ErrorCode inside a try/catch. Every
 * refusal in this codebase is OperationException, so a bare expectException()
 * would pass on a completely different refusal than the one the test aimed at.
 */
final class MediaFetchTest extends TestCase {

	/**
	 * Every add_filter/add_action call the class made, as [ hook, priority ].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $added = [];

	/**
	 * Every remove_filter/remove_action call the class made, as [ hook, priority ].
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $removed = [];

	/**
	 * The `http_request_args` callbacks currently registered.
	 *
	 * @var array<int, callable>
	 */
	private array $requestArgFilters = [];

	/**
	 * The `http_api_curl` callbacks currently registered.
	 *
	 * @var array<int, callable>
	 */
	private array $curlActions = [];

	/**
	 * Every curl option the class set, as [ option, value ], newest last.
	 *
	 * @var array<int, array{0: int, 1: mixed}>
	 */
	private array $curlOptions = [];

	/**
	 * The response the faked transport hands back.
	 *
	 * @var mixed
	 */
	private mixed $response = null;

	/**
	 * Redirect targets the faked transport walks after the primary URL, which is
	 * how a 302 is modelled: core re-applies `http_request_args` per hop.
	 *
	 * @var array<int, string>
	 */
	private array $hops = [];

	/**
	 * The request arguments the faked transport ended up with, post-filter.
	 *
	 * @var array<string, mixed>
	 */
	private array $sentArgs = [];

	/**
	 * What DNS is made to say, per host. A host absent from this map resolves to
	 * nothing, which MediaUrlGuard refuses.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $dns = [ 'cdn.example.com' => [ '93.184.216.34' ] ];

	protected function setUp(): void {
		parent::setUp();

		$this->added             = [];
		$this->removed           = [];
		$this->requestArgFilters = [];
		$this->curlActions       = [];
		$this->curlOptions       = [];
		$this->hops              = [];
		$this->sentArgs          = [];
		$this->dns               = [ 'cdn.example.com' => [ '93.184.216.34' ] ];
		$this->response          = $this->responseWith( 200, 'PNGBYTES' );

		// MediaUrlGuard's own dependencies. Faked exactly as MediaUrlGuardTest
		// fakes them, because the guard is exercised for real here rather than
		// mocked: the hop revalidation IS the guard running.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( string $url ) {
				return $url;
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
			}
		);

		// Brain Monkey does not run a hook system, so the hooks are recorded
		// here and the transport fake below replays them. Recording both the
		// hook name and the priority is what lets the removal tests assert that
		// what was added is what was taken away.
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) {
				$this->added[] = [ $hook, $priority ];

				if ( 'http_request_args' === $hook ) {
					$this->requestArgFilters[] = $callback;
				}

				return true;
			}
		);

		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) {
				unset( $callback );

				$this->removed[] = [ $hook, $priority ];

				if ( 'http_request_args' === $hook ) {
					$this->requestArgFilters = [];
				}

				return true;
			}
		);

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) {
				$this->added[] = [ $hook, $priority ];

				if ( 'http_api_curl' === $hook ) {
					$this->curlActions[] = $callback;
				}

				return true;
			}
		);

		Functions\when( 'remove_action' )->alias(
			function ( string $hook, $callback, int $priority = 10 ) {
				unset( $callback );

				$this->removed[] = [ $hook, $priority ];

				if ( 'http_api_curl' === $hook ) {
					$this->curlActions = [];
				}

				return true;
			}
		);

		// A miniature WP_Http. For the primary URL and then for every queued
		// redirect hop it applies the registered http_request_args filters and
		// then fires http_api_curl, in that order, which is what core's curl
		// transport does per request and per redirect. Firing the action is the
		// part that makes the pin observable at all: a fake that only returned
		// bytes would leave the entire DNS-rebinding defence unexercised.
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url, array $args = [] ) {
				$handle = 'curl-handle';

				foreach ( array_merge( [ $url ], $this->hops ) as $hop ) {
					foreach ( $this->requestArgFilters as $filter ) {
						$args = $filter( $args, $hop );
					}

					$this->sentArgs = $args;

					foreach ( $this->curlActions as $action ) {
						$action( $handle, $args, $hop );
					}
				}

				return $this->response;
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

		// Installed for every test but one, and installed HERE rather than in
		// the tests that read it, because Brain Monkey leaks a fake's
		// DEFINITION to every later test in the process while resetting its
		// BEHAVIOUR. Installed per-test, the first test to call fakeCurl() would
		// leave `function_exists( 'curl_setopt' )` true for every test after it
		// with no expectation behind it, and each of those would die on a
		// missing expectation rather than on anything it was testing.
		//
		// The one exception needs the function genuinely absent and says so by
		// name; it runs in its own process, so the exclusion cannot leak either.
		if ( 'test_the_fetch_proceeds_when_curl_is_unavailable' !== $this->getName() ) {
			$this->fakeCurl();
		}
	}

	/**
	 * One canned HTTP response in core's array shape.
	 *
	 * @param int    $code The status code.
	 * @param string $body The response body.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function responseWith( int $code, string $body ): array {
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
	 * A fetcher wired to a real MediaUrlGuard over a resolver that answers from
	 * $this->dns.
	 *
	 * The guard is real rather than doubled on purpose: "every redirect hop
	 * passes the same policy the original URL passed" is only true if the same
	 * policy object runs, and a doubled guard would assert the design instead of
	 * testing it.
	 */
	private function fetcher(): MediaFetch {
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
	private function validated(): array {
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
	private function refusal( ErrorCode $expected, callable $act ): OperationException {
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
	private function assertFetchRefused( ErrorCode $expected ): OperationException {
		return $this->refusal(
			$expected,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	/**
	 * Every hook this class added, minus every hook it removed.
	 *
	 * @return array<int, array{0: string, 1: int}> The leaked registrations.
	 */
	private function leakedHooks(): array {
		$leaked = $this->added;

		foreach ( $this->removed as $gone ) {
			$at = array_search( $gone, $leaked, true );

			if ( false !== $at ) {
				unset( $leaked[ $at ] );
			}
		}

		return array_values( $leaked );
	}

	/**
	 * Installs a `curl_setopt` fake that records what the pin sets.
	 *
	 * The curl extension is NOT loaded in this suite's PHP, so
	 * `function_exists( 'curl_setopt' )` is false by default and the pin would
	 * be dead code under test — leaving the single line that closes DNS
	 * rebinding unexercised. Brain Monkey can define a function that does not
	 * exist, which both flips the guard true and records the call, so the pin is
	 * proven rather than declared.
	 *
	 * CURLOPT_RESOLVE comes from the same missing extension and is defined here
	 * for the same reason, at its real value.
	 */
	private function fakeCurl(): void {
		if ( ! defined( 'CURLOPT_RESOLVE' ) ) {
			define( 'CURLOPT_RESOLVE', 203 );
		}

		Functions\when( 'curl_setopt' )->alias(
			function ( $handle, int $option, $value ): bool {
				unset( $handle );

				$this->curlOptions[] = [ $option, $value ];

				return true;
			}
		);
	}

	/**
	 * Declared FIRST and process-isolated on purpose. It is the only test in
	 * this file that needs `function_exists( 'curl_setopt' )` to be FALSE, and
	 * Brain Monkey leaks a fake's definition — though not its behaviour — to
	 * every later test in the same process, so any ordering that let fakeCurl()
	 * run first would silently invert what this test asserts.
	 *
	 * What it pins: a site on WordPress's streams transport has no
	 * CURLOPT_RESOLVE. Delete the function_exists guard and the fetch dies on an
	 * undefined function instead of proceeding under the other guards, which is
	 * the accepted cost in spec §7 item 3 turning into an outage.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_fetch_proceeds_when_curl_is_unavailable(): void {
		$this->assertFalse( function_exists( 'curl_setopt' ), 'This test is only meaningful without ext-curl.' );

		$this->assertSame( 'PNGBYTES', $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	public function test_the_fetch_pins_the_curl_handle_to_the_validated_address(): void {
		// THE DNS-rebinding defence, end to end: the action fires mid-request
		// and the directive handed to curl must name the address the guard
		// approved, so the address that was validated is the address dialled.
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame(
			[ [ CURLOPT_RESOLVE, [ 'cdn.example.com:443:93.184.216.34' ] ] ],
			$this->curlOptions
		);
	}

	public function test_a_public_redirect_hop_re_pins_the_curl_handle(): void {
		// A hop that is validated but not RE-PINNED is the rebinding hole
		// reopening one level down: the handle would still carry the first
		// hop's directive while dialling the second hop's host, and curl would
		// fall back to a resolver for the name it has no directive for.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];
		$this->hops                      = [ 'https://images.example.net/a.png' ];

		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertSame(
			[
				[ CURLOPT_RESOLVE, [ 'cdn.example.com:443:93.184.216.34' ] ],
				[ CURLOPT_RESOLVE, [ 'images.example.net:443:93.184.216.35' ] ],
			],
			$this->curlOptions
		);
	}

	public function test_a_successful_fetch_returns_the_body_bytes(): void {
		$this->response = $this->responseWith( 200, 'PNGBYTES' );

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
		$this->response = new class() {
			public function get_error_message(): string {
				return 'cURL error 7: Failed to connect to 127.0.0.1 port 8080';
			}
		};

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

	public function test_a_404_is_refused_naming_only_the_status(): void {
		$this->response = $this->responseWith( 404, 'Not Found' );

		$refusal = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->assertStringContainsString( '404', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'content-type', strtolower( $refusal->getMessage() ) );
		$this->assertStringNotContainsString( '/a.png', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'cdn.example.com', $refusal->getMessage() );
	}

	public function test_a_500_is_refused(): void {
		$this->response = $this->responseWith( 500, 'boom' );

		$this->assertStringContainsString(
			'500',
			$this->assertFetchRefused( ErrorCode::ExecutionFailed )->getMessage()
		);
	}

	public function test_a_204_is_refused(): void {
		// A 204 is not an error to a naive `>= 400` check, and it carries no
		// body. Refusing anything other than 200 is what makes it fail here
		// rather than three lines later with a confusing empty-body diagnosis.
		$this->response = $this->responseWith( 204, '' );

		$this->assertStringContainsString(
			'204',
			$this->assertFetchRefused( ErrorCode::ExecutionFailed )->getMessage()
		);
	}

	public function test_an_empty_body_is_refused(): void {
		// A 200 with nothing in it. MediaMimeGuard would refuse the empty string
		// later, but the diagnosis belongs here where the remote server's
		// behaviour is what went wrong.
		$this->response = $this->responseWith( 200, '' );

		$this->assertFetchRefused( ErrorCode::ExecutionFailed );
	}

	public function test_a_body_over_the_size_cap_is_refused(): void {
		// `limit_response_size` is set to the cap PLUS ONE precisely so that an
		// over-cap response arrives one byte over and is recognisable here,
		// rather than arriving truncated to exactly the cap and being accepted
		// as a valid, silently corrupted file.
		$this->response = $this->responseWith( 200, str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES + 1 ) );

		$this->assertFetchRefused( ErrorCode::InvalidInput );
	}

	public function test_a_body_at_the_size_cap_is_allowed(): void {
		// The boundary in the allowing direction. Without it, an off-by-one that
		// refuses everything at the cap would pass every other test in this file.
		$bytes = str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES );

		$this->response = $this->responseWith( 200, $bytes );

		$this->assertSame( $bytes, $this->fetcher()->fetch( $this->validated(), 'corr-1' ) );
	}

	public function test_both_hooks_are_registered_for_the_fetch(): void {
		// Named rather than counted. The removal tests below compare added
		// against removed, so they stay green if a hook is never added at all —
		// this is what pins each registration by name.
		$this->fetcher()->fetch( $this->validated(), 'corr-1' );

		$this->assertContains( [ 'http_request_args', 10 ], $this->added );
		$this->assertContains( [ 'http_api_curl', 10 ], $this->added );
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
		$this->response = $this->responseWith( 500, 'boom' );

		$this->refusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertNotSame( [], $this->added, 'The class registered no hooks at all.' );
		$this->assertSame( [], $this->leakedHooks() );
	}

	public function test_the_hooks_are_removed_when_a_redirect_hop_is_refused(): void {
		// The second throwing path, and the one that throws from INSIDE the
		// filter callback rather than from the fetch body. A `finally` catches
		// both; a removal placed after the checks catches neither.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];
		$this->hops                    = [ 'https://evil.example.com/a.png' ];

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);

		$this->assertSame( [], $this->leakedHooks() );
	}

	public function test_the_request_arguments_force_the_safe_settings(): void {
		$args = $this->fetcher()->filterRequestArgs( [], 'https://cdn.example.com/a.png' );

		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 2, $args['redirection'] );
		$this->assertSame( MediaMimeGuard::MAX_DECODED_BYTES + 1, $args['limit_response_size'] );
		$this->assertIsInt( $args['timeout'] );
		$this->assertGreaterThan( 0, $args['timeout'] );
		$this->assertStringContainsString( 'SiteHelm', (string) $args['user-agent'] );
		$this->assertFalse( $args['stream'] );
	}

	public function test_a_hostile_filter_cannot_relax_a_forced_argument(): void {
		// Another plugin's http_request_args filter runs alongside this one, and
		// whichever set of values comes SECOND in the array_merge wins. The
		// forced values come second deliberately: a safety setting a third party
		// can switch off is not a safety setting.
		$args = $this->fetcher()->filterRequestArgs(
			[
				'reject_unsafe_urls'  => false,
				'redirection'         => 20,
				'limit_response_size' => PHP_INT_MAX,
				'timeout'             => 600,
				'stream'              => true,
			],
			'https://cdn.example.com/a.png'
		);

		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 2, $args['redirection'] );
		$this->assertSame( MediaMimeGuard::MAX_DECODED_BYTES + 1, $args['limit_response_size'] );
		$this->assertLessThan( 600, $args['timeout'] );
		$this->assertFalse( $args['stream'] );
	}

	public function test_an_unrelated_request_argument_survives(): void {
		// The merge must FORCE the safety arguments, not replace the whole
		// argument set: a cookie or header another plugin added has nothing to
		// do with this policy and must still be there.
		$args = $this->fetcher()->filterRequestArgs(
			[ 'headers' => [ 'X-Trace' => 'abc' ] ],
			'https://cdn.example.com/a.png'
		);

		$this->assertSame( [ 'X-Trace' => 'abc' ], $args['headers'] );
	}

	public function test_a_redirect_hop_to_a_private_address_is_refused(): void {
		// The DNS-rebinding-via-redirect case. Without the hop revalidation the
		// original URL passes every check and a 302 walks the site straight into
		// loopback.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->filterRequestArgs( [], 'https://evil.example.com/a.png' )
		);
	}

	public function test_a_redirect_hop_to_the_metadata_endpoint_is_refused(): void {
		$this->dns['meta.example.com'] = [ '169.254.169.254' ];

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->filterRequestArgs( [], 'https://meta.example.com/latest/meta-data/' )
		);
	}

	public function test_a_redirect_hop_to_a_public_address_is_allowed(): void {
		// Redirects are capped at two rather than disabled because the CDN
		// redirects that make imports work in practice are ordinary. This is the
		// test that fails if hop revalidation is tightened into hop refusal.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$args = $this->fetcher()->filterRequestArgs( [], 'https://images.example.net/a.png' );

		$this->assertTrue( $args['reject_unsafe_urls'] );
	}

	public function test_a_redirect_hop_to_a_private_address_fails_the_whole_fetch(): void {
		// End to end through the faked transport rather than by calling the
		// filter directly: the outer fetch must report a refusal, not a success
		// with whatever bytes the hop returned.
		$this->dns['evil.example.com'] = [ '127.0.0.1' ];
		$this->hops                    = [ 'https://evil.example.com/a.png' ];

		$this->refusal(
			ErrorCode::InvalidInput,
			fn() => $this->fetcher()->fetch( $this->validated(), 'corr-1' )
		);
	}

	public function test_a_public_redirect_hop_re_pins_the_connection(): void {
		// A hop that is validated but not RE-PINNED is the DNS-rebinding hole
		// reopening one level down: the connection would still be pinned to the
		// first hop's address while dialling the second hop's host. The pin
		// directive is read back after the hop to prove it moved.
		$this->dns['images.example.net'] = [ '93.184.216.35' ];

		$fetch = $this->fetcher();

		$fetch->filterRequestArgs( [], 'https://images.example.net/a.png' );

		$this->assertSame(
			'images.example.net:443:93.184.216.35',
			$fetch->resolveDirective( $fetch->pinnedTarget() ?? [] )
		);
	}

	public function test_the_pin_is_cleared_after_a_fetch(): void {
		// The pin is torn down alongside the hooks. Leaving it set would arm the
		// next accidental invocation of the action callback with a stale target.
		$fetch = $this->fetcher();

		$fetch->fetch( $this->validated(), 'corr-1' );

		$this->assertNull( $fetch->pinnedTarget() );
	}

	public function test_the_curl_pin_is_a_no_op_when_nothing_is_pinned(): void {
		// The action can only fire while a fetch is in flight, but WordPress
		// hooks are global and this callback is public. With no pin set it must
		// touch nothing rather than dereference a null target.
		$fetch  = $this->fetcher();
		$handle = 'curl-handle';

		$fetch->pinCurlHandle( $handle, [], 'https://cdn.example.com/a.png' );

		$this->assertSame( [], $this->curlOptions );
	}

	/**
	 * The envelope-discipline invariant, read from every refusal this class can
	 * produce rather than sampled.
	 *
	 * The four things an attacker harvests from a blind SSRF probe are a
	 * resolved IP address, a redirect target URL, a response header, and a
	 * transport error string. Leaking any one of them turns a refused fetch into
	 * an internal port scanner, so all four are swept here.
	 *
	 * Digits are NOT banned outright, unlike MediaUrlGuard: a status number is
	 * the one number a caller genuinely needs and cannot use as an oracle for
	 * anything about this site's network.
	 */
	public function test_no_refusal_message_contains_an_ip_address(): void {
		$refusals = [];

		$this->response = new class() {
			public function get_error_message(): string {
				return 'cURL error 7: Failed to connect to 169.254.169.254 port 80';
			}
		};

		$refusals[] = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		foreach ( [ 204, 302, 404, 500 ] as $code ) {
			$this->response = $this->responseWith( $code, '' );

			$refusals[] = $this->assertFetchRefused( ErrorCode::ExecutionFailed );
		}

		$this->response = $this->responseWith( 200, '' );
		$refusals[]     = $this->assertFetchRefused( ErrorCode::ExecutionFailed );

		$this->response = $this->responseWith( 200, str_repeat( 'a', MediaMimeGuard::MAX_DECODED_BYTES + 1 ) );
		$refusals[]     = $this->assertFetchRefused( ErrorCode::InvalidInput );

		$forbidden = [
			'93.184.216.34',
			'169.254.169.254',
			'127.0.0.1',
			'curl',
			'content-type',
			'location',
			'cdn.example.com',
			'/a.png',
			'https://',
			'failed to connect',
		];

		foreach ( $refusals as $refusal ) {
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
	}
}
