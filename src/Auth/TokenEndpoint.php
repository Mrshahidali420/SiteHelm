<?php
/**
 * Exchanging a code, and rotating a refresh token.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Turns an approved consent into an access token, and a refresh token into a
 * fresh pair.
 *
 * Rotation is where this kind of server usually goes wrong, so three rules are
 * enforced here deliberately.
 *
 * A rotated refresh token is not deleted; its expiry is pulled in to a short
 * grace window. A client that sent a refresh request and lost the response can
 * retry inside that window instead of being signed out, and a replay after the
 * window is refused.
 *
 * The access token bound to a rotated refresh token is never cascade-deleted.
 * It lives out its own hour. Deleting it means every tool call already in
 * flight fails the moment a refresh happens.
 *
 * Refreshes for one token are serialised behind a lock, so two concurrent
 * refreshes cannot both rotate and leave the loser holding a token the winner
 * has already replaced.
 *
 * @package SiteHelm
 */
final class TokenEndpoint {

	/**
	 * How long an access token lives.
	 */
	public const ACCESS_TTL = 3600;

	/**
	 * How long a refresh token lives.
	 */
	public const REFRESH_TTL = 2592000;

	/**
	 * How long a rotated refresh token stays usable after rotation.
	 */
	public const GRACE_SECONDS = 120;

	/**
	 * How long the per-token refresh lock is held.
	 */
	private const LOCK_SECONDS = 10;

	/**
	 * Prefix on the refresh lock transient.
	 */
	private const LOCK_PREFIX = 'sitehelm_oauth_lock_';

	/**
	 * Constructs the endpoint.
	 *
	 * @param OAuthStore         $store    The token store.
	 * @param AuthorizationCodes $codes    The code store.
	 * @param TokenFactory       $factory  The randomness source.
	 * @param Pkce               $pkce     The challenge checker.
	 * @param MetadataDocument   $metadata The identifier comparer.
	 * @param PublicUrl          $urls     The address resolver.
	 * @param callable|null      $clock    Returns the current time; null for time().
	 */
	public function __construct(
		private readonly OAuthStore $store,
		private readonly AuthorizationCodes $codes,
		private readonly TokenFactory $factory,
		private readonly Pkce $pkce,
		private readonly MetadataDocument $metadata,
		private readonly PublicUrl $urls,
		private $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Answers a token request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response The token pair, or an RFC 6749 error.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$params = $this->params( $request );

		return match ( $params['grant_type'] ) {
			'authorization_code' => $this->exchangeCode( $params ),
			'refresh_token'      => $this->rotate( $params ),
			default              => $this->refuse(
				'unsupported_grant_type',
				'This site issues tokens for the authorization_code and refresh_token grants only.'
			),
		};
	}

	/**
	 * Redeems an authorization code.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return WP_REST_Response The token pair, or an error.
	 */
	private function exchangeCode( array $params ): WP_REST_Response {
		if ( '' === $params['code'] ) {
			return $this->refuse( 'invalid_request', 'Send the authorization code as the code parameter.' );
		}

		$grant = $this->codes->consume( $params['code'] );

		if ( null === $grant ) {
			return $this->refuse(
				'invalid_grant',
				'That authorization code is expired, already used, or unknown. Start the connection again.'
			);
		}

		if ( ! hash_equals( (string) $grant['client_id'], $params['client_id'] ) ) {
			return $this->refuse( 'invalid_grant', 'That authorization code was issued to a different app.' );
		}

		// Byte-exact here, with none of the loopback latitude the consent screen
		// allows: at this point the client is proving it is the same party, not
		// asking to be sent somewhere.
		if ( ! hash_equals( (string) $grant['redirect_uri'], $params['redirect_uri'] ) ) {
			return $this->refuse(
				'invalid_grant',
				'The redirect_uri sent here must match the one the authorization code was issued for, exactly.'
			);
		}

		if ( ! $this->pkce->verify( $params['code_verifier'], (string) $grant['code_challenge'] ) ) {
			return $this->refuse(
				'invalid_grant',
				'The code_verifier does not match the code_challenge this connection started with.'
			);
		}

		if ( '' !== $params['resource'] && ! $this->metadata->sameIdentifier( $params['resource'], (string) $grant['resource'] ) ) {
			return $this->refuse( 'invalid_target', 'That authorization code was issued for a different resource.' );
		}

		return $this->issuePair( (string) $grant['client_id'], (int) $grant['user_id'], (string) $grant['resource'] );
	}

