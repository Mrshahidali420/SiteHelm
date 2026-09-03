<?php
/**
 * The consent leg of the authorization code flow.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use SiteHelm\Admin\AdminMenu;

/**
 * Shows an administrator what an app is asking for and turns an approval into
 * an authorization code.
 *
 * The order of the checks is the security property, not a style choice. The
 * client and its redirect URI are resolved and validated **before** any error
 * is sent to a redirect target, because delivering an error to an unverified
 * redirect URI is delivering data to whoever asked for it. Until both are
 * known good, every failure renders here as a page.
 *
 * Approval is limited to accounts holding {@see AdminMenu::CAPABILITY}. Widening
 * that later is safe; narrowing it after connections exist is not.
 *
 * @package SiteHelm
 */
final class AuthorizeEndpoint {

	/**
	 * The `admin-post.php` action this endpoint answers, on GET and on POST.
	 */
	public const ACTION = 'sitehelm_authorize';

	/**
	 * The nonce protecting the approval POST. It is the only CSRF control in
	 * the flow — the REST endpoints are public by design and are protected
	 * instead by PKCE, single-use codes and hashed-at-rest tokens.
	 */
	public const NONCE = 'sitehelm_authorize_consent';

	/**
	 * The field carrying the administrator's answer.
	 */
	public const FIELD_DECISION   = 'sitehelm_decision';
	public const DECISION_APPROVE = 'approve';
	public const DECISION_DENY    = 'deny';

