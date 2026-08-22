<?php
/**
 * The Licence section SiteHelm Pro adds to the Health tab.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Admin;

use SiteHelm\Admin\Ui;
use SiteHelm\Pro\Licence\Licence;

/**
 * Says in one sentence whether Pro is licensed here, and offers the key form.
 */
final class LicenceSection {

	/**
	 * Wire the store.
	 *
	 * @param Licence $licence The licence store.
	 */
	public function __construct( private readonly Licence $licence ) {
	}

	/**
	 * Render the section; called on `sitehelm_status_sections`.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ LicenceAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ LicenceAction::ARG_STATE ] ) ) : '';

		Ui::section_open(
			__( 'SiteHelm Pro licence', 'sitehelm-pro' ),
			__( 'Pro operations run only while a licence for this site is active. The key is checked on this site; nothing is sent anywhere.', 'sitehelm-pro' )
		);

		if ( LicenceAction::STATE_SAVED === $state ) {
			self::note( 'ok', __( 'Licence key saved.', 'sitehelm-pro' ) );
		} elseif ( LicenceAction::STATE_REMOVED === $state ) {
			self::note( 'ok', __( 'Licence key removed.', 'sitehelm-pro' ) );
		}

		$licence_state = $this->licence->state();
		self::note( Licence::STATE_ACTIVE === $licence_state ? 'ok' : 'waiting', $this->sentence( $licence_state ) );

		printf( '<form method="post" action="%s" class="sitehelm-inline-form sitehelm-licence">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( LicenceAction::NONCE );
		printf(
			'<input type="hidden" name="action" value="%1$s"><label for="sitehelm-pro-licence-key">%2$s</label>'
				. '<input type="text" id="sitehelm-pro-licence-key" name="%3$s" value="%4$s" autocomplete="off" spellcheck="false" size="48">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--small">%5$s</button>',
			esc_attr( LicenceAction::ACTION ),
			esc_html__( 'Licence key', 'sitehelm-pro' ),
			esc_attr( LicenceAction::FIELD_KEY ),
			esc_attr( $this->licence->key() ),
			esc_html__( 'Save', 'sitehelm-pro' )
		);
		if ( Licence::STATE_MISSING !== $licence_state ) {
			printf(
				'<button type="submit" name="%1$s" value="1" class="sitehelm-btn sitehelm-btn--small">%2$s</button>',
				esc_attr( LicenceAction::FIELD_REMOVE ),
				esc_html__( 'Remove', 'sitehelm-pro' )
			);
		}
		echo '</form>';

		Ui::section_close();
	}

	/**
	 * The plain sentence for a licence state.
	 *
	 * @param string $state A Licence::STATE_* value.
	 */
	private function sentence( string $state ): string {
		switch ( $state ) {
			case Licence::STATE_ACTIVE:
				$payload = $this->licence->payload();
				if ( null !== $payload && null !== $payload['exp'] ) {
					/* translators: %s: a date. */
					return sprintf( __( 'Pro is active on this site until %s.', 'sitehelm-pro' ), $payload['exp'] );
				}
				return __( 'Pro is active on this site.', 'sitehelm-pro' );
			case Licence::STATE_EXPIRED:
				return __( 'This licence has expired. Pro operations are paused until it is renewed.', 'sitehelm-pro' );
			case Licence::STATE_OTHER_SITE:
				/* translators: %s: this site's host name. */
				return sprintf( __( 'This licence was issued for a different site; this site is %s.', 'sitehelm-pro' ), Licence::host() );
			case Licence::STATE_INVALID:
				return __( 'This key is not a valid SiteHelm Pro licence. Check it was pasted whole.', 'sitehelm-pro' );
			default:
				return __( 'No licence key yet. Paste the key from your purchase to turn Pro on.', 'sitehelm-pro' );
		}
	}

	/**
	 * A status note in the console's own style.
	 *
	 * @param string $tone ok or waiting.
	 * @param string $text The sentence.
	 */
	private static function note( string $tone, string $text ): void {
		printf( '<div class="sitehelm-note sitehelm-note--%s" role="status"><p>%s</p></div>', esc_attr( $tone ), esc_html( $text ) );
	}
}
