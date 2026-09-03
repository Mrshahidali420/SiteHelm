<?php
/**
 * Tests for TokenEndpoint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use SiteHelm\Auth\AuthorizationCodes;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Auth\Pkce;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Auth\TokenEndpoint;
use SiteHelm\Auth\TokenFactory;
use SiteHelm\Tests\Doubles\FakeWpRestRequest;

/**
 * Tests both grants: redeeming an authorization code, and rotating a refresh
 * token.
 */
final class TokenEndpointTest extends AuthTestCase {

	private const NOW      = 1700000000;
	private const CLIENT   = 'shc_someclient';
	private const REDIRECT = 'http://127.0.0.1:33418/callback';
	private const VERIFIER = 'a-verifier-long-enough-to-satisfy-rfc7636-rules';

	private int $now = self::NOW;

	private function codes(): AuthorizationCodes {
		return new AuthorizationCodes( new TokenFactory() );
	}

	private function endpoint( ?AuthorizationCodes $codes = null ): TokenEndpoint {
		$urls = new PublicUrl();

		return new TokenEndpoint(
			new OAuthStore(),
			$codes ?? $this->codes(),
			new TokenFactory(),
			new Pkce(),
			new MetadataDocument( $urls ),
			$urls,
			fn(): int => $this->now
		);
	}

	private function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param array<string, string> $params The form parameters.
	 */
	private function post( array $params, ?AuthorizationCodes $codes = null ): array {
		$response = $this->endpoint( $codes )->handle( new FakeWpRestRequest( http_build_query( $params ) ) );

		return [
			'status' => $response->get_status(),
			'body'   => $response->get_data(),
		];
	}

	/**
	 * Issues a code the way the consent screen does.
	 */
	private function issueCode( AuthorizationCodes $codes, string $verifier = self::VERIFIER ): string {
		return $codes->issue(
			[
				'client_id'      => self::CLIENT,
				'user_id'        => 7,
				'redirect_uri'   => self::REDIRECT,
				'code_challenge' => $this->challenge( $verifier ),
				'resource'       => 'https://example.com/wp-json/sitehelm/v1/mcp',
				'scope'          => 'mcp',
				'issued_at'      => self::NOW,
			]
		);
	}

	public function test_a_code_is_exchanged_for_a_bearer_token_and_a_refresh_token(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			],
			$codes
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Bearer', $result['body']['token_type'] );
		$this->assertSame( TokenEndpoint::ACCESS_TTL, $result['body']['expires_in'] );
		$this->assertSame( 'mcp', $result['body']['scope'] );
		$this->assertNotSame( $result['body']['access_token'], $result['body']['refresh_token'] );

		// The secret itself is never stored: only its fingerprint, and the row
		// carries the user the code was issued for, not whoever asked.
		$this->assertCount( 2, $this->wpdb->inserts );

