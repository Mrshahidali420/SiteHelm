<?php
/**
 * Error codes for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * The eleven stable public error codes. Codes never change meaning;
 * adding a code requires a revision of the foundation contract.
 */
enum ErrorCode: string {
	case AuthenticationFailed   = 'authentication_failed';
	case Forbidden              = 'forbidden';
	case IntegrationUnavailable = 'integration_unavailable';
	case UnsupportedVersion     = 'unsupported_version';
	case InvalidInput           = 'invalid_input';
	case TargetNotFound         = 'target_not_found';
	case Conflict               = 'conflict';
	case StalePlan              = 'stale_plan';
	case ExecutionFailed        = 'execution_failed';
	case VerificationFailed     = 'verification_failed';
	case RollbackUnavailable    = 'rollback_unavailable';

	/**
	 * Whether retrying THIS request can help, per the contract's retryability
	 * table.
	 *
	 * True for exactly the four codes whose condition a corrected or refreshed
	 * request can clear: `invalid_input` (correct the input), `conflict` and
	 * `stale_plan` (re-read the target and approve a fresh plan), and
	 * `execution_failed` (retry with a fresh plan; an automatic retry is
	 * appropriate only when the operation declares `isIdempotent` true). It never
	 * means "safe to blindly repeat".
	 *
	 * False for every code whose condition can only change outside the request,
	 * even where the contract describes a corrective action. `authentication_failed`
	 * is the clearest case: presenting a different credential is a new
	 * authenticated connection, not a retry of this request, so the contract
	 * marks it "not retryable with the same credential" and the value is false.
	 * The same reasoning covers `forbidden`, `integration_unavailable`,
	 * `unsupported_version`, `target_not_found`, `verification_failed`, and
	 * `rollback_unavailable`, which need WordPress-side configuration, a
	 * different target, or operator inspection.
	 *
	 * @return bool True when this request can be corrected and retried.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isRetryable(): bool {
		return match ( $this ) {
			self::InvalidInput, self::Conflict, self::StalePlan, self::ExecutionFailed => true,
			default => false,
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
