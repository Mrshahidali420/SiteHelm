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
	public const RPC_RATE_LIMITED      = -32000;
	public const UNKNOWN_CLIENT        = 'unknown-client';

	/**
	 * The audit column that ultimately stores this value is varchar(191).
	 */
	private const MAX_CLIENT_ID_LENGTH = 191;

	/**
	 * How long a name declared during `initialize` is remembered for the user
	 * who declared it. MCP clients open a session once and then issue many
	 * `tools/call` messages over it; an hour outlives a working session
	 * without pinning a stale name to the account indefinitely.
	 */
	private const CLIENT_MEMORY_SECONDS = 3600;

	private const CLIENT_MEMORY_PREFIX = 'sitehelm_client_';

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

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Handle an incoming REST request to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response The REST response.
	 */
	public function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$user_id          = get_current_user_id();
		$header_client_id = $request->get_header( 'mcp-client-name' );
		$declared         = is_string( $header_client_id ) ? $this->normalizeClientId( $header_client_id ) : '';
		$client_id        = '' !== $declared ? $declared : self::UNKNOWN_CLIENT;

		if ( ! $this->withinRateLimit( $user_id ) ) {
			return new WP_REST_Response(
				$this->rpcError( self::RPC_RATE_LIMITED, 'Rate limit exceeded. Retry after a short pause.' ),
				429
			);
		}

		$processed = $this->processRawBody( (string) $request->get_body(), $client_id, $user_id );

		return new WP_REST_Response( $processed['body'], $processed['status'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	/**
	 * Process a raw request body and return [status, body].
	 *
	 * @param string   $rawBody  The raw request body string.
	 * @param string   $clientId The client identifier taken from the request header.
	 * @param int|null $userId   The authenticated user, or null to skip the
	 *                           remembered-name lookup entirely.
	 * @return array{status: int, body: ?array}
	 */
	public function processRawBody( string $rawBody, string $clientId, ?int $userId = null ): array {
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

		$clientId = $this->resolveClientId( $message, $clientId, $userId );

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
	 * Decide which client identity this message should be audited under.
	 *
	 * Precedence is header, then the name the client declared when it opened
	 * the session, then the fallback. The two are not interchangeable: the
	 * header is proprietary to this plugin, while `initialize.params.
	 * clientInfo.name` is what every standard MCP client sends. A declaration
	 * arrives on `initialize` alone, and nothing is audited on that message —
	 * the audit rows come from the `tools/call` messages that follow it. So
	 * the declared name is remembered against the authenticated user and read
	 * back on those later messages; without that, a standards-compliant
	 * client is recorded as `unknown-client` forever.
	 *
	 * @param array<string, mixed> $message  The decoded JSON-RPC message.
	 * @param string               $clientId The identity resolved from the header.
	 * @param int|null             $userId   The authenticated user, or null to skip the lookup.
	 * @return string The identity to audit under.
	 */
	public function resolveClientId( array $message, string $clientId, ?int $userId ): string {
		if ( null === $userId || $userId <= 0 ) {
			return $clientId;
		}

		if ( 'initialize' === ( $message['method'] ?? '' ) ) {
			$raw      = $message['params']['clientInfo']['name'] ?? '';
			$declared = is_string( $raw ) ? $this->normalizeClientId( $raw ) : '';
			if ( '' === $declared ) {
				return $clientId;
			}

			set_transient( self::CLIENT_MEMORY_PREFIX . $userId, $declared, self::CLIENT_MEMORY_SECONDS );

			return self::UNKNOWN_CLIENT === $clientId ? $declared : $clientId;
		}

		if ( self::UNKNOWN_CLIENT !== $clientId ) {
			return $clientId;
		}

		$remembered = get_transient( self::CLIENT_MEMORY_PREFIX . $userId );

		return is_string( $remembered ) && '' !== $remembered ? $remembered : $clientId;
	}

	/**
	 * Reduce a caller-supplied client name to something safe to store.
	 *
	 * @param string $raw The name as the client sent it.
	 * @return string The trimmed, control-free, column-width name.
	 */
	private function normalizeClientId( string $raw ): string {
		// Control characters are stripped rather than escaped: this value is
		// shown in the activity console, and a name carrying a newline or a
		// terminal escape would break the row it is rendered in.
		$clean = trim( (string) preg_replace( '/[[:cntrl:]]/', '', $raw ) );

		// mb_substr, not substr: the audit column is 191 characters of
		// utf8mb4, and cutting on a byte boundary splits a multi-byte name
		// mid-character. That stores invalid UTF-8, which a strict server
		// rejects outright — losing the entire audit row over a long name.
		return mb_substr( $clean, 0, self::MAX_CLIENT_ID_LENGTH, 'UTF-8' );
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
