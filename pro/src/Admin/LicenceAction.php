<?php
/**
 * The admin-post handler that stores or removes the Pro licence key.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Pro\Licence\Licence;

/**
 * Saves what the Licence section's form posts and redirects back to Health
 * with a state word the section turns into a sentence.
 */
final class LicenceAction {

	public const ACTION        = 'sitehelm_pro_licence';
	public const NONCE         = 'sitehelm_pro_licence';
	public const FIELD_KEY     = 'sitehelm_pro_licence_key';
	public const FIELD_REMOVE  = 'sitehelm_pro_licence_remove';
	public const ARG_STATE     = 'sitehelm_pro_licence';
	public const STATE_SAVED   = 'saved';
	public const STATE_REMOVED = 'removed';

	/**
	 * Redirect-and-exit.
	 *
	 * @var callable(string): void
	 */
	private $redirect;

	/**
	 * Wire the store and the redirect.
	 *
	 * @param Licence       $licence  The licence store.
	 * @param callable|null $redirect Redirect-and-exit; the real one by default.
	 */
	public function __construct( private readonly Licence $licence, ?callable $redirect = null ) {
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Handle the POST.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm-pro' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		if ( isset( $_POST[ self::FIELD_REMOVE ] ) ) {
			$this->licence->remove();
			( $this->redirect )( add_query_arg( self::ARG_STATE, self::STATE_REMOVED, $url ) );
			return;
		}

		$key = isset( $_POST[ self::FIELD_KEY ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_KEY ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// An empty field is no request at all; the stored key is left as it was.
		if ( '' === $key ) {
			( $this->redirect )( $url );
			return;
		}

		$this->licence->save( $key );
		( $this->redirect )( add_query_arg( self::ARG_STATE, self::STATE_SAVED, $url ) );
	}
}
