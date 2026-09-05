<?php
/**
 * Minimal MCP server core for JSON-RPC 2.0 message handling.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
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
	 * The newest MCP protocol revision this server speaks, and the one it answers
	 * with when the client asks for nothing it can honour.
	 */
	public const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Every protocol revision this server's behaviour is actually correct under,
	 * oldest first.
	 *
	 * A LIST OF PROMISES, NOT A LIST OF DATES. A revision belongs here only when
	 * the wire shapes this server emits — the initialize result, the tool
	 * definitions, and the tool-call content blocks — are what a client of that
	 * revision expects. Nothing about `initialize`, `tools/list` or `tools/call`
	 * changed in the three named here, which is why all three are honest.
	 *
	 * The version is echoed rather than merely accepted because a client that
	 * asked for an older revision and is handed a newer one has no way to tell a
	 * disagreement from a server it should stop reading: several take the
	 * mismatch as the end of the handshake and never call `tools/list`, so every
	 * operation this site publishes silently disappears.
	 *
	 * @var string[]
	 */
	public const SUPPORTED_PROTOCOL_VERSIONS = [
		'2024-11-05',
		'2025-03-26',
		'2025-06-18',
	];

	/**
	 * What each dispatcher covers, in the words an operator would use.
	 *
	 * THE TOOL LIST IS THE ONLY PART OF THIS SERVER A CLIENT READS BEFORE IT
	 * DECIDES WHAT THE SITE CAN DO. A client scans eleven names, and if it
	 * cannot see the ability it was asked for it concludes the site does not
	 * have it - it does not go asking each dispatcher for its catalog first.
	 * Observed 2026-09-04: an agent asked to install a theme looked for a
	 * `system-write` tool, found none, and reported that the site could not
	 * install themes, while `theme-install` sat in the `content-write` catalog
	 * it already held. Naming the subjects here is what makes that scan land on
	 * the right tool.
	 *
	 * These are a map, not the inventory. What a site can actually do depends on
	 * which integrations are present, whether the add-on is licensed, and which
	 * operations the operator has switched off, so every description sends the
	 * client to the catalog for the answer. A subject named here that this site
	 * cannot serve is a catalog that will not list it, which is the honest
	 * outcome; a subject NOT named here is an ability the client never looks for.
	 *
	 * @var array<string, string>
	 */
	private const DISPATCHER_SUBJECTS = [
		'content-read'    => 'Reads posts, pages and any post type, taxonomies and terms, comments, redirects, SEO metadata and audits, forms and their entries, and shop products, orders and customers.',
		'content-write'   => 'Writes posts, pages and any post type, taxonomies and terms, comments, redirects, SEO metadata, user roles, site settings, shop products, code snippets, and plugins and themes - installing, updating, activating and switching them.',
		'media-read'      => 'Reads the media library and the image sizes this site generates.',
		'media-write'     => 'Uploads, imports, resizes and re-describes media, and attaches it to content.',
		'menu-read'       => 'Reads navigation menus, their items and their theme locations.',
		'menu-write'      => 'Creates and edits navigation menu items, reorders them, and assigns menus to theme locations.',
		'elementor-read'  => 'Reads Elementor documents, their element trees, widget availability, control schemas, global tokens and classes, templates and page settings.',
		'elementor-write' => 'Builds and edits Elementor documents: adding, updating, moving and removing elements, page settings, global colours, typography and classes, templates, popups and theme templates.',
		'fields-read'     => 'Reads Advanced Custom Fields and Meta Box field groups, definitions and values.',
		'fields-write'    => 'Writes Advanced Custom Fields and Meta Box field values.',
		'system-read'     => 'Reads this connection, the site environment, integration health, users, site settings, the audit log, installed plugins and themes, operation schemas, SEO settings and logs, and code snippets.',
	];

	/**
	 * The correlation identifier reported when a request failed before it had
	 * one. Not a placeholder for a value that exists somewhere else: a request
	 * that never built a context never generated an identifier at all.
	 */
	private const UNRESOLVED_CORRELATION_ID = 'unresolved';

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
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	public function handle( array $message, string $clientId = 'unknown-client' ): ?array {
		$id = $message['id'] ?? null;

		// Outermost containment boundary: no transport-level defect may fatal the
		// gateway, and no internal detail may reach the client.
		try {
			$method = $message['method'] ?? null;

			if ( ! is_string( $method ) ) {
				return $this->error( $id, -32600, 'Invalid request: missing method.' );
			}

			if ( 'tools/call' === $method ) {
				$params = $message['params'] ?? [];
				if ( ! is_array( $params ) ) {
					return $this->error( $id, -32602, 'Invalid params: params must be an object.' );
				}
				return $this->toolCall( $id, $params, $clientId );
			}

			return match ( $method ) {
				'initialize'                => $this->result( $id, $this->initializeResult( $message['params'] ?? null ) ),
				'notifications/initialized' => null,
				'ping'                      => $this->result( $id, [] ),
				'tools/list'                => $this->result( $id, [ 'tools' => $this->toolList() ] ),
				default                     => $this->error( $id, -32601, 'Method not found.' ),
			};
		} catch ( Throwable $e ) {
			error_log( sprintf( 'SiteHelm gateway failure: %s', $e->getMessage() ) );
			return $this->error( $id, -32603, 'Internal error. The details were logged on the server.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Builds the initialize response envelope.
	 *
	 * @param mixed $params The client's initialize parameters, whatever arrived.
	 *
	 * @return array<string, mixed> Initialize response.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function initializeResult( mixed $params ): array {
		return [
			'protocolVersion' => $this->negotiatedProtocolVersion( $params ),
			'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
			'serverInfo'      => [
				'name'    => 'SiteHelm',
				'version' => SITEHELM_VERSION,
			],
			'instructions'    => ServerInstructions::text(),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Settles which protocol revision the connection speaks.
	 *
	 * The spec's own rule, in both directions: echo the client's revision when
	 * this server supports it, and answer with this server's newest when it does
	 * not. The second half covers a client naming a revision from the future as
	 * well as one naming nothing at all, and neither is an error — the client
	 * decides whether it can live with the answer.
	 *
	 * `$params` is typed `mixed` rather than an array because it arrives from the
	 * wire: `initialize` carrying a string, a list, or no params at all is a
	 * malformed request this server answers rather than fatals on, and a
	 * `protocolVersion` that is not a string is treated exactly like an absent
	 * one.
	 *
	 * @param mixed $params The client's initialize parameters.
	 *
	 * @return string The revision to report.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function negotiatedProtocolVersion( mixed $params ): string {
		$requested = is_array( $params ) ? ( $params['protocolVersion'] ?? null ) : null;

		if ( is_string( $requested ) && in_array( $requested, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return $requested;
		}

		return self::PROTOCOL_VERSION;
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
					'%s Call without an operation to list the operations this site publishes on it.',
					self::DISPATCHER_SUBJECTS[ $dispatcher ]
				),
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'operation' => [
							'type'        => 'string',
							'description' => 'Operation identifier from this dispatcher catalog. Omit to receive the catalog.',
						],
						'planToken' => [
							'type'        => 'string',
							'description' => 'Approval token from a previous preview. Omit on a write to receive a plan instead of executing. When supplied, resend the SAME arguments the preview was generated from: the token authorizes those arguments and is checked against them, and the server does not store them.',
						],
						'arguments' => [
							'type'        => 'object',
							'description' => 'Arguments matching the operation input schema.',
						],
					],
					// EMPTY ON PURPOSE, AND NOT THE SAME THING AS ABSENT. A closed
					// schema that never says which of its members are mandatory is
					// rejected outright by the strict validators some hosts run
					// over a tool definition before they will call it, so the
					// member has to be present. It is empty because none of the
					// three IS mandatory: a call naming no operation is the catalog
					// request the dispatcher answers with its list of operations,
					// which is how a client discovers this dispatcher at all.
					'required'             => [],
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
		// This guard exists for the MESSAGE, not for safety. The membership test
		// below is strict, so a non-string name never reaches anything that
		// interpolates it — it would simply fall through as an unknown tool. What
		// it would NOT get is an accurate reason: a client that sent a number
		// would be told to call tools/list for the available dispatchers, and
		// would find its tool sitting right there in the answer.
		if ( ! is_string( $tool ) ) {
			return $this->error( $id, -32602, 'Invalid params: tool name must be a string.' );
		}
		if ( ! in_array( $tool, CapabilityRegistry::DISPATCHERS, true ) ) {
			return $this->error( $id, -32602, 'Invalid params: unknown tool. Call tools/list for the available dispatchers.' );
		}

		// Declared before the try so that the failure branches can tell "no
		// context yet" apart from a context, in a form the type system carries.
		// Construction happens inside the try, so null is a reachable state.
		$context = null;

		try {
			$context = $this->contextFactory->create( $this->moduleHealth, $clientId );
			$payload = $this->dispatcher->dispatch(
				$tool,
				is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [],
				$context
			);
			return $this->toolResult( $id, $payload, false );
		} catch ( OperationException $e ) {
			return $this->safeErrorResult( $id, $e, $this->correlationIdOrUnresolved( $context ) );
		} catch ( Throwable $e ) {
			error_log( sprintf( 'SiteHelm unexpected failure in %s: %s', $tool, $e->getMessage() ) );
			$safe = new OperationException(
				ErrorCode::ExecutionFailed,
				'An unexpected error occurred. The details were logged on the server.',
				'Open SiteHelm > Status in the WordPress admin, then retry with a fresh request.'
			);
			return $this->safeErrorResult( $id, $safe, $this->correlationIdOrUnresolved( $context ) );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the correlation identifier a failing request should report.
	 *
	 * Both failure branches share this rather than each carrying its own copy,
	 * because the two did diverge: the generic branch reported the sentinel
	 * while holding a perfectly good context, so exactly the failures whose
	 * envelope carries no message, path or trace were the ones that could not be
	 * tied to the server log entry their own remediation text points at. One
	 * resolution makes that divergence impossible to reintroduce in either
	 * direction, which a repeated ternary does not.
	 *
	 * The null case is load-bearing rather than defensive. The context is built
	 * inside the same try block both branches guard, so a failure during its
	 * construction — an authentication failure being the routine one — arrives
	 * here with nothing to read. Resolving unconditionally would turn a
	 * contained failure into an uncontained one.
	 *
	 * @param OperationContext|null $context The request context, or null when the
	 *                                       failure preceded its construction.
	 *
	 * @return string The request's correlation identifier, or the unresolved
	 *                sentinel when it never had one.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function correlationIdOrUnresolved( ?OperationContext $context ): string {
		return $context?->correlationId ?? self::UNRESOLVED_CORRELATION_ID;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Wraps envelope construction so that a failure to build the error envelope
	 * can never propagate out of the gateway. Falls back to a hardcoded safe
	 * execution_failed envelope.
	 *
	 * @param mixed              $id            Request ID.
	 * @param OperationException $exception     The failure to report.
	 * @param string             $correlationId The request correlation identifier.
	 *
	 * @return array<string, mixed> Tool result carrying a safe error envelope.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function safeErrorResult( mixed $id, OperationException $exception, string $correlationId ): array {
		try {
			$payload = OperationError::fromException( $exception, $correlationId )->toArray();
		} catch ( Throwable $e ) {
			error_log( sprintf( 'SiteHelm failed to build an error envelope: %s', $e->getMessage() ) );
			$payload = [
				'code'          => ErrorCode::ExecutionFailed->value,
				'message'       => 'An unexpected error occurred. The details were logged on the server.',
				'retryable'     => ErrorCode::ExecutionFailed->isRetryable(),
				'correlationId' => $correlationId,
			];
		}

		return $this->toolResult( $id, $payload, true );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

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
