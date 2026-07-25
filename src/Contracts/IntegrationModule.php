<?php
/**
 * Integration module contract.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use SiteHelm\Registry\CapabilityRegistry;

/**
 * One internal integration module. Modules depend only on the registry,
 * policy, and change contracts — never on another integration module.
 *
 * @package SiteHelm
 */
interface IntegrationModule {

	/**
	 * The module's unique identifier.
	 */
	public function id(): ModuleId;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Human-readable name for this module.
	 */
	public function displayName(): string;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Runtime dependency for this module.
	 *
	 * @return array{name: string, versionRange: ?string}
	 */
	public function dependency(): array;

	/**
	 * Detected version and health status.
	 *
	 * @return array{version: ?string, health: string}
	 */
	public function health(): array;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * @return list<string>
	 */
	public function cacheCleanup(): array;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers this module's OperationDefinitions and handlers.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void;
}
