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
	 *
	 * `assign_terms` is deliberately absent, and must not be re-added. It is
	 * not post-scoped: WordPress resolves it against a TAXONOMY, through
	 * `get_taxonomy( $tax )->cap->assign_terms`, which is what `TaxonomyList`
	 * reads. It was once mapped here to the post-scoped `edit_posts`, so an
	 * operation declaring it would have been granted term-assignment authority
	 * on the strength of a capability that means something else. With no row
	 * the fallback branch below asks WordPress for `assign_terms` as a
	 * primitive, which no default WordPress role holds, so a mistaken
	 * declaration fails closed.
	 * An operation that genuinely needs it checks the taxonomy's own
	 * capability inside `planChange()`.
	 */
	public const META_CAPABILITY_MAP = [
		'edit_post'   => 'edit_posts',
		'delete_post' => 'delete_posts',
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether the resolved user holds every capability the operation requires,
	 * with target meta-capabilities evaluated through their primitive stand-ins.
	 *
	 * This answers "could this caller plausibly perform this operation at all",
	 * which is the only question a target-less surface can answer. It is
	 * deliberately NOT an authorization decision: authorize() performs the real
	 * target-bound check at invocation time and remains authoritative, so an
	 * operation visible here may still be refused with `forbidden` when invoked
	 * against a specific target.
	 *
	 * It is static because the two surfaces that need it — the dispatcher catalog
	 * and the on-demand schema lookup — describe operations rather than run them,
	 * and neither should have to be handed the gate to ask a question about
	 * visibility. Both must answer it the same way: an operation the catalog hides
	 * would otherwise still surrender its schema on request.
	 *
	 * @param OperationDefinition $definition The operation to test.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return bool True when the operation may be described to this caller.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public static function isVisibleWithoutTarget( OperationDefinition $definition, OperationContext $context ): bool {
		foreach ( $definition->requiredCapabilities as $capability ) {
			$effective = self::META_CAPABILITY_MAP[ $capability ] ?? $capability;

			if ( ! user_can( $context->userId, $effective ) ) {
				return false;
			}
		}

		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

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

		if ( Mode::Write === $definition->mode && ! RequestHost::matches( $context->siteId ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This request reached the site at an address the site no longer answers as, so writes are refused.',
				sprintf( 'Point the connector at %s and reconnect.', home_url( '/' ) )
			);
		}

		foreach ( $definition->requiredCapabilities as $capability ) {
			$is_meta = array_key_exists( $capability, self::META_CAPABILITY_MAP );

			if ( $is_meta && null !== $targetId ) {
				// The precise check: WordPress resolves the meta-capability
				// against this specific post through map_meta_cap.
				$allowed = user_can( $context->userId, $capability, $targetId );

				// A TARGET THAT DOES NOT EXIST IS NOT A PERMISSIONS PROBLEM.
				// map_meta_cap resolves a meta-capability against a missing post
				// to do_not_allow, so the check above refuses an administrator
				// who simply typed the wrong number, and the refusal it produces
				// sends them to audit roles and capabilities over a typo. The
				// operations that declare the governing primitive instead have
				// always answered the same mistake with target_not_found, so
				// which diagnosis an operator got depended on nothing but which
				// capability the operation happened to declare.
				//
				// So when the refusal is only because there is nothing there,
				// fall back to the primitive exactly as the target-less branch
				// does, and let the handler give the real answer. Nothing is
				// loosened: a caller without the primitive is still refused here,
				// and every handler re-asks the precise question itself before it
				// reveals anything, so what a caller can learn about an
				// identifier they may not read is unchanged. It is asked second
				// so the ordinary authorized call still costs one check.
				if ( ! $allowed && null === get_post( $targetId ) ) {
					$allowed = user_can( $context->userId, self::META_CAPABILITY_MAP[ $capability ] );
				}
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
				throw $this->refuse( $capability, $definition->id );
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Authorizes one capability against one concrete target.
	 *
	 * A restore-time re-check needs this rather than authorize(): the capability
	 * it must enforce is derived from the target being overwritten, not read off
	 * an operation definition, so no declaration made elsewhere can weaken it.
	 * Passing the capability explicitly is the point — authorize() answers "may
	 * this caller run this operation", while this answers "may this caller act on
	 * this object", and a restore needs the second question asked about the post
	 * it is about to overwrite.
	 *
	 * @param string           $capability  The capability to require.
	 * @param int              $targetId    The concrete target it is evaluated against.
	 * @param string           $operationId The operation named in a refusal.
	 * @param OperationContext $context     The operation context.
	 *
	 * @return void
	 *
	 * @throws OperationException With ErrorCode::Forbidden when not authorized.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function authorizeTargetCapability(
		string $capability,
		int $targetId,
		string $operationId,
		OperationContext $context
	): void {
		if ( ! user_can( $context->userId, $capability, $targetId ) ) {
			throw $this->refuse( $capability, $operationId );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The one refusal shape for a capability shortfall.
	 *
	 * Both entry points raise the identical message, so a caller cannot tell the
	 * front-gate check from a restore-time re-check by reading the envelope.
	 *
	 * @param string $capability   The capability that was not held.
	 * @param string $operation_id The operation requiring it.
	 *
	 * @return OperationException The refusal to throw.
	 */
	private function refuse( string $capability, string $operation_id ): OperationException {
		return new OperationException(
			ErrorCode::Forbidden,
			sprintf( "Your WordPress user lacks the '%s' capability required by '%s'.", $capability, $operation_id ),
			'Ask a site administrator to grant the capability or use a different account.'
		);
	}
}
