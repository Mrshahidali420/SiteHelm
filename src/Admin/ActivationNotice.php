<?php
/**
 * The one notice the console shows after activation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Points a fresh install at the Connect screen, once.
 *
 * After activating, an operator is on the Plugins screen with no idea where
 * the endpoint and credentials live. One notice, shown on the next admin
 * page an operator who can open the console loads, then gone. Loading a
 * console screen counts as having found it. Anything an operator is told
 * twice becomes noise, so the notice is armed at activation and disarmed
 * the first time it is shown or would be redundant.
 *
 * @package SiteHelm
 */
final class ActivationNotice {

	/**
	 * The transient that says activation just happened.
	 */
	public const TRANSIENT = 'sitehelm_activated';

	/**
	 * How long the notice waits for an operator before giving up, in seconds.
	 */
	public const TTL = 600;

	/**
	 * Arm the notice. Called from the activation hook.
	 */
	public static function arm(): void {
		set_transient( self::TRANSIENT, 1, self::TTL );
	}

	/**
	 * Register the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Show the notice once, to someone who can act on it, off the console.
	 */
	public function render(): void {
		if ( false === get_transient( self::TRANSIENT ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		delete_transient( self::TRANSIENT );

		if ( self::on_console() ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible sitehelm-activation-notice"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
			esc_html__( 'SiteHelm is active.', 'sitehelm' ),
			esc_html__( 'Connect a client to start working on this site: the Connect screen has the endpoint and issues the credentials.', 'sitehelm' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT ) ),
			esc_html__( 'Open Connect', 'sitehelm' )
		);
	}

	/**
	 * Whether the current admin page is one of the console's own.
	 */
	private static function on_console(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return is_object( $screen ) && isset( $screen->id ) && AdminMenu::is_console_screen( (string) $screen->id );
	}
}
