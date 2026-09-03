<?php
/**
 * The banner shown while SiteHelm Pro is installed and unlicensed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Says, on every admin screen, that the add-on is installed and locked.
 *
 * An add-on sitting unlicensed is a paid plugin doing nothing, and the only
 * place WordPress mentions it is the Plugins list — a screen somebody visits
 * once, on the day they install. Somebody who bought a licence and has not
 * entered it should not have to go looking for where it goes, so the banner
 * follows them and carries the field itself.
 *
 * It does not dismiss. A dismissible notice for a state that persists is a
 * notice that vanishes while the problem stays, and the state ends the moment
 * the key is entered — at which point the banner ends with it. It is shown only
 * to somebody who could act on it, and never on the Upgrade screen, where the
 * same field is already the body of the page.
 *
 * @package SiteHelm
 */
final class UnlicensedNotice {

	/**
	 * The add-on's state, as the console reads it.
	 *
	 * @var ProCatalogue
	 */
	private ProCatalogue $pro;

	/**
	 * Constructs the notice.
	 *
	 * @param ProCatalogue|null $pro The add-on's state; null probes the site.
	 */
	public function __construct( ?ProCatalogue $pro = null ) {
		$this->pro = $pro ?? new ProCatalogue();
	}

	/**
	 * Register the banner and the dialog it opens.
	 *
	 * The dialog is printed into the footer of whatever page the banner is on,
	 * because the SDK's script binds the trigger to a dialog in the same
	 * document. {@see LicenceDialog::print_dialog()} skips the Plugins screens,
	 * where the SDK prints its own.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_footer', [ LicenceDialog::class, 'print_dialog' ] );
	}

	/**
	 * Show the banner while there is something to say.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		if ( ProCatalogue::STATE_UNLICENSED !== (string) $this->pro->probe()['state'] ) {
			return;
		}

		if ( self::on_upgrade_screen() ) {
			return;
		}

		$activate = LicenceDialog::is_available()
			? LicenceDialog::trigger( __( 'Enter licence key', 'sitehelm' ), 'button button-primary' )
			: '';

		printf(
			'<div class="notice notice-warning sitehelm-licence-notice"><p><strong>%1$s</strong> %2$s</p><p>%3$s <a class="button" href="%4$s">%5$s</a></p></div>',
			esc_html__( 'SiteHelm Pro is installed but not licensed.', 'sitehelm' ),
			esc_html__( 'Everything it adds stays locked until a licence key is entered.', 'sitehelm' ),
			$activate, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- LicenceDialog::trigger() escapes its own markup.
			esc_url( ProCatalogue::upgrade_url() ),
			esc_html__( 'Open Upgrade', 'sitehelm' )
		);
	}

	/**
	 * Whether the page being rendered is the Upgrade screen itself.
	 */
	private static function on_upgrade_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return is_object( $screen )
			&& isset( $screen->id )
			&& str_contains( (string) $screen->id, '_page_' . AdminMenu::PAGE_UPGRADE );
	}
}