	/**
	 * The request parameters this endpoint reads.
	 *
	 * @var string[]
	 */
	private const PARAMS = [
		'client_id',
		'redirect_uri',
		'state',
		'response_type',
		'code_challenge',
		'code_challenge_method',
		'resource',
		'scope',
	];

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the endpoint.
	 *
	 * @param OAuthStore         $store    The client store.
	 * @param RedirectUriPolicy  $policy   The redirect rules.
	 * @param AuthorizationCodes $codes    The code store.
	 * @param MetadataDocument   $metadata The identifier comparer.
	 * @param PublicUrl          $urls     The address resolver.
	 * @param Pkce               $pkce     The challenge checker.
	 * @param ConsentView        $view     The renderer.
	 * @param callable|null      $redirect Redirects and exits; null for the real one.
	 * @param callable|null      $clock    Returns the current time; null for time().
	 */
	public function __construct(
		private readonly OAuthStore $store,
		private readonly RedirectUriPolicy $policy,
		private readonly AuthorizationCodes $codes,
		private readonly MetadataDocument $metadata,
		private readonly PublicUrl $urls,
		private readonly Pkce $pkce,
		private readonly ConsentView $view,
		?callable $redirect = null,
		private $clock = null
	) {
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is a registered redirect URI, which is off-site by definition; wp_safe_redirect would send every desktop client to the dashboard instead.
			exit;
		};
		$this->clock    = $clock ?? static fn(): int => time();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Sends a signed-out visitor to log in and come straight back.
	 */
	public function requireLogin(): void {
		$target = add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) );

		foreach ( $this->readParams() as $name => $value ) {
			if ( '' !== $value ) {
				$target = add_query_arg( rawurlencode( $name ), rawurlencode( $value ), $target );
			}
		}

		( $this->redirect )( wp_login_url( $target ) );
	}

	/**
	 * Answers a consent request, on GET or on POST.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */
	public function handle(): void {
		if ( ! ( new AuthSettings() )->enabled() ) {
			$this->view->refusal(
				__( 'Signing in with this site is switched off', 'sitehelm' ),
				__( 'An administrator can turn it back on from the SiteHelm Connect screen. Until then, connect with an application password instead.', 'sitehelm' )
			);

			return;
		}

		if ( ! $this->urls->isSecure() ) {
			$this->view->refusal(
				__( 'This site is not served over HTTPS', 'sitehelm' ),
				__( 'A connection approved here would send its access token in clear text. Put the site behind HTTPS, or set the Server URL on the SiteHelm Connect screen if a proxy already terminates TLS.', 'sitehelm' ),
				[ $this->urls->base() ]
			);

			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			$this->view->refusal(
				__( 'Only an administrator can approve a connection', 'sitehelm' ),
				__( 'Sign in as an account that can manage this site, then start the connection again from the app.', 'sitehelm' )
			);

			return;
		}

		$params = $this->readParams();
		$client = $this->store->findClient( $params['client_id'] );

		if ( null === $client ) {
			$this->view->refusal(
				__( 'This site does not recognise that app', 'sitehelm' ),
				__( 'The app registered with this site, and the registration has since been removed or expired. Remove the connection in the app and add it again.', 'sitehelm' ),
				[ 'client_id: ' . $params['client_id'] ]
			);

			return;
		}

		$registered = ClientRegistry::redirectUris( $client );
		$matched    = $this->policy->match( $registered, $params['redirect_uri'] );

		if ( null === $matched ) {
			$this->view->refusal(
				__( 'That app asked to be sent somewhere it never registered', 'sitehelm' ),
				__( 'Nothing has been approved. Remove the connection in the app and add it again so it registers the address it is actually listening on.', 'sitehelm' ),
				array_merge(
					[ 'requested: ' . $params['redirect_uri'] ],
					array_map( static fn( string $uri ): string => 'registered: ' . $uri, $registered )
				)
			);

			return;
		}

		// Past this point the redirect target is verified, so protocol errors
		// may travel back to the app as RFC 6749 requires.
		$protocol = $this->protocolError( $params );

		if ( null !== $protocol ) {
			$this->bounce( $params, [ 'error' => $protocol ] );

			return;
		}

		if ( 'POST' === $this->method() ) {
			$this->decide( $params, $client );

			return;
		}

		$this->view->consent(
			$this->hiddenFields( $params ),
			(string) $client['client_name'],
			(string) get_bloginfo( 'name' ),
			(string) wp_get_current_user()->user_login,
			$this->nonceField()
		);
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/**
	 * Acts on the administrator's answer.
	 *
	 * Every check above ran again on the way here, because the POST is a fresh
	 * request and the GET that rendered the form proves nothing about it.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param array<string, mixed>  $client The registered client.
	 */
	private function decide( array $params, array $client ): void {
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$decision = isset( $_POST[ self::FIELD_DECISION ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_DECISION ] ) ) : '';

		if ( self::DECISION_APPROVE !== $decision ) {
			$this->bounce( $params, [ 'error' => 'access_denied' ] );

			return;
		}

		$now = ( $this->clock )();

		$code = $this->codes->issue(
			[
				'client_id'      => (string) $client['client_id'],
				'user_id'        => get_current_user_id(),
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'resource'       => $this->urls->resource(),
				'scope'          => MetadataDocument::SCOPE,
				'issued_at'      => $now,
			]
		);

		$this->store->markAuthorized( (string) $client['client_id'], $now );

		$this->bounce( $params, [ 'code' => $code ] );
	}

	/**
	 * Which protocol rule the request breaks, if any.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return string|null An RFC 6749 error code, or null.
	 */
	private function protocolError( array $params ): ?string {
		if ( 'code' !== $params['response_type'] ) {
			return 'unsupported_response_type';
		}

		if ( Pkce::METHOD !== $params['code_challenge_method'] ) {
			return 'invalid_request';
		}

		if ( ! $this->pkce->wellFormedChallenge( $params['code_challenge'] ) ) {
			return 'invalid_request';
		}

		if ( '' !== $params['resource'] && ! $this->metadata->sameIdentifier( $params['resource'], $this->urls->resource() ) ) {
			return 'invalid_target';
		}

		return null;
	}

	/**
	 * Sends the browser back to the app, echoing `state` untouched.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param array<string, string> $args   `code`, or `error`.
	 */
	private function bounce( array $params, array $args ): void {
		if ( '' !== $params['state'] ) {
			$args['state'] = $params['state'];
		}

		$separator = str_contains( $params['redirect_uri'], '?' ) ? '&' : '?';
		$query     = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

		( $this->redirect )( $params['redirect_uri'] . $separator . $query );
	}

	/**
	 * The parameters carried across the consent form.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return array<string, string> Field name to value.
	 */
	private function hiddenFields( array $params ): array {
		$fields = [ 'action' => self::ACTION ];

		foreach ( $params as $name => $value ) {
			$fields[ $name ] = $value;
		}

		return $fields;
	}

	/**
	 * Reads every parameter this endpoint understands from the request.
	 *
	 * @return array<string, string> Every PARAMS key, present and a string.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function readParams(): array {
		$params = [];

		foreach ( self::PARAMS as $name ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed and stripped of control characters two lines below; the sniff cannot see across the assignment.
			$raw = $_POST[ $name ] ?? $_GET[ $name ] ?? '';

			$params[ $name ] = is_string( $raw )
				? trim( (string) preg_replace( '/[[:cntrl:]]/', '', wp_unslash( $raw ) ) )
				: '';
		}

		return $params;
	}

	/**
	 * The request method, uppercased.
	 *
	 * @return string For example `GET`.
	 */
	private function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * The rendered nonce field.
	 *
	 * @return string Markup.
	 */
	private function nonceField(): string {
		return (string) wp_nonce_field( self::NONCE, '_wpnonce', true, false );
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
