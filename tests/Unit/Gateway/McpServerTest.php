<?php
/**
 * Tests for McpServer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * Tests McpServer.
 */
final class McpServerTest extends TestCase {

	/**
	 * The MCP server instance.
	 *
	 * @var McpServer
	 */
	private McpServer $server;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Wrapping wp_parse_url via alias.
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $c ) => parse_url( $url, $c ) );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'get_option' )->justReturn( 'safe-write' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'corr-uuid' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$registry     = new CapabilityRegistry();
		$this->server = new McpServer(
			new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator() ),
			new ContextFactory(),
			[
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			],
		);
	}

	/**
	 * Test that initialize reports server info.
	 */
	public function test_initialize_reports_server_info(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [],
			]
		);
		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertSame( 1, $response['id'] );
		$this->assertSame( McpServer::PROTOCOL_VERSION, $response['result']['protocolVersion'] );
		$this->assertSame( 'SiteHelm', $response['result']['serverInfo']['name'] );
	}

	/**
	 * Test that initialized notification returns null.
	 */
	public function test_initialized_notification_returns_null(): void {
		$this->assertNull(
			$this->server->handle(
				[
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				]
			)
		);
	}

	/**
	 * Test that tools/list exposes exactly eleven dispatchers.
	 */
	public function test_tools_list_exposes_exactly_eleven_dispatchers(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
			]
		);
		$tools    = $response['result']['tools'];
		$this->assertCount( 11, $tools );
		$names = array_column( $tools, 'name' );
		$this->assertSame( CapabilityRegistry::DISPATCHERS, $names );
		foreach ( $tools as $tool ) {
			$this->assertFalse( $tool['inputSchema']['additionalProperties'] );
		}
	}

	/**
	 * Test that tools/call without operation returns catalog content.
	 */
	public function test_tools_call_without_operation_returns_catalog_content(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'system-read',
					'arguments' => [],
				],
			]
		);
		$this->assertFalse( $response['result']['isError'] );
		$payload = json_decode( $response['result']['content'][0]['text'], true );
		$this->assertSame( 'system-read', $payload['dispatcher'] );
	}

	/**
	 * Test that unauthenticated tools/call returns error content.
	 */
	public function test_tools_call_unauthenticated_is_error_content(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'system-read',
					'arguments' => [],
				],
			]
		);
		$this->assertTrue( $response['result']['isError'] );
		$payload = json_decode( $response['result']['content'][0]['text'], true );
		$this->assertSame( 'authentication_failed', $payload['code'] );
	}

	/**
	 * Test that unknown tool returns JSON-RPC invalid params error.
	 */
	public function test_unknown_tool_is_jsonrpc_invalid_params(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'plugins-write',
					'arguments' => [],
				],
			]
		);
		$this->assertSame( -32602, $response['error']['code'] );
	}

	/**
	 * Test that unknown method returns JSON-RPC method not found error.
	 */
	public function test_unknown_method_is_jsonrpc_method_not_found(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'resources/list',
			]
		);
		$this->assertSame( -32601, $response['error']['code'] );
	}

	/**
	 * Test that ping returns empty result.
	 */
	public function test_ping_returns_empty_result(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'ping',
			]
		);
		$this->assertSame( [], $response['result'] );
	}
}
