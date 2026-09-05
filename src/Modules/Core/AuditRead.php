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
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a route to the handler which is not the policy engine — a direct
	 * invocation, a test, a second dispatcher — still meets the gate. This log
	 * names every actor, client and target the site has been changed through,
	 * which makes it the last read on the site that should depend on one caller
	 * upstream having remembered to ask.
	 */
	private const CAPABILITY = 'manage_options';

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
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Returns one page of audit entries, newest first.
	 *
	 * THE CAPABILITY IS ASKED AGAIN HERE, before storage is even probed. It was
	 * previously left entirely to PolicyEngine::authorize() on the argument that
	 * a site-wide capability with no per-row target is settled upstream — true of
	 * the request path that exists, and an assumption about every caller that
	 * might ever exist. The check costs one call and the log it protects names
	 * every actor and target on the site, so the order is: capability, then
	 * storage. A caller who may not read the log learns nothing about it, not
	 * even whether its table was created.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return array<string, mixed> Entries, total, limit, and offset.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the caller cannot
	 *                           manage site options, or
	 *                           ErrorCode::IntegrationUnavailable when the audit
	 *                           table was never created.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reading the change log requires the capability to manage site options.',
				'Ask a site administrator to review the change log.'
			);
		}

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
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Projects one stored row into a client-facing entry.
	 *
	 * THE FAILURE NOTE IS HELD BACK. A write that fails in a way nothing
	 * predicted records what the throwable said onto its audit row, and that
	 * text is not redacted — it can carry a query, a path, or whatever a third
	 * party's exception put in it. The engine deliberately refuses to hand that
	 * to the client that made the call, so handing it over here, one read
	 * later, would undo the same guarantee by a longer route. It is written for
	 * the person administering the site, and the Activity screen in wp-admin is
	 * where they read it.
	 *
	 * @param array<string, mixed> $row The stored audit row.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( array $row ): array {
		$summary   = json_decode( (string) ( $row['summary'] ?? '' ), true );
		$reference = $row['rollback_ref'] ?? null;

		if ( is_array( $summary ) ) {
			unset( $summary['failure'] );
		}

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
