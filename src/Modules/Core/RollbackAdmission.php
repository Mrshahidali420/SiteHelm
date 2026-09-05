<?php
/**
 * The refusals a rollback must survive before anything is restored.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Whether a stored snapshot may be restored at all.
 *
 * EVERY METHOD HERE EITHER THROWS OR RETURNS NOTHING. That is the contract, and
 * it is what separates this class from the operation that calls it: these
 * refusals decide whether a restoration may proceed, while `planChange()` decides
 * what it would put back. Mixing the two put a 214-line method in front of the
 * plugin's recovery path, where the reader who most needs to see the refusals had
 * to find them among the promise-building.
 *
 * Nothing here may read or write site state beyond what it is handed. A check
 * that needs to consult the target has been given the resolved target already;
 * one that reaches out for it would be doing the operation's work.
 *
 * THE ORDER THE OPERATION CALLS THESE IN IS LOAD-BEARING and is not this class's
 * to decide. A snapshot failing more than one refusal must keep reporting the
 * first one it always reported, so the caller sequences them.
 *
 * @package SiteHelm
 */
final class RollbackAdmission {

	/**
	 * The capability re-checked against the post about to be overwritten.
	 *
	 * Target-bound rather than a site-wide primitive, which is the whole point of
	 * assert_original_capability() — see its docblock.
	 */
	private const RESTORE_CAPABILITY = 'edit_post';

	/**
	 * Builds the admission gate.
	 *
	 * @param CapabilityRegistry $registry     Where the origin operation is looked up.
	 * @param ContentFields      $fields       Post-key and taxonomy questions.
	 * @param PolicyEngine       $policy       The authority the capability re-check asks.
	 * @param string             $operation_id The rollback operation's own id, for the policy record.
	 */
	public function __construct(
		private readonly CapabilityRegistry $registry,
		private readonly ContentFields $fields,
		private readonly PolicyEngine $policy,
		private readonly string $operation_id,
	) {
	}

