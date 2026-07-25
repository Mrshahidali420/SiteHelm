<?php

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
	 * @param array<string, array{version: ?string, health: string}> $moduleVersions Module health map.
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
}
