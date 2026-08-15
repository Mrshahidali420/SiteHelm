<?php
/**
 * The WordPress and transport fixture every MediaImport test runs on.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use ReflectionProperty;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\HostResolver;
use SiteHelm\Modules\Media\MediaFetch;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaImport;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Modules\Media\MediaUrlGuard;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0052: add a client-approved asset to the media library from a public URL.
 *
 * WHAT THIS FILE IS ABOUT is the operation's ORCHESTRATION: that the address is
 * refused before a packet leaves the machine, that the bytes a fetch returned are
 * inspected as untrusted content, that the filename is derived rather than
 * invented, and above all that the payload is a function of the bytes THIS run
 * fetched — which is what makes a remote that changed between preview and apply
 * produce a different payload and a refused plan.
 *
 * WHAT IT IS DELIBERATELY NOT ABOUT is the address pin. MediaUrlGuard's policy
 * table and MediaFetch's `CURLOPT_RESOLVE` pinning have their own files, which
 * model core's curl transport in detail; re-modelling it here would duplicate the
 * thing those files verify rather than add to it. The transport fake below is
 * therefore a STREAMS-transport WordPress: it fires `http_request_args` and never
 * `http_api_curl`, which is a real supported configuration (spec §7 item 3) and
 * the one case MediaFetch's fail-closed pin check must NOT refuse. The guard and
 * the fetcher run for real throughout — neither is mocked — so a URL this site
 * would not fetch is refused by the same code that refuses it in production.
 *
 * Every refusal is asserted on its specific ErrorCode inside a try/catch. Every
 * refusal in this codebase is OperationException, so a bare expectException()
 * would pass on a completely different refusal than the one the test aimed at.
 */
abstract class MediaImportTestCase extends TestCase {

	use FakesWordPressHooks;

	protected MediaImport $operation;

	/**
	 * What DNS is made to say, per host. A host absent from this map resolves to
	 * nothing, which MediaUrlGuard refuses.
	 *
	 * @var array<string, array<int, string>>
	 */
	protected array $dns = [];

	/**
	 * The responses the faked transport hands back, one per request, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $responses = [];

	/**
	 * Every URL the faked transport was actually asked for, in order. An empty
	 * list is how "the fetch never happened" is asserted.
	 *
	 * @var array<int, string>
	 */
	protected array $requestedUrls = [];

	/** @var array<int, array<string, mixed>> Every sideload argument set seen. */
	protected array $sideloads = [];

	/** @var array<int, array<string, mixed>> Every wp_insert_attachment call seen. */
	protected array $inserts = [];

	/** @var array<int, string> Every temp path wp_tempnam() handed out. */
	protected array $tempFiles = [];

	/** @var array<string, mixed> Post meta written during a test. */
	protected array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->fakeHookRegistration();

		$this->dns           = [ 'cdn.example.com' => [ '93.184.216.34' ] ];
		$this->responses     = [];
		$this->requestedUrls = [];
		$this->sideloads     = [];
		$this->inserts       = [];
		$this->tempFiles     = [];
		$this->meta          = [];

		$this->stubAddressPolicy();
		$this->stubTransport();
		$this->stubContentRules();
		$this->stubWritePath();
		$this->stubStoredAttachment();

		$fields = new MediaFields();
		$urls   = new MediaUrlGuard( $this->resolver() );

