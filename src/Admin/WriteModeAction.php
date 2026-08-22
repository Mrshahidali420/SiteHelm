<?php
/**
 * Pausing and resuming writes from the console.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Gateway\ContextFactory;

/**
 * The console's one switch: whether connected clients may change anything.
 *
 * The gateway already honours a stored permission mode — `read-only` refuses
 * every write at the policy gate before any module runs, and the other modes let
 * writes through the normal preview-then-apply path. What it lacked was a way to
 * set that mode from wp-admin. An operator who sees an agent doing something
 * they do not like should be able to stop every write in one click, without
 * revoking credentials or deactivating the plugin, and turn it back on just as
 * easily once they have looked.
 *
 * It is deliberately two states, not three. The storage distinguishes
 * `safe-write` from `trusted-write`, but nothing in the gateway treats them
 * differently yet, and a control that offered a choice with no effect would be
 * a lie. Resuming writes stores `safe-write`; a site that was already on
 * `trusted-write` keeps it, because pausing and resuming should not quietly
 * change a setting the operator never touched.
 *
 * @package SiteHelm
 */
final class WriteModeAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_write_mode';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_write_mode';

	/**
	 * The form field naming what the operator wants: `pause` or `resume`.
	 */
	public const FIELD = 'sitehelm_write_mode';

	/**
	 * The two things the form can ask for.
	 */
	public const PAUSE  = 'pause';
	public const RESUME = 'resume';

	/**
	 * The query argument the Status screen reads to report what happened.
	 */
	public const ARG_STATE = 'sitehelm_write_mode';

	/**
	 * States the Status screen renders.
	 */
	public const STATE_PAUSED  = 'paused';
	public const STATE_RESUMED = 'resumed';

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
	 * Whether writes are currently paused for every connected client.
	 *
	 * Reads the same option the gateway's context factory reads, with the same
	 * fallback, so the console and the gateway can never disagree about it.
	 */
	public static function is_paused(): bool {
		return PermissionMode::ReadOnly === self::current();
	}

	/**
	 * The stored permission mode, as the gateway will interpret it.
	 */
	public static function current(): PermissionMode {
		$stored = get_option( ContextFactory::MODE_OPTION, PermissionMode::SafeWrite->value );

		return PermissionMode::tryFrom( is_string( $stored ) ? $stored : '' ) ?? PermissionMode::SafeWrite;
	}

	/**
	 * Answer the POST: pause or resume.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$wanted = isset( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : '';

		if ( self::PAUSE === $wanted ) {
			update_option( ContextFactory::MODE_OPTION, PermissionMode::ReadOnly->value );
			$this->go_back( self::STATE_PAUSED );
			return;
		}

		if ( self::RESUME === $wanted ) {
			if ( self::is_paused() ) {
				update_option( ContextFactory::MODE_OPTION, PermissionMode::SafeWrite->value );
			}

			$this->go_back( self::STATE_RESUMED );
			return;
		}

		$this->go_back( '' );
	}

	/**
	 * Return to the Status screen, naming the outcome when there is one.
	 *
	 * @param string $state The state to report, or '' for none.
	 */
	private function go_back( string $state ): void {
		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS );

		if ( '' !== $state ) {
			$url = add_query_arg( self::ARG_STATE, $state, $url );
		}

		( $this->redirect )( $url );
	}
}
