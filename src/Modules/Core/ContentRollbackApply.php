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
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
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
 * domain, the capability of the ORIGINAL operation against the concrete target,
 * and the compatibility of the module that recorded the snapshot. The first is
 * about identity and the third is about health; they are not the same check and
 * neither substitutes for the other.
 *
 * @package SiteHelm
 */
final class ContentRollbackApply implements WriteOperation {

	/**
	 * The fields a content snapshot restores.
	 */
	private const RESTORED_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

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

		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( self::RESTORED_FIELDS as $field ) {
			$promised[ $field ] = (string) ( $state[ $field ] ?? '' );
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
		foreach ( self::RESTORED_FIELDS as $field ) {
			$restore_state[ $field ] = (string) ( $planned->afterFields[ $field ] ?? '' );
		}

		$target_key = $this->targets->restoreFields( $restore_state );

		$snapshot = $this->snapshots->findByRef( (string) ( $planned->payload['rollbackRef'] ?? '' ) );
		if ( null !== $snapshot ) {
			$this->snapshots->markRestored( (int) $snapshot['id'], $context->requestTime );
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
	 * Re-checks the capability of the operation that recorded the snapshot,
	 * against the concrete target, at restore time.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param TargetState          $current  The resolved current state.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           original operation no longer exists, or
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

		$this->policy->authorize(
			$this->registry->definition( $original ),
			$context,
			$this->fields->postIdFromTargetKey( $current->targetKey )
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
}
