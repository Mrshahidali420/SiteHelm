<?php
/**
 * Error codes for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * The thirteen stable public error codes. Codes never change meaning;
 * adding a code requires a revision of the foundation contract, and
 * `integration_unlicensed` and `upstream_unavailable` were added by the
 * amendment of 2026-09-05 recorded there.
 */
enum ErrorCode: string {
	case AuthenticationFailed   = 'authentication_failed';
	case Forbidden              = 'forbidden';
	case IntegrationUnavailable = 'integration_unavailable';
	case IntegrationUnlicensed  = 'integration_unlicensed';
	case UpstreamUnavailable    = 'upstream_unavailable';
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
	 * True for exactly the five codes whose condition a corrected, refreshed or
	 * simply repeated request can clear: `invalid_input` (correct the input),
	 * `conflict` and `stale_plan` (re-read the target and approve a fresh plan),
	 * `execution_failed` (retry with a fresh plan; an automatic retry is
	 * appropriate only when the operation declares `isIdempotent` true), and
	 * `upstream_unavailable`. It never means "safe to blindly repeat".
	 *
	 * `upstream_unavailable` IS THE ONLY ONE THAT CAN CLEAR ON ITS OWN, and it is
	 * the reason the code exists. A remote service that was slow or briefly down
	 * is the one refusal on this list where the caller has nothing to correct and
	 * waiting is the whole remedy — so a caller that groups it with the site's own
	 * missing dependencies either gives up on something that would have worked, or
	 * hammers a WordPress installation that will never answer differently.
	 *
	 * False for every code whose condition can only change outside the request,
	 * even where the contract describes a corrective action. `authentication_failed`
	 * is the clearest case: presenting a different credential is a new
	 * authenticated connection, not a retry of this request, so the contract
	 * marks it "not retryable with the same credential" and the value is false.
	 * The same reasoning covers `forbidden`, `integration_unavailable`,
	 * `integration_unlicensed`, `unsupported_version`, `target_not_found`,
	 * `verification_failed`, and `rollback_unavailable`, which need WordPress-side
	 * configuration, a purchase, a different target, or operator inspection.
	 *
	 * @return bool True when this request can be corrected and retried.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isRetryable(): bool {
		return match ( $this ) {
			self::InvalidInput, self::Conflict, self::StalePlan, self::ExecutionFailed, self::UpstreamUnavailable => true,
			default => false,
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