	/**
	 * Exchanges a refresh token for a fresh pair.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return WP_REST_Response The token pair, or an error.
	 */
	private function rotate( array $params ): WP_REST_Response {
		if ( '' === $params['refresh_token'] ) {
			return $this->refuse( 'invalid_request', 'Send the refresh token as the refresh_token parameter.' );
		}

		$hash = $this->factory->fingerprint( $params['refresh_token'] );
		$lock = self::LOCK_PREFIX . substr( $hash, 0, 32 );

		// A refresh that cannot take the lock is told to retry rather than
		// handed a second rotation. Returning a pair here is what produces the
		// hourly "reconnect needed": the loser's write lands last and replaces
		// the token the winner already gave the client.
		if ( false !== get_transient( $lock ) ) {
			return $this->refuse(
				'invalid_request',
				'Another refresh for this connection is already in progress. Retry in a moment.',
				409
			);
		}

		set_transient( $lock, 1, self::LOCK_SECONDS );

		$response = $this->rotateLocked( $params, $hash );

		delete_transient( $lock );

		return $response;
	}

	/**
	 * The rotation itself, with the lock held.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param string                $hash   The presented token's fingerprint.
	 *
	 * @return WP_REST_Response The token pair, or an error.
	 */
	private function rotateLocked( array $params, string $hash ): WP_REST_Response {
		$row = $this->store->findToken( $hash, OAuthStore::TYPE_REFRESH );
		$now = ( $this->clock )();

		if ( null === $row || (int) $row['expires_at'] <= $now ) {
			return $this->refuse(
				'invalid_grant',
				'That refresh token is expired or unknown. Sign in to this site from the app again.'
			);
		}

		if ( '' !== $params['client_id'] && ! hash_equals( (string) $row['client_id'], $params['client_id'] ) ) {
			return $this->refuse( 'invalid_grant', 'That refresh token was issued to a different app.' );
		}

		// Not a delete: the expiry is pulled forward so a client that lost the
		// response can retry once, and a replay after the window still fails.
		$this->store->expireToken( $hash, min( (int) $row['expires_at'], $now + self::GRACE_SECONDS ) );

		return $this->issuePair( (string) $row['client_id'], (int) $row['user_id'], (string) $row['resource'] );
	}

	/**
	 * Mints and stores one access token and one refresh token.
	 *
	 * @param string $client_id The registered client.
	 * @param int    $user_id   The approving administrator.
	 * @param string $resource  The resource the tokens are bound to.
	 *
	 * @return WP_REST_Response The token response, or a storage failure.
	 */
	private function issuePair( string $client_id, int $user_id, string $resource ): WP_REST_Response { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- `resource` is the RFC 8707 parameter name, and renaming it here would leave the code and the protocol disagreeing.
		$now      = ( $this->clock )();
		$access   = $this->factory->mint();
		$refresh  = $this->factory->mint();
		$resource = '' !== $resource ? $resource : $this->urls->resource();

		$refresh_hash = $this->factory->fingerprint( $refresh );

		$stored_refresh = $this->store->insertToken(
			[
				'token_hash' => $refresh_hash,
				'token_type' => OAuthStore::TYPE_REFRESH,
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scopes'     => MetadataDocument::SCOPE,
				'resource'   => $resource,
				'expires_at' => $now + self::REFRESH_TTL,
				'refresh_of' => '',
				'created_at' => $now,
			]
		);

		$stored_access = $this->store->insertToken(
			[
				'token_hash' => $this->factory->fingerprint( $access ),
				'token_type' => OAuthStore::TYPE_ACCESS,
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scopes'     => MetadataDocument::SCOPE,
				'resource'   => $resource,
				'expires_at' => $now + self::ACCESS_TTL,
				'refresh_of' => $refresh_hash,
				'created_at' => $now,
			]
		);

		// A token handed out but never written is a token that works until the
		// first request and then does not. Say so instead.
		if ( 0 === $stored_access || 0 === $stored_refresh ) {
			return $this->refuse(
				'server_error',
				'This site could not store the new connection. Check the SiteHelm Status screen, then try again.',
				500
			);
		}

		return new WP_REST_Response(
			[
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => MetadataDocument::SCOPE,
			],
			200
		);
	}

	/**
	 * Every parameter this endpoint reads, from the body or the query.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return array<string, string> Parameter name to value.
	 */
	private function params( WP_REST_Request $request ): array {
		$body = [];

		parse_str( (string) $request->get_body(), $body );

		$decoded = json_decode( (string) $request->get_body(), true );

		if ( is_array( $decoded ) ) {
			$body = $decoded;
		}

		$read = static function ( string $name ) use ( $body ): string {
			$value = $body[ $name ] ?? '';

			return is_string( $value ) ? trim( $value ) : '';
		};

		return [
			'grant_type'    => $read( 'grant_type' ),
			'code'          => $read( 'code' ),
			'code_verifier' => $read( 'code_verifier' ),
			'client_id'     => $read( 'client_id' ),
			'redirect_uri'  => $read( 'redirect_uri' ),
			'refresh_token' => $read( 'refresh_token' ),
			'resource'      => $read( 'resource' ),
		];
	}

	/**
	 * An RFC 6749 error response.
	 *
	 * No refusal here ever repeats the token, code or verifier it was given.
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
