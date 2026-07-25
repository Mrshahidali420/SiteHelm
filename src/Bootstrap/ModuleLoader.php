<?php
/**
 * Module loader for isolated module initialization.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Registry\CapabilityRegistry;
use Throwable;

/**
 * Loads integration modules with hard isolation: one failing module never
 * disables the gateway, the registry, or any other module.
 *
 * @package SiteHelm
 */
final class ModuleLoader {

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- doc comment specifies list type for clarity while runtime accepts array.
	/**
	 * Loads every module in isolation, recording health and handling errors.
	 *
	 * @param list<IntegrationModule> $modules Modules to load.
	 * @param CapabilityRegistry      $registry The capability registry.
	 * @return array<string, array{version: ?string, health: string}> Health map.
	 */
	public function load( array $modules, CapabilityRegistry $registry ): array {
	// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		$health_map = [];

		foreach ( $modules as $module ) {
			$module_id = $module->id()->value;
			try {
				$health_map[ $module_id ] = $module->health();
				// Register definitions even when inactive/version-blocked, so
				// catalogs can list the operations with their blocking reason.
				$module->register( $registry );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- contained module failure, logged server-side.
				error_log( sprintf( 'SiteHelm module %s failed to load: %s', $module_id, $e->getMessage() ) );
				$health_map[ $module_id ] = [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				];
			}
		}

		return $health_map;
	}
}
