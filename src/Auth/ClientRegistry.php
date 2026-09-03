<?php
/**
 * Dynamic Client Registration.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Lets an MCP client introduce itself before anyone has consented to anything.
 *
 * Registration is open by necessity — a desktop app has no credential to
 * present the first time it meets a site — so it is not the security boundary.
 * The boundary is the consent screen: a registration on its own can do nothing
 * at all until an administrator approves it. What this class must therefore
 * defend is the table, not the site, and it does that three ways: registration
 * is idempotent by shape, so an app re-registering on every launch reuses one
 * row; a per-address throttle caps how fast rows can appear; and a ceiling on
 * rows that never completed a consent refuses new ones outright rather than
 * letting the table grow without limit.
 *
 * No client secret is ever issued. Every client here is public.
 *
 * @package SiteHelm
 */
final class ClientRegistry {

	/**
	 * Registration limits.
	 */
	public const MAX_REDIRECT_URIS    = 10;
	public const MAX_NAME_LENGTH      = 191;
	public const THROTTLE_PER_WINDOW  = 20;
	public const THROTTLE_SECONDS     = 900;
	public const UNAUTHORIZED_CEILING = 500;

	/**
	 * Prefix on the throttle transient key.
	 */
	private const THROTTLE_PREFIX = 'sitehelm_oauth_dcr_';

	/**
	 * Prefix on every issued client identifier, so a value found in a log is
	 * recognisable as ours at a glance.
	 */
	private const ID_PREFIX = 'shc_';

	/**
	 * Constructs the registry.
	 *
	 * @param OAuthStore        $store    The client store.
	 * @param RedirectUriPolicy $policy   The redirect rules.
	 * @param TokenFactory      $factory  The randomness source.
	 * @param callable|null     $clock    Returns the current time; null for time().
	 */
	public function __construct(
		private readonly OAuthStore $store,
		private readonly RedirectUriPolicy $policy,
		private readonly TokenFactory $factory,
		private $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Answers a registration request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response The registration, or an RFC 7591 error.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			return $this->refuse( 'invalid_client_metadata', 'The registration request body must be a JSON object.' );
		}

		$name = $this->cleanName( $body['client_name'] ?? '' );
		$uris = $body['redirect_uris'] ?? null;

		if ( ! is_array( $uris ) || [] === $uris ) {
			return $this->refuse( 'invalid_redirect_uri', 'Send at least one redirect_uri, as an array of strings.' );
		}

		if ( count( $uris ) > self::MAX_REDIRECT_URIS ) {
			return $this->refuse(
				'invalid_redirect_uri',
				sprintf( 'Send at most %d redirect URIs.', self::MAX_REDIRECT_URIS )
			);
		}

		$clean = [];

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) ) {
				return $this->refuse( 'invalid_redirect_uri', 'Every redirect_uri must be a string.' );
			}

			$reason = $this->policy->refusalReason( $uri );

			if ( '' !== $reason ) {
				return $this->refuse( 'invalid_redirect_uri', $reason );
			}

			$clean[] = $uri;
		}

		$encoded  = (string) wp_json_encode( $clean );
		$existing = $this->store->findClientByShape( $name, $encoded );

		if ( is_array( $existing ) ) {
			return new WP_REST_Response( $this->represent( $existing ), 201 );
		}

		if ( ! $this->withinThrottle( $request ) ) {
			return $this->refuse(
				'invalid_client_metadata',
				'Too many registrations from this address. Wait a few minutes and try again.',
				429
			);
		}

		if ( $this->store->countNeverAuthorized() >= self::UNAUTHORIZED_CEILING ) {
			return $this->refuse(
				'invalid_client_metadata',
				'This site is holding too many app registrations that were never approved. An administrator can clear them on the SiteHelm Connect screen.',
				503
			);
		}

		$now = ( $this->clock )();
		$row = [
			'client_id'     => $this->factory->identifier( self::ID_PREFIX ),
			'client_name'   => $name,
			'redirect_uris' => $encoded,
			'created_by'    => 0,
			'created_at'    => $now,
			'authorized_at' => 0,
		];

		if ( ! $this->store->insertClient( $row ) ) {
			return $this->refuse(
				'invalid_client_metadata',
				'This site could not store the registration. Check the SiteHelm Status screen.',
				500
			);
		}

		return new WP_REST_Response( $this->represent( $row ), 201 );
	}

	/**
	 * The registered redirect URIs of one stored client row.
	 *
	 * @param array<string, mixed> $client The stored row.
	 *
	 * @return string[] The registered URIs.
	 */
	public static function redirectUris( array $client ): array {
		$decoded = json_decode( (string) ( $client['redirect_uris'] ?? '' ), true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		return array_values( array_filter( $decoded, 'is_string' ) );
	}

	/**
	 * The RFC 7591 representation of a client.
	 *
	 * @param array<string, mixed> $client The stored row.
	 *
	 * @return array<string, mixed> The response body.
	 */
	private function represent( array $client ): array {
		return [
			'client_id'                  => (string) $client['client_id'],
			'client_name'                => (string) $client['client_name'],
			'redirect_uris'              => self::redirectUris( $client ),
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'response_types'             => [ 'code' ],
			'client_id_issued_at'        => (int) $client['created_at'],
		];
	}

	/**
	 * Reduces a client-supplied name to something safe to store and display.
	 *
	 * @param mixed $raw The name as sent.
	 *
	 * @return string The stored name, never empty.
	 */
	private function cleanName( mixed $raw ): string {
		$name = is_string( $raw ) ? trim( (string) preg_replace( '/[[:cntrl:]]/', '', $raw ) ) : '';

		if ( '' === $name ) {
			return 'Unnamed app';
		}

		return mb_substr( $name, 0, self::MAX_NAME_LENGTH, 'UTF-8' );
	}

	/**
	 * Whether this caller may register another client right now.
	 *
	 * The counter is keyed on a hash of the address rather than the address, so
	 * the options table does not become a visitor log.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return bool True when the caller is under the limit.
	 */
	private function withinThrottle( WP_REST_Request $request ): bool {
		$key   = self::THROTTLE_PREFIX . substr( hash( 'sha256', $this->callerAddress( $request ) ), 0, 32 );
		$count = (int) get_transient( $key );

		if ( $count >= self::THROTTLE_PER_WINDOW ) {
			return false;
		}

		set_transient( $key, $count + 1, self::THROTTLE_SECONDS );

		return true;
	}

	/**
	 * The address this request came from.
	 *
	 * `X-Forwarded-For` is deliberately not read. It is caller-controlled unless
	 * the proxy in front of this site is known and configured, and reading it
	 * here would let one caller present a fresh identity per request and walk
	 * straight past the throttle.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return string The address, or '' when there is none.
	 */
	private function callerAddress( WP_REST_Request $request ): string {
		unset( $request );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Hashed immediately by the caller; never stored, printed or used in SQL.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * An RFC 7591 error response.
	 *
	 * @param string $code        The error code.
	 * @param string $description What to fix, in plain words.
	 * @param int    $status      The HTTP status.
	 *
	 * @return WP_REST_Response The refusal.
	 */
	private function refuse( string $code, string $description, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'error'             => $code,
				'error_description' => $description,
			],
			$status
		);
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
