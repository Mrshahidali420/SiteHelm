<?php
/**
 * Policy engine for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Policy;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;

/**
 * Site-level permission mode and WordPress capability enforcement.
 * Every rejection is the stable public code 'forbidden'.
 *
 * @package SiteHelm
 */
final class PolicyEngine {

	private const META_CAPABILITIES = [ 'edit_post', 'delete_post', 'assign_terms' ];

	/**
	 * Authorizes one dispatch. Returns void on success; throws OperationException(Forbidden) otherwise.
	 *
	 * @param OperationDefinition $definition The operation definition.
	 * @param OperationContext    $context    The operation context.
	 * @param int|null            $targetId   The concrete target for meta-capability checks.
	 *
	 * @return void
	 *
	 * @throws OperationException With ErrorCode::Forbidden when not authorized.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function authorize( OperationDefinition $definition, OperationContext $context, ?int $targetId = null ): void {
		if ( PermissionMode::ReadOnly === $context->permissionMode && Mode::Write === $definition->mode ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This site is in read-only mode; write operations are disabled.',
				'A site administrator can change the permission mode in SiteHelm settings.'
			);
		}

		foreach ( $definition->requiredCapabilities as $capability ) {
			$is_meta = in_array( $capability, self::META_CAPABILITIES, true ) && null !== $targetId;
			$allowed = $is_meta
				? user_can( $context->userId, $capability, $targetId )
				: user_can( $context->userId, $capability );

			if ( ! $allowed ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					sprintf( "Your WordPress user lacks the '%s' capability required by '%s'.", $capability, $definition->id ),
					'Ask a site administrator to grant the capability or use a different account.'
				);
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
