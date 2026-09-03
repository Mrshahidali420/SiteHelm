<?php
/**
 * The apps that have signed in to this site.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\OAuthStore;

/**
 * Lists every app that registered itself against this site, with the two
 * controls that end the relationship.
 *
 * An application password is visible in the user's own profile, so there has
 * always been somewhere to look. A registration made by a client over the
 * network is visible nowhere at all unless this table exists, which would make
 * "which apps can reach my site" a question with no answer on the site itself.
 *
 * A row is shown whether or not the app currently holds a token: a registration
 * with no live tokens is an app that can still ask, and an operator deciding
 * what to remove needs to see it.
 *
 * @package SiteHelm
 */
final class ConnectedAppsPanel {

	/**
	 * The largest page listed. More than this and the table stops being a thing
	 * a person reads and becomes a thing they scroll past.
	 */
	private const LIMIT = 50;

	/**
	 * The registration store.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Returns the current time. Signature: (): int.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructs the panel.
	 *
	 * @param OAuthStore|null $store The registration store; null for a fresh one.
	 * @param callable|null   $now   Returns the current time; null for `time()`.
	 */
	public function __construct( ?OAuthStore $store = null, ?callable $now = null ) {
		$this->store = $store ?? new OAuthStore();
		$this->now   = $now ?? static fn(): int => time();
	}

	/**
	 * Render the section.
	 */
	public function render(): void {
		Ui::section_open(
			__( 'Connected apps', 'sitehelm' ),
			__( 'Apps that signed in to this site. Signing one out throws away the tokens it holds, so it has to ask you again. Removing it deletes the registration as well, so it has to start over.', 'sitehelm' )
		);

		$this->render_notice();

		$rows = $this->store->listClients( ( $this->now )(), self::LIMIT );

		if ( [] === $rows ) {
			Ui::empty_state(
				__( 'No apps have signed in', 'sitehelm' ),
				__( 'An app that signs in registers itself the first time it calls, and appears here with the date it did.', 'sitehelm' )
			);
			Ui::section_close();
			return;
		}

		printf(
			'<div class="sitehelm-scroll"><table class="sitehelm-table sitehelm-apps"><thead><tr>'
				. '<th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th>'
				. '<th scope="col">%4$s</th><th scope="col">%5$s</th>'
				. '</tr></thead><tbody>',
			esc_html__( 'App', 'sitehelm' ),
			esc_html__( 'Registered', 'sitehelm' ),
			esc_html__( 'Last let in', 'sitehelm' ),
			esc_html__( 'Live tokens', 'sitehelm' ),
			esc_html__( 'Actions', 'sitehelm' )
		);

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</tbody></table></div>';

		Ui::section_close();
	}

