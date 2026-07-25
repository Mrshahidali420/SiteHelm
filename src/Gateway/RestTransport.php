<?php
/**
 * MCP REST transport layer for WordPress.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Streamable-HTTP style MCP transport: one REST route accepting one
 * JSON-RPC message per POST. Authentication is WordPress Application
 * Passwords, resolved by core before the callback runs.
 *
 * @package SiteHelm
 */
final class RestTransport {

	public const ROUTE_NAMESPACE       = 'sitehelm/v1';
	public const ROUTE                 = '/mcp';
	public const MAX_BODY_BYTES        = 1_048_576;
	public const RATE_LIMIT_PER_MINUTE = 60;

	/**
	 * Initialize the REST transport with an MCP server instance.
	 *
	 * @param McpServer $server The server instance.
	 */
	public function __construct( private readonly McpServer $server ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Register the MCP REST route on rest_api_init hook.
	 */
	public function registerRoute(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleRequest' ],
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Handle an incoming REST request to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response The REST response.
	 */
	public function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$header_client_id = $request->get_header( 'mcp-client-name' );
		$client_id        = ( null !== $header_client_id && '' !== $header_client_id ) ? $header_client_id : 'unknown-client';

		if ( ! $this->withinRateLimit( get_current_user_id() ) ) {
			return new WP_REST_Response(
				$this->rpcError( -32600, 'Rate limit exceeded. Retry after a short pause.' ),
				429
			);
		}

		$processed = $this->processRawBody( (string) $request->get_body(), $client_id );

		return new WP_REST_Response( $processed['body'], $processed['status'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	/**
	 * Process a raw request body and return [status, body].
	 *
	 * @param string $rawBody The raw request body string.
	 * @param string $clientId The client identifier.
	 * @return array{status: int, body: ?array}
	 */
	public function processRawBody( string $rawBody, string $clientId ): array {
		if ( strlen( $rawBody ) > self::MAX_BODY_BYTES ) {
			return [
				'status' => 413,
				'body'   => $this->rpcError( -32600, 'Request body exceeds the 1 MiB limit.' ),
			];
		}

		$message = json_decode( $rawBody, true );
		if ( ! is_array( $message ) ) {
			return [
				'status' => 400,
				'body'   => $this->rpcError( -32700, 'Request body is not valid JSON.' ),
			];
		}

		if ( 'initialize' === ( $message['method'] ?? '' ) ) {
			$declared = $message['params']['clientInfo']['name'] ?? '';
			if ( is_string( $declared ) && '' !== $declared ) {
				$clientId = $declared;
			}
		}

		$response = $this->server->handle( $message, $clientId );

		return null === $response
			? [
				'status' => 202,
				'body'   => null,
			]
			: [
				'status' => 200,
				'body'   => $response,
			];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	/**
	 * Check if user is within rate limit; increments count if so.
	 *
	 * Fixed-window per-user rate limit backed by transients. Counting is
	 * best-effort (transients are not atomic); the limit is a guard rail,
	 * not a billing meter.
	 *
	 * @param int $userId The user ID.
	 * @return bool True if within rate limit, false if at ceiling.
	 */
	public function withinRateLimit( int $userId ): bool {
		$key   = 'sitehelm_rate_' . $userId;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 60 );
		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Create a JSON-RPC error response.
	 *
	 * @param int    $code The error code.
	 * @param string $message The error message.
	 * @return array<string, mixed>
	 */
	private function rpcError( int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
