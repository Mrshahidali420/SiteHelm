<?php
/**
 * The Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Gateway\RestTransport;
use SiteHelm\Storage\AuditStore;
use WP_Application_Passwords;
use WP_Error;

/**
 * The screen that turns an installed plugin into a connected one.
 *
 * Without it a person has to know that SiteHelm speaks MCP over a REST route,
 * find that route, create an application password in a different screen, encode
 * the pair as a Basic credential, and assemble their client's configuration by
 * hand. Every one of those steps is a place to get it wrong silently, and the
 * failure looks identical to the plugin not working.
 *
 * The password is shown exactly once and never stored by SiteHelm: it is handed
 * over in a short-lived transient that the render deletes as it reads. A secret
 * that survives its own page load is a secret waiting to be found in a backup.
 *
 * @package SiteHelm
 */
final class ConnectScreen {

	/**
	 * The admin-post action that creates an application password.
	 */
	public const ACTION_CREATE_PASSWORD = 'sitehelm_create_password';

	/**
	 * The nonce action guarding that request.
	 */
	public const NONCE_CREATE_PASSWORD = 'sitehelm_create_password';

	/**
	 * How long the newly created password waits to be rendered, in seconds.
	 */
	public const HANDOFF_TTL = 120;

	/**
	 * The application password's name, as it appears in the user's profile.
	 */
	public const PASSWORD_NAME = 'SiteHelm MCP';

	/**
	 * The query argument carrying a failure back to the screen.
	 */
	private const ARG_ERROR = 'sitehelm_error';

	/**
	 * The audit log this screen asks whether a client has ever called.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null $store The audit log, or null to use the real one.
	 */
	public function __construct( ?AuditStore $store = null ) {
		$this->store = $store ?? new AuditStore();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$user     = wp_get_current_user();
		$endpoint = self::endpoint();
		$password = $this->take_new_password( (int) $user->ID );

		echo '<div class="wrap sitehelm-app">';

		Ui::masthead(
			__( 'Connect', 'sitehelm' ),
			__( 'Point an AI client at this site. Everything below is generated from this site\'s own settings.', 'sitehelm' )
		);

		$this->render_verdict();
		$this->render_failure();
		$this->render_endpoint( $endpoint );
		$this->render_credential( $password );
		$this->render_snippets( $endpoint, (string) $user->user_login, $password );

		echo '</div>';
	}

	/**
	 * Create an application password for the current user and return to the screen.
	 *
	 * Bound to `admin_post` rather than performed on render, so the credential is
	 * created by a deliberate, nonce-checked POST. A password minted by a page
	 * load would be minted again by every refresh and by every prefetch.
	 */
	public function handle_create_password(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE_CREATE_PASSWORD );

