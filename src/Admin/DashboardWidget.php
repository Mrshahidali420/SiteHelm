<?php
/**
 * The SiteHelm widget on the wp-admin Dashboard.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Storage\AuditStore;

/**
 * One glance at the Dashboard answers: can clients write, how many credentials
 * exist, and what was done most recently.
 *
 * The console has five screens; an operator who opens wp-admin to do something
 * else should not have to visit any of them to know whether an AI client has
 * been busy. The widget states three facts and links each to the screen that
 * explains it. It never offers a control: every switch stays on the console,
 * behind its own nonce and its own confirmation.
 *
 * @package SiteHelm
 */
final class DashboardWidget {

	/**
	 * The widget id WordPress keys it by.
	 */
	public const ID = 'sitehelm_dashboard';

	/**
	 * The screen hook suffix of the Dashboard, where the console styles are also needed.
	 */
	public const HOOK_SUFFIX = 'index.php';

	/**
	 * How many recent operations are shown.
	 */
	public const RECENT = 5;

	/**
	 * The audit store.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * The credential store.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructs the widget.
	 *
	 * @param AuditStore|null  $store       The audit store; null for the default.
	 * @param Credentials|null $credentials The credential store; null for the WordPress-backed one.
	 */
	public function __construct( ?AuditStore $store = null, ?Credentials $credentials = null ) {
		$this->store       = $store ?? new AuditStore();
		$this->credentials = $credentials ?? new Credentials();
	}

	/**
	 * Register the widget, for people who may see the console at all.
	 *
	 * Bound to `wp_dashboard_setup`.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'SiteHelm', 'sitehelm' ), [ $this, 'render' ] );
	}

	/**
	 * Render the widget body.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		echo '<div class="sitehelm-widget">';

		$this->render_facts();
		$this->render_recent();

		echo '</div>';
	}

	/**
	 * Write access and issued credentials, each linked to where it is managed.
	 */
	private function render_facts(): void {
		$paused      = WriteModeAction::is_paused();
		$credentials = count( $this->credentials->for_users( ConnectScreen::selectable_users() ) );

		echo '<ul class="sitehelm-widget__facts">';

		printf(
			'<li><a href="%s">%s</a>%s</li>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS ) ),
			$paused ? esc_html__( 'Writes paused', 'sitehelm' ) : esc_html__( 'Writes allowed', 'sitehelm' ),
			Ui::badge( $paused ? 'waiting' : 'ok', $paused ? __( 'Paused', 'sitehelm' ) : __( 'Open', 'sitehelm' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes.
		);

		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT ) ),
			esc_html(
				sprintf(
					/* translators: %s: number of credentials. */
					_n( '%s credential issued', '%s credentials issued', $credentials, 'sitehelm' ),
					number_format_i18n( $credentials )
				)
			)
		);

		echo '</ul>';
	}

	/**
	 * The most recent operations, newest first, linking to the full log.
	 */
	private function render_recent(): void {
		$rows = $this->store->query( [], self::RECENT, 0 );

		if ( [] === $rows ) {
			printf(
				'<p class="sitehelm-widget__empty">%s</p>',
				esc_html__( 'No client has performed an operation yet.', 'sitehelm' )
			);
			return;
		}

		echo '<ol class="sitehelm-widget__recent">';

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</ol>';

		printf(
			'<p class="sitehelm-widget__more"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_ACTIVITY ) ),
			esc_html__( 'See all activity', 'sitehelm' )
		);
	}

	/**
	 * One recent operation: when, what, by which client, how it ended.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function render_row( array $row ): void {
		$outcome  = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		$recorded = isset( $row['recorded_at'] ) ? (int) $row['recorded_at'] : 0;
		$when     = $recorded > 0 ? (string) wp_date( 'Y-m-d H:i', $recorded ) : '';
		$client   = isset( $row['client_id'] ) ? (string) $row['client_id'] : '';

		printf(
			'<li><span class="sitehelm-widget__time">%s</span> <code>%s</code> <span class="sitehelm-widget__who">%s</span> %s</li>',
			esc_html( $when ),
			esc_html( isset( $row['operation_id'] ) ? (string) $row['operation_id'] : '' ),
			esc_html( $client ),
			Ui::badge( ActivityScreen::tone_for( $outcome ), ActivityScreen::label_for( $outcome ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes.
		);
	}
}
