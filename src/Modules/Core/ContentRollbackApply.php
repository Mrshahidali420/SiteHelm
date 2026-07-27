<?php
/**
 * Rollback execution for the content domain.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;

/**
 * REQ-0008: rollback execution. An agency operator reverses a supported write
 * on a client site without manual database repair.
 *
 * This is itself a preview-required write, so restoring goes through the same
 * two-phase flow as any other change: the operator sees exactly what will be
 * put back before approving it, and the pre-rollback state is captured in a
 * fresh snapshot so the rollback can itself be reversed.
 *
 * Three re-checks happen at restore time, all inside planChange() so they run at
 * preview and again at apply: that the snapshot belongs to THIS operation's own
 * domain, that this caller holds the target-bound capability for the post about
 * to be overwritten, and that the module which recorded the snapshot is still
 * compatible. The first is about identity and the third is about health; they are
 * not the same check and neither substitutes for the other.
 *
 * @package SiteHelm
 */
final class ContentRollbackApply implements WriteOperation {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-rollback-apply.
	 */
	public static function definition(): OperationDefinition {
		// requiredCapabilities is the target-bound meta capability edit_post,
		// matching content-update, rather than the site-wide primitive
		// edit_posts. It is the front-gate and catalog declaration only:
		// assert_original_capability() derives the capability it re-checks
		// from the resolved target itself, so no declaration here or on any
		// origin operation can weaken the restore-time check.
		//
		// The request carries no post id (only rollbackRef), so PolicyEngine's
		// front-gate check for a direct invocation cannot evaluate edit_post
		// against a target and falls back to the governing primitive. That
		// target-less fallback was introduced in this phase, to stop a
		// target-less meta-capability resolving to do_not_allow and refusing
		// every user including administrators. It is deliberately coarse and
		// is safe precisely because the restore-time re-check inside this
		// operation is target-bound.
		return new OperationDefinition(
			id: 'content-rollback-apply',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Restore a recorded snapshot for a previously executed content write, re-checking the original permission at restore time.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'rollbackRef' => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Rollback reference offered on a previous write result or audit entry.',
					],
				],
				'required'             => [ 'rollbackRef' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-rollback-apply',
				'arguments' => [ 'rollbackRef' => 'rb-0123456789abcdef01234567' ],
			],
		);
	}

	/**
	 * This operation's own identifier, named in a restore-time refusal.
	 */
	private const OPERATION_ID = 'content-rollback-apply';

	/**
	 * The target-bound capability that overwriting one post requires.
	 *
	 * Every target this operation resolves is a post, so the capability the
	 * restore-time re-check enforces is derived from that fact rather than from
	 * any operation's declaration. `edit_post` is a meta-capability: WordPress
	 * resolves it against the specific post through map_meta_cap, which is what
	 * makes it target-bound rather than a blanket site-wide grant.
	 */
	private const RESTORE_CAPABILITY = 'edit_post';

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields      $fields    The normalized field map.
	 * @param ContentTarget      $targets   Shared target resolution.
	 * @param SnapshotStore      $snapshots The rollback snapshot store.
	 * @param CapabilityRegistry $registry  The registry, for the original definition.
	 * @param PolicyEngine       $policy    The policy engine, for the re-check.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
		private readonly SnapshotStore $snapshots,
		private readonly CapabilityRegistry $registry,
		private readonly PolicyEngine $policy,
	) {
	}

	/**
	 * Resolves the content item the referenced snapshot belongs to.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$snapshot = $this->snapshot( (string) ( $input['rollbackRef'] ?? '' ) );

		return $this->targets->resolve(
			$this->fields->postIdFromTargetKey( (string) $snapshot['target_key'] )
		);
	}

	/**
	 * Builds the restoration, after re-checking authority and compatibility.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound,
	 *                           ErrorCode::Forbidden, or
	 *                           ErrorCode::RollbackUnavailable.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$reference = (string) ( $input['rollbackRef'] ?? '' );
		$snapshot  = $this->snapshot( $reference );

		$this->assert_same_site( $snapshot, $context );
		$this->assert_same_module( $snapshot );
		$this->assert_original_capability( $snapshot, $current, $context );
		$this->assert_module_compatibility( $snapshot, $context );

		// Only the fields the stored snapshot actually recorded are promised.
		// A snapshot written before a column joined RESTORABLE_FIELDS does not
		// carry it, and defaulting the absence to '' would promise — and then
		// write — an empty value the snapshot never observed. For post_status
		// that is not cosmetic: wp_update_post() resolves an empty status to
		// 'draft', so a rollback of an older snapshot would silently
		// un-publish a live post while reporting success.
		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) ) {
				$promised[ $field ] = (string) $state[ $field ];
			}
		}
		// Values outside RESTORABLE_FIELDS are not post columns and are recorded
		// as integers, so they are promised as integers: a string here would make
		// the promise disagree with the read-back, which reports featured_media
		// as an int, and a correct rollback would verify as adjusted.
		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) ) {
				$promised[ $field ] = (int) $state[ $field ];
			}
		}
		ksort( $promised, SORT_STRING );

		return new PlannedChange(
			[
				'rollbackRef' => $reference,
				'restore'     => $promised,
			],
			$promised,
			ContentFields::FIELD_ORDER
		);
	}

	/**
	 * Captures the state the rollback is about to overwrite, so the rollback can
	 * itself be reversed.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Writes the recorded prior state back and stamps the snapshot as restored.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised restoration.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$restore_state = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) ) {
				$restore_state[ $field ] = (string) $planned->afterFields[ $field ];
			}
		}

		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) ) {
				$restore_state[ $field ] = (int) $planned->afterFields[ $field ];
			}
		}

		$target_key = $this->targets->restoreFields( $restore_state );

		$snapshot = $this->snapshots->findByRef( (string) ( $planned->payload['rollbackRef'] ?? '' ) );
		if ( null !== $snapshot ) {
			$snapshot_id = (int) $snapshot['id'];
			if ( ! $this->snapshots->markRestored( $snapshot_id, $context->requestTime ) ) {
				$this->log_unmarked_snapshot( $snapshot_id );
			}
		}

		return $target_key;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the restored item for verification.
	 *
	 * @param string           $targetKey The restored target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Undoes a failed rollback by writing the pre-rollback state back.
	 *
	 * @param array<string, mixed> $restoreState The pre-rollback state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Resolves one snapshot row, or refuses.
	 *
	 * @param string $reference The rollback reference from the request.
	 *
	 * @return array<string, mixed> The snapshot row.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function snapshot( string $reference ): array {
		$row = '' === $reference ? null : $this->snapshots->findByRef( $reference );

		if ( null === $row ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		return $row;
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
	private function assert_same_site( array $snapshot, OperationContext $context ): void {
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
	 * Confirms the snapshot belongs to this operation's own domain.
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
	private function assert_same_module( array $snapshot ): void {
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
	 * declared that primitive. Unreachable today, because reads cannot record
	 * snapshots and a creation's captureSnapshot() returns null, but live the
	 * moment REQ-0018 ships. Changing one operation's declared capability closed
	 * the chained-reference entrance to that hole; this closes the general one,
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
	private function assert_original_capability(
		array $snapshot,
		TargetState $current,
		OperationContext $context
	): void {
		$original = (string) $snapshot['operation_id'];

		if ( ! $this->registry->has( $original ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The operation that recorded this snapshot is no longer available, so restoration cannot be authorized.',
				'Recover through WordPress revisions instead.'
			);
		}

		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		if ( Mode::Write !== $this->registry->definition( $original )->mode || $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		$this->policy->authorizeTargetCapability(
			self::RESTORE_CAPABILITY,
			$post_id,
			self::OPERATION_ID,
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
	private function assert_module_compatibility( array $snapshot, OperationContext $context ): void {
		$module  = (string) $snapshot['module_id'];
		$current = $context->moduleVersions[ $module ] ?? [];
		$health  = is_array( $current ) ? ( $current['health'] ?? ModuleHealth::Inactive->value ) : ModuleHealth::Inactive->value;

		if ( ModuleHealth::Active->value !== $health ) {
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

	/**
	 * Logs server-side when a restored snapshot could not be stamped
	 * `restored_at`, so the failure is at least discoverable rather than
	 * silently dropped. The restoration itself already succeeded by this
	 * point — restoreFields() ran first and would have thrown had it
	 * failed — so this is a bookkeeping failure only, not a reason to
	 * report the write itself as failed or to attempt any compensation.
	 *
	 * @param int $snapshotId The snapshot row identifier.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function log_unmarked_snapshot( int $snapshotId ): void {
		error_log( sprintf( 'SiteHelm restored a rollback snapshot but could not mark it restored (id: %d).', $snapshotId ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
