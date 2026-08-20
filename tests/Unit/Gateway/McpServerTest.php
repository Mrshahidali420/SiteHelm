<?php
/**
 * Tests for McpServer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use RuntimeException;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
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

		$this->server = $this->serverRunning( static fn(): array => [ 'wordpress' => '6.8.1' ] );
	}

	/**
	 * Builds a server whose single registered operation runs the given handler.
	 *
	 * The handler is a parameter so a test can choose what the operation does
	 * once the gateway has already built its context — including raising a
	 * failure the gateway did not anticipate.
	 *
	 * @param callable $handler The handler backing `system-environment`.
	 *
	 * @return McpServer The configured server.
	 */
	private function serverRunning( callable $handler ): McpServer {
		$registry = new CapabilityRegistry();
		$registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report environment versions.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'wordpress' => [ 'type' => 'string' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'system-environment',
					'arguments' => [],
				],
			),
			$handler
		);

		return new McpServer(
			new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator(), ChangeEngine::create() ),
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
	 * Sends one tools/call for the system-read dispatcher.
	 *
	 * @param array<string, mixed> $arguments Dispatcher arguments.
	 *
	 * @return array<string, mixed> The decoded tool payload.
	 */
	private function callSystemRead( array $arguments ): array {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 42,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'system-read',
					'arguments' => $arguments,
				],
			]
		);
		// Asserted before indexing, deliberately. A failure that escapes toolCall()
		// into handle()'s outermost handler returns an envelope with no 'result'
		// key at all, and indexing it blind would report that as an "Undefined
		// array key" error rather than as a failed assertion — which is how a
		// regression here would arrive looking like broken test infrastructure.
		$this->assertArrayHasKey( 'result', $response, 'Expected a tool result, not an escaped failure.' );
		$this->assertTrue( $response['result']['isError'], 'Expected an isError tool result.' );

		return (array) json_decode( $response['result']['content'][0]['text'], true );
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
		$text = $response['result']['content'][0]['text'];
		// I3: the wire text must be valid JSON Schema, so empty object members
		// serialize as {} rather than []. REQ-0075 moved the schemas themselves
		// out of the catalog, so the example's arguments are what remains here.
		$this->assertStringContainsString( '"arguments":{}', $text );
		$this->assertStringNotContainsString( '"arguments":[]', $text );
		$payload = json_decode( $text, true );
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
		// Pre-context failures have no correlation identifier to echo.
		$this->assertSame( 'unresolved', $payload['correlationId'] );
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
	 * A request with no usable method is malformed, not merely unrecognised.
	 *
	 * JSON-RPC separates the two on purpose. -32601 says "I understood your
	 * request and do not offer that method", which invites the client to try a
	 * different name. -32600 says "this is not a well-formed request", which is
	 * the truth when `method` is absent or is not a string, and is the only one
	 * of the two that tells the client to look at how it is building the
	 * envelope rather than at what it is asking for.
	 *
	 * Nothing covered this before: the guard could be deleted and every one of
	 * these payloads simply fell through to the method lookup and came back
	 * -32601, which no test contradicted.
	 *
	 * @dataProvider unusable_method_provider
	 *
	 * @param array<string, mixed> $message A request whose method cannot be read.
	 */
	public function test_a_request_without_a_usable_method_is_jsonrpc_invalid_request( array $message ): void {
		$response = $this->server->handle( $message );

		$this->assertSame( -32600, $response['error']['code'] );
		$this->assertStringContainsString( 'Invalid request', $response['error']['message'] );
	}

	/**
	 * Requests whose method cannot be read as a string.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function unusable_method_provider(): array {
		return [
			'absent' => [ [ 'jsonrpc' => '2.0', 'id' => 61 ] ],
			'null'   => [ [ 'jsonrpc' => '2.0', 'id' => 62, 'method' => null ] ],
			'int'    => [ [ 'jsonrpc' => '2.0', 'id' => 63, 'method' => 7 ] ],
			'array'  => [ [ 'jsonrpc' => '2.0', 'id' => 64, 'method' => [ 'tools/list' ] ] ],
		];
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

	/**
	 * C1: an operation string that trips the envelope leak guard must still
	 * produce a normal invalid_input envelope. No exception may escape handle().
	 *
	 * @dataProvider leaky_operation_provider
	 *
	 * @param string $operation Attacker-supplied operation identifier.
	 */
	public function test_leaky_operation_string_returns_invalid_input_envelope( string $operation ): void {
		$payload = $this->callSystemRead( [ 'operation' => $operation ] );

		$this->assertSame( 'invalid_input', $payload['code'] );
		$this->assertStringNotContainsString( $operation, $payload['message'] );
	}

	/**
	 * Operation strings that match the OperationError leak pattern.
	 *
	 * @return array<string, array{string}> Provider rows.
	 */
	public function leaky_operation_provider(): array {
		return [
			'password'   => [ 'password' ],
			'secret'     => [ 'my-secret-op' ],
			'api key'    => [ 'get-api-key' ],
			'wp-content' => [ 'wp-content' ],
			'backslash'  => [ 'a\\b' ],
		];
	}

	/**
	 * C1: an argument key that trips the envelope leak guard must still produce
	 * a normal invalid_input envelope with the offending text redacted.
	 *
	 * @dataProvider leaky_argument_key_provider
	 *
	 * @param string $key Attacker-supplied argument key.
	 */
	public function test_leaky_argument_key_returns_invalid_input_envelope( string $key ): void {
		$payload = $this->callSystemRead(
			[
				'operation' => 'system-environment',
				'arguments' => [ $key => 1 ],
			]
		);

		$this->assertSame( 'invalid_input', $payload['code'] );
		$this->assertStringNotContainsString( $key, $payload['message'] );
		$this->assertStringContainsString( '[redacted]', $payload['message'] );
	}

	/**
	 * Argument keys that match the OperationError leak pattern.
	 *
	 * @return array<string, array{string}> Provider rows.
	 */
	public function leaky_argument_key_provider(): array {
		return [
			'password'      => [ 'password' ],
			'authorization' => [ 'authorization' ],
		];
	}

	/**
	 * C2: non-array params must be rejected as JSON-RPC invalid params.
	 *
	 * @dataProvider non_array_params_provider
	 *
	 * @param mixed $params Malformed params member.
	 */
	public function test_non_array_params_is_jsonrpc_invalid_params( mixed $params ): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 8,
				'method'  => 'tools/call',
				'params'  => $params,
			]
		);
		$this->assertSame( -32602, $response['error']['code'] );
	}

	/**
	 * Malformed params members.
	 *
	 * @return array<string, array{mixed}> Provider rows.
	 */
	public function non_array_params_provider(): array {
		return [
			'string' => [ 'hello' ],
			'int'    => [ 123 ],
		];
	}

	/**
	 * C2: a non-string tool name must be told what is actually wrong with it.
	 *
	 * THE MESSAGE IS THE ASSERTION HERE, and the code alone is not, because the
	 * membership test underneath this guard returns the same -32602 for a name
	 * it does not recognise. An earlier version of this test checked only the
	 * code, so deleting the guard entirely changed nothing it could see — a
	 * deletion sweep found it surviving and this is the repair.
	 *
	 * The difference matters to whoever is holding the failing client. Falling
	 * through tells them the tool is unknown and to call tools/list, which they
	 * then do, and find their tool listed. The reason they never get is that
	 * they sent it as a number.
	 *
	 * @dataProvider non_string_tool_name_provider
	 *
	 * @param mixed $name Malformed tool name.
	 */
	public function test_non_string_tool_name_is_jsonrpc_invalid_params( mixed $name ): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => $name,
					'arguments' => [],
				],
			]
		);

		$this->assertSame( -32602, $response['error']['code'] );
		$this->assertStringContainsString(
			'tool name must be a string',
			$response['error']['message'],
			'A malformed name was reported as an unknown tool, which sends the client looking in the wrong place.'
		);
	}

	/**
	 * Malformed tool names.
	 *
	 * @return array<string, array{mixed}> Provider rows.
	 */
	public function non_string_tool_name_provider(): array {
		return [
			'array' => [ [ 'x' ] ],
			'int'   => [ 123 ],
		];
	}

	/**
	 * The client's tool name is untrusted text and must never be echoed. The
	 * sibling method path already stopped echoing; this aligns the two.
	 */
	public function test_unknown_tool_message_does_not_echo_the_client_value(): void {
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
		$this->assertStringNotContainsString( 'plugins-write', $response['error']['message'] );
	}

	/**
	 * An OperationException raised once the context exists reports that
	 * request's correlation id.
	 *
	 * This branch was already correct, and nothing asserted it: reverting it to
	 * the sentinel left all 597 tests green. Both branches now resolve the
	 * identifier through one shared method, so leaving this half unasserted
	 * would mean half of that method's contract is pinned by nothing — the same
	 * shape of gap that let the generic branch drift in the first place.
	 */
	public function test_operation_failure_after_the_context_exists_reports_its_correlation_id(): void {
		$payload = $this->callSystemRead( [ 'operation' => 'no-such-operation' ] );

		$this->assertSame( 'invalid_input', $payload['code'] );
		$this->assertSame( 'corr-uuid', $payload['correlationId'] );
	}

	/**
	 * A failure that is NOT an OperationException must report the correlation id
	 * of the request it failed, exactly as the OperationException branch does.
	 *
	 * This envelope carries no message, no path and no trace by design, so the
	 * correlation id is the only handle it offers — and its own remediation
	 * tells the operator to go read the server-side log. Reporting the
	 * 'unresolved' sentinel while a context existed severed that link for
	 * precisely the failures that have nothing else to go on. It matters more
	 * than a missing field: `execution_failed` is declared retryable, so the
	 * client is told to try again, and this product's primary client is a
	 * language model that will.
	 */
	public function test_unexpected_failure_after_the_context_exists_reports_its_correlation_id(): void {
		$server = $this->serverRunning(
			static fn(): array => throw new RuntimeException( 'Boom in C:/wp-content/db-password.php' )
		);

		$response = $server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 10,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'system-read',
					'arguments' => [ 'operation' => 'system-environment' ],
				],
			]
		);

		$this->assertArrayHasKey(
			'result',
			$response,
			'The failure must be contained as a tool result rather than escaping to the outermost handler.'
		);
		$this->assertTrue( $response['result']['isError'] );

		$text    = $response['result']['content'][0]['text'];
		$payload = json_decode( $text, true );

		$this->assertSame( 'execution_failed', $payload['code'] );
		// The id is the one the context generated, and is that opaque value
		// alone: the assertion is identity against wp_generate_uuid4()'s return,
		// so anything appended or substituted fails here.
		$this->assertSame( 'corr-uuid', $payload['correlationId'] );
		// Restoring the link must not widen what this branch lets through. The
		// raw failure names a filesystem path; none of it may reach the wire.
		$this->assertStringNotContainsString( 'db-password', $text );
		$this->assertStringNotContainsString( 'wp-content', $text );
		$this->assertStringNotContainsString( 'Boom', $text );
	}

	/**
	 * The same branch must survive a failure raised DURING context construction,
	 * where there genuinely is no correlation id to report.
	 *
	 * The context is built inside the same try block, so this state is reachable
	 * rather than defensive: here the correlation id's own generator is what
	 * fails. Resolving the id unconditionally would read a property off nothing
	 * and turn a contained failure into an uncontained one — strictly worse than
	 * the sentinel this asserts.
	 */
	public function test_unexpected_failure_before_the_context_exists_reports_unresolved(): void {
		Functions\when( 'wp_generate_uuid4' )->alias(
			static fn(): string => throw new RuntimeException( 'No randomness source available.' )
		);

		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 11,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'system-read',
					'arguments' => [ 'operation' => 'system-environment' ],
				],
			]
		);

		$this->assertArrayHasKey(
			'result',
			$response,
			'A failure with no context must still be contained as a tool result, not escape to the outermost handler.'
		);
		$this->assertTrue( $response['result']['isError'] );

		$payload = json_decode( $response['result']['content'][0]['text'], true );

		$this->assertSame( 'execution_failed', $payload['code'] );
		$this->assertSame( 'unresolved', $payload['correlationId'] );
	}

	public function test_every_dispatcher_tool_advertises_the_reserved_plan_token(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'tools/list',
			]
		);

		foreach ( $response['result']['tools'] as $tool ) {
			$this->assertArrayHasKey( 'planToken', $tool['inputSchema']['properties'] );
			$this->assertFalse( $tool['inputSchema']['additionalProperties'] );
		}
	}
}
