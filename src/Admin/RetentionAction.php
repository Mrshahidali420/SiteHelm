<?php
/**
 * Setting how long SiteHelm keeps its records, from the Status screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Storage\Retention;

/**
 * Answers the "Keep records for N days" form on the Status screen.
 *
 * The number is the one {@see Retention::days()} reads before each scheduled
 * prune, so the console and the pruner can never disagree about it. Anything
 * outside the supported range is clamped, never refused: an operator who types
 * 9999 gets the largest value the pruner would have used anyway, and the screen
 * reports what was kept. An empty field changes nothing.
 *
 * @package SiteHelm
 */
final class RetentionAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_retention';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_retention';

	/**
	 * The form field carrying the number of days.
	 */
	public const FIELD = 'sitehelm_retention_days';

	/**
	 * The query argument the Status screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_retention';

	/**
	 * The one outcome the Status screen renders.
	 */
	public const STATE_SAVED = 'saved';

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
	 * The configured retention window in days, clamped the way the pruner clamps it.
	 */
	public static function days(): int {
		$stored = get_option( Retention::RETENTION_OPTION, Retention::DEFAULT_DAYS );

		return self::clamp( is_numeric( $stored ) ? (int) $stored : Retention::DEFAULT_DAYS );
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
		$wanted = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : 0;

		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS );

		// An empty or non-numeric field is not a request for one day; it is no
		// request at all, and the stored value is left as it was.
		if ( $wanted < 1 ) {
			( $this->redirect )( $url );
			return;
		}

		update_option( Retention::RETENTION_OPTION, self::clamp( $wanted ) );

		( $this->redirect )( add_query_arg( self::ARG_STATE, self::STATE_SAVED, $url ) );
	}

	/**
	 * Bring a number of days into the range the pruner supports.
	 *
	 * @param int $days The requested number of days.
	 */
	private static function clamp( int $days ): int {
		return max( Retention::MIN_DAYS, min( Retention::MAX_DAYS, $days ) );
	}
}
