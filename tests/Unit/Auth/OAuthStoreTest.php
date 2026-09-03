<?php
/**
 * Tests for OAuthStore, OAuthGarbageCollector and RevokeEndpoint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use SiteHelm\Auth\OAuthGarbageCollector;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Auth\RevokeEndpoint;
use SiteHelm\Auth\TokenFactory;
use SiteHelm\Tests\Doubles\FakeWpRestRequest;

/**
 * Tests the storage layer's own rules: what it will delete, what it refuses to
 * delete, and what it reports about the site's state.
 */
final class OAuthStoreTest extends AuthTestCase {

	private const NOW = 1700000000;

	/**
	 * Every query the store issues names the prefixed table and passes its
	 * values through prepare(). A concatenated identifier here would be a
	 * SQL injection on a public, unauthenticated endpoint.
	 */
	public function test_every_query_is_prepared_and_prefixed(): void {
		$store = new OAuthStore();

		$this->wpdb->varQueue = [ 0 ];
		$store->countNeverAuthorized();
		$store->findToken( str_repeat( 'a', 64 ), OAuthStore::TYPE_ACCESS );
		$store->pruneExpiredTokens( self::NOW );

		$this->assertNotSame( [], $this->wpdb->queries );

		foreach ( $this->wpdb->queries as $query ) {
			$this->assertStringContainsString( 'wp_sitehelm_oauth_', $query );
		}

		$this->assertNotSame( [], $this->wpdb->prepared );
	}

	/**
	 * The clause that stops the collector eating live connections. A client
	 * whose refresh token lapsed after a month of disuse looks exactly like an
	 * abandoned registration until this is read.
	 */
	public function test_a_registration_that_ever_completed_a_consent_is_never_pruned(): void {
		( new OAuthStore() )->pruneNeverAuthorizedClients( self::NOW - OAuthGarbageCollector::ABANDONED_SECONDS );

		$this->assertCount( 1, $this->wpdb->queries );
		$this->assertStringContainsString( 'authorized_at = 0', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'created_at <', $this->wpdb->queries[0] );
	}

	public function test_the_collector_prunes_both_tables_and_reports_what_it_removed(): void {
		$this->wpdb->queryRowsQueue = [ 3, 2 ];

		$removed = ( new OAuthGarbageCollector( new OAuthStore() ) )->collect( self::NOW );

		$this->assertSame(
			[
				'oauth_tokens'  => 3,
				'oauth_clients' => 2,
			],
			$removed
		);

		$this->assertStringContainsString( 'expires_at <=', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'authorized_at = 0', $this->wpdb->queries[1] );
	}

	/**
	 * The opportunistic run exists because a great many sites have no working
	 * cron, but it must not turn every request into two DELETEs.
	 */
	public function test_the_opportunistic_run_happens_once_per_window_and_then_stands_down(): void {
		$collector = new OAuthGarbageCollector( new OAuthStore() );

		$this->wpdb->queryRowsQueue = [ 1, 0 ];

		$this->assertNotNull( $collector->collectThrottled( self::NOW ) );
		$this->assertCount( 2, $this->wpdb->queries );

		$this->assertNull( $collector->collectThrottled( self::NOW + 60 ) );
		$this->assertCount( 2, $this->wpdb->queries );
	}

	/**
	 * The Home screen asks this to decide whether the connect step is done. It
	 * reads the clients table rather than the tokens table on purpose: tokens
	 * expire, and a real connection that has simply gone quiet is still a
	 * connection the operator made.
	 */
	public function test_has_authenticated_reports_whether_any_connection_was_ever_approved(): void {
		$this->wpdb->varQueue = [ 'shc_someclient' ];
		$this->assertTrue( OAuthStore::has_authenticated() );

		$this->assertCount( 1, $this->wpdb->queries );
		$this->assertStringContainsString( 'authorized_at > 0', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'LIMIT 1', $this->wpdb->queries[0] );

		$this->wpdb->varQueue = [ null ];
		$this->assertFalse( OAuthStore::has_authenticated() );
	}

	/**
	 * It is called on the dashboard of a site that may have been updated but
	 * not yet migrated, so a missing table has to read as "no connections"
	 * rather than a fatal on an admin screen.
	 */
	public function test_has_authenticated_is_false_when_there_is_no_database_at_all(): void {
		unset( $GLOBALS['wpdb'] );

		$this->assertFalse( OAuthStore::has_authenticated() );

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * Revocation answers 200 whatever it was handed. Telling an unauthenticated
	 * caller whether a token existed turns the endpoint into an oracle for
	 * checking stolen tokens, and RFC 7009 requires the flat answer anyway.
	 */
	public function test_revocation_deletes_the_token_and_everything_minted_from_it(): void {
		$endpoint = new RevokeEndpoint( new OAuthStore(), new TokenFactory() );

		$response = $endpoint->handle( new FakeWpRestRequest( http_build_query( [ 'token' => 'the-refresh-token' ] ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'revoked' => true ], $response->get_data() );

		$this->assertCount( 2, $this->wpdb->queries );
		$this->assertStringContainsString( hash( 'sha256', 'the-refresh-token' ), $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'refresh_of', $this->wpdb->queries[1] );
	}

	public function test_revocation_answers_the_same_way_for_a_token_that_was_never_issued(): void {
		$endpoint = new RevokeEndpoint( new OAuthStore(), new TokenFactory() );

		$unknown = $endpoint->handle( new FakeWpRestRequest( http_build_query( [ 'token' => 'never-issued' ] ) ) );
		$empty   = $endpoint->handle( new FakeWpRestRequest( '' ) );

		$this->assertSame( 200, $unknown->get_status() );
		$this->assertSame( 200, $empty->get_status() );
		$this->assertSame( $unknown->get_data(), $empty->get_data() );
	}

	/**
	 * A JSON body is accepted as well as a form-encoded one: clients differ,
	 * and a revocation that silently does nothing is worse than most bugs.
	 */
	public function test_a_json_body_revokes_just_as_a_form_body_does(): void {
		$endpoint = new RevokeEndpoint( new OAuthStore(), new TokenFactory() );

		$endpoint->handle( new FakeWpRestRequest( (string) json_encode( [ 'token' => 'the-refresh-token' ] ) ) );

		$this->assertCount( 2, $this->wpdb->queries );
		$this->assertStringContainsString( hash( 'sha256', 'the-refresh-token' ), $this->wpdb->queries[0] );
	}
}