		$this->operation = new MediaImport(
			$fields,
			new MediaTarget( $fields ),
			new MediaMimeGuard( $fields ),
			$urls,
			new MediaFetch( $urls )
		);
	}

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * A resolver that answers from $this->dns and nothing else.
	 *
	 * An anonymous implementation of the real interface rather than a mock, so
	 * that a change to HostResolver's signature breaks this file loudly instead
	 * of leaving a mock agreeing with a contract that no longer exists.
	 */
	private function resolver(): HostResolver {
		$dns = &$this->dns;

		return new class( $dns ) implements HostResolver {

			/** @var array<string, array<int, string>> */
			private array $dns;

			/**
			 * @param array<string, array<int, string>> $dns The answers.
			 */
			public function __construct( array &$dns ) {
				$this->dns = &$dns;
			}

			/**
			 * @param string $host The host to resolve.
			 *
			 * @return array<int, string> The addresses.
			 */
			public function resolve( string $host ): array {
				return $this->dns[ $host ] ?? [];
			}
		};
	}

	/**
	 * MediaUrlGuard's two core dependencies, faked exactly as its own tests fake
	 * them, because the guard runs for real here.
	 *
	 * `wp_http_validate_url()` is NOT an echo of its argument: core returns the
	 * URL after `wp_kses_bad_protocol()` has lower-cased its scheme, and that
	 * return value is what the guard hands back as the validated address.
	 */
	private function stubAddressPolicy(): void {
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( string $url ) {
				return (string) preg_replace_callback(
					'#\A[a-zA-Z][a-zA-Z0-9+.\-]*:#',
					static fn( array $m ): string => strtolower( $m[0] ),
					$url,
					1
				);
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
	}

	/**
	 * A streams-transport WordPress HTTP stack. See this class's docblock for why
	 * `http_api_curl` is deliberately never fired here.
	 *
	 * The registered `http_request_args` filters ARE applied, in priority order,
	 * because that is where MediaFetch forces `redirection => 0` — without which
	 * this fake's single-response-per-request model would not match what the class
	 * under test actually asks WordPress for.
	 */
	private function stubTransport(): void {
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url, array $args = [] ) {
				$this->requestedUrls[] = $url;

				// OBSERVED, not merely run. This fake hands back exactly one
				// response per call and models no redirect following of its own,
				// which is a faithful stand-in for core only while the class under
				// test has switched core's own redirect following off. Discarding
				// the filtered arguments left that claim in the prose above and
				// nowhere in the code: `redirection` could go back to a following
				// value and every test in this file would still pass.
				$filtered = $this->applyRequestArgFilters( $args, $url );

				$this->assertSame( 0, $filtered['redirection'] ?? null );

				if ( [] === $this->responses ) {
					$this->fail( 'The transport was asked for more requests than the test queued responses for.' );
				}

				return array_shift( $this->responses );
			}
		);

		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => $response['response']['code'] ?? ''
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ): string => (string) ( $response['body'] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => is_object( $thing ) && method_exists( $thing, 'get_error_message' )
		);
	}

	/**
	 * The site's own content rules, as MediaMimeGuard reads them.
	 */
	private function stubContentRules(): void {
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_slash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			]
		);
		Functions\when( 'wp_get_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'php'          => 'application/x-httpd-php',
				'htm|html'     => 'text/html',
			]
		);
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			static function ( string $file, string $filename, $mimes = null ): array {
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				foreach ( (array) $mimes as $pattern => $mime ) {
					if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
						return [
							'ext'             => $extension,
							'type'            => $mime,
							'proper_filename' => false,
						];
					}
				}

				return [
					'ext'             => false,
					'type'            => false,
					'proper_filename' => false,
				];
			}
		);
	}

	/**
	 * The write path, using a REAL temporary file so the cleanup that
	 * MediaSideload's finally performs is observable rather than notional.
	 */
	private function stubWritePath(): void {
		Functions\when( 'wp_tempnam' )->alias(
			function ( string $filename = '' ): string {
				$path              = (string) tempnam( sys_get_temp_dir(), 'sitehelm-import-' );
				$this->tempFiles[] = $path;

				return $path;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $path ): void {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ): array {
				$this->sideloads[] = [
					'file'      => $file,
					'overrides' => $overrides,
				];

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/holiday-1.png',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/holiday-1.png',
					'type' => 'image/png',
				];
			}
		);
		Functions\when( 'wp_insert_attachment' )->alias(
			function ( array $attachment, $file = false, $parent = 0, $wp_error = false ): int {
				$this->inserts[] = [
					'attachment' => $attachment,
					'file'       => $file,
					'parent'     => $parent,
				];

				return 512;
			}
		);
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [ 'width' => 1 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $key ] ?? ''
		);
	}

	/**
	 * The persisted attachment, as MediaFields::read() re-reads it during
	 * readBack(). The filename is UNIQUIFIED — `holiday-1.png` where the address
	 * offered `holiday.png` — because that routine collision is exactly what the
	 * read-back exists to disclose and the promise deliberately omits.
	 */
	private function stubStoredAttachment(): void {
		$post                    = new stdClass();
		$post->ID                = 512;
		$post->post_type         = 'attachment';
		$post->post_mime_type    = 'image/png';
		$post->post_title        = 'Holiday photo';
		$post->post_excerpt      = '';
		$post->post_content      = '';
		$post->post_parent       = 0;
		$post->post_status       = 'inherit';
		$post->post_date_gmt     = '2026-08-12 09:00:00';
		$post->post_modified_gmt = '2026-08-12 09:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/html/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'file'   => '2026/08/holiday-1.png',
				'width'  => 1,
				'height' => 1,
				'sizes'  => [],
			]
		);
		Functions\when( 'wp_basename' )->alias( static fn( string $p ): string => basename( $p ) );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-import-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	protected function pngBytes(): string {
		return (string) base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
			true
		);
	}

	/**
	 * A second, different, genuinely valid PNG — a one-pixel image of another
	 * colour. Needed by the determinism test, which must compare two payloads
	 * that both PASSED every content guard and still differ.
	 */
	protected function otherPngBytes(): string {
		return (string) base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADElEQVQI12P4z8AAAAMBAQAY3Y2wAAAAAElFTkSuQmCC',
			true
		);
	}

	/**
	 * Queues one 200 response carrying the given body.
	 */
	protected function serve( string $body ): void {
		$this->responses[] = [
			'response' => [
				'code'    => 200,
				'message' => 'canned',
			],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => $body,
		];
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> A complete import payload.
	 */
	protected function input( array $overrides = [] ): array {
		return array_merge(
			[
				'url'   => 'https://cdn.example.com/photos/holiday.png',
				'title' => 'Holiday photo',
				'alt'   => 'A beach at sunset',
			],
			$overrides
		);
	}

	/**
	 * Plans one import, serving the given bytes for it.
	 *
	 * @param string               $body  The bytes the remote serves.
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return \SiteHelm\Change\PlannedChange The plan.
	 */
	protected function planWith( string $body, array $input ) {
		$this->serve( $body );
		$context = $this->makeContext();

		return $this->operation->planChange(
			$this->operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	/**
	 * The bytes the operation is currently holding between its two phases.
	 *
	 * Read by reflection because the property is private and must stay private:
	 * exposing it for a test would be exposing the one place an unreviewed image
	 * lives, and the test that matters is precisely that it does not live there
	 * for longer than the write.
	 */
	protected function heldBytes(): ?string {
		$property = new ReflectionProperty( MediaImport::class, 'pending_bytes' );
		$property->setAccessible( true );

		$held = $property->getValue( $this->operation );

		return is_string( $held ) ? $held : null;
	}
}
