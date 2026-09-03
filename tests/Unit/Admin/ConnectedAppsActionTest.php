<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ConnectedAppsAction;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class ConnectedAppsActionTest extends TestCase {

	/**
	 * The database double the store reads and writes through.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	/**
	 * Where the handler sent the browser, if anywhere.
	 *
	 * @var array<int, string>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->sent      = [];
		$_POST           = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Builds a handler that records its redirect instead of exiting.
	 */
	private function action(): ConnectedAppsAction {
		return new ConnectedAppsAction(
			new OAuthStore(),
			function ( string $url ): void {
				$this->sent[] = $url;
			}
		);
	}

	/**
	 * Queues the row `findClient()` will return for the named registration.
	 *
	 * @param string $client_id The registration to find.
	 */
	private function existing( string $client_id ): void {
		$this->wpdb->rowQueue[] = [
			'client_id'     => $client_id,
			'client_name'   => 'Claude Desktop',
			'redirect_uris' => '[]',
			'created_at'    => 1_699_000_000,
			'authorized_at' => 1_699_000_100,
		];
	}

	public function testAVisitorWithoutTheCapabilityIsRefusedBeforeAnythingIsRead(): void {
		AdminWordPressStubs::$canManage = false;

		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';

		$this->expectException( AdminDied::class );

		$this->action()->handle_remove();
	}

	public function testThePostIsVerifiedAgainstItsOwnNonceBeforeAnythingIsDeleted(): void {
		$this->existing( 'shc_abc123' );

		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';

		$this->action()->handle_sign_out();

		$this->assertSame( [ ConnectedAppsAction::NONCE ], AdminWordPressStubs::$refererChecks );
	}

	public function testSigningOutDeletesTheTokensAndLeavesTheRegistrationStanding(): void {
		$this->existing( 'shc_abc123' );

		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';

		$this->action()->handle_sign_out();

		$sql = implode( ' ', $this->wpdb->queries );

		$this->assertStringContainsString( 'DELETE FROM ' . OAuthStore::tokensTable(), $sql );
		$this->assertStringNotContainsString( 'DELETE FROM ' . OAuthStore::clientsTable(), $sql );
	}

	public function testRemovingDeletesTheRegistrationAsWellAsItsTokens(): void {
		$this->existing( 'shc_abc123' );

		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';

		$this->action()->handle_remove();

		$sql = implode( ' ', $this->wpdb->queries );

		$this->assertStringContainsString( 'DELETE FROM ' . OAuthStore::tokensTable(), $sql );
		$this->assertStringContainsString( 'DELETE FROM ' . OAuthStore::clientsTable(), $sql );
	}

	public function testEachHandlerSendsThePersonBackToConnectSayingWhatItDid(): void {
		$this->existing( 'shc_abc123' );
		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';
		$this->action()->handle_sign_out();

		$this->existing( 'shc_abc123' );
		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_abc123';
		$this->action()->handle_remove();

		$this->assertCount( 2, $this->sent );
		$this->assertStringContainsString( ConnectedAppsAction::STATE_SIGNED_OUT, $this->sent[0] );
		$this->assertStringContainsString( ConnectedAppsAction::STATE_REMOVED, $this->sent[1] );
		$this->assertStringContainsString( 'page=sitehelm-connect', $this->sent[0] );
	}

	public function testAPostNamingNoRegistrationDeletesNothingAndSaysSo(): void {
		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = '';

		$this->action()->handle_remove();

		$this->assertSame( [], $this->wpdb->queries );
		$this->assertStringContainsString( ConnectedAppsAction::STATE_UNKNOWN, $this->sent[0] );
	}

	public function testAPostNamingARegistrationThisSiteDoesNotHaveDeletesNothing(): void {
		$_POST[ ConnectedAppsAction::FIELD_CLIENT ] = 'shc_not_here';

		$this->action()->handle_remove();

		$this->assertStringNotContainsString( 'DELETE', implode( ' ', $this->wpdb->queries ) );
		$this->assertStringContainsString( ConnectedAppsAction::STATE_UNKNOWN, $this->sent[0] );
	}
}
