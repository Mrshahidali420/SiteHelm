<?php
/**
 * Audit log read handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use stdClass;

/**
 * REQ-0009: audit log read. An agency operator reviews who changed what and
 * when for accountability reporting.
 *
 * The entries expose only what the audit table holds — identifiers, a redacted
 * summary of names and sizes, and a rollback reference. Field values were never
 * stored, so there is nothing here to redact at read time; the guarantee is
 * enforced on the way in rather than on the way out.
 *
 * @package SiteHelm
 */
final class AuditRead {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for audit-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'audit-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List recorded change events with actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'operationId'   => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Return only events for this operation identifier.',
					],
					'correlationId' => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Return only events for this request correlation identifier.',
					],
					'actorId'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Return only events performed by this WordPress user.',
					],
					'since'         => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only events recorded at or after this UTC instant.',
					],
					'until'         => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only events recorded at or before this UTC instant.',
					],
					'limit'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset'        => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Events to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'entries' => [
						'type'  => 'array',
						'items' => [ 'type' => 'object' ],
					],
					'total'   => [ 'type' => 'integer' ],
					'limit'   => [ 'type' => 'integer' ],
					'offset'  => [ 'type' => 'integer' ],
				],
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
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'audit-list',
				'arguments' => [ 'limit' => 20 ],
			],
		);
	}

	/**
	 * The page size used when the request names none.
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * Request keys forwarded to the store. Anything else is ignored, and the
	 * store maps these to column names from its own hardcoded table.
	 */
	private const FILTER_KEYS = [ 'operationId', 'correlationId', 'actorId', 'since', 'until' ];

	/**
	 * Constructs the handler.
	 *
	 * @param AuditStore $store     The audit event store.
	 * @param Installer  $installer Storage availability probe.
	 */
	public function __construct(
		private readonly AuditStore $store,
		private readonly Installer $installer,
	) {
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	/**
	 * Returns one page of audit entries, newest first.
	 *
	 * $context is unused: the required 'manage_options' capability is a
	 * site-wide check with no per-row target, so PolicyEngine::authorize()
	 * settles it entirely before Dispatcher ever calls this handler. The
	 * parameter stays in the signature to match the uniform bare-callable
	 * shape every registered read handler uses.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return array<string, mixed> Entries, total, limit, and offset.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when the
	 *                           audit table was never created.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! $this->installer->isAvailable() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The audit log is unavailable because its local storage was not created.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		$filters = [];
		foreach ( self::FILTER_KEYS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$filters[ $key ] = $input[ $key ];
			}
		}

		$limit  = max( 1, min( AuditStore::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$entries = [];
		foreach ( $this->store->query( $filters, $limit, $offset ) as $row ) {
			$entries[] = $this->entry( $row );
		}

		return [
			'entries' => $entries,
			'total'   => $this->store->count( $filters ),
			'limit'   => $limit,
			'offset'  => $offset,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Projects one stored row into a client-facing entry.
	 *
	 * @param array<string, mixed> $row The stored audit row.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( array $row ): array {
		$summary   = json_decode( (string) ( $row['summary'] ?? '' ), true );
		$reference = $row['rollback_ref'] ?? null;

		return [
			'auditRef'        => AuditRecorder::reference( (int) $row['id'] ),
			'correlationId'   => (string) $row['correlation_id'],
			'actor'           => [
				'id'    => (int) $row['actor_id'],
				'login' => (string) $row['actor_login'],
			],
			'client'          => (string) $row['client_id'],
			'operation'       => (string) $row['operation_id'],
			'target'          => (string) $row['target_key'],
			'planFingerprint' => (string) $row['plan_fingerprint'],
			'outcome'         => (string) $row['outcome'],
			'summary'         => is_array( $summary ) ? $summary : new stdClass(),
			'rollbackRef'     => is_string( $reference ) && '' !== $reference ? $reference : null,
			'timestamp'       => (int) $row['recorded_at'],
		];
	}
}