	/**
	 * The outcome of an action just carried back in the URL, if any.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome the handler put in the URL; it grants nothing and changes nothing.
		$state = isset( $_GET[ ConnectedAppsAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ ConnectedAppsAction::ARG_STATE ] ) ) : '';

		$said = [
			ConnectedAppsAction::STATE_SIGNED_OUT => [
				'ok',
				__( 'Signed out. That app keeps its registration but holds no tokens, so its next call will ask you to approve it again.', 'sitehelm' ),
			],
			ConnectedAppsAction::STATE_REMOVED    => [
				'ok',
				__( 'Removed. That app has no registration and no tokens, so it has to register from scratch before it can ask for anything.', 'sitehelm' ),
			],
			ConnectedAppsAction::STATE_UNKNOWN    => [
				'refused',
				__( 'Nothing was changed: no app on this site has that registration. It may already have been removed in another tab.', 'sitehelm' ),
			],
		];

		if ( ! isset( $said[ $state ] ) ) {
			return;
		}

		printf(
			'<div class="sitehelm-note sitehelm-note--%1$s" role="%2$s"><p>%3$s</p></div>',
			esc_attr( $said[ $state ][0] ),
			'ok' === $said[ $state ][0] ? 'status' : 'alert',
			esc_html( $said[ $state ][1] )
		);
	}

	/**
	 * One registration.
	 *
	 * @param array<string, mixed> $row A row from {@see OAuthStore::listClients()}.
	 */
	private function render_row( array $row ): void {
		$client_id = (string) ( $row['client_id'] ?? '' );
		$name      = (string) ( $row['client_name'] ?? '' );

		printf(
			'<tr><td>%1$s<span class="sitehelm-table__sub"><code>%2$s</code></span></td><td>%3$s</td>'
				. '<td>%4$s</td><td class="sitehelm-table__num">%5$s</td><td>',
			esc_html( '' !== $name ? $name : __( 'Unnamed app', 'sitehelm' ) ),
			esc_html( $client_id ),
			esc_html( $this->stamp( (int) ( $row['created_at'] ?? 0 ) ) ),
			esc_html( $this->ago( (int) ( $row['last_token_at'] ?? 0 ) ) ),
			esc_html( (string) (int) ( $row['live_tokens'] ?? 0 ) )
		);

		$this->render_button(
			ConnectedAppsAction::ACTION_SIGN_OUT,
			$client_id,
			__( 'Sign out', 'sitehelm' ),
			/* translators: %s: the app's name. */
			__( 'Sign out %s?', 'sitehelm' ),
			$name,
			false
		);

		$this->render_button(
			ConnectedAppsAction::ACTION_REMOVE,
			$client_id,
			__( 'Remove', 'sitehelm' ),
			/* translators: %s: the app's name. */
			__( 'Remove %s?', 'sitehelm' ),
			$name,
			true
		);

		echo '</td></tr>';
	}

	/**
	 * One button, with the wording its second press carries.
	 *
	 * The confirmation is the button's own second state rather than a browser
	 * dialog: a dialog cannot say which app it is about, and a person who has
	 * dismissed one out of habit has no way of telling what they agreed to.
	 * With scripting off the single press does the job, which is the same
	 * bargain every other control on this screen makes.
	 *
	 * @param string $action    The `admin_post` action to submit to.
	 * @param string $client_id The registration to act on.
	 * @param string $label     What the button says at rest.
	 * @param string $pattern   The confirmation wording, with one `%s` for the name.
	 * @param string $name      The app's name.
	 * @param bool   $danger    Whether the control destroys the registration.
	 */
	private function render_button( string $action, string $client_id, string $label, string $pattern, string $name, bool $danger ): void {
		printf(
			'<form method="post" action="%s" class="sitehelm-inline-form sitehelm-inline-form--flush">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( ConnectedAppsAction::NONCE );

		printf(
			'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="%2$s" value="%3$s">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--small%4$s" data-sitehelm-confirm="%5$s">%6$s</button></form>',
			esc_attr( $action ),
			esc_attr( ConnectedAppsAction::FIELD_CLIENT ),
			esc_attr( $client_id ),
			$danger ? ' sitehelm-btn--danger' : '',
			esc_attr( sprintf( $pattern, '' !== $name ? $name : __( 'this app', 'sitehelm' ) ) ),
			esc_html( $label )
		);
	}

	/**
	 * A stored time as a date, or an em dash when there is none.
	 *
	 * @param int $when The timestamp.
	 *
	 * @return string The rendered date.
	 */
	private function stamp( int $when ): string {
		return $when > 0 ? (string) wp_date( 'Y-m-d H:i', $when ) : '—';
	}

	/**
	 * A stored time as an interval, or "Never".
	 *
	 * @param int $when The timestamp.
	 *
	 * @return string The rendered interval.
	 */
	private function ago( int $when ): string {
		if ( $when <= 0 ) {
			return __( 'Never', 'sitehelm' );
		}

		return sprintf(
			/* translators: %s: how long ago, such as "5 minutes". */
			__( '%s ago', 'sitehelm' ),
			human_time_diff( $when, ( $this->now )() )
		);
	}
}
