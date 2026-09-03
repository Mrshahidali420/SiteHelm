<?php
/**
 * Recording that an administrator does not want the connect dialog again.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The "Not now" half of the first-run dialog.
 *
 * A FORM POST RATHER THAN A SCRIPT CALL, which is the console's convention
 * everywhere else and the reason the dismissal survives. A close button that
 * only hid the dialog would have it back on the next page load; a fetch would
 * make the one control the dialog cannot do without depend on JavaScript
 * reaching the network. This posts, stores, and comes back.
 *
 * IT COMES BACK WHERE IT WAS. The dialog can open on any console screen, so the
 * handler returns to the referring screen rather than to Home -- being thrown
 * to the dashboard for closing a dialog reads as a punishment. The referrer is
 * only ever fed to `wp_safe_redirect()`, which refuses a host that is not this
 * site, and Home is the fallback when there is no referrer to trust.
 *
 * The flag is per user. Two administrators get their own answer: one dismissing
 * the dialog must not decide for the other, who has not seen it and still needs
 * the one instruction it carries.
 *
 * @package SiteHelm
 */
final class ConnectModalAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_connect_modal_dismiss';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_connect_modal_dismiss';

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param callable|null $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct( ?callable $redirect = null ) {
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Answer the POST: remember the dismissal, go back.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, ConnectModal::DISMISSED_META, '1' );
		}

		( $this->redirect )( $this->back_to() );
	}

	/**
	 * The screen the dialog was closed on, or Home.
	 */
	private function back_to(): string {
		$referer = wp_get_referer();

		return is_string( $referer ) && '' !== $referer
			? $referer
			: admin_url( 'admin.php?page=' . AdminMenu::PAGE_HOME );
	}
}
