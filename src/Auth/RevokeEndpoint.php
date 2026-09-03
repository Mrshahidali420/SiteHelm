<?php
/**
 * Token revocation, RFC 7009.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Lets a client hand a token back.
 *
 * The answer is always 200, whatever was sent. That is not laziness: a
 * revocation endpoint that reports "unknown token" is an oracle anyone can use
 * to test guessed tokens, and RFC 7009 §2.2 requires the invalid case to
 * succeed anyway.
 *
 * Revoking a refresh token also removes the access tokens minted from it,
 * because a person revoking is ending a session. Rotation does the opposite and
 * deliberately so — see {@see TokenEndpoint}.
 *
 * @package SiteHelm
 */
final class RevokeEndpoint {

	/**
	 * Constructs the endpoint.
	 *
	 * @param OAuthStore   $store   The token store.
	 * @param TokenFactory $factory The fingerprint source.
	 */
	public function __construct(
		private readonly OAuthStore $store,
		private readonly TokenFactory $factory
	) {
	}

	/**
	 * Answers a revocation request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Always 200.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = [];

		parse_str( (string) $request->get_body(), $body );

		$decoded = json_decode( (string) $request->get_body(), true );

		if ( is_array( $decoded ) ) {
			$body = $decoded;
		}

		$token = isset( $body['token'] ) && is_string( $body['token'] ) ? trim( $body['token'] ) : '';

		if ( '' !== $token ) {
			// The fingerprint is looked up without being told which kind of
			// token it is: `token_type_hint` is a hint, and honouring it would
			// mean refusing to revoke a token whose owner mislabelled it.
			$hash = $this->factory->fingerprint( $token );

			$this->store->deleteToken( $hash );
			$this->store->deleteTokensDerivedFrom( $hash );
		}

		return new WP_REST_Response( [ 'revoked' => true ], 200 );
	}
}
