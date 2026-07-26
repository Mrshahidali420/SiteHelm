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

	/**
	 * The primitive that governs each meta-capability.
	 *
	 * This is the canonical map. `CatalogBuilder` consumes it rather than
	 * keeping a second copy: when the catalog and this gate encoded the same
	 * knowledge separately they disagreed, and a target-less invocation of a
	 * meta-capability operation was refused for every user including
	 * administrators while the catalog still advertised it as available.
	 */
	public const META_CAPABILITY_MAP = [
		'edit_post'    => 'edit_posts',
		'delete_post'  => 'delete_posts',
		'assign_terms' => 'edit_posts',
	];

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
			$is_meta = array_key_exists( $capability, self::META_CAPABILITY_MAP );

			if ( $is_meta && null !== $targetId ) {
				// The precise check: WordPress resolves the meta-capability
				// against this specific post through map_meta_cap.
				$allowed = user_can( $context->userId, $capability, $targetId );
			} elseif ( $is_meta ) {
				// No target to check against. WordPress resolves a target-less
				// meta-capability to do_not_allow, so asking it directly would
				// refuse every user including administrators — which is what
				// broke content-rollback-apply, whose arguments carry a rollback
				// reference rather than a post id. Fall back to the governing
				// primitive, exactly as the catalog does, so the gate and the
				// catalog cannot disagree.
				//
				// This is deliberately coarse. It is safe because an operation
				// reaching here has no target for anyone to check, and the
				// operations that resolve a target later re-check it precisely:
				// content-rollback-apply calls authorize() again from inside
				// itself with the concrete target id taken from the snapshot.
				$allowed = user_can( $context->userId, self::META_CAPABILITY_MAP[ $capability ] );
			} else {
				$allowed = user_can( $context->userId, $capability );
			}

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
