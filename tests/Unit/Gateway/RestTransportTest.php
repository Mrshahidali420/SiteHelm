<?php
/**
 * Unit tests for RestTransport class.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * Test suite for RestTransport class.
 *
 * @package SiteHelm
 */
final class RestTransportTest extends TestCase {

	/**
	 * The transport instance under test.
	 *
	 * @var RestTransport
	 */
	private RestTransport $transport;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$registry        = new CapabilityRegistry();
		$this->transport = new RestTransport(
			new McpServer(
				new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator(), ChangeEngine::create() ),
				new ContextFactory(),
				[],
			)
		);
	}

	/**
	 * Test that a valid initialize message returns 200 with protocol version.
	 */
	public function test_valid_initialize_round_trip(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$raw = (string) json_encode(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [
					'clientInfo' => [
						'name'    => 'claude-desktop',
						'version' => '1.0',
					],
				],
			]
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( McpServer::PROTOCOL_VERSION, $response['body']['result']['protocolVersion'] );
	}

	/**
	 * Test that an oversized body is rejected with 413 and error code -32600.
	 */
	public function test_oversized_body_is_rejected_413(): void {
		$raw      = str_repeat( 'x', RestTransport::MAX_BODY_BYTES + 1 );
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );
		$this->assertSame( 413, $response['status'] );
		$this->assertSame( -32600, $response['body']['error']['code'] );
	}

	/**
	 * Test that invalid JSON is rejected with 400 and parse error -32700.
	 */
	public function test_invalid_json_is_parse_error_400(): void {
		$response = $this->transport->processRawBody( '{not json', 'unknown-client' );
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( -32700, $response['body']['error']['code'] );
	}

	/**
	 * Test that a notification (no id) returns 202 with null body.
	 */
	public function test_notification_returns_202_with_no_body(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$raw = (string) json_encode(
			[
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			]
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );
		$this->assertSame( 202, $response['status'] );
		$this->assertNull( $response['body'] );
	}

	/**
	 * Test that rate limit returns false when ceiling is reached.
	 */
	public function test_rate_limit_returns_429_when_exceeded(): void {
		Functions\when( 'get_transient' )->justReturn( RestTransport::RATE_LIMIT_PER_MINUTE );
		$this->assertFalse( $this->transport->withinRateLimit( 7 ) );
	}

	/**
	 * Test that rate limit allows requests and increments the counter.
	 */
	public function test_rate_limit_allows_and_increments_below_threshold(): void {
		$stored = [];
		Functions\when( 'get_transient' )->justReturn( 3 );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, int $value, int $ttl ) use ( &$stored ): bool {
				$stored = [ $key, $value, $ttl ];
				return true;
			}
		);
		$this->assertTrue( $this->transport->withinRateLimit( 7 ) );
		$this->assertSame( [ 'sitehelm_rate_7', 4, 60 ], $stored );
	}

	/**
	 * The endpoint itself must refuse an over-limit caller, not merely report
	 * the limit. withinRateLimit() being correct proves nothing about whether
	 * handleRequest() consults it: delete that call and the gateway has no
	 * rate limiting at all while every other test stays green.
	 */
	public function test_exceeding_the_rate_limit_refuses_the_request_without_processing_the_body(): void {
		$handled = false;
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->justReturn( RestTransport::RATE_LIMIT_PER_MINUTE );
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$handled ): bool {
				$handled = true;
				return true;
			}
		);

		$response = $this->transport->handleRequest(
			new \WP_REST_Request( $this->encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ] ) )
		);

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( RestTransport::RPC_RATE_LIMITED, $response->get_data()['error']['code'] );

		// A refused request must not have been counted, and must not have
		// reached the server: a 429 that still ran the message would rate
		// limit nothing.
		$this->assertFalse( $handled, 'A refused request incremented the counter or reached the server.' );
	}

	/**
	 * A request under the limit is served, and the identity from the header
	 * reaches the body-processing step.
	 */
	public function test_a_request_within_the_limit_is_served(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );

		$response = $this->transport->handleRequest(
			new \WP_REST_Request( $this->encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ] ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['result'] );
	}

	/**
	 * The name an MCP client declares when it opens the session must survive
	 * to the calls that follow it. Nothing is audited on `initialize`, so a
	 * declaration that is not remembered is a declaration that is thrown away.
	 */
	public function test_a_declared_client_name_is_remembered_for_the_calls_that_follow(): void {
		$stored = [];
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$stored ): bool {
				$stored = [ $key, $value, $ttl ];
				return true;
			}
		);

		$raw      = $this->encode(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [ 'clientInfo' => [ 'name' => 'claude-desktop' ] ],
			]
		);
		$response = $this->transport->processRawBody( $raw, 'unknown-client', 7 );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( [ 'sitehelm_client_7', 'claude-desktop', 3600 ], $stored );
	}

	/**
	 * The remembered name is what a later message is attributed to.
	 */
	public function test_a_later_message_is_attributed_to_the_remembered_name(): void {
		Functions\when( 'get_transient' )->justReturn( 'claude-desktop' );

		$this->assertSame(
			'claude-desktop',
			$this->transport->resolveClientId( [ 'method' => 'tools/call' ], 'unknown-client', 7 )
		);
	}

	/**
	 * The header is proprietary and explicit, so it outranks a name declared
	 * on an earlier message.
	 */
	public function test_the_header_outranks_a_remembered_name(): void {
		Functions\when( 'get_transient' )->justReturn( 'claude-desktop' );

		$this->assertSame(
			'ci-runner',
			$this->transport->resolveClientId( [ 'method' => 'tools/call' ], 'ci-runner', 7 )
		);
	}

	/**
	 * With nothing declared and nothing remembered, the fallback stands.
	 */
	public function test_an_unidentified_client_falls_back(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->assertSame(
			RestTransport::UNKNOWN_CLIENT,
			$this->transport->resolveClientId( [ 'method' => 'tools/call' ], 'unknown-client', 7 )
		);
	}

	/**
	 * A declaration that is not a usable string is ignored rather than
	 * adopted. Adopting it would hand McpServer::handle() a non-string for a
	 * string parameter and fatal the request under strict types.
	 *
	 * @dataProvider provide_unusable_declarations
	 *
	 * @param mixed $declared The declared clientInfo.name value.
	 */
	public function test_an_unusable_declaration_is_ignored( $declared ): void {
		Functions\when( 'set_transient' )->alias(
			static function (): bool {
				throw new \LogicException( 'An unusable declaration was remembered.' );
			}
		);

		$resolved = $this->transport->resolveClientId(
			[
				'method' => 'initialize',
				'params' => [ 'clientInfo' => [ 'name' => $declared ] ],
			],
			'unknown-client',
			7
		);

		$this->assertSame( RestTransport::UNKNOWN_CLIENT, $resolved );
	}

	/**
	 * Declarations that must not be adopted.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_unusable_declarations(): array {
		return [
			'integer'          => [ 42 ],
			'array'            => [ [ 'claude' ] ],
			'null'             => [ null ],
			'empty string'     => [ '' ],
			'whitespace only'  => [ "  \t " ],
			'control-char only' => [ "\n\r" ],
		];
	}

	/**
	 * A caller-supplied name is cut to the audit column's width on a character
	 * boundary, and stripped of control characters, before anything stores or
	 * displays it.
	 */
	public function test_a_hostile_client_name_is_cut_to_the_column_width_on_a_character_boundary(): void {
		$stored = '';
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$stored ): bool {
				$stored = $value;
				return true;
			}
		);

		// Multi-byte, so a byte-boundary cut would produce invalid UTF-8, and
		// longer than the column, so an uncut value would be refused by a
		// strict server and cost the whole audit row.
		$this->transport->resolveClientId(
			[
				'method' => 'initialize',
				'params' => [ 'clientInfo' => [ 'name' => "clau\nde-" . str_repeat( 'é', 400 ) ] ],
			],
			'unknown-client',
			7
		);

		$this->assertSame( 191, mb_strlen( $stored, 'UTF-8' ) );
		$this->assertSame( $stored, mb_convert_encoding( $stored, 'UTF-8', 'UTF-8' ), 'The cut produced invalid UTF-8.' );
		$this->assertStringStartsWith( 'claude-', $stored );
	}

	/**
	 * Without a resolved user there is nowhere to remember a name and nothing
	 * to recall, so the lookup is skipped rather than run against user zero —
	 * which would pool every unauthenticated caller under one identity.
	 *
	 * @dataProvider provide_absent_users
	 *
	 * @param int|null $user_id The unusable user id.
	 */
	public function test_an_unresolved_user_is_never_looked_up( ?int $user_id ): void {
		Functions\when( 'get_transient' )->alias(
			static function (): void {
				throw new \LogicException( 'An unresolved user was looked up.' );
			}
		);

		$this->assertSame(
			RestTransport::UNKNOWN_CLIENT,
			$this->transport->resolveClientId( [ 'method' => 'tools/call' ], 'unknown-client', $user_id )
		);
	}

	/**
	 * User ids that cannot own a remembered name.
	 *
	 * @return array<string, array{int|null}>
	 */
	public static function provide_absent_users(): array {
		return [
			'null'     => [ null ],
			'zero'     => [ 0 ],
			'negative' => [ -1 ],
		];
	}

	/**
	 * Encode a JSON-RPC message for the transport.
	 *
	 * @param array<string, mixed> $message The message.
	 * @return string The encoded body.
	 */
	private function encode( array $message ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return (string) json_encode( $message );
	}

	/**
	 * Test that rate-limit and oversized-body error codes are distinct.
	 */
	public function test_rate_limit_and_oversized_body_use_distinct_rpc_codes(): void {
		// Rate limit uses -32000 (implementation-defined server error).
		$this->assertSame( -32000, RestTransport::RPC_RATE_LIMITED );

		// Oversized body uses -32600 (invalid request, same as bad JSON).
		$oversized_response = $this->transport->processRawBody(
			str_repeat( 'x', RestTransport::MAX_BODY_BYTES + 1 ),
			'unknown-client'
		);
		$oversized_code     = $oversized_response['body']['error']['code'];
		$this->assertSame( -32600, $oversized_code );

		// Verify they are different.
		$this->assertNotSame( RestTransport::RPC_RATE_LIMITED, $oversized_code );
	}
}
