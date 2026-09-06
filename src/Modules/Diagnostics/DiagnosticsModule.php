<?php
/**
 * Diagnostics module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogExport;

/**
 * System discovery and diagnostics. Depends only on WordPress core,
 * so it is always active when the plugin boots.
 */
final class DiagnosticsModule implements IntegrationModule {

	/**
	 * Returns the module ID.
	 */
	public function id(): ModuleId {
		return ModuleId::Diagnostics;
	}

	/**
	 * Returns the display name.
	 */
	public function displayName(): string {
		return 'System Diagnostics';
	}

	/**
	 * Returns the module dependency.
	 *
	 * @return array<string, string> Dependency info.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * Returns the module health status.
	 *
	 * @return array<string, mixed> Health info.
	 */
	public function health(): array {
		return [
			'version' => null,
			'health'  => ModuleHealth::Active->value,
		];
	}

	/**
	 * Returns cache cleanup operations.
	 *
	 * @return array<int, mixed> Cache cleanup operations.
	 */
	public function cacheCleanup(): array {
		return [];
	}

	/**
	 * Registers the four system read operations.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report WordPress, PHP, theme, SiteHelm, and integration module versions for this site.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'wordpress'      => [ 'type' => 'string' ],
						'php'            => [ 'type' => 'string' ],
						'sitehelm'       => [ 'type' => 'string' ],
						'theme'          => [
							'type'       => 'object',
							'properties' => [
								'name'    => [ 'type' => 'string' ],
								'version' => [ 'type' => 'string' ],
							],
							'required'   => [ 'name', 'version' ],
						],
						'permissionMode' => [ 'type' => 'string' ],
						'modules'        => [ 'type' => 'object' ],
					],
					'required'             => [ 'wordpress', 'php', 'sitehelm', 'theme', 'permissionMode', 'modules' ],
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
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-environment',
					'arguments' => [],
				],
			),
			[ new EnvironmentDiscovery(), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'system-integrations',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report which bundled integration modules are active, inactive, version-blocked, or active but not yet configured, and what each one needs.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'integrations' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'               => [ 'type' => 'string' ],
									'displayName'      => [ 'type' => 'string' ],
									'dependency'       => [ 'type' => 'object' ],
									'installedVersion' => [ 'type' => [ 'string', 'null' ] ],
									'health'           => [ 'type' => 'string' ],
									'explanation'      => [ 'type' => 'string' ],
								],
								'required'   => [ 'id', 'displayName', 'dependency', 'installedVersion', 'health', 'explanation' ],
							],
						],
					],
					'required'             => [ 'integrations' ],
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
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-integrations',
					'arguments' => [],
				],
			),
			[ new IntegrationHealth(), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'system-connection',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report which WordPress user this site resolved the caller as, and the transport the request arrived on.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'user'                => [ 'type' => 'object' ],
						'transport'           => [ 'type' => 'object' ],
						'applicationPassword' => [ 'type' => 'object' ],
					],
					'required'             => [ 'user', 'transport', 'applicationPassword' ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'read' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-connection',
					'arguments' => [],
				],
			),
			[ new ConnectionCheck(), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'system-operation-schema',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Return the full input and output schema of one named operation. A dispatcher catalog lists operations without their schemas; this returns the one you are about to call.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'operation' => [
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 64,
							'description' => 'The operation identifier, as listed in a dispatcher catalog.',
						],
					],
					'required'             => [ 'operation' ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'operation'            => [ 'type' => 'string' ],
						'dispatcher'           => [ 'type' => 'string' ],
						'description'          => [ 'type' => 'string' ],
						'schemaVersion'        => [ 'type' => 'integer' ],
						'requiredCapabilities' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'inputSchema'          => [ 'type' => 'object' ],
						'outputSchema'         => [ 'type' => 'object' ],
						'examples'             => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
					'required'             => [
						'operation',
						'dispatcher',
						'description',
						'schemaVersion',
						'requiredCapabilities',
						'inputSchema',
						'outputSchema',
						'examples',
					],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'read' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-operation-schema',
					'arguments' => [ 'operation' => 'content-update' ],
				],
			),
			[ new OperationSchema( $registry ), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'system-operation-find',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Find the operations that do a thing, from the words you would use to describe it. Searches every dispatcher at once, so use it before concluding this site cannot do something.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'query' => [
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 200,
							'description' => 'What you are trying to do, in plain words, as in "install a plugin from a zip file".',
						],
						'limit' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 50,
							'description' => 'How many matches to return. Defaults to 10.',
						],
					],
					'required'             => [ 'query' ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'query'   => [ 'type' => 'string' ],
						'matches' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'note'    => [ 'type' => 'string' ],
					],
					'required'             => [ 'query', 'matches', 'note' ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'read' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-operation-find',
					'arguments' => [ 'query' => 'install a plugin from a zip file' ],
				],
			),
			[ new OperationFind( $registry ), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'system-catalog-export',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Return every operation this site publishes in one document, grouped by subject, with the risk and rollback flags a choice turns on. Save it and re-export when system-connection reports a different catalog version.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'format' => [
							'type'        => 'string',
							'enum'        => [ 'markdown', 'json' ],
							'description' => 'markdown to save and grep, json to render. Defaults to markdown.',
						],
						'detail' => [
							'type'        => 'string',
							'enum'        => [ 'compact', 'full' ],
							'description' => 'compact is identifiers, descriptions and flags. full adds every input and output schema, and is roughly two and a half times the size. Defaults to compact.',
						],
						'module' => [
							'type'        => 'string',
							'enum'        => [ 'core', 'diagnostics', 'media', 'menus', 'elementor', 'acf', 'metabox', 'seo', 'forms', 'extensions', 'woocommerce', 'code' ],
							'description' => 'Export one subject group instead of all of them.',
						],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'catalogVersion' => [ 'type' => 'string' ],
						'operationCount' => [ 'type' => 'integer' ],
						'format'         => [ 'type' => 'string' ],
						'catalog'        => [ 'type' => 'string' ],
						'catalogData'    => [ 'type' => 'object' ],
					],
					'required'             => [ 'catalogVersion', 'operationCount', 'format', 'catalog', 'catalogData' ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'read' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-catalog-export',
					'arguments' => [],
				],
			),
			[ new CatalogExportOperation( new CatalogExport( $registry ) ), 'handle' ]
		);
	}
}
