<?php
/**
 * Redirect table read handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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

/**
 * REQ-0079: the stored redirect table, read back.
 *
 * REGISTERED UNDER `content-read` RATHER THAN `system-read`, and the two writes
 * beside it under `content-write`, because the eleven dispatchers are a frozen
 * contract with no `system-write` in it. The writes therefore have exactly one
 * place they can live, and a read that sat under a different dispatcher from the
 * writes it describes would split one feature across two tools a client
 * discovers separately. A redirect is a rule about a URL path, which is close
 * enough to content for the grouping to read as intentional.
 *
 * THE WHOLE TABLE IS RETURNED, unpaged, and that is safe only because
 * RedirectStore bounds it: MAX_REDIRECTS rows of four small fields is a
 * predictable payload, where a `limit`/`offset` pair would add a page cursor to
 * a value that is stored, read and written whole anyway. The count is reported
 * beside the rows so a client can see the bound approaching rather than discover
 * it when a write refuses.
 *
 * @package SiteHelm
 */
final class RedirectList {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for redirect-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'redirect-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List every redirect this site serves, with the source path, the target, the status code, and whether the visitor\'s query string is carried over. Reports the table\'s capacity so a client can see the limit approaching.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'redirects' => [
						'type'        => 'array',
						'description' => 'Every stored redirect, ordered by source path.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'source'       => [
									'type'        => 'string',
									'description' => 'The canonical path on this site that is redirected.',
								],
								'target'       => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Where the visitor is sent, or null when the status is 410 and there is no successor.',
								],
								'status'       => [
									'type'        => 'integer',
									'description' => 'The HTTP status this redirect answers with.',
								],
								'forwardQuery' => [
									'type'        => 'boolean',
									'description' => 'Whether the visitor\'s query string is carried over to the target.',
								],
							],
							'required'             => [ 'source', 'target', 'status', 'forwardQuery' ],
							'additionalProperties' => false,
						],
					],
					'total'     => [
						'type'        => 'integer',
						'description' => 'How many redirects are stored.',
					],
					'capacity'  => [
						'type'        => 'integer',
						'description' => 'The greatest number of redirects this site will store.',
					],
				],
				'required'             => [ 'redirects', 'total', 'capacity' ],
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
				'operation' => 'redirect-list',
				'arguments' => [],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param RedirectStore $store The redirect table.
	 */
	public function __construct( private readonly RedirectStore $store ) {
	}

	/**
	 * Returns the stored redirect table.
	 *
	 * The capability is re-checked here rather than left to the dispatcher, for
	 * the reason every read in this module re-checks it: this method is public,
	 * and a future caller reaching it by another route would otherwise read a
	 * site's whole URL topology — which is a map of what has been renamed, and
	 * of what used to be where — with no gate at all.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return array<string, mixed> The table, its size, and its capacity.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, 'manage_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not read this site\'s redirects.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}

		$table = $this->store->all();

		return [
			'redirects' => array_values( $table ),
			'total'     => count( $table ),
			'capacity'  => RedirectStore::MAX_REDIRECTS,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
}