		foreach ( $this->wpdb->inserts as $insert ) {
			$this->assertSame( 64, strlen( (string) $insert['data']['token_hash'] ) );
			$this->assertSame( 7, $insert['data']['user_id'] );
			$this->assertNotSame( $result['body']['access_token'], $insert['data']['token_hash'] );
			$this->assertNotSame( $result['body']['refresh_token'], $insert['data']['token_hash'] );
		}
	}

	public function test_the_wrong_verifier_is_refused(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => 'a-different-verifier-of-a-perfectly-legal-length',
			],
			$codes
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
		$this->assertSame( [], $this->wpdb->inserts );
	}

	/**
	 * A code is single-use. If a replay worked, an authorization code captured
	 * from a browser log or a redirect chain would be as good as a password.
	 */
	public function test_a_code_cannot_be_used_twice(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$params = [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => self::CLIENT,
			'redirect_uri'  => self::REDIRECT,
			'code_verifier' => self::VERIFIER,
		];

		$this->assertSame( 200, $this->post( $params, $codes )['status'] );

		$replay = $this->post( $params, $codes );

		$this->assertSame( 400, $replay['status'] );
		$this->assertSame( 'invalid_grant', $replay['body']['error'] );
	}

	public function test_an_expired_code_is_refused(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		// The code lives in a transient with its own TTL; expiry is modelled here
		// by the store forgetting it, which is exactly what WordPress does.
		$this->transients = [];

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			],
			$codes
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
	}

	public function test_a_code_redeemed_by_another_app_is_refused(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'shc_someoneelse',
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			],
			$codes
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
	}

	public function test_a_code_redeemed_for_a_different_redirect_uri_is_refused(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => 'http://127.0.0.1:33418/callback-two',
				'code_verifier' => self::VERIFIER,
			],
			$codes
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
	}

	public function test_an_unknown_grant_type_is_named_rather_than_ignored(): void {
		$result = $this->post( [ 'grant_type' => 'password' ] );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'unsupported_grant_type', $result['body']['error'] );
	}

	/**
	 * Rotation: the presented refresh token is not deleted, only brought forward
	 * to a short grace expiry, so a client whose response was lost can retry.
	 */
	public function test_a_refresh_token_rotates_and_the_old_one_keeps_a_grace_window(): void {
		$this->wpdb->rowQueue = [
			[
				'token_hash' => hash( 'sha256', 'old-refresh' ),
				'token_type' => OAuthStore::TYPE_REFRESH,
				'client_id'  => self::CLIENT,
				'user_id'    => 7,
				'scopes'     => 'mcp',
				'resource'   => 'https://example.com/wp-json/sitehelm/v1/mcp',
				'refresh_of' => '',
				'created_at' => self::NOW - 100,
				'expires_at' => self::NOW + 2592000,
			],
		];

		$result = $this->post(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'old-refresh',
				'client_id'     => self::CLIENT,
			]
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertNotSame( 'old-refresh', $result['body']['refresh_token'] );

		$this->assertCount( 1, $this->wpdb->updates );
		$this->assertSame(
			self::NOW + TokenEndpoint::GRACE_SECONDS,
			$this->wpdb->updates[0]['data']['expires_at']
		);
		foreach ( $this->wpdb->queries as $query ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'DELETE',
				$query,
				'Rotation must not delete the bound access token.'
			);
		}
		$this->assertCount( 2, $this->wpdb->inserts );
	}

	/**
	 * The other half of the grace window: once it has passed, the old token is a
	 * replay and is refused.
	 */
	public function test_a_replay_after_the_grace_window_is_refused(): void {
		$this->wpdb->rowQueue = [
			[
				'token_hash' => hash( 'sha256', 'old-refresh' ),
				'token_type' => OAuthStore::TYPE_REFRESH,
				'client_id'  => self::CLIENT,
				'user_id'    => 7,
				'scopes'     => 'mcp',
				'resource'   => '',
				'refresh_of' => '',
				'created_at' => self::NOW - 100,
				'expires_at' => self::NOW + TokenEndpoint::GRACE_SECONDS,
			],
		];

		$this->now = self::NOW + TokenEndpoint::GRACE_SECONDS + 1;

		$result = $this->post(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'old-refresh',
			]
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
		$this->assertSame( [], $this->wpdb->inserts );
	}

	/**
	 * Two refreshes racing is the ordinary case for a client with several
	 * windows open. The loser is told to retry rather than handed a second
	 * rotation that would overwrite the winner's token.
	 */
	public function test_a_concurrent_refresh_is_told_to_retry_rather_than_rotated_twice(): void {
		$this->transients[ 'sitehelm_oauth_lock_' . substr( hash( 'sha256', 'old-refresh' ), 0, 32 ) ] = 1;

		$result = $this->post(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'old-refresh',
			]
		);

		$this->assertSame( 409, $result['status'] );
		$this->assertSame( [], $this->wpdb->inserts );
		$this->assertSame( [], $this->wpdb->updates );
	}

	public function test_an_unknown_refresh_token_is_refused(): void {
		$this->wpdb->rowQueue = [ null ];

		$result = $this->post(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'never-issued',
			]
		);

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'invalid_grant', $result['body']['error'] );
	}

	/**
	 * A token handed to a client but never written works until the first request
	 * and then stops, with nothing to explain it. Saying so is better.
	 */
	public function test_a_storage_failure_is_reported_rather_than_returning_a_token(): void {
		$codes = $this->codes();
		$code  = $this->issueCode( $codes );

		$this->wpdb->failInsert = true;

		$result = $this->post(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			],
			$codes
		);

		$this->assertSame( 500, $result['status'] );
		$this->assertSame( 'server_error', $result['body']['error'] );
		$this->assertArrayNotHasKey( 'access_token', $result['body'] );
	}

	/**
	 * No refusal ever repeats the secret it was handed. A refusal is often the
	 * thing that ends up in a support ticket or a server log.
	 */
	public function test_no_refusal_echoes_the_secret_it_was_given(): void {
		$this->wpdb->rowQueue = [ null ];

		$result = $this->post(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'super-secret-value',
			]
		);

		$this->assertStringNotContainsString( 'super-secret-value', (string) json_encode( $result['body'] ) );
	}
}
