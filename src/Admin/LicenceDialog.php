<?php
/**
 * The licence activation dialog, borrowed onto our own screens.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Puts the add-on's licence field where the console says it is.
 *
 * SiteHelm Pro is a Freemius add-on, and add-ons do not get an account page of
 * their own: `get_account_url()` on the add-on's instance builds a slug nothing
 * registers, so every link the console offered for entering a licence answered
 * "you are not allowed to access this page". The only route that worked was the
 * Activate License link on the Plugins list, which is the one place the console
 * never mentioned.
 *
 * The SDK's own modal is reusable. Its script binds clicks on
 * `.activate-license-trigger.<affix>` anywhere in the document, provided the
 * dialog template has been printed into that page's footer. So rather than
 * linking somewhere, the console prints the dialog and hands out the trigger,
 * and the licence field opens on the screen the person is already looking at.
 *
 * Nothing here reads, stores or forwards a licence key: the field, the request
 * and the result are the SDK's, and this class only decides where the field
 * appears.
 *
 * @package SiteHelm
 */
final class LicenceDialog {

	/**
	 * The class the SDK's script watches for, less the module affix.
	 */
	public const TRIGGER_CLASS = 'activate-license-trigger';

	/**
	 * The affix used when the add-on cannot be asked for its own — the add-on's
	 * slug, which is what the SDK derives it from.
	 */
	public const DEFAULT_AFFIX = 'sitehelm-pro';

	/**
	 * Whether the dialog has already been printed into this page.
	 *
	 * Two dialogs on one page both answer the same click, which submits the
	 * key twice.
	 *
	 * @var bool
	 */
	private static bool $printed = false;

	/**
	 * The add-on's Freemius instance, or null when there is not one.
	 *
	 * @return object|null
	 */
	private static function instance() {
		if ( ! function_exists( 'sitehelm_pro_fs' ) ) {
			return null;
		}

		$fs = sitehelm_pro_fs();

		return is_object( $fs ) ? $fs : null;
	}

	/**
	 * Whether a licence can be entered on this page at all.
	 *
	 * False on a site with no add-on installed — there is nothing to license —
	 * and false against an SDK too old to have the method, in which case the
	 * console falls back to saying where the Plugins-list link is rather than
	 * printing a button that would do nothing.
	 */
	public static function is_available(): bool {
		$fs = self::instance();

		return null !== $fs
			&& method_exists( $fs, '_add_license_activation_dialog_box' )
			&& method_exists( $fs, 'get_unique_affix' );
	}

	/**
	 * The module affix the SDK's script is bound to.
	 */
	public static function affix(): string {
		$fs = self::instance();

		if ( null === $fs || ! method_exists( $fs, 'get_unique_affix' ) ) {
			return self::DEFAULT_AFFIX;
		}

		$affix = (string) $fs->get_unique_affix();

		return '' === $affix ? self::DEFAULT_AFFIX : $affix;
	}

	/**
	 * Print the dialog into the footer of the page being rendered.
	 *
	 * Skipped on the Plugins list, where the SDK prints its own for the
	 * Activate License link; a second copy there would answer the same click.
	 */
	public static function print_dialog(): void {
		if ( self::$printed || ! self::is_available() ) {
			return;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( is_object( $screen ) && in_array( $screen->id ?? '', [ 'plugins', 'plugins-network' ], true ) ) {
				return;
			}
		}

		self::$printed = true;

		$fs = self::instance();

		if ( null !== $fs ) {
			$fs->_add_license_activation_dialog_box();
		}
	}

	/**
	 * A button that opens the licence field.
	 *
	 * The href is a fragment because the SDK's handler cancels the click; a
	 * person with no script gets the fallback sentence instead, which is why
	 * every caller prints one.
	 *
	 * @param string $label   The button's text.
	 * @param string $classes Extra classes for the caller's own styling.
	 */
	public static function trigger( string $label, string $classes = '' ): string {
		return sprintf(
			'<a href="#" class="%s">%s</a>',
			esc_attr( trim( $classes . ' ' . self::TRIGGER_CLASS . ' ' . self::affix() ) ),
			esc_html( $label )
		);
	}

	/**
	 * Where the licence link lives without our screens, said in words.
	 *
	 * Printed alongside every trigger, so a console with no JavaScript still
	 * tells somebody holding a key where to put it.
	 */
	public static function fallback_sentence(): string {
		return __( 'You can also enter it from the Plugins screen: find SiteHelm Pro in the list and choose Activate License.', 'sitehelm' );
	}

	/**
	 * Forget that the dialog was printed. For tests, which render many pages.
	 */
	public static function reset(): void {
		self::$printed = false;
	}
}
