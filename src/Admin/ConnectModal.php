<?php
/**
 * The one thing a new owner has to do, asked once.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The first-run dialog: connect an app, or say not now.
 *
 * ONE JOB. A plugin nothing can reach does nothing at all, so the console asks
 * for exactly that and nothing else. Everything a person might do afterwards --
 * narrowing what an app may touch, making a test call, undoing a change -- is
 * worth doing and none of it is required, so none of it is in here. It lives
 * further down Home as a list with no numbering and no tally, because a
 * numbered list of five reads as five obligations.
 *
 * THIS ONE IS REMEMBERED, unlike everything else on Home. A dialog that opens
 * over the screen has to be dismissible for good, which means a flag. It is
 * stored per user rather than per site: a second administrator arriving later
 * has not seen it, and inheriting someone else's dismissal would hide the only
 * instruction they need. Nothing clears the flag; a person who wants the dialog
 * again has the Connect tab, which is the same destination.
 *
 * The connected test is deliberately live rather than a stored "onboarded"
 * flag. Revoke every credential and the dialog is offered again, which is the
 * correct reading of a site nothing can reach -- unless that administrator
 * already dismissed it, in which case their answer stands.
 *
 * @package SiteHelm
 */
final class ConnectModal {

	/**
	 * The per-user flag saying this person closed the dialog.
	 */
	public const DISMISSED_META = 'sitehelm_connect_modal_dismissed';

	/**
	 * The id the dialog answers to, for the script that opens it.
	 */
	public const DIALOG_ID = 'sitehelm-connect-modal';

	/**
	 * Whether the dialog should open at all.
	 *
	 * Pure, and the whole rule: something can already reach this site, or this
	 * person said not now. Either one is a no.
	 *
	 * @param bool $connected Whether any client can reach this site.
	 * @param bool $dismissed Whether this administrator closed the dialog before.
	 */
	public static function should_open( bool $connected, bool $dismissed ): bool {
		return ! $connected && ! $dismissed;
	}

	/**
	 * Whether this administrator has closed the dialog.
	 *
	 * @param int $user_id The administrator being asked about.
	 */
	public static function is_dismissed( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::DISMISSED_META, true );
	}

	/**
	 * Whether anything can reach this site.
	 *
	 * A credential that exists, or a browser sign-in that has happened. The
	 * audit log is not consulted: rows there prove something reached the site
	 * once, not that anything still can, and the dialog is about now.
	 *
	 * @param Credentials|null $credentials The credential store; null builds one.
	 */
	public static function is_connected( ?Credentials $credentials = null ): bool {
		if ( self::oauth_seen() ) {
			return true;
		}

		if ( null === $credentials && ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}

		$store = $credentials ?? new Credentials();

		return [] !== $store->for_users( ConnectScreen::selectable_users() );
	}

	/**
	 * Whether a bearer or OAuth session has ever authenticated.
	 *
	 * Asked only if the class is here, for the same reason Home asks that way:
	 * a console that fataled because browser sign-in had not landed yet is a
	 * worse trade than a dialog that opens once too often.
	 */
	public static function oauth_seen(): bool {
		$asked = [ 'SiteHelm\\Auth\\OAuthStore', 'has_authenticated' ];

		return is_callable( $asked ) && true === call_user_func( $asked );
	}

	/**
	 * Print the dialog, if this administrator should be shown it.
	 *
	 * Called on every console screen rather than on Home alone: the dialog is
	 * the answer to "nothing can reach this site", and that is just as true
	 * when the person is looking at Permissions.
	 *
	 * @param Credentials|null $credentials The credential store; null builds one.
	 */
	public static function render_if_needed( ?Credentials $credentials = null ): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		self::render(
			self::is_connected( $credentials ),
			self::is_dismissed( get_current_user_id() )
		);
	}

	/**
	 * Print the dialog.
	 *
	 * It is printed closed. The script opens it with `showModal()`, which is
	 * what gives it a backdrop, a focus trap and Escape -- none of which an
	 * `open` attribute provides. A browser running no script shows nothing
	 * rather than an untrapped panel nailed over the screen.
	 *
	 * @param bool $connected Whether any client can reach this site.
	 * @param bool $dismissed Whether this administrator closed the dialog before.
	 */
	public static function render( bool $connected, bool $dismissed ): void {
		if ( ! self::should_open( $connected, $dismissed ) ) {
			return;
		}

		printf(
			'<dialog id="%1$s" class="sitehelm-modal" data-sitehelm-connect-modal aria-labelledby="%1$s-title"><div class="sitehelm-modal__panel">',
			esc_attr( self::DIALOG_ID )
		);

		self::dismiss_form(
			'<button type="submit" class="sitehelm-modal__close">'
				. '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
				. '<span class="sitehelm-srt">' . esc_html__( 'Close and do not ask again', 'sitehelm' ) . '</span>'
				. '</button>'
		);

		printf(
			'<span class="sitehelm-modal__art" aria-hidden="true">%1$s</span><h2 class="sitehelm-modal__title" id="%2$s-title">%3$s</h2><p class="sitehelm-modal__lede">%4$s</p><p class="sitehelm-modal__cta"><a class="sitehelm-btn sitehelm-btn--primary" href="%5$s">%6$s</a></p>',
			self::art(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A literal defined below, no input in it.
			esc_attr( self::DIALOG_ID ),
			esc_html__( 'Connect your first AI app', 'sitehelm' ),
			esc_html__( 'SiteHelm is installed and answering, but nothing can reach it yet. An AI app needs one address and one password from this site, and getting both takes about a minute.', 'sitehelm' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT ) ),
			esc_html__( 'Connect an app', 'sitehelm' )
		);

		self::dismiss_form(
			'<button type="submit" class="sitehelm-modal__later">' . esc_html__( 'Not now', 'sitehelm' ) . '</button>'
		);

		echo '</div></dialog>';
	}

	/**
	 * A form that records the dismissal and comes back to this screen.
	 *
	 * Both ways out of the dialog are this same POST. A close button that only
	 * hid the dialog would reopen it on the next page load, which reads as the
	 * console ignoring the person -- the one thing a panel over the screen must
	 * never do.
	 *
	 * @param string $button The already-escaped button markup.
	 */
	private static function dismiss_form( string $button ): void {
		printf(
			'<form method="post" action="%1$s" class="sitehelm-modal__dismiss"><input type="hidden" name="action" value="%2$s" />',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( ConnectModalAction::ACTION )
		);

		wp_nonce_field( ConnectModalAction::NONCE );

		echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		echo '</form>';
	}

	/**
	 * The illustration, drawn rather than shipped as an image.
	 *
	 * Inline so it inherits the console's colours and costs no request of its
	 * own: two ends and the link between them, which is what the dialog is
	 * asking a person to make.
	 */
	private static function art(): string {
		return '<svg viewBox="0 0 64 40" width="64" height="40" focusable="false">'
			. '<rect x="1" y="9" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>'
			. '<rect x="41" y="9" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>'
			. '<path d="M23 20h18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-dasharray="4 4"/>'
			. '</svg>';
	}
}
