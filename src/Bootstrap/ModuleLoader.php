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

	// PHPDoc uses IntegrationModule[] rather than list<IntegrationModule>: WPCS's IncorrectTypeHint sniff does not understand generic list syntax, and sequential keys are not required here.
	/**
	 * Loads every module in isolation, recording health and handling errors.
	 *
	 * @param IntegrationModule[] $modules Modules to load.
	 * @param CapabilityRegistry  $registry The capability registry.
	 * @return array<string, array{version: ?string, health: string}> Health map.
	 */
	public function load( array $modules, CapabilityRegistry $registry ): array {
		$health_map = [];

		foreach ( $modules as $module ) {
			// The class name is a stable fallback key: id() is module code too, so
			// it must be called inside the isolation boundary. Without a key the
			// failure could not be recorded in the health map at all.
			$module_id = get_class( $module );
			try {
				$module_id               = $module->id()->value;
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
