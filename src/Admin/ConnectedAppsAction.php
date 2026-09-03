<?php
/**
 * Signing an app out, and removing it altogether.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\OAuthStore;

/**
 * Answers the two buttons on a row of the Connected apps table.
 *
 * They are deliberately different errands. Signing out throws away the tokens
 * an app is holding, so the next call it makes sends the operator back through
 * the approval page — the fix for a laptop left in a hotel. Removing deletes
 * the registration as well, so the app has to register from scratch before it
 * can even ask — the fix for an app that should never have been here.
 *
 * One handler answers both because the capability check, the nonce, the client
 * lookup and the redirect are identical; only the last line differs. Two
 * classes would have been two copies of the guard, and a guard that exists
 * twice is a guard that gets fixed once.
 *
 * @package SiteHelm
 */
final class ConnectedAppsAction {

	/**
	 * The `admin_post` actions this handler answers.
	 */
	public const ACTION_SIGN_OUT = 'sitehelm_signout_app';
	public const ACTION_REMOVE   = 'sitehelm_remove_app';

	/**
	 * The nonce action both forms carry.
	 */
	public const NONCE = 'sitehelm_connected_app';

	/**
	 * The form field naming the registration to act on.
	 */
	public const FIELD_CLIENT = 'sitehelm_client_id';

	/**
	 * The query argument the Connect screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_app';

	/**
	 * Outcomes the Connect screen renders.
	 */
	public const STATE_SIGNED_OUT = 'signed_out';
	public const STATE_REMOVED    = 'removed';
	public const STATE_UNKNOWN    = 'unknown';

	/**
	 * The registration store.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param OAuthStore|null $store    The registration store; null for a fresh one.
	 * @param callable|null   $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct( ?OAuthStore $store = null, ?callable $redirect = null ) {
		$this->store    = $store ?? new OAuthStore();
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Answer the "Sign out" POST: the registration stays, its tokens do not.
	 */
	public function handle_sign_out(): void {
		$client_id = $this->accept();

		if ( null === $client_id ) {
			return;
		}

		$this->store->deleteTokensForClient( $client_id );

		$this->go_back( self::STATE_SIGNED_OUT );
	}

	/**
	 * Answer the "Remove" POST: the registration goes too.
	 */
	public function handle_remove(): void {
		$client_id = $this->accept();

		if ( null === $client_id ) {
			return;
		}

		$this->store->deleteClient( $client_id );

		$this->go_back( self::STATE_REMOVED );
	}

	/**
	 * Check the request and return the registration it names.
	 *
	 * A client id that does not exist ends the request here rather than at the
	 * store, because "we deleted nothing" and "we deleted it" are the same
	 * return value from a delete, and the operator deserves to be told which
	 * one happened.
	 *
	 * @return string|null The registered identifier, or null when the request
	 *                     named none and has already been sent back.
	 */
	private function accept(): ?string {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$raw = isset( $_POST[ self::FIELD_CLIENT ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_CLIENT ] ) ) : '';

		if ( '' === $raw || null === $this->store->findClient( $raw ) ) {
			$this->go_back( self::STATE_UNKNOWN );

			return null;
		}

		return $raw;
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
