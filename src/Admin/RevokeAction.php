<?php
/**
 * Revoking a SiteHelm credential from the Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Answers the "Revoke" button on a listed credential.
 *
 * The boundary is the same one the Connect screen's picker enforces when a
 * credential is minted: the account must be this person's own, or one they may
 * edit. A forged POST naming some other administrator's account is refused
 * whatever the page offered, and a uuid that names a password this plugin did
 * not mint is refused by {@see Credentials::revoke()}.
 *
 * @package SiteHelm
 */
final class RevokeAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_revoke_password';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_revoke_password';

	/**
	 * Form fields: which account, which password.
	 */
	public const FIELD_USER = 'sitehelm_revoke_user';
	public const FIELD_UUID = 'sitehelm_revoke_uuid';

	/**
	 * The query argument the Connect screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_revoked';

	/**
	 * Outcomes the Connect screen renders.
	 */
	public const STATE_DONE   = 'done';
	public const STATE_FAILED = 'failed';

	/**
	 * The credential store.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param Credentials|null $credentials The store; null for the WordPress-backed one.
	 * @param callable|null    $redirect    Redirects and exits; null for the WordPress default.
	 */
	public function __construct( ?Credentials $credentials = null, ?callable $redirect = null ) {
		$this->credentials = $credentials ?? new Credentials();
		$this->redirect    = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Answer the POST.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$user_id = isset( $_POST[ self::FIELD_USER ] ) ? absint( wp_unslash( (string) $_POST[ self::FIELD_USER ] ) ) : 0;
		$uuid    = isset( $_POST[ self::FIELD_UUID ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_UUID ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You may not revoke a credential for that account.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		$revoked = $this->credentials->revoke( $user_id, $uuid );

		$this->go_back( $revoked ? self::STATE_DONE : self::STATE_FAILED );
	}

	/**
	 * Return to the Connect screen, naming the outcome.
	 *
	 * @param string $state The outcome to report.
	 */
	private function go_back( string $state ): void {
		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT );

		( $this->redirect )( add_query_arg( self::ARG_STATE, $state, $url ) );
	}
}
