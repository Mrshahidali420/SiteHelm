<?php
/**
 * The "Issued credentials" section of the Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Lists every SiteHelm credential the operator can see, each with a Revoke button.
 *
 * A credential minted from Connect is only ever shown once, so until now the
 * screen had no answer to "which clients can still reach this site?". This
 * section answers it from WordPress's own application-password store: which
 * account each credential acts as, when it was made, when it was last used, and
 * one button to take it back.
 *
 * @package SiteHelm
 */
final class CredentialsPanel {

	/**
	 * The credential store.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructs the panel.
	 *
	 * @param Credentials $credentials The store.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Render the section.
	 *
	 * @param array<int, object> $users The accounts this person may act for.
	 */
	public function render( array $users ): void {
		Ui::section_open(
			__( 'Issued credentials', 'sitehelm' ),
			__( 'Every application password SiteHelm has created for the accounts you can act for. Revoking one cuts that client off at WordPress sign-in; nothing already recorded is touched.', 'sitehelm' )
		);

		$this->render_notice();

		$rows = $this->credentials->for_users( $users );

		if ( [] === $rows ) {
			Ui::empty_state(
				__( 'No credentials yet', 'sitehelm' ),
				__( 'Create one above and it will be listed here with the account it acts as.', 'sitehelm' )
			);
			Ui::section_close();
			return;
		}

		printf(
			'<div class="sitehelm-scroll"><table class="sitehelm-table sitehelm-credentials"><thead><tr>'
				. '<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th>'
				. '</tr></thead><tbody>',
			esc_html__( 'Acts as', 'sitehelm' ),
			esc_html__( 'Created', 'sitehelm' ),
			esc_html__( 'Last used', 'sitehelm' ),
			esc_html__( 'Revoke', 'sitehelm' )
		);

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</tbody></table></div>';

		Ui::section_close();
	}

	/**
	 * The outcome of a revocation just carried back in the URL, if any.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome the handler put in the URL; it grants nothing and changes nothing.
		$state = isset( $_GET[ RevokeAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RevokeAction::ARG_STATE ] ) ) : '';

		if ( RevokeAction::STATE_DONE === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
				esc_html__( 'Credential revoked. That client can no longer sign in.', 'sitehelm' )
			);
			return;
		}

		if ( RevokeAction::STATE_FAILED === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--refused" role="alert"><p>%s</p></div>',
				esc_html__( 'That credential could not be revoked. It may already be gone, or it was not one SiteHelm created.', 'sitehelm' )
			);
		}
	}

	/**
	 * One credential.
	 *
	 * @param array{user_id: int, login: string, uuid: string, created: int, last_used: int, last_ip: string} $row The credential.
	 */
	private function render_row( array $row ): void {
		$last_used = $row['last_used'] > 0
			? sprintf(
				/* translators: 1: how long ago, such as "5 minutes". */
				__( '%1$s ago', 'sitehelm' ),
				human_time_diff( $row['last_used'] )
			)
			: __( 'Never', 'sitehelm' );

		printf(
			'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>',
			esc_html( $row['login'] ),
			esc_html( $row['created'] > 0 ? wp_date( 'Y-m-d H:i', $row['created'] ) : '—' ),
			esc_html( $last_used )
		);

		$this->render_button( $row['user_id'], $row['uuid'] );

		echo '</td></tr>';
	}

	/**
	 * The Revoke form for one credential.
	 *
	 * @param int    $user_id The account holding it.
	 * @param string $uuid    The password's uuid.
	 */
	private function render_button( int $user_id, string $uuid ): void {
		printf(
			'<form method="post" action="%s" class="sitehelm-inline-form sitehelm-inline-form--flush">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( RevokeAction::NONCE );

		printf(
			'<input type="hidden" name="action" value="%s"><input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--small sitehelm-btn--danger">%s</button></form>',
			esc_attr( RevokeAction::ACTION ),
			esc_attr( RevokeAction::FIELD_USER ),
			esc_attr( (string) $user_id ),
			esc_attr( RevokeAction::FIELD_UUID ),
			esc_attr( $uuid ),
			esc_html__( 'Revoke', 'sitehelm' )
		);
	}
}