		$user_id = get_current_user_id();
		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			[
				'name'   => self::PASSWORD_NAME,
				'app_id' => '',
			]
		);

		if ( $created instanceof WP_Error || ! is_array( $created ) || ! isset( $created[0] ) ) {
			$this->go_back( __( 'WordPress would not create an application password for your account.', 'sitehelm' ) );
			return;
		}

		set_transient( self::handoff_key( $user_id ), (string) $created[0], self::HANDOFF_TTL );

		$this->go_back( '' );
	}

	/**
	 * The site's MCP endpoint.
	 *
	 * Built from `rest_url()` rather than assembled from the home URL, so an
	 * install with a filtered REST prefix, a site in a subdirectory, or plain
	 * permalinks still yields the URL that actually answers.
	 */
	public static function endpoint(): string {
		return rest_url( ltrim( RestTransport::ROUTE_NAMESPACE . RestTransport::ROUTE, '/' ) );
	}

	/**
	 * Open the screen with the one thing a person came to learn.
	 *
	 * "Connected" is claimed only when a request has actually arrived. Before
	 * that the screen says the site is ready, which is a different and honest
	 * claim: SiteHelm cannot know a client is configured until one calls.
	 */
	private function render_verdict(): void {
		$last = $this->store->query( [], 1, 0 );

		if ( [] !== $last && isset( $last[0]['recorded_at'] ) ) {
			Ui::verdict(
				'ok',
				__( 'Connected', 'sitehelm' ),
				sprintf(
					/* translators: %s: human-readable time difference, such as "12 minutes". */
					__( 'Last request %s ago', 'sitehelm' ),
					human_time_diff( (int) $last[0]['recorded_at'] )
				)
			);
			return;
		}

		if ( ! wp_is_application_passwords_available() ) {
			Ui::verdict(
				'refused',
				__( 'Not ready', 'sitehelm' ),
				__( 'Application passwords are disabled on this site', 'sitehelm' )
			);
			return;
		}

		Ui::verdict(
			'waiting',
			__( 'Ready to connect', 'sitehelm' ),
			__( 'No client has called this site yet', 'sitehelm' )
		);
	}

	/**
	 * Show a failure carried back from the password request, if there was one.
	 */
	private function render_failure(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a message this screen put in the URL itself; it grants nothing and changes nothing.
		$message = isset( $_GET[ self::ARG_ERROR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::ARG_ERROR ] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="sitehelm-section"><div class="sitehelm-note sitehelm-note--refused" role="alert"><p>%s</p>'
				. '<p>%s</p></div></div>',
			esc_html( $message ),
			esc_html__(
				'You can create one by hand under Users, then Profile, and paste it into the snippets below.',
				'sitehelm'
			)
		);
	}

	/**
	 * The endpoint, as a selectable field with a copy button.
	 *
	 * @param string $endpoint The site's MCP endpoint.
	 */
	private function render_endpoint( string $endpoint ): void {
		Ui::section_open(
			__( 'Endpoint', 'sitehelm' ),
			__( 'The address your AI client talks to. One route; SiteHelm adds nothing else to this site.', 'sitehelm' )
		);

		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body"><div class="sitehelm-field">';

		printf(
			'<label class="sitehelm-field__label" for="sitehelm-endpoint">%s</label>',
			esc_html__( 'MCP endpoint', 'sitehelm' )
		);

		printf(
			'<input class="sitehelm-field__input" type="text" id="sitehelm-endpoint" value="%s" readonly'
				. ' spellcheck="false" onfocus="this.select()">',
			esc_attr( $endpoint )
		);

		Ui::copy_button( 'sitehelm-endpoint', __( 'Copy endpoint', 'sitehelm' ) );

		if ( ! is_ssl() ) {
			printf(
				'<p class="sitehelm-field__hint">%s</p>',
				esc_html__(
					'This site is not served over HTTPS. An application password sent to it travels in the clear, so connect only over HTTPS on a live site.',
					'sitehelm'
				)
			);
		}

		echo '</div></div></div>';
		Ui::section_close();
	}

	/**
	 * The credential block: either the button that creates one, or the one just created.
	 *
	 * @param string $password The password just created, or an empty string.
	 */
	private function render_credential( string $password ): void {
		Ui::section_open(
			__( 'Credential', 'sitehelm' ),
			__(
				'SiteHelm authenticates as a WordPress user, so an agent can never do more than that user could. Give it an account with only the capabilities it needs.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body">';

		if ( '' !== $password ) {
			$this->render_new_password( $password );
		} else {
			$this->render_create_form();
		}

		echo '</div></div>';
		Ui::section_close();
	}

	/**
	 * The form that mints an application password.
	 */
	private function render_create_form(): void {
		if ( ! wp_is_application_passwords_available() ) {
			printf(
				'<div class="sitehelm-note"><p>%s</p></div>',
				esc_html__(
					'Application passwords are disabled on this site, so SiteHelm cannot create one. Enable them, or use another authentication method your client supports.',
					'sitehelm'
				)
			);
			return;
		}

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_CREATE_PASSWORD ) );
		wp_nonce_field( self::NONCE_CREATE_PASSWORD );

		printf(
			'<button type="submit" class="sitehelm-btn sitehelm-btn--primary">%s</button>',
			esc_html__( 'Create an application password', 'sitehelm' )
		);

		printf(
			'<p class="sitehelm-field__hint">%s</p></form>',
			esc_html(
				sprintf(
					/* translators: %s: the name the application password is given. */
					__( 'Creates a password named "%s" on your own account. You can revoke it at any time under Users, then Profile.', 'sitehelm' ),
					self::PASSWORD_NAME
				)
			)
		);
	}

	/**
	 * The password, shown once.
	 *
	 * @param string $password The password just created.
	 */
	private function render_new_password( string $password ): void {
		printf(
			'<div class="sitehelm-note sitehelm-note--ok"><p>%s</p></div>',
			esc_html__( 'Copy this now. WordPress does not show an application password a second time.', 'sitehelm' )
		);

		echo '<div class="sitehelm-field" style="margin-top:14px">';

		printf(
			'<label class="sitehelm-field__label" for="sitehelm-password">%s</label>',
			esc_html__( 'Application password', 'sitehelm' )
		);

		printf(
			'<input class="sitehelm-field__input" type="text" id="sitehelm-password" value="%s" readonly'
				. ' spellcheck="false" autocomplete="off" onfocus="this.select()">',
			esc_attr( $password )
		);

		Ui::copy_button( 'sitehelm-password', __( 'Copy password', 'sitehelm' ) );

		echo '</div>';
	}

	/**
	 * The client configuration snippets.
	 *
	 * All three are rendered. Script hides the two that are not selected; with
	 * scripting off every snippet stays on the page and readable, which is the
	 * only behaviour that keeps this screen usable in every case.
	 *
	 * @param string $endpoint The site's MCP endpoint.
	 * @param string $username The current user's login.
	 * @param string $password The password just created, or an empty string.
	 */
	private function render_snippets( string $endpoint, string $username, string $password ): void {
		$snippets = ( new ClientConfig( $endpoint, $username, $password ) )->snippets();

		Ui::section_open(
			__( 'Your client', 'sitehelm' ),
			'' === $password
				? __( 'Create a password above and these snippets fill themselves in. Until then they carry a placeholder.', 'sitehelm' )
				: __( 'Ready to paste, with the password you just created.', 'sitehelm' )
		);

		echo '<fieldset class="sitehelm-segments" data-sitehelm-clients>';
		printf( '<legend class="sitehelm-segments__legend">%s</legend>', esc_html__( 'Choose your client', 'sitehelm' ) );

		foreach ( $snippets as $index => $snippet ) {
			printf(
				'<div class="sitehelm-segment"><label><input type="radio" name="sitehelm-client" value="%s"%s>'
					. '<span>%s</span></label></div>',
				esc_attr( $snippet['id'] ),
				0 === $index ? ' checked' : '',
				esc_html( $snippet['name'] )
			);
		}

		echo '</fieldset>';

		foreach ( $snippets as $snippet ) {
			$this->render_snippet( $snippet );
		}

		Ui::section_close();
	}

	/**
	 * One client snippet.
	 *
	 * @param array{id: string, name: string, caption: string, body: string} $snippet The snippet to render.
	 */
	private function render_snippet( array $snippet ): void {
		$id = 'sitehelm-snippet-' . $snippet['id'];

		printf(
			'<div class="sitehelm-code" data-sitehelm-client="%s" style="margin-bottom:14px">'
				. '<div class="sitehelm-code__bar"><span class="sitehelm-code__name">%s</span>',
			esc_attr( $snippet['id'] ),
			esc_html( $snippet['caption'] )
		);

		Ui::copy_button(
			$id,
			sprintf(
				/* translators: %s: client name, such as Claude Code. */
				__( 'Copy %s config', 'sitehelm' ),
				$snippet['name']
			)
		);

		printf(
			'</div><pre id="%s"><code>%s</code></pre></div>',
			esc_attr( $id ),
			esc_html( $snippet['body'] )
		);
	}

	/**
	 * Read and delete the newly created password handed over by the POST.
	 *
	 * @param int $user_id The user the password was created for.
	 */
	private function take_new_password( int $user_id ): string {
		$key      = self::handoff_key( $user_id );
		$password = get_transient( $key );

		if ( ! is_string( $password ) || '' === $password ) {
			return '';
		}

		delete_transient( $key );

		return $password;
	}

	/**
	 * The transient key the new password is handed over in.
	 *
	 * Keyed by user, so two administrators creating a password at the same
	 * moment cannot be shown each other's.
	 *
	 * @param int $user_id The user the password belongs to.
	 */
	private static function handoff_key( int $user_id ): string {
		return 'sitehelm_new_password_' . $user_id;
	}

	/**
	 * Return to the Connect screen, carrying a message when there is one.
	 *
	 * @param string $message The failure to report, or an empty string.
	 */
	private function go_back( string $message ): void {
		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT );

		if ( '' !== $message ) {
			$url = add_query_arg( self::ARG_ERROR, rawurlencode( $message ), $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
