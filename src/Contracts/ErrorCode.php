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
	 * Whether SENDING THIS REQUEST AGAIN UNCHANGED can succeed later.
	 *
	 * That is the whole meaning, and it is deliberately narrow, because this is
	 * the field an automated client reads to decide whether to back off and try
	 * again. It once meant something looser — "a corrected, refreshed or simply
	 * repeated request can clear this" — which put `retryable: true` on
	 * `invalid_input`, beside a message reading "identical input always fails
	 * identically". A client obeying the flag retries the same broken call until
	 * it gives up; the contradiction was reported from a real session. The thing
	 * that looser reading was trying to express — you can fix this and send a
	 * different request — is real, and it already has a field of its own:
	 * `remediation` says what to change.
	 *
	 * True for the three codes whose condition can clear on its own, with nothing
	 * about the request altered: `upstream_unavailable` (a remote service that was
	 * slow or briefly down), `conflict` (the state the target was in has since
	 * moved), and `execution_failed` (a write that failed for a reason nothing
	 * predicted; an automatic retry is appropriate only where the operation
	 * declares `isIdempotent` true).
	 *
	 * False for every code that answers the identical bytes identically forever.
	 * `invalid_input` and `stale_plan` are the two that changed: both are fixed by
	 * sending a DIFFERENT request — corrected arguments, or a fresh plan token —
	 * and a different request is not a retry. `authentication_failed` is the same
	 * shape: presenting another credential is a new authenticated connection. So
	 * are `forbidden`, `integration_unavailable`, `integration_unlicensed`,
	 * `unsupported_version`, `target_not_found`, `verification_failed` and
	 * `rollback_unavailable`, which need WordPress-side configuration, a purchase,
	 * a different target, or operator inspection.
	 *
	 * @return bool True when this exact request may succeed if sent again later.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isRetryable(): bool {
		return match ( $this ) {
			self::Conflict, self::ExecutionFailed, self::UpstreamUnavailable => true,
			default => false,
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
