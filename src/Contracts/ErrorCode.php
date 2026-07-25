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
	 * Whether a retry can ever help, per the contract's retryability table.
	 * Retryable here means "retryable after correcting input / refreshing a plan",
	 * not "safe to blindly retry".
	 */
	public function isRetryable(): bool {
		return match ( $this ) {
			self::InvalidInput, self::Conflict, self::StalePlan, self::ExecutionFailed => true,
			default => false,
		};
	}
}
