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
 * ANOTHER PLUGIN'S RULES ARE REPORTED BESIDE SITEHELM'S OWN, tagged with the
 * plugin that owns them. Two plugins can hold a rule for one path, and which one
 * serves the visitor falls out of hook priority rather than out of anything
 * either plugin promises. A client that read only this table would see a site
 * with no redirect for a path that redirects, decide the path is free, and write
 * a second answer for one address. They are reported and never edited: SiteHelm
 * does not own them, and redirect-set and redirect-delete write only to
 * SiteHelm's own table.
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
			description: 'List every redirect this site serves, with the source path, the target, the status code, and whether the visitor\'s query string is carried over. Reports the table\'s capacity so a client can see the limit approaching, and the rules another redirect plugin on this site holds, so one read answers what the site actually serves.',
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
					'others'    => [
						'type'                 => 'object',
						'description'          => 'The redirects another plugin on this site holds, which SiteHelm does not own and cannot change.',
						'properties'           => [
							'rules'     => [
								'type'        => 'array',
								'description' => 'Each rule another plugin holds, with the plugin that owns it.',
								'items'       => [
									'type'                 => 'object',
									'properties'           => [
										'owner'      => [
											'type'        => 'string',
											'description' => 'The plugin that holds this rule.',
										],
										'pattern'    => [
											'type'        => 'string',
											'description' => 'The source the owning plugin matches on, in that plugin\'s own spelling.',
										],
										'comparison' => [
											'type'        => 'string',
											'description' => 'How the owning plugin matches that source: exact, contains, start, end, or regex.',
										],
										'target'     => [
											'type'        => 'string',
											'description' => 'Where the owning plugin sends the visitor.',
										],
										'status'     => [
											'type'        => 'integer',
											'description' => 'The HTTP status the owning plugin answers with.',
										],
										'active'     => [
											'type'        => 'boolean',
											'description' => 'Whether the owning plugin has that rule switched on.',
										],
									],
									'required'             => [ 'owner', 'pattern', 'comparison', 'target', 'status', 'active' ],
									'additionalProperties' => false,
								],
							],
							'truncated' => [
								'type'        => 'boolean',
								'description' => 'Whether the other plugin holds more rules than this listing reported.',
							],
						],
						'required'             => [ 'rules', 'truncated' ],
						'additionalProperties' => false,
					],
				],
				'required'             => [ 'redirects', 'total', 'capacity', 'others' ],
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
	 * @param RedirectStore    $store   The redirect table.
	 * @param ForeignRedirects $foreign The redirects other plugins hold.
	 */
	public function __construct(
		private readonly RedirectStore $store,
		private readonly ForeignRedirects $foreign,
	) {
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
			'others'    => $this->foreign->all(),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
}
