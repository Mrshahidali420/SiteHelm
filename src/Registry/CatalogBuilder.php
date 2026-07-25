<?php

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationDefinition;

/**
 * Builds the catalog a dispatcher returns when called without an operation.
 * Blocked operations stay listed with their blocking reason.
 *
 * @package SiteHelm
 */
final class CatalogBuilder {

	public function __construct( private readonly CapabilityRegistry $registry ) {
	}

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Health map.
	 * @return array<string, mixed>
	 */
	public function build( string $dispatcher, array $moduleHealth ): array {
		return [
			'dispatcher' => $dispatcher,
			'operations' => array_map(
				fn( OperationDefinition $d ): array => $this->entry( $d, $moduleHealth ),
				$this->registry->forDispatcher( $dispatcher )
			),
		];
	}

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Health map.
	 * @return array<string, mixed>
	 */
	private function entry( OperationDefinition $definition, array $moduleHealth ): array {
		$health = $moduleHealth[ $definition->module->value ]['health'] ?? ModuleHealth::Inactive->value;

		$blocked_reason = match ( $health ) {
			ModuleHealth::Active->value         => null,
			ModuleHealth::VersionBlocked->value => 'unsupported_version',
			default                             => 'integration_unavailable',
		};

		return [
			'operation'            => $definition->id,
			'description'          => $definition->description,
			'inputSchema'          => $definition->inputSchema,
			'outputSchema'         => $definition->outputSchema,
			'schemaVersion'        => $definition->schemaVersion,
			'requiredCapabilities' => $definition->requiredCapabilities,
			'risk'                 => $definition->risk->value,
			'previewPolicy'        => $definition->previewPolicy->value,
			'snapshotPolicy'       => $definition->snapshotPolicy->value,
			'rollbackPolicy'       => $definition->rollbackPolicy->value,
			'available'            => null === $blocked_reason,
			'blockedReason'        => $blocked_reason,
			'example'              => $definition->example,
		];
	}
}
