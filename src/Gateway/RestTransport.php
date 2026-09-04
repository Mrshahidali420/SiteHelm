<?php
/**
 * MCP REST transport layer for WordPress.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Auth\BearerAuthenticator;
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
	 * Where a name declared during `initialize` is kept for the user who
	 * declared it.
	 *
	 * THIS IS DELIBERATELY NOT A TRANSIENT. An MCP client declares its name
	 * once, when the session opens, and then works for as long as the editor
	 * stays open — which is routinely a whole day, with quiet hours in the
	 * middle of it. An expiring memory therefore lapses mid-session, and
	 * everything the app does after that is filed against nobody: the activity
	 * log reads "An unnamed app changed a plugin" for changes made by the same
	 * connection that named itself perfectly well that morning. What is stored
	 * here is not a session but a fact about the account — the name of the last
	 * client that opened a session as this user — and facts about a user belong
	 * in user meta. Every declaration overwrites it, so the name can only be
	 * wrong while two different apps are working as one WordPress user at the
	 * same moment, which no expiry would have got right either.
	 */
	private const CLIENT_MEMORY_KEY = 'sitehelm_client_name';

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
	 *
	 * The permission check is deliberately still "somebody is signed in". A
	 * bearer token resolves to the administrator who approved it before this
	 * runs, and an invalid, expired or foreign one resolves to nobody at all —
	 * so a bad token arrives here as user 0 and is refused, while a request
	 * carrying no bearer header is judged exactly as it was before OAuth
	 * existed. Adding a second, OAuth-aware test here would be a second place
	 * for the two answers to disagree.
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
		$user_id = get_current_user_id();

		// A registered OAuth app names itself once, at registration, and the
		// activity log should say that name rather than a header the same
		// request could have set to anything. The header stays authoritative for
		// Application Password clients, which have no registration to consult.
		$registered = class_exists( BearerAuthenticator::class ) ? BearerAuthenticator::clientName() : '';

		if ( '' !== $registered ) {
			$declared = $this->normalizeClientId( $registered );
		} else {
			$header_client_id = $request->get_header( 'mcp-client-name' );
			$declared         = is_string( $header_client_id ) ? $this->normalizeClientId( $header_client_id ) : '';
		}

		$client_id = '' !== $declared ? $declared : self::UNKNOWN_CLIENT;

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
	 * client is recorded as `unknown-client` forever. The memory outlives the
	 * session on purpose — see CLIENT_MEMORY_KEY.
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

			update_user_meta( $userId, self::CLIENT_MEMORY_KEY, $declared );

			return self::UNKNOWN_CLIENT === $clientId ? $declared : $clientId;
		}

		if ( self::UNKNOWN_CLIENT !== $clientId ) {
			return $clientId;
		}

		$remembered = get_user_meta( $userId, self::CLIENT_MEMORY_KEY, true );

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
