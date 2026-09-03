<?php
/**
 * The settings behind signing in.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\Discovery;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Auth\PublicUrl;

/**
 * The switch, the address, and the button that proves the address works.
 *
 * A client is given one address and finds everything else from it, so an
 * address that is subtly wrong fails in a way nobody can see from inside the
 * admin: every page here looks right, and every app is told to go somewhere it
 * cannot reach. The three addresses derived from the override are therefore
 * printed rather than described, and the test fetches them the way a client
 * would.
 *
 * @package SiteHelm
 */
final class AuthSettingsPanel {

	/**
	 * The address resolver.
	 *
	 * @var PublicUrl
	 */
	private PublicUrl $urls;

	/**
	 * The enable flag.
	 *
	 * @var AuthSettings
	 */
	private AuthSettings $settings;

	/**
	 * Constructs the panel.
	 *
	 * @param PublicUrl|null    $urls     The address resolver; null for a fresh one.
	 * @param AuthSettings|null $settings The enable flag; null for a fresh one.
	 */
	public function __construct( ?PublicUrl $urls = null, ?AuthSettings $settings = null ) {
		$this->urls     = $urls ?? new PublicUrl();
		$this->settings = $settings ?? new AuthSettings( $this->urls );
	}

	/**
	 * Render the section.
	 */
	public function render(): void {
		Ui::section_open(
			__( 'Settings', 'sitehelm' ),
			__( 'Whether apps may sign in, and the address this site gives them when they do.', 'sitehelm' )
		);

		$this->render_notice();

		printf(
			'<form method="post" action="%s" class="sitehelm-settings-form"><div class="sitehelm-panel"><div class="sitehelm-panel__body">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( AuthSettingsAction::NONCE );

		printf( '<input type="hidden" name="action" value="%s">', esc_attr( AuthSettingsAction::ACTION ) );

		$this->render_switch();
		$this->render_url_field();
		$this->render_derived();
		$this->render_buttons();

		echo '</div></div></form>';

		$this->render_last_test();

		Ui::section_close();
	}

	/**
	 * The one switch.
	 */
	private function render_switch(): void {
		printf(
			'<div class="sitehelm-field"><label class="sitehelm-check"><input type="checkbox" name="%1$s" value="1"%2$s>'
				. '<span>%3$s</span></label><p class="sitehelm-field__hint">%4$s</p></div>',
			esc_attr( AuthSettingsAction::FIELD_ENABLED ),
			$this->settings->enabled() ? ' checked' : '',
			esc_html__( 'Let apps sign in', 'sitehelm' ),
			esc_html__( 'With this off, the sign-in addresses stop answering and every app has to use an application password. Tokens already issued stop being accepted.', 'sitehelm' )
		);
	}

	/**
	 * The address override.
	 */
	private function render_url_field(): void {
		printf(
			'<div class="sitehelm-field"><label class="sitehelm-field__label" for="sitehelm-public-url">%1$s</label>'
				. '<input class="sitehelm-field__input" type="url" id="sitehelm-public-url" name="%2$s" value="%3$s"'
				. ' placeholder="%4$s" spellcheck="false" autocomplete="off">'
				. '<p class="sitehelm-field__hint">%5$s</p></div>',
			esc_html__( 'Server URL', 'sitehelm' ),
			esc_attr( AuthSettingsAction::FIELD_URL ),
			esc_attr( $this->urls->stored() ),
			esc_attr( $this->urls->base() ),
			esc_html__( 'Leave this empty unless the address in WordPress Settings is not the address the outside world uses. Some hosts pin the site address to a staging domain while the site answers on the live one; when that happens, type the live address here. It must be HTTPS.', 'sitehelm' )
		);
	}

	/**
	 * The three addresses everything else is built from.
	 */
	private function render_derived(): void {
		Ui::facts(
			[
				__( 'MCP endpoint', 'sitehelm' )      => $this->urls->mcpEndpoint(),
				__( 'Resource document', 'sitehelm' ) => $this->urls->base() . Discovery::WELL_KNOWN_RESOURCE,
				__( 'Sign-in document', 'sitehelm' )  => $this->urls->base() . Discovery::WELL_KNOWN_SERVER,
			]
		);
	}

	/**
	 * Save, and the check that proves the addresses above are real.
	 */
	private function render_buttons(): void {
		printf(
			'<p class="sitehelm-settings-form__actions">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--primary" name="%1$s" value="%2$s">%3$s</button>'
				. '<button type="submit" class="sitehelm-btn" name="%1$s" value="%4$s">%5$s</button></p>'
				. '<p class="sitehelm-field__hint">%6$s</p>',
			esc_attr( AuthSettingsAction::FIELD_INTENT ),
			esc_attr( AuthSettingsAction::INTENT_SAVE ),
			esc_html__( 'Save settings', 'sitehelm' ),
			esc_attr( AuthSettingsAction::INTENT_TEST ),
			esc_html__( 'Test discovery', 'sitehelm' ),
			esc_html__( 'The test asks this site for its own sign-in documents, over the network, the way an app would. It changes nothing and does not save what you have typed above.', 'sitehelm' )
		);
	}

	/**
	 * The outcome of a save or a test just carried back in the URL.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome this plugin put in the URL; it grants nothing and changes nothing.
		$state = isset( $_GET[ AuthSettingsAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ AuthSettingsAction::ARG_STATE ] ) ) : '';

		$said = [
			AuthSettingsAction::STATE_SAVED    => [
				'ok',
				__( 'Saved. The addresses below are the ones apps will be given from now on.', 'sitehelm' ),
			],
			AuthSettingsAction::STATE_TESTED   => [
				'ok',
				__( 'Tested. What each address returned is below.', 'sitehelm' ),
			],
			AuthSettingsAction::STATE_BAD_URL  => [
				'refused',
				__( 'Nothing was saved: that is not an address this site can hand to an app. It needs the scheme as well as the host, like https://example.com.', 'sitehelm' ),
			],
			AuthSettingsAction::STATE_INSECURE => [
				'refused',
				__( 'Nothing was saved: that address is not HTTPS. An app that signs in is given a token, and a token sent in clear text can be read and reused by anyone on the network in between.', 'sitehelm' ),
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
	 * What the last test found, whenever it was run.
	 */
	private function render_last_test(): void {
		$last = DiscoverySelfTest::last();

		if ( [] === $last ) {
			return;
		}

		printf(
			'<p class="sitehelm-field__hint">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: how long ago, such as "5 minutes". */
					__( 'Last tested %s ago.', 'sitehelm' ),
					human_time_diff( $last['at'], time() )
				)
			)
		);

