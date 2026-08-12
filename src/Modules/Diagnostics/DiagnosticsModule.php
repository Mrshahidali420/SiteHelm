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
	 * Registers the two system read operations.
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
						],
						'permissionMode' => [ 'type' => 'string' ],
						'modules'        => [ 'type' => 'object' ],
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
				description: 'Report which bundled integration modules are active, inactive, or version-blocked, and what each one needs.',
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
							],
						],
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
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-integrations',
					'arguments' => [],
				],
			),
			[ new IntegrationHealth(), 'handle' ]
		);
	}
}
