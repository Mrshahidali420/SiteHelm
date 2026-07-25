<?php
/**
 * Context factory for building immutable operation contexts.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;

/**
 * Builds the immutable OperationContext for each gateway request.
 * Application Password authentication has already run inside WordPress by
 * the time REST callbacks execute; an unresolved user means it failed.
 *
 * @package SiteHelm
 */
final class ContextFactory {

	public const MODE_OPTION = 'sitehelm_permission_mode';

	/**
	 * Builds the per-request operation context from WordPress state.
	 *
	 * @param array<string, array{version: ?string, health: string}> $moduleVersions Module health map.
	 * @param string                                                 $clientId       Client identifier from MCP handshake.
	 *
	 * @return OperationContext The constructed operation context.
	 *
	 * @throws OperationException When no WordPress user is resolved.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function create( array $moduleVersions, string $clientId = 'unknown-client' ): OperationContext {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			throw new OperationException(
				ErrorCode::AuthenticationFailed,
				'No WordPress user could be resolved for this request.',
				'Connect with a valid Application Password for a real WordPress user.'
			);
		}

		$stored_mode = get_option( self::MODE_OPTION, PermissionMode::SafeWrite->value );
		$mode        = PermissionMode::tryFrom( is_string( $stored_mode ) ? $stored_mode : '' )
			?? PermissionMode::SafeWrite;

		return new OperationContext(
			siteId: (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			userId: $user_id,
			clientId: $clientId,
			correlationId: wp_generate_uuid4(),
			permissionMode: $mode,
			moduleVersions: $moduleVersions,
			requestTime: time(),
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