		echo '<div class="sitehelm-scroll"><table class="sitehelm-table sitehelm-discovery"><tbody>';

		foreach ( $last['rows'] as $row ) {
			$this->render_test_row( $row );
		}

		echo '</tbody></table></div>';
	}

	/**
	 * One address and what it returned.
	 *
	 * @param array<string, mixed> $row A row from {@see DiscoverySelfTest::run()}.
	 */
	private function render_test_row( array $row ): void {
		$outcome = (string) ( $row['outcome'] ?? '' );
		$detail  = (string) ( $row['detail'] ?? '' );

		printf(
			'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
			esc_html( (string) ( $row['url'] ?? '' ) ),
			Ui::badge( self::tone( $outcome ), self::verdict( $outcome ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes its own label.
			esc_html( '' !== $detail ? $detail : __( 'This site answered with its own document.', 'sitehelm' ) )
		);
	}

	/**
	 * The badge tone for an outcome.
	 *
	 * @param string $outcome One of the DiscoverySelfTest outcome constants.
	 */
	private static function tone( string $outcome ): string {
		return DiscoverySelfTest::PASS === $outcome ? 'ok' : 'refused';
	}

	/**
	 * The outcome in a site owner's words.
	 *
	 * @param string $outcome One of the DiscoverySelfTest outcome constants.
	 */
	private static function verdict( string $outcome ): string {
		return match ( $outcome ) {
			DiscoverySelfTest::PASS        => __( 'Answered', 'sitehelm' ),
			DiscoverySelfTest::WRONG_OWNER => __( 'Not this site', 'sitehelm' ),
			DiscoverySelfTest::UNREACHABLE => __( 'No answer', 'sitehelm' ),
			default                        => __( 'Unknown', 'sitehelm' ),
		};
	}
}
