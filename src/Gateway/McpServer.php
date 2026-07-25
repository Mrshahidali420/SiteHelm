<?php
/**
 * Minimal MCP server core for JSON-RPC 2.0 message handling.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationError;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\CapabilityRegistry;
use Throwable;

/**
 * MCP JSON-RPC 2.0 server: routes initialize, ping, tools/list, and tools/call.
 * Transport-agnostic; the REST transport feeds decoded messages in.
 *
 * @package SiteHelm
 */
final class McpServer {

	/**
	 * MCP protocol version.
	 */
	public const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Constructs the MCP server with its dependencies.
	 *
	 * @param Dispatcher                                             $dispatcher   The operation dispatcher.
	 * @param ContextFactory                                         $contextFactory The operation context factory.
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Boot-time module map.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		private readonly Dispatcher $dispatcher,
		private readonly ContextFactory $contextFactory,
		private readonly array $moduleHealth,
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Processes one decoded JSON-RPC 2.0 message.
	 *
	 * @param array<string, mixed> $message  Decoded JSON-RPC message.
	 * @param string               $clientId Client identifier.
	 *
	 * @return array<string, mixed>|null Response array, or null for notifications.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function handle( array $message, string $clientId = 'unknown-client' ): ?array {
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? null;

		if ( ! is_string( $method ) ) {
			return $this->error( $id, -32600, 'Invalid request: missing method.' );
		}

		return match ( $method ) {
			'initialize'                => $this->result( $id, $this->initializeResult() ),
			'notifications/initialized' => null,
			'ping'                      => $this->result( $id, [] ),
			'tools/list'                => $this->result( $id, [ 'tools' => $this->toolList() ] ),
			'tools/call'                => $this->toolCall( $id, $message['params'] ?? [], $clientId ),
			default                     => $this->error( $id, -32601, "Method not found: {$method}." ),
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Builds the initialize response envelope.
	 *
	 * @return array<string, mixed> Initialize response.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function initializeResult(): array {
		return [
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
			'serverInfo'      => [
				'name'    => 'SiteHelm',
				'version' => SITEHELM_VERSION,
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Lists all available tools (dispatchers).
	 *
	 * @return list<array<string, mixed>> Tool definitions.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function toolList(): array {
		return array_map(
			static fn( string $dispatcher ): array => [
				'name'        => $dispatcher,
				'description' => sprintf(
					'SiteHelm %s dispatcher. Call without an operation to list its catalog of operations.',
					$dispatcher
				),
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'operation' => [
							'type'        => 'string',
							'description' => 'Operation identifier from this dispatcher catalog. Omit to receive the catalog.',
						],
						'arguments' => [
							'type'        => 'object',
							'description' => 'Arguments matching the operation input schema.',
						],
					],
					'additionalProperties' => false,
				],
			],
			CapabilityRegistry::DISPATCHERS
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Handles a tool call request.
	 *
	 * @param mixed                $id       Request ID.
	 * @param array<string, mixed> $params   Tool call parameters.
	 * @param string               $clientId Client identifier.
	 *
	 * @return array<string, mixed> Tool result or JSON-RPC error.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function toolCall( mixed $id, array $params, string $clientId ): array {
		$tool = $params['name'] ?? '';
		if ( ! in_array( $tool, CapabilityRegistry::DISPATCHERS, true ) ) {
			return $this->error( $id, -32602, "Unknown tool '{$tool}'." );
		}

		try {
			$context = $this->contextFactory->create( $this->moduleHealth, $clientId );
			$payload = $this->dispatcher->dispatch(
				$tool,
				is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [],
				$context
			);
			return $this->toolResult( $id, $payload, false );
		} catch ( OperationException $e ) {
			$correlation = isset( $context ) ? $context->correlationId : 'unresolved';
			return $this->toolResult( $id, OperationError::fromException( $e, $correlation )->toArray(), true );
		} catch ( Throwable $e ) {
			error_log( sprintf( 'SiteHelm unexpected failure in %s: %s', $tool, $e->getMessage() ) );
			$safe = new OperationException(
				ErrorCode::ExecutionFailed,
				'An unexpected error occurred. The details were logged on the server.',
				'Check the SiteHelm diagnostics on the site, then retry with a fresh request.'
			);
			return $this->toolResult( $id, OperationError::fromException( $safe, 'unresolved' )->toArray(), true );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Builds a tool result envelope.
	 *
	 * @param mixed                $id       Request ID.
	 * @param array<string, mixed> $payload  Payload to wrap.
	 * @param bool                 $isError  Whether this is an error result.
	 *
	 * @return array<string, mixed> Tool result.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function toolResult( mixed $id, array $payload, bool $isError ): array {
		return $this->result(
			$id,
			[
				'content' => [
					[
						'type' => 'text',
						'text' => (string) wp_json_encode( $payload ),
					],
				],
				'isError' => $isError,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Builds a success response envelope.
	 *
	 * @param mixed                $id     Request ID.
	 * @param array<string, mixed> $result Result data.
	 *
	 * @return array<string, mixed> JSON-RPC response.
	 */
	private function result( mixed $id, array $result ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * Builds an error response envelope.
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 *
	 * @return array<string, mixed> JSON-RPC error response.
	 */
	private function error( mixed $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
