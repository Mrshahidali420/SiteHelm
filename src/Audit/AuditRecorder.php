<?php
/**
 * Creates and finalizes audit records for preview-required writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Audit;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Storage\AuditStore;

/**
 * Records who changed what, when, and with what result.
 *
 * The record is created BEFORE execution and finalized after. That ordering is
 * what makes the contract's guarantee unbreakable: if the record cannot be
 * created, the change engine refuses to execute, so no preview-required write
 * can ever land without an audit trail.
 *
 * @package SiteHelm
 */
final class AuditRecorder {

	public const OUTCOME_STARTED             = 'started';
	public const OUTCOME_APPLIED             = 'applied';
	public const OUTCOME_VERIFICATION_FAILED = 'verification-failed';
	public const OUTCOME_EXECUTION_FAILED    = 'execution-failed';
	public const OUTCOME_RESTORED            = 'restored';
	public const OUTCOME_RESTORE_FAILED      = 'restore-failed';

	/**
	 * The summary written at creation, before any change is known.
	 */
	private const EMPTY_SUMMARY = '{"changed":[],"metrics":{}}';

	/**
	 * The audit table's actor_login column width.
	 */
	private const MAX_LOGIN_LENGTH = 60;

	/**
	 * Monotonic-ish start marks, keyed by audit row identifier.
	 *
	 * One request can perform several writes — a batch operation opens and
	 * closes a record per element — so a single stored mark would time the
	 * wrong one. Keying by row identifier keeps each write's clock its own.
	 *
	 * @var array<int, float>
	 */
	private array $started_at = [];

	/**
	 * Constructs the recorder.
	 *
	 * @param AuditStore    $store    The audit event store.
	 * @param AuditRedactor $redactor The summary redactor.
	 */
	public function __construct(
		private readonly AuditStore $store,
		private readonly AuditRedactor $redactor,
	) {
	}

	/**
	 * The public reference for one audit record.
	 *
	 * @param int $auditId The audit row identifier.
	 *
	 * @return string The reference, for example 'audit-42'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public static function reference( int $auditId ): string {
		return sprintf( 'audit-%d', $auditId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Opens one audit record before execution.
	 *
	 * The recovery handle is part of the OPENING insert, not something finish()
	 * adds later. The snapshot is captured before this is called, so both values
	 * are already known; deferring them would mean a fatal inside the write left
	 * a captured snapshot that no audit row references, recoverable only by
	 * direct database access.
	 *
	 * @param OperationDefinition $definition      The operation being executed.
	 * @param OperationContext    $context         The request context.
	 * @param string              $targetKey       The planned target key.
	 * @param string              $planFingerprint The approved plan's state fingerprint.
	 * @param int|null            $snapshotId      The snapshot row captured for this write, if any.
	 * @param string|null         $rollbackRef     The snapshot's rollback reference, if any.
	 *
	 * @return int The audit row identifier, or 0 when storage refused.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function start(
		OperationDefinition $definition,
		OperationContext $context,
		string $targetKey,
		string $planFingerprint,
		?int $snapshotId,
		?string $rollbackRef
	): int {
		$auditId = $this->store->insert(
			[
				'correlation_id'   => $context->correlationId,
				'site_id'          => $context->siteId,
				'actor_id'         => $context->userId,
				'actor_login'      => $this->login( $context->userId ),
				'client_id'        => $context->clientId,
				'operation_id'     => $definition->id,
				'target_key'       => $targetKey,
				'plan_fingerprint' => $planFingerprint,
				'outcome'          => self::OUTCOME_STARTED,
				'summary'          => self::EMPTY_SUMMARY,
				'snapshot_id'      => $snapshotId,
				'rollback_ref'     => $rollbackRef,
				'recorded_at'      => $context->requestTime,
			]
		);

		// A refused insert returns 0, which is not a row and must not be timed:
		// the next refused write would otherwise inherit this one's mark.
		if ( $auditId > 0 ) {
			$this->started_at[ $auditId ] = microtime( true );
		}

		return $auditId;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Finalizes one audit record after execution.
	 *
	 * @param int                  $auditId      The audit row identifier.
	 * @param string               $outcome      One of the OUTCOME_* constants.
	 * @param int|null             $snapshotId   The captured snapshot row, if any.
	 * @param string|null          $rollbackRef  The rollback reference, if offered.
	 * @param string               $targetKey    The concrete target key.
	 * @param array<string, mixed> $beforeFields The resolved before-state.
	 * @param array<string, mixed> $afterFields  The promised after-state.
	 * @param string|null          $failure      What went wrong, when the write failed in
	 *                                           a way nothing predicted.
	 *
	 * @return bool True when the record was updated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function finish(
		int $auditId,
		string $outcome,
		?int $snapshotId,
		?string $rollbackRef,
		string $targetKey,
		array $beforeFields,
		array $afterFields,
		?string $failure = null
	): bool {
		return $this->store->finish(
			$auditId,
			$outcome,
			$snapshotId,
			$rollbackRef,
			$targetKey,
			$this->redactor->summarize( $beforeFields, $afterFields, $failure ),
			$this->elapsed( $auditId )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Milliseconds since this record was opened, consuming the start mark.
	 *
	 * A record finalized twice is timed once: the second call finds no mark and
	 * reports null, which finish() reads as "leave the stored duration alone"
	 * rather than overwriting a real measurement with a fabricated zero.
	 *
	 * @param int $auditId The audit row identifier.
	 *
	 * @return int|null The elapsed milliseconds, or null when this row was
	 *                  never opened by this recorder.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function elapsed( int $auditId ): ?int {
		if ( ! isset( $this->started_at[ $auditId ] ) ) {
			return null;
		}

		$started = $this->started_at[ $auditId ];
		unset( $this->started_at[ $auditId ] );

		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The actor's login, or an empty string when the user cannot be resolved.
	 *
	 * @param int $user_id The resolved WordPress user.
	 *
	 * @return string The login, truncated to the column width.
	 */
	private function login( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) ) {
			// mb_substr, not substr: the column is 60 characters, and cutting on
			// a byte boundary splits a multi-byte login mid-character. That
			// stores invalid UTF-8 in a utf8mb4 column, which a strict server
			// rejects outright — losing the audit row rather than truncating a
			// name.
			return mb_substr( $user->user_login, 0, self::MAX_LOGIN_LENGTH, 'UTF-8' );
		}

		return '';
	}
}
