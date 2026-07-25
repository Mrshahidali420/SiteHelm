<?php
/**
 * The immutable per-request operation context.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * Immutable per-request context. Built by the gateway; modules only read it.
 *
 * @package SiteHelm
 */
final class OperationContext {

	/**
	 * Constructs one validated request context.
	 *
	 * @param string         $siteId         Stable identifier of the operated site.
	 * @param int            $userId         The resolved WordPress user.
	 * @param string         $clientId       The MCP client making the request.
	 * @param string         $correlationId  Gateway-generated request identifier.
	 * @param PermissionMode $permissionMode The site-level permission mode in force.
	 * @param array          $moduleVersions Module health map: module id to version and health.
	 * @param int            $requestTime    Server-side UTC acceptance timestamp.
	 *
	 * @throws InvalidArgumentException When any context invariant is violated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		public readonly string $siteId,
		public readonly int $userId,
		public readonly string $clientId,
		public readonly string $correlationId,
		public readonly PermissionMode $permissionMode,
		public readonly array $moduleVersions,
		public readonly int $requestTime,
	) {
		if ( '' === $siteId ) {
			throw new InvalidArgumentException( 'OperationContext requires a site identifier.' );
		}
		if ( $userId <= 0 ) {
			throw new InvalidArgumentException( 'OperationContext requires a resolved WordPress user.' );
		}
		if ( '' === $correlationId ) {
			throw new InvalidArgumentException( 'OperationContext requires a correlation identifier.' );
		}
		if ( $requestTime <= 0 ) {
			throw new InvalidArgumentException( 'OperationContext requires a server-side request time.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
