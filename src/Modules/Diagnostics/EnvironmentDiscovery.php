<?php
/**
 * Environment discovery handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0001: system environment discovery. An agency operator confirms
 * WordPress, PHP, theme, and module versions before planning any change.
 * The report never contains credentials or filesystem paths.
 */
final class EnvironmentDiscovery {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a future caller reaching the handler by any other route — a direct
	 * invocation, a test, a second dispatcher — still meets the gate. This was
	 * the one Diagnostics handler declaring a capability without re-checking it,
	 * while its two siblings both did; the report names the installed versions of
	 * WordPress, PHP, the theme and every module, which is exactly the inventory
	 * an attacker wants first.
	 */
	private const CAPABILITY = 'manage_options';

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId, $permissionMode and $moduleVersions are contract properties this module does not name, and the refusal message here is a literal written for end users.
	/**
	 * Handles a system environment discovery operation.
	 *
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Environment report.
	 *
	 * @throws OperationException When the caller cannot manage site options.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reporting the site environment requires the capability to manage site options.',
				'Ask a site administrator to run this diagnostic.'
			);
		}

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
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
