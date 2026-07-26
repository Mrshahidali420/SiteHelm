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
