<?php
/**
 * The Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\PublicUrl;
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
 * There are two ways in, and the screen asks which before it shows anything
 * else. A client that can sign in needs the endpoint and nothing more; one that
 * cannot needs an application password carried in a header. The choice decides
 * every snippet below it, so it is the first thing on the page rather than a
 * distinction the reader is left to draw from two similar-looking blocks.
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
	 * The table of apps that signed in, with its own two controls.
	 *
	 * @var ConnectedAppsPanel
	 */
	private ConnectedAppsPanel $apps;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null         $store       The audit log, or null to use the real one.
	 * @param Credentials|null        $credentials The credential store, or null for the WordPress-backed one.
	 * @param ConnectedAppsPanel|null $apps        The connected-apps table, or null for a fresh one.
	 */
	public function __construct( ?AuditStore $store = null, ?Credentials $credentials = null, ?ConnectedAppsPanel $apps = null ) {
		$this->store       = $store ?? new AuditStore();
		$this->credentials = $credentials ?? new Credentials();
		$this->apps        = $apps ?? new ConnectedAppsPanel();
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
			__( 'Connect an app', 'sitehelm' ),
			__( 'Point an AI app such as Claude or ChatGPT at this site. Everything below is generated from this site\'s own settings.', 'sitehelm' )
		);

		$this->render_verdict( $last );
		$this->render_failure();
		$this->render_readiness( [] !== $last );
		$this->render_method_chooser( $endpoint );
		$this->render_endpoint( $endpoint );
		$this->render_credential( $handoff );
		( new CredentialsPanel( $this->credentials ) )->render( self::selectable_users() );
		$this->render_clients( $endpoint, $handoff );
		$this->apps->render();
		( new AuthSettingsPanel() )->render();

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
	 * Asked of {@see PublicUrl} rather than assembled here, so the Server URL
	 * override is authoritative for every address on this screen. A screen that
	 * built its own endpoint would print one URL in the snippets while the
	 * discovery documents published another, and the two would disagree
	 * silently: the client would fetch metadata naming a resource it was never
	 * given, and the token it was issued would be refused with nothing on the
	 * screen to explain it.
	 */
	public static function endpoint(): string {
		return ( new PublicUrl() )->mcpEndpoint();
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
	 * Whether a client can sign in to this site rather than carrying a password.
	 *
	 * Two conditions, both of them the site's own: OAuth has to be switched on,
	 * and the public address has to be one a bearer token may travel over. A
	 * token sent over plain HTTP is a password sent over plain HTTP, so on an
	 * HTTP site the sign-in path is not offered at all rather than offered and
	 * refused halfway through by a component the operator never sees.
	 */
	private function oauth_available(): bool {
		$urls = new PublicUrl();

		return ( new AuthSettings( $urls ) )->enabled() && $urls->isSecure();
	}

	/**
	 * The choice that decides everything below it: sign in, or paste a password.
	 *
	 * Put first because it is the fork. Every snippet on the screen belongs to
	 * one path or the other, and a person who reads the header block before
	 * discovering the sign-in path has already done work they did not need to.
	 *
	 * @param string $endpoint The site's MCP endpoint.
	 */
	private function render_method_chooser( string $endpoint ): void {
		$available = $this->oauth_available();

		Ui::section_open(
			__( 'How your app signs in', 'sitehelm' ),
			__( 'Two ways in. Pick one and the snippets below follow it.', 'sitehelm' )
		);

		echo '<fieldset class="sitehelm-methods" data-sitehelm-methods>';

		printf(
			'<legend class="sitehelm-srt">%s</legend>',
			esc_html__( 'Choose how your app signs in', 'sitehelm' )
		);

		$this->render_method_card(
			ClientConfig::AUTH_OAUTH,
			__( 'Sign in with OAuth (recommended)', 'sitehelm' ),
			$available
				? __( 'Your app sends you here to approve it, the way any other app you sign in to does. No password is written into a config file, and you can sign it out again from this screen.', 'sitehelm' )
				: __( 'Not available on this site yet. See below.', 'sitehelm' ),
			$available,
			$available
		);

		$this->render_method_card(
			ClientConfig::AUTH_PASSWORD,
			__( 'Application password', 'sitehelm' ),
			__( 'You create a password here and paste it into your app. Works with every client, including ones that cannot sign in.', 'sitehelm' ),
			true,
			! $available
		);

		echo '</fieldset>';

		if ( $available ) {
			$this->render_oauth_card( $endpoint );
			( new ConnectTroubleshooting() )->render();
		} else {
			$this->render_oauth_unavailable();
		}

		Ui::section_close();
	}

	/**
	 * One card in the connection-method chooser.
	 *
	 * @param string $value    The method this card selects.
	 * @param string $headline What the method is called.
	 * @param string $detail   What choosing it means.
	 * @param bool   $enabled  Whether it can be chosen at all.
	 * @param bool   $checked  Whether it starts selected.
	 */
	private function render_method_card( string $value, string $headline, string $detail, bool $enabled, bool $checked ): void {
		printf(
			'<label class="sitehelm-method%1$s"><input type="radio" name="sitehelm-method" value="%2$s"%3$s%4$s>'
				. '<span class="sitehelm-method__body"><span class="sitehelm-method__head">%5$s</span>'
				. '<span class="sitehelm-method__detail">%6$s</span></span></label>',
			$enabled ? '' : ' sitehelm-method--off',
			esc_attr( $value ),
			$checked ? ' checked' : '',
			$enabled ? '' : ' disabled',
			esc_html( $headline ),
			esc_html( $detail )
		);
	}

	/**
	 * The one value an app that signs in needs, and nothing beside it.
	 *
	 * @param string $endpoint The site's MCP endpoint.
	 */
	private function render_oauth_card( string $endpoint ): void {
		printf(
			'<div class="sitehelm-panel" data-sitehelm-auth="%s"><div class="sitehelm-panel__body"><div class="sitehelm-field">',
			esc_attr( ClientConfig::AUTH_OAUTH )
		);

		printf(
			'<label class="sitehelm-field__label" for="sitehelm-oauth-url">%s</label>',
			esc_html__( 'Paste this into your app', 'sitehelm' )
		);

		printf(
			'<input class="sitehelm-field__input" type="text" id="sitehelm-oauth-url" value="%s" readonly'
				. ' spellcheck="false" onfocus="this.select()">',
			esc_attr( $endpoint )
		);

		Ui::copy_button( 'sitehelm-oauth-url', __( 'Copy the sign-in URL', 'sitehelm' ) );

		printf(
			'<p class="sitehelm-field__hint">%s</p>',
			esc_html__(
				'That is the whole configuration. Your app finds the sign-in page from this address by itself, and brings you here to approve it the first time it calls.',
				'sitehelm'
			)
		);

		echo '</div></div></div>';
	}

	/**
	 * Why the sign-in path is not on offer, in the site's own terms.
	 *
	 * Two different reasons with two different fixes, said apart rather than
	 * together: "turn it on" and "get a certificate" are not the same errand,
	 * and an operator told both at once will try the wrong one first.
	 */
	private function render_oauth_unavailable(): void {
		$urls   = new PublicUrl();
		$secure = $urls->isSecure();

		printf(
			'<div class="sitehelm-note sitehelm-note--waiting"><p>%s</p><p>%s</p></div>',
			esc_html(
				$secure
					? __( 'Signing in is switched off on this site.', 'sitehelm' )
					: sprintf(
						/* translators: %s: the site's public address. */
						__( 'This site answers on %s, which is not HTTPS.', 'sitehelm' ),
						$urls->base()
					)
			),
			esc_html(
				$secure
					? __( 'Turn it on in Settings, further down this screen, and the sign-in option here becomes available. Until then, use an application password.', 'sitehelm' )
					: __( 'An app that signs in is given a token, and a token sent over plain HTTP can be read and reused by anyone on the network between your app and this site. Put the site behind a certificate, then turn signing in on. Until then, use an application password below.', 'sitehelm' )
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
		$users = self::selectable_users();

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
	public static function selectable_users(): array {
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
	 * One client's configuration blocks, grouped by connection method.
	 *
	 * A client rarely accepts one shape. The same server is a bare URL to a
	 * client that signs in, an HTTP object carrying a header to one using an
	 * application password, a launched command to one that speaks only stdio,
	 * and a terminal one-liner to one with a CLI. They are offered as a tab
	 * strip so all of them stay one click away without four blocks of JSON
	 * stacked on top of each other.
	 *
	 * The strip is skipped where a group holds one shape: a choice of one is
	 * not a choice, and the file line above the block already says what it is.
	 *
	 * @param array{id: string, name: string, hint: string, blocks: array<int, array{id: string, label: string, file: string, auth: string, body: string}>} $client The client.
	 */
	private function render_client_blocks( array $client ): void {
		printf( '<div class="sitehelm-clientpanel" data-sitehelm-client="%s">', esc_attr( $client['id'] ) );

		printf( '<p class="sitehelm-section__note">%s</p>', esc_html( $client['hint'] ) );

		foreach ( [ ClientConfig::AUTH_OAUTH, ClientConfig::AUTH_PASSWORD ] as $method ) {
			$this->render_shape_group( $client, $method );
		}

		echo '</div>';
	}

	/**
	 * The shapes one client accepts under one connection method.
	 *
	 * @param array{id: string, name: string, blocks: array<int, array{id: string, label: string, file: string, auth: string, body: string}>} $client The client.
	 * @param string                                                                                                                          $method Which connection method these belong to.
	 */
	private function render_shape_group( array $client, string $method ): void {
		$shapes = array_values(
			array_filter(
				$client['blocks'],
				static fn( array $block ): bool => $method === $block['auth']
			)
		);

		if ( [] === $shapes ) {
			return;
		}

		printf(
			'<div class="sitehelm-shapes" data-sitehelm-shapes data-sitehelm-auth="%s">',
			esc_attr( $method )
		);

		if ( count( $shapes ) > 1 ) {
			$this->render_shape_tabs( $client['id'], $method, $shapes );
		}

		foreach ( $shapes as $shape ) {
			$this->render_shape_panel( $client, $shape );
		}

		echo '</div>';
	}

	/**
	 * The tab strip naming the shapes in one group.
	 *
	 * Radio inputs rather than buttons, so the strip is a working choice with
	 * scripting off: every panel is on the page and the strip is the label for
	 * what is already visible.
	 *
	 * @param string                                       $client_id The client the strip belongs to.
	 * @param string                                       $method    The connection method.
	 * @param array<int, array{id: string, label: string}> $shapes    The shapes offered.
	 */
	private function render_shape_tabs( string $client_id, string $method, array $shapes ): void {
		printf(
			'<fieldset class="sitehelm-shapes__tabs" data-sitehelm-shapetabs><legend class="sitehelm-srt">%s</legend>',
			esc_html__( 'Choose a format', 'sitehelm' )
		);

		foreach ( $shapes as $index => $shape ) {
			printf(
				'<label class="sitehelm-shape"><input type="radio" name="sitehelm-shape-%1$s-%2$s" value="%3$s"%4$s>'
					. '<span class="sitehelm-shape__label">%5$s</span></label>',
				esc_attr( $client_id ),
				esc_attr( $method ),
				esc_attr( $shape['id'] ),
				0 === $index ? ' checked' : '',
				esc_html( $shape['label'] )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * One shape: the line saying where it goes, then the snippet itself.
	 *
	 * @param array{name: string}                                          $client The client this belongs to.
	 * @param array{id: string, label: string, file: string, body: string} $shape  The shape.
	 */
	private function render_shape_panel( array $client, array $shape ): void {
		printf(
			'<div class="sitehelm-shapes__panel" data-sitehelm-shape="%s">',
			esc_attr( $shape['id'] )
		);

		printf( '<p class="sitehelm-shape__file">%s</p>', esc_html( $shape['file'] ) );

		Ui::code_block(
			'sitehelm-snippet-' . $shape['id'],
			$shape['label'],
			$shape['body'],
			sprintf(
				/* translators: 1: client name, such as Claude Code, 2: the format, such as Config file. */
				__( 'Copy the %1$s %2$s snippet', 'sitehelm' ),
				$client['name'],
				$shape['label']
			)
		);

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
