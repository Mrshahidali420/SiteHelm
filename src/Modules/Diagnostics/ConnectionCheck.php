<?php
/**
 * The onboarding connection diagnostic.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Gateway\RestTransport;

/**
 * REQ-0004: the first call a newly configured client makes, answering the two
 * questions an operator cannot otherwise answer without a server log — which
 * WordPress user this site resolved them as, and what it thinks the request
 * arrived on.
 *
 * IT REPORTS THE CALLER AND ONLY THE CALLER. The declared capability is `read`,
 * which every authenticated subscriber holds, so the identity is taken strictly
 * from `$context->userId` and no user id, login or other selector is accepted as
 * input at all: the input schema is an empty object closed to additions. A
 * selector here would turn the weakest capability on the site into a user
 * enumeration endpoint.
 *
 * IT CANNOT OBSERVE A FAILED AUTHENTICATION, and contains no logic pretending
 * to. REQ-0004's other half — `authentication_failed` for an invalid credential
 * — is already the gateway's: the route's permission callback refuses a request
 * with no resolved user, and ContextFactory throws AuthenticationFailed on the
 * same condition, both upstream of dispatch. A bad credential never reaches this
 * class, so a branch here claiming to detect one could only ever be dead code
 * that an operator would nevertheless read as a guarantee.
 *
 * NOTHING IT RETURNS COMES FROM THE REQUEST. The transport block is assembled
 * from the plugin's own route constants and from the context the gateway built;
 * no header and no `$_SERVER` member is reflected, because reflecting one would
 * place attacker-controlled text into a response envelope.
 */
final class ConnectionCheck {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a future caller reaching the handler by any other route — a direct
	 * invocation, a test, a second dispatcher — still meets the gate. `read` is
	 * the floor deliberately: an onboarding check that only an administrator can
	 * run cannot diagnose the subscriber whose client will not connect.
	 */
	private const CAPABILITY = 'read';

	/**
	 * The wire protocol the gateway speaks, as a stable string for the client.
	 */
	private const PROTOCOL = 'json-rpc-2.0';

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId, $siteId, $clientId and $permissionMode are contract properties this module does not name, and every message here is a literal written for end users.
	/**
	 * Handles a system connection operation.
	 *
	 * `$input` is accepted and ignored rather than absent, because the handler
	 * signature is the one the dispatcher calls for every operation; the schema,
	 * not this method, is what makes the argument list empty.
	 *
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The connection report.
	 *
	 * @throws OperationException When the caller cannot read the site, or when
	 *                            the context's user cannot be resolved.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reporting connection status requires an authenticated site user.',
				'Authenticate with an Application Password for a user who can read this site.'
			);
		}

		$user = get_userdata( $context->userId );

		if ( ! is_object( $user ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The authenticated user could not be resolved on this site.',
				'Retry the call; if it persists, a site administrator should check the user account.'
			);
		}

		return [
			'user'                => [
				'id'          => $context->userId,
				'username'    => isset( $user->user_login ) ? (string) $user->user_login : '',
				'displayName' => isset( $user->display_name ) ? (string) $user->display_name : '',
			],
			'transport'           => [
				'route'          => RestTransport::ROUTE_NAMESPACE . RestTransport::ROUTE,
				'protocol'       => self::PROTOCOL,
				'permissionMode' => $context->permissionMode->value,
				'siteId'         => $context->siteId,
				'clientId'       => $context->clientId,
			],
			'applicationPassword' => $this->applicationPassword(),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Method names in this codebase are camelCase, which WPCS's snake_case rule for functions does not distinguish from a global function.
	/**
	 * Whether this site offers Application Passwords, and whether this request
	 * arrived on one.
	 *
	 * THE UUID IS DELIBERATELY NOT RETURNED. `rest_get_authenticated_app_password()`
	 * answers with the identifier of the credential that authenticated this
	 * request, and a credential identifier in a response envelope is a leak
	 * whatever else the envelope is for. Whether one was used is the diagnostic
	 * an operator needs; which one is not.
	 *
	 * Both functions are guarded: they arrived in WordPress 5.6, and a plugin
	 * that fatals on an older core has turned a diagnostic into an outage.
	 *
	 * @return array<string, bool> Availability and use.
	 */
	private function applicationPassword(): array {
		$available = function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();

		$in_use = function_exists( 'rest_get_authenticated_app_password' )
			&& is_string( rest_get_authenticated_app_password() );

		return [
			'available' => $available,
			'inUse'     => $in_use,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
