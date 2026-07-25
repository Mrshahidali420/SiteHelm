<?php
/**
 * Environment discovery handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\OperationContext;

/**
 * REQ-0001: system environment discovery. An agency operator confirms
 * WordPress, PHP, theme, and module versions before planning any change.
 * The report never contains credentials or filesystem paths.
 */
final class EnvironmentDiscovery {

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Handles a system environment discovery operation.
	 *
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @param OperationContext     $context The operation context.
	 * @return array<string, mixed> Environment report.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$theme = wp_get_theme();

		return [
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'sitehelm'       => SITEHELM_VERSION,
			'theme'          => [
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			],
			'permissionMode' => $context->permissionMode->value,
			'modules'        => $context->moduleVersions,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
