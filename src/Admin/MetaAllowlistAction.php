<?php
/**
 * Choosing which custom fields SiteHelm may write, from the Status screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Modules\Core\ContentFields;

/**
 * Answers the "Custom fields" form on the Status screen.
 *
 * SiteHelm will not write a custom field nobody has named. Until this screen
 * existed there was no way to name one, so `content-meta-update` refused every
 * request on every site while still listing itself as available. The list this
 * form saves is the same list {@see ContentFields::allowlist()} reads, so what
 * an owner types here is exactly what a connected client may write.
 *
 * A field is a name, one per line. Anything that is not a name SiteHelm can
 * write is dropped rather than saved, and the screen says how many were
 * dropped, so a typo does not sit in the list looking like it works. Clearing
 * the box clears the list, which is the only way to take a field back.
 *
 * @package SiteHelm
 */
final class MetaAllowlistAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_meta_allowlist';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_meta_allowlist';

	/**
	 * The form field carrying the field names, one per line.
	 */
	public const FIELD = 'sitehelm_meta_allowlist_keys';

	/**
	 * The query argument the Status screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_fields';

	/**
	 * The query argument carrying how many entries were dropped.
	 */
	public const ARG_IGNORED = 'sitehelm_fields_ignored';

	/**
	 * The one outcome the Status screen renders.
	 */
	public const STATE_SAVED = 'saved';

	/**
	 * The most entries this form will accept in one save.
	 */
	private const MAX_KEYS = 200;

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
	 * The field names saved on this screen.
	 *
	 * This is what the owner typed. It is not necessarily everything a client
	 * may write: a theme or plugin can add its own fields through the
	 * `sitehelm_meta_allowlist` filter, and those belong to the code that
	 * declared them rather than to this form. The screen shows both, separately,
	 * so nobody tries to delete a field from a box that never held it.
	 *
	 * @return string[] The saved names in ascending order.
	 */
	public static function saved(): array {
		$stored = get_option( ContentFields::META_ALLOWLIST_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$keys = [];
		foreach ( $stored as $key ) {
			if ( is_string( $key ) && ContentFields::is_writable_field_name( $key ) ) {
				$keys[] = $key;
			}
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Answer the POST.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$raw = isset( $_POST[ self::FIELD ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : '';

		$kept    = [];
		$ignored = 0;

		$lines = preg_split( '/[\r\n,]+/', $raw );
		if ( ! is_array( $lines ) ) {
			$lines = [];
		}

		foreach ( $lines as $line ) {
			$name = trim( (string) $line );
			if ( '' === $name ) {
				continue;
			}

			if ( ! ContentFields::is_writable_field_name( $name ) || count( $kept ) >= self::MAX_KEYS ) {
				++$ignored;
				continue;
			}

			$kept[] = $name;
		}

		$kept = array_values( array_unique( $kept ) );
		sort( $kept, SORT_STRING );

		update_option( ContentFields::META_ALLOWLIST_OPTION, $kept );

		$url = add_query_arg(
			self::ARG_STATE,
			self::STATE_SAVED,
			admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS )
		);

		if ( $ignored > 0 ) {
			$url = add_query_arg( self::ARG_IGNORED, (string) $ignored, $url );
		}

		( $this->redirect )( $url );
	}
}
