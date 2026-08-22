<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ClientConfig;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class ConnectScreenTest extends TestCase {

	/**
	 * The database double the real audit store reads through.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$_GET            = [];
		$_POST           = [];
	}

	protected function tearDown(): void {
		$_GET  = [];
		$_POST = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Renders the screen against a queued audit page.
	 *
	 * @param array<int, array<string, mixed>> $rows What the audit store will return.
	 */
	private function render( array $rows = [] ): string {
		$this->wpdb->resultQueue[] = $rows;

		ob_start();
		( new ConnectScreen( new AuditStore(), new Credentials( static fn(): array => [], static fn(): bool => true ) ) )->render();

		return (string) ob_get_clean();
	}

	public function testAVisitorWithoutTheCapabilityIsStoppedRatherThanShownTheCredentials(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		ob_start();

		try {
			( new ConnectScreen( new AuditStore(), new Credentials( static fn(): array => [], static fn(): bool => true ) ) )->render();
		} finally {
			ob_end_clean();
		}
	}

	public function testTheEndpointIsBuiltFromTheSitesOwnRestUrlRatherThanAssembled(): void {
		$this->assertSame(
			'https://example.test/wp-json/' . RestTransport::ROUTE_NAMESPACE . RestTransport::ROUTE,
			ConnectScreen::endpoint()
		);
	}

	public function testTheEndpointIsShownAsASelectableField(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'id="sitehelm-endpoint"', $html );
		$this->assertStringContainsString( ConnectScreen::endpoint(), $html );
		$this->assertStringContainsString( 'readonly', $html );
	}

	/**
	 * SiteHelm cannot know a client is configured until one calls, so before the
	 * first request the screen claims readiness rather than connection. Claiming
	 * "Connected" on an unconfigured site would send an operator debugging the
	 * wrong end of the connection.
	 */
	public function testWithNoRecordedRequestTheScreenClaimsReadinessRatherThanConnection(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Ready to connect', $html );
		$this->assertStringContainsString( 'No client has called this site yet', $html );
		$this->assertStringNotContainsString( 'Connected', $html );
	}

	public function testARecordedRequestIsWhatMakesTheScreenSayConnected(): void {
		$html = $this->render( [ [ 'recorded_at' => 1755300000 ] ] );

		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringContainsString( 'Last request 5 minutes ago', $html );
	}

	public function testASiteWithApplicationPasswordsDisabledIsReportedAsNotReady(): void {
		AdminWordPressStubs::$applicationPasswords = false;

		$html = $this->render();

		$this->assertStringContainsString( 'Not ready', $html );
		$this->assertStringContainsString( 'Application passwords are disabled on this site', $html );
	}

	public function testApplicationPasswordsBeingDisabledReplacesTheCreateButtonWithAnExplanation(): void {
		AdminWordPressStubs::$applicationPasswords = false;

		$html = $this->render();

		$this->assertStringNotContainsString( 'Create an application password', $html );
		$this->assertStringContainsString( 'so SiteHelm cannot create one', $html );
	}

	public function testWithNoPasswordYetTheScreenOffersToCreateOne(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Create an application password', $html );
		$this->assertStringContainsString( ConnectScreen::ACTION_CREATE_PASSWORD, $html );
	}

	public function testANonHttpsSiteIsWarnedThatTheCredentialWouldTravelInTheClear(): void {
		AdminWordPressStubs::$isSsl = false;

		$html = $this->render();

		$this->assertStringContainsString( 'travels in the clear', $html );
	}

	public function testAnHttpsSiteIsNotWarned(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'travels in the clear', $html );
	}

	/**
	 * Hands the screen a password to reveal, shaped as the handler stores it.
	 *
	 * @param string $password The password the handler minted.
	 * @param int    $user     The account it belongs to.
	 */
	private function queueHandoff( string $password, int $user = 0 ): string {
		$key = 'sitehelm_new_password_' . AdminWordPressStubs::$currentUserId;

		AdminWordPressStubs::$transients[ $key ] = [
			'user'     => 0 === $user ? AdminWordPressStubs::$currentUserId : $user,
			'password' => $password,
		];

		return $key;
	}

	public function testANewlyCreatedPasswordIsShownOnce(): void {
		$this->queueHandoff( 'abcd efgh ijkl' );

		$html = $this->render();

		$this->assertStringContainsString( 'abcd efgh ijkl', $html );
		$this->assertStringContainsString( 'does not show an application password a second time', $html );
	}

	/**
	 * A secret that survives its own page load is a secret waiting to be found in
	 * a backup. The handoff transient is deleted as it is read, so a refresh shows
	 * the create form again rather than the password a second time.
	 */
	public function testTheHandoffTransientIsDeletedAsItIsRead(): void {
		$key = $this->queueHandoff( 'abcd efgh ijkl' );

		$this->render();

		$this->assertSame( [ $key ], AdminWordPressStubs::$deletedTransients );
		$this->assertArrayNotHasKey( $key, AdminWordPressStubs::$transients );
	}

	/**
	 * The handoff is keyed by user, so two administrators creating a password at
	 * the same moment cannot be shown each other's.
	 */
	public function testAnotherUsersHandoffIsNeverRead(): void {
		AdminWordPressStubs::$transients['sitehelm_new_password_99'] = [
			'user'     => 99,
			'password' => 'someone elses password',
		];

		$html = $this->render();

		$this->assertStringNotContainsString( 'someone elses password', $html );
		$this->assertSame( [], AdminWordPressStubs::$deletedTransients );
	}

	/**
	 * With scripting off the two unselected snippets must still be on the page and
	 * readable, so the screen renders all three and lets script hide the rest.
	 */
	public function testEverySupportedClientSnippetIsRenderedRatherThanOnlyTheSelectedOne(): void {
		$html = $this->render();

		foreach ( [ 'claude-code', 'cursor', 'other' ] as $client ) {
			$this->assertStringContainsString( 'data-sitehelm-client="' . $client . '"', $html );
		}
	}

	public function testTheSnippetsCarryThePlaceholderUntilAPasswordExists(): void {
		$html = $this->render();

		$this->assertStringContainsString(
			base64_encode( AdminWordPressStubs::$currentUserLogin . ':' . ClientConfig::PASSWORD_PLACEHOLDER ),
			$html
		);
		$this->assertStringContainsString( 'they carry a placeholder', $html );
	}

	public function testTheSnippetsCarryTheNewPasswordOnceThereIsOne(): void {
		$this->queueHandoff( 'abcd efgh' );

		$html = $this->render();

		$this->assertStringContainsString(
			base64_encode( AdminWordPressStubs::$currentUserLogin . ':abcd efgh' ),
			$html
		);
		$this->assertStringContainsString( 'ready to paste', $html );
	}

	/**
	 * A password minted for another account must be described by that account's
	 * login, not by the person looking at the screen. Snippets that named the
	 * reader would be pasted into a client and fail to authenticate.
	 */
	public function testSnippetsForAnotherAccountsPasswordNameThatAccount(): void {
		AdminWordPressStubs::$users         = [ 12 => [ 'login' => 'editorial', 'role' => 'editor' ] ];
		AdminWordPressStubs::$editableUsers = [ 12 ];

		$this->queueHandoff( 'abcd efgh', 12 );

		$html = $this->render();

		$this->assertStringContainsString( base64_encode( 'editorial:abcd efgh' ), $html );
		$this->assertStringNotContainsString(
			base64_encode( AdminWordPressStubs::$currentUserLogin . ':abcd efgh' ),
			$html
		);
	}

	/**
	 * One choice is not a choice. A dropdown holding only the reader's own account
	 * asks a question with a single answer, so the field states the account.
	 */
	public function testWithOnlyOneAccountThePickerIsAStatementRatherThanAChoice(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'name="sitehelm_user"', $html );
		$this->assertStringContainsString( 'value="agency" readonly disabled', $html );
	}

	public function testTheAccountsThisPersonMayActForAreOffered(): void {
		AdminWordPressStubs::$users         = [ 12 => [ 'login' => 'editorial', 'role' => 'editor' ] ];
		AdminWordPressStubs::$editableUsers = [ 12 ];

		$html = $this->render();

		$this->assertStringContainsString( 'name="sitehelm_user"', $html );
		$this->assertStringContainsString( '<option value="12">editorial (editor)</option>', $html );
	}

	/**
	 * The picker cannot be the thing that grants the permission. An account this
	 * person may not edit is not offered, even though it exists on the site.
	 */
	public function testAnAccountThisPersonMayNotEditIsNotOffered(): void {
		AdminWordPressStubs::$users         = [
			12 => [ 'login' => 'editorial', 'role' => 'editor' ],
			13 => [ 'login' => 'someone-elses', 'role' => 'author' ],
		];
		AdminWordPressStubs::$editableUsers = [ 12 ];

		$html = $this->render();

		$this->assertStringContainsString( '>editorial (editor)<', $html );
		$this->assertStringNotContainsString( 'someone-elses', $html );
	}

	/**
	 * The rendered dropdown is not the boundary; the handler is. A POST naming an
	 * account this person may not edit is refused whatever the page offered, so a
	 * forged form cannot mint a credential for an administrator.
	 */
	public function testCreatingAPasswordForAnAccountThisPersonMayNotEditIsRefused(): void {
		AdminWordPressStubs::$users         = [ 13 => [ 'login' => 'someone-elses', 'role' => 'administrator' ] ];
		AdminWordPressStubs::$editableUsers = [];

		$_POST['sitehelm_user'] = '13';

		try {
			( new ConnectScreen( new AuditStore() ) )->handle_create_password();
			$this->fail( 'The handler minted a password for an account the actor may not edit.' );
		} catch ( AdminDied $died ) {
			$this->assertStringContainsString( 'do not have permission', $died->getMessage() );
		}
	}

	public function testAFailureCarriedBackFromThePasswordRequestIsShown(): void {
		$_GET['sitehelm_error'] = 'WordPress would not create an application password for your account.';

		$html = $this->render();

		$this->assertStringContainsString( 'would not create an application password', $html );
		$this->assertStringContainsString( 'Users, then Profile', $html );
	}

	public function testNoFailureBannerIsShownWhenNothingFailed(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'role="alert"', $html );
	}

	public function testAMessageCarriedInTheUrlIsEscapedBeforeItReachesThePage(): void {
		$_GET['sitehelm_error'] = '<script>alert(1)</script>';

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function testCreatingAPasswordWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		( new ConnectScreen( new AuditStore() ) )->handle_create_password();
	}

	/**
	 * The capability gate alone would let a third-party page mint a credential in
	 * an administrator's browser, so the POST is checked for its own nonce before
	 * anything is read from it.
	 */
	public function testTheCapabilityGateIsRefusedBeforeTheNonceIsEvenConsulted(): void {
		AdminWordPressStubs::$canManage = false;

		try {
			( new ConnectScreen( new AuditStore() ) )->handle_create_password();
		} catch ( AdminDied $died ) {
			unset( $died );
		}

		$this->assertSame( [], AdminWordPressStubs::$refererChecks );
	}

	public function testThePostIsVerifiedAgainstItsOwnNonceBeforeAnAccountIsRead(): void {
		AdminWordPressStubs::$users         = [ 13 => [ 'login' => 'someone-elses', 'role' => 'administrator' ] ];
		AdminWordPressStubs::$editableUsers = [];

		$_POST['sitehelm_user'] = '13';

		try {
			( new ConnectScreen( new AuditStore() ) )->handle_create_password();
		} catch ( AdminDied $died ) {
			unset( $died );
		}

		$this->assertSame( [ ConnectScreen::NONCE_CREATE_PASSWORD ], AdminWordPressStubs::$refererChecks );
	}
}
