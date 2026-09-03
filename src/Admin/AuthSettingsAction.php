<?php
/**
 * The settings behind signing in, from the Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\PublicUrl;

/**
 * Answers the settings form on the Connect screen.
 *
 * Two things are set here and they belong together: whether apps may sign in,
 * and the address this site hands them when they do. Get the second wrong and
 * the first cannot work, because every client is sent to a door that does not
 * open.
 *
 * The address is refused rather than clamped. A retention window can be brought
 * into range and still mean what the operator meant; an address cannot. Saving
 * `htp://exmaple.com` because it looked close enough would publish it to every
 * client on the site.
 *
 * @package SiteHelm
 */
final class AuthSettingsAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_auth_settings';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_auth_settings';

	/**
	 * The checkbox that decides whether apps may sign in.
	 */
	public const FIELD_ENABLED = 'sitehelm_oauth_enabled';

	/**
	 * The server address override.
	 */
	public const FIELD_URL = 'sitehelm_public_url';

	/**
	 * Which button was pressed.
	 */
	public const FIELD_INTENT = 'sitehelm_intent';

	/**
	 * Save the two settings.
	 */
	public const INTENT_SAVE = 'save';

	/**
	 * Fetch the discovery documents and report what came back.
	 */
	public const INTENT_TEST = 'test';

	/**
	 * The query argument the panel reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_settings';

	/**
	 * Outcomes the panel renders.
	 */
	public const STATE_SAVED    = 'saved';
	public const STATE_TESTED   = 'tested';
	public const STATE_BAD_URL  = 'bad_url';
	public const STATE_INSECURE = 'insecure';

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
	 * The discovery check.
	 *
	 * @var DiscoverySelfTest
	 */
	private DiscoverySelfTest $test;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param PublicUrl|null         $urls     The address resolver; null for a fresh one.
	 * @param AuthSettings|null      $settings The enable flag; null for a fresh one.
	 * @param DiscoverySelfTest|null $test     The discovery check; null for a fresh one.
	 * @param callable|null          $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct(
		?PublicUrl $urls = null,
		?AuthSettings $settings = null,
		?DiscoverySelfTest $test = null,
		?callable $redirect = null
	) {
		$this->urls     = $urls ?? new PublicUrl();
		$this->settings = $settings ?? new AuthSettings( $this->urls );
		$this->test     = $test ?? new DiscoverySelfTest( new MetadataDocument( $this->urls ), $this->urls );
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Answer the POST.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$intent = isset( $_POST[ self::FIELD_INTENT ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_INTENT ] ) ) : self::INTENT_SAVE;

		if ( self::INTENT_TEST === $intent ) {
			$this->test->runAndRemember();

			$this->go_back( self::STATE_TESTED );

			return;
		}

		$this->save();
	}

	/**
	 * Stores the address first, then the switch.
	 *
	 * The order matters: the default the switch falls back to is read from the
	 * address, so an operator who fixes both in one submission gets the answer
	 * their new address gives.
	 */
	private function save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$typed = isset( $_POST[ self::FIELD_URL ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_URL ] ) ) : '';

		$refusal = self::refusal( $typed );

		if ( '' !== $refusal ) {
			$this->go_back( $refusal );

			return;
		}

		$this->urls->save( $typed );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$this->settings->set( isset( $_POST[ self::FIELD_ENABLED ] ) );

		$this->go_back( self::STATE_SAVED );
	}

	/**
	 * Why an address cannot be saved, or '' when it can.
	 *
	 * @param string $typed The address an operator typed. '' clears the override.
	 *
	 * @return string A state constant, or ''.
	 */
	private static function refusal( string $typed ): string {
		$typed = trim( $typed );

		if ( '' === $typed ) {
			return '';
		}

		$normalized = PublicUrl::normalize( $typed );

		if ( '' === $normalized ) {
			return self::STATE_BAD_URL;
		}

		if ( str_starts_with( $normalized, 'https://' ) ) {
			return '';
		}

		return PublicUrl::isLocalHost( PublicUrl::bareHost( $normalized ) ) ? '' : self::STATE_INSECURE;
	}

	/**
	 * Back to Connect, saying what happened.
	 *
	 * @param string $state One of the state constants.
	 */
	private function go_back( string $state ): void {
		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT );

		( $this->redirect )( add_query_arg( self::ARG_STATE, $state, $url ) );
	}
}