	/**
	 * Refuses a reference whose origin is gone, or is not a write.
	 *
	 * A snapshot's origin is always a write, so a reference naming anything else
	 * is malformed and is not something a restore may act on. The origin is also
	 * required to still exist, so a retired operation cannot be restored blind.
	 *
	 * The second refusal reuses the missing-snapshot message verbatim, for the
	 * reason assert_same_module() gives.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           original operation no longer exists, or
	 *                           ErrorCode::TargetNotFound when it is not a write.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_origin_is_a_write( array $snapshot ): void {
		$original = (string) $snapshot['operation_id'];

		if ( ! $this->registry->has( $original ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The operation that recorded this snapshot is no longer available, so restoration cannot be authorized.',
				'Recover through WordPress revisions instead.'
			);
		}

		if ( Mode::Write !== $this->registry->definition( $original )->mode ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses a snapshot recorded for a different site.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_same_site( array $snapshot, OperationContext $context ): void {
		if ( (string) $snapshot['site_id'] !== $context->siteId ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Confirms the snapshot belongs to the content domain.
	 *
	 * The contract scopes a write dispatcher's rollback to a write "in its own
	 * domain". Module HEALTH is a different question from module IDENTITY:
	 * `media-*` snapshots are recorded by a module that will be perfectly
	 * healthy, and health alone would authorize `content-rollback-apply` to
	 * restore one. Today only the core module records snapshots, so the check is
	 * unobservable — which is exactly why it must exist before a second module
	 * ships and makes it observable as a defect.
	 *
	 * `target_not_found` is reused rather than a new code invented: the eleven
	 * codes are fixed, and from the caller's side a reference it may not act on
	 * is indistinguishable from one that does not exist. Reusing the same
	 * message as the missing and cross-site cases also keeps the response from
	 * becoming a probe for which references exist.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_same_module( array $snapshot ): void {
		if ( ModuleId::Core->value !== (string) $snapshot['module_id'] ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-checks, at restore time, that this caller may overwrite this post.
	 *
	 * The capability is derived from the RESOLVED TARGET, not from what the
	 * origin operation declares. That distinction is the whole check. Deriving it
	 * from the origin's declaration meant the strength of the re-check was set by
	 * whichever operation happened to record the snapshot: a reviewer probed the
	 * previous behaviour and found a Contributor holding only the site-wide
	 * primitive `edit_posts` was allowed to overwrite post 42 whenever the origin
	 * declared that primitive. It was unreachable when that was written, because
	 * reads cannot record snapshots and a creation's captureSnapshot() returns
	 * null, and REQ-0018 was expected to make it live. REQ-0018 has since shipped
	 * and did not, only because `content-status-set` declares the target-bound
	 * `edit_post` rather than the site-wide primitive — which is precisely the
	 * per-operation declaration this check refuses to take its strength from.
	 * Changing one operation's declared capability closed the chained-reference
	 * entrance to that hole; this closes the general one,
	 * because a target-bound capability evaluated against the target itself cannot
	 * be weakened by a declaration made anywhere else.
	 *
	 * The origin is still required to exist, so a retired operation cannot be
	 * restored blind, and is now also required to be a WRITE: a snapshot's origin
	 * is always a write, so a reference naming anything else is malformed and is
	 * not something a restore may act on.
	 *
	 * That refusal reuses the missing-snapshot message verbatim. The eleven codes
	 * are fixed, and from the caller's side a reference it may not act on is
	 * indistinguishable from one that does not exist; keeping every such refusal
	 * identical stops the response from becoming a probe for which references
	 * exist.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param TargetState          $current  The resolved current state.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           original operation no longer exists,
	 *                           ErrorCode::TargetNotFound when the reference is
	 *                           not one a restore may act on, or
	 *                           ErrorCode::Forbidden from the policy engine.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_original_capability(
		array $snapshot,
		TargetState $current,
		OperationContext $context
	): void {
		$this->assert_origin_is_a_write( $snapshot );

		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		$this->policy->authorizeTargetCapability(
			self::RESTORE_CAPABILITY,
			$post_id,
			$this->operation_id,
			$context
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-verifies that the module which recorded the snapshot is still active at
	 * the same detected version.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_module_compatibility( array $snapshot, OperationContext $context ): void {
		$module  = (string) $snapshot['module_id'];
		$current = $context->moduleVersions[ $module ] ?? [];
		$health  = is_array( $current ) ? ( $current['health'] ?? ModuleHealth::Inactive->value ) : ModuleHealth::Inactive->value;

		// An unconfigured module is admitted. It records and restores through the
		// same store it always did; the plugin's unfinished setup governs what
		// visitors see, not whether a snapshot can be put back.
		if ( true !== ModuleHealth::tryFrom( is_string( $health ) ? $health : '' )?->isOperational() ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The module that recorded this snapshot is not active, so restoration cannot be proven safe.',
				'Activate a supported version of the required dependency, then retry.'
			);
		}

		$recorded = $this->decode( (string) $snapshot['module_versions'] );
		$before   = is_array( $recorded[ $module ] ?? null ) ? ( $recorded[ $module ]['version'] ?? null ) : null;
		$now      = is_array( $current ) ? ( $current['version'] ?? null ) : null;

		if ( $before !== $now ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The module that recorded this snapshot has changed version since capture, so restoration cannot be proven safe.',
				'Recover through WordPress revisions instead.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses a restoration that would write a taxonomy storing term ORDER.
	 *
	 * SCOPED TO THE PROMISED MAP, NOT TO EVERY TAXONOMY THE ITEM CARRIES, unlike
	 * ContentTermsAssign's. The wide scope refused a COLUMN-ONLY rollback merely
	 * because the post carried an unrelated sorted taxonomy, though such a
	 * rollback writes no terms — and over-refusing is worse here than anywhere
	 * else, because this operation IS the recovery path.
	 *
	 * The promise is not narrower than the write. Whenever a `terms` promise is
	 * made at all, overlayKnownKeys() has returned the COMPLETE CURRENT map, so it
	 * names every taxonomy on the item, and applyChange() hands exactly that map to
	 * ContentTarget::restore_terms(). The refusal set therefore contains the write
	 * set. When NO recorded key survives the overlay the promise is not made — the
	 * caller's array_intersect_key() guard skips it — and this check does not run;
	 * that is safe rather than a gap, because a `terms` value that was never
	 * promised is never written either.
	 *
	 * THIS IS THE ONE REFUSAL HERE THAT IS NOT A RE-CHECK OF THE SNAPSHOT. It
	 * judges the promise the operation has just built, which is why the operation
	 * calls it from inside the promise loop rather than beside the others.
	 *
	 * PAIRED WITH captureSnapshot() OMITTING THE `terms` KEY; neither half is
	 * correct alone. See the core-writes design's ContentRollbackApply row.
	 *
	 * @param array<string, mixed> $promised The complete taxonomy map this
	 *                                       restore would write.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function assert_order_is_recordable( array $promised ): void {
		if ( $this->fields->anyTaxonomyIsOrdered( array_keys( $promised ) ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This content item carries a taxonomy that stores its terms in a curated order, which no snapshot can record, so nothing was restored.',
				'Ask a site administrator to review how that taxonomy is registered on this site, then request a fresh preview.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Decodes one stored JSON column.
	 *
	 * @param string $json The stored JSON.
	 *
	 * @return array<string, mixed> The decoded value, or an empty array.
	 */
	private function decode( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
