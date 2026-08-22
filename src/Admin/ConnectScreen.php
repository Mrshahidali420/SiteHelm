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
 * A password can be minted for another account, because the account is the only
 * permission boundary an agent has — pointing a client at an editor rather than
 * an administrator is how you stop it touching settings. That is gated on
 * `edit_user` for the specific account, checked again inside the handler, so the
 * dropdown can never become the thing that grants the permission.
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
	 * The form field naming the account the password is minted for.
	 */
	public const FIELD_USER = 'sitehelm_user';

	/**
	 * How many accounts the dropdown offers before it stops listing them.
	 */
	private const USER_LIMIT = 50;

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
	 * The credential store the listing and the Revoke button read through.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null  $store       The audit log, or null to use the real one.
	 * @param Credentials|null $credentials The credential store, or null for the WordPress-backed one.
	 */
	public function __construct( ?AuditStore $store = null, ?Credentials $credentials = null ) {
		$this->store       = $store ?? new AuditStore();
		$this->credentials = $credentials ?? new Credentials();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$endpoint = self::endpoint();
		$handoff  = $this->take_new_password( get_current_user_id() );

		// Asked once and passed down: the verdict and the readiness cards answer
		// the same question, and a second query could answer it differently.
		$last = $this->store->query( [], 1, 0 );

		Ui::app_open( AdminMenu::PAGE_CONNECT );

		Ui::page_head(
			__( 'Connect', 'sitehelm' ),
			__( 'Point an AI client at this site. Everything below is generated from this site\'s own settings.', 'sitehelm' )
		);

		$this->render_verdict( $last );
		$this->render_failure();
		$this->render_readiness( [] !== $last );
		$this->render_endpoint( $endpoint );
		$this->render_credential( $handoff );
		( new CredentialsPanel( $this->credentials ) )->render( $this->selectable_users() );
		$this->render_clients( $endpoint, $handoff );

		( new ConnectHelp() )->render();

		Ui::app_close();
	}

	/**
	 * Create an application password and return to the screen.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$requested = isset( $_POST[ self::FIELD_USER ] ) ? absint( wp_unslash( $_POST[ self::FIELD_USER ] ) ) : 0;
		$target    = 0 === $requested ? get_current_user_id() : $requested;

		// Re-checked here rather than trusted from the form: the dropdown is a
		// convenience, and a POST naming any other account must be refused on its
		// own merits whether or not that account was ever offered.
		if ( get_current_user_id() !== $target && ! current_user_can( 'edit_user', $target ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$target,
			[
				'name'   => self::PASSWORD_NAME,
				'app_id' => '',
			]
		);

		if ( $created instanceof WP_Error || ! is_array( $created ) || ! isset( $created[0] ) ) {
			$this->go_back( __( 'WordPress would not create an application password for that account.', 'sitehelm' ) );
			return;
		}

		// Handed to whoever asked for it, not to whoever it belongs to: the person
		// at the keyboard is the one who has to paste it into a client.
		set_transient(
			self::handoff_key( get_current_user_id() ),
			[
				'user'     => $target,
				'password' => (string) $created[0],
			],
			self::HANDOFF_TTL
		);

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
	 *
	 * @param array<int, array<string, mixed>> $last The most recent audit row, if any.
	 */
	private function render_verdict( array $last ): void {
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
	 * The three conditions a connection needs, each answered in words.
	 *
	 * @param bool $called Whether any request has ever been recorded.
	 */
	private function render_readiness( bool $called ): void {
		$passwords = (bool) wp_is_application_passwords_available();

		Ui::stat_grid(
			[
				[
					'label' => __( 'Application passwords', 'sitehelm' ),
					'value' => $passwords ? __( 'Available', 'sitehelm' ) : __( 'Disabled', 'sitehelm' ),
					'ok'    => $passwords,
				],
				[
					'label' => __( 'Transport security', 'sitehelm' ),
					'value' => is_ssl() ? __( 'HTTPS', 'sitehelm' ) : __( 'Not HTTPS', 'sitehelm' ),
					'ok'    => is_ssl(),
				],
				[
					'label' => __( 'Requests received', 'sitehelm' ),
					'value' => $called ? __( 'At least one', 'sitehelm' ) : __( 'None yet', 'sitehelm' ),
					'ok'    => $called,
				],
			]
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
	 * The credential block: either the form that creates one, or the one just created.
	 *
	 * @param array{user: int, password: string} $handoff The password just created, if any.
	 */
	private function render_credential( array $handoff ): void {
		Ui::section_open(
			__( 'Credential', 'sitehelm' ),
			__(
				'SiteHelm authenticates as a WordPress user, so an agent can never do more than that user could. Give it an account with only the capabilities it needs.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body">';

		if ( '' !== $handoff['password'] ) {
			$this->render_new_password( $handoff );
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

		$this->render_user_field();

		printf(
			'<button type="submit" class="sitehelm-btn sitehelm-btn--primary">%s</button>',
			esc_html__( 'Create an application password', 'sitehelm' )
		);

		printf(
			'<p class="sitehelm-field__hint">%s</p></form>',
			esc_html(
				sprintf(
					/* translators: %s: the name the application password is given. */
					__( 'Creates a password named "%s". It can be revoked at any time under Users, then that account\'s profile.', 'sitehelm' ),
					self::PASSWORD_NAME
				)
			)
		);
	}

	/**
	 * The account picker.
	 *
	 * Rendered as a dropdown only when there is more than one account this
	 * person may act for. With one choice a dropdown is a decision that isn't
	 * one, so the field states the account instead.
	 */
	private function render_user_field(): void {
		$users = $this->selectable_users();

		echo '<div class="sitehelm-field">';

		printf(
			'<label class="sitehelm-field__label" for="sitehelm-user">%s</label>',
			esc_html__( 'Account the agent will act as', 'sitehelm' )
		);

		if ( count( $users ) < 2 ) {
			printf(
				'<input class="sitehelm-field__input" type="text" id="sitehelm-user" value="%s" readonly disabled>',
				esc_attr( wp_get_current_user()->user_login )
			);
			echo '</div>';
			return;
		}

		printf(
			'<select class="sitehelm-select" id="sitehelm-user" name="%s">',
			esc_attr( self::FIELD_USER )
		);

		foreach ( $users as $user ) {
			printf(
				'<option value="%d">%s</option>',
				(int) $user->ID,
				esc_html( $this->user_label( $user ) )
			);
		}

		printf(
			'</select><p class="sitehelm-field__hint">%s</p></div>',
			esc_html__(
				'An agent can do exactly what this account can do, and no more. An editor account cannot change settings or install plugins however it is asked to.',
				'sitehelm'
			)
		);
	}

	/**
	 * The accounts this person may mint a password for, their own first.
	 *
	 * @return array<int, object>
	 */
	private function selectable_users(): array {
		$current = wp_get_current_user();
		$others  = [];

		$candidates = get_users(
			[
				'number'  => self::USER_LIMIT,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'exclude' => [ (int) $current->ID ],
			]
		);

		foreach ( (array) $candidates as $user ) {
			if ( is_object( $user ) && isset( $user->ID ) && current_user_can( 'edit_user', (int) $user->ID ) ) {
				$others[] = $user;
			}
		}

		return array_merge( [ $current ], $others );
	}

	/**
	 * How an account is named in the picker.
	 *
	 * @param object $user The account.
	 */
	private function user_label( object $user ): string {
		// `array_values()` because WordPress keys a user's roles by role name, and
		// the first role is the one this label states.
		$roles = array_values( array_map( 'strval', (array) ( $user->roles ?? [] ) ) );
		$first = (string) ( $roles[0] ?? '' );

		return sprintf(
			/* translators: 1: user login, 2: the account's first role, such as editor. */
			__( '%1$s (%2$s)', 'sitehelm' ),
			(string) ( $user->user_login ?? '' ),
			'' === $first ? __( 'no role', 'sitehelm' ) : $first
		);
	}

	/**
	 * The password, shown once.
	 *
	 * @param array{user: int, password: string} $handoff The password just created.
	 */
	private function render_new_password( array $handoff ): void {
		$login = $this->login_of( $handoff['user'] );

		printf(
			'<div class="sitehelm-note sitehelm-note--ok"><p>%s</p></div>',
			esc_html(
				'' === $login
					? __( 'Copy this now. WordPress does not show an application password a second time.', 'sitehelm' )
					: sprintf(
						/* translators: %s: the WordPress login the password belongs to. */
						__( 'Created for %s. Copy it now — WordPress does not show an application password a second time.', 'sitehelm' ),
						$login
					)
			)
		);

		echo '<div class="sitehelm-field">';

		printf(
			'<label class="sitehelm-field__label" for="sitehelm-password">%s</label>',
			esc_html__( 'Application password', 'sitehelm' )
		);

		printf(
			'<input class="sitehelm-field__input" type="text" id="sitehelm-password" value="%s" readonly'
				. ' spellcheck="false" autocomplete="off" onfocus="this.select()">',
			esc_attr( $handoff['password'] )
		);

		Ui::copy_button( 'sitehelm-password', __( 'Copy password', 'sitehelm' ) );

		echo '</div>';
	}

	/**
	 * The client picker and its configuration blocks.
	 *
	 * Every client's blocks are rendered. Script hides the ones that are not
	 * selected; with scripting off the whole page stays on screen and readable,
	 * which is the only behaviour that keeps this screen usable in every case.
	 *
	 * @param string                             $endpoint The site's MCP endpoint.
	 * @param array{user: int, password: string} $handoff  The password just created, if any.
	 */
	private function render_clients( string $endpoint, array $handoff ): void {
		$login = $this->login_of( $handoff['user'] );

		// Falls back to the person reading the screen, because that is whose
		// credential the placeholder snippets describe when none has been created.
		if ( '' === $login ) {
			$login = (string) ( wp_get_current_user()->user_login ?? '' );
		}

		$clients = ( new ClientConfig( $endpoint, $login, $handoff['password'] ) )->clients();

		Ui::section_open(
			__( 'Your client', 'sitehelm' ),
			'' === $handoff['password']
				? __( 'Pick your client. Create a password above and these fill themselves in; until then they carry a placeholder.', 'sitehelm' )
				: __( 'Pick your client. These are ready to paste, with the password you just created.', 'sitehelm' )
		);

		echo '<fieldset class="sitehelm-clients" data-sitehelm-clients>';

		printf( '<legend class="sitehelm-srt">%s</legend>', esc_html__( 'Choose your client', 'sitehelm' ) );

		foreach ( $clients as $index => $client ) {
			$this->render_client_option( $client, 0 === $index );
		}

		echo '</fieldset>';

		foreach ( $clients as $client ) {
			$this->render_client_blocks( $client );
		}

		Ui::section_close();
	}

	/**
	 * One card in the client picker.
	 *
	 * @param array{id: string, name: string, icon: string, hint: string} $client  The client.
	 * @param bool                                                        $checked Whether it starts selected.
	 */
	private function render_client_option( array $client, bool $checked ): void {
		printf(
			'<label class="sitehelm-client"><input type="radio" name="sitehelm-client" value="%s"%s>'
				. '<span class="dashicons %s" aria-hidden="true"></span>'
				. '<span class="sitehelm-client__label">%s</span></label>',
			esc_attr( $client['id'] ),
			$checked ? ' checked' : '',
			esc_attr( $client['icon'] ),
			esc_html( $client['name'] )
		);
	}

	/**
	 * One client's configuration blocks.
	 *
	 * @param array{id: string, name: string, hint: string, blocks: array<int, array{id: string, caption: string, body: string}>} $client The client.
	 */
	private function render_client_blocks( array $client ): void {
		printf( '<div data-sitehelm-client="%s">', esc_attr( $client['id'] ) );

		printf( '<p class="sitehelm-section__note">%s</p>', esc_html( $client['hint'] ) );

		foreach ( $client['blocks'] as $block ) {
			Ui::code_block(
				'sitehelm-snippet-' . $block['id'],
				$block['caption'],
				$block['body'],
				sprintf(
					/* translators: %s: client name, such as Claude Code. */
					__( 'Copy the %s config', 'sitehelm' ),
					$client['name']
				)
			);
		}

		echo '</div>';
	}

	/**
	 * The login belonging to an account id, or an empty string if there is none.
	 *
	 * @param int $user_id The account.
	 */
	private function login_of( int $user_id ): string {
		if ( 0 === $user_id ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return is_object( $user ) ? (string) ( $user->user_login ?? '' ) : '';
	}

	/**
	 * Read and delete the newly created password handed over by the POST.
	 *
	 * @param int $user_id The user who asked for the password.
	 *
	 * @return array{user: int, password: string}
	 */
	private function take_new_password( int $user_id ): array {
		$empty = [
			'user'     => 0,
			'password' => '',
		];

		$key    = self::handoff_key( $user_id );
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			return $empty;
		}

		// Deleted whatever it turned out to hold: a handoff that survives the one
		// render it was written for is a secret left lying in the options table.
		delete_transient( $key );

		$password = isset( $stored['password'] ) && is_string( $stored['password'] ) ? $stored['password'] : '';

		if ( '' === $password ) {
			return $empty;
		}

		return [
			'user'     => isset( $stored['user'] ) ? (int) $stored['user'] : 0,
			'password' => $password,
		];
	}

	/**
	 * The transient key the new password is handed over in.
	 *
	 * Keyed by the person who asked, so two administrators creating a password
	 * at the same moment cannot be shown each other's.
	 *
	 * @param int $user_id The user who asked for the password.
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
