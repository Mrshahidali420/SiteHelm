<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AuthSettingsAction;
use SiteHelm\Admin\ClientConfig;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Gateway\McpServer;
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
		$this->assertStringNotContainsString( '<span>Connected</span>', $html );
	}

	public function testARecordedRequestIsWhatMakesTheScreenSayConnected(): void {
		$html = $this->render( [ [ 'recorded_at' => 1755300000 ] ] );

		$this->assertStringContainsString( '<span>Connected</span>', $html );
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

	/**
	 * Every shape a client accepts reaches the page, each in its own panel with
	 * its own copy target. Rendering only the first one would leave a person
	 * whose client wants the other format with a snippet that quietly does
	 * nothing in the file they paste it into.
	 */
	public function testEveryShapeIsRenderedAsItsOwnPanelWithItsOwnCopyTarget(): void {
		$html = $this->render();

		foreach ( [ 'claude-code-oauth-cli', 'claude-code-json', 'vscode-settings', 'codex-toml' ] as $shape ) {
			$this->assertStringContainsString( 'data-sitehelm-shape="' . $shape . '"', $html );
			$this->assertStringContainsString( 'id="sitehelm-snippet-' . $shape . '"', $html );
		}
	}

	/**
	 * The shapes are grouped by connection method, which is what lets the chooser
	 * above them hide a header block from somebody who has chosen to sign in.
	 */
	public function testTheShapesAreGroupedByConnectionMethod(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-sitehelm-auth="' . ClientConfig::AUTH_OAUTH . '"', $html );
		$this->assertStringContainsString( 'data-sitehelm-auth="' . ClientConfig::AUTH_PASSWORD . '"', $html );
	}

	/**
	 * A group holding more than one shape gets a tab strip naming them. A tab
	 * strip over a single shape is a choice that isn't one, so it is not drawn.
	 */
	public function testAGroupOfSeveralShapesIsGivenATabStripNamingEachOne(): void {
		$html = $this->render();

		$this->assertStringContainsString(
			'name="sitehelm-shape-vscode-' . ClientConfig::AUTH_PASSWORD . '"',
			$html
		);
		$this->assertStringContainsString( 'value="vscode-settings"', $html );
		$this->assertStringNotContainsString(
			'name="sitehelm-shape-antigravity-' . ClientConfig::AUTH_PASSWORD . '"',
			$html
		);
	}

	/**
	 * Each shape states the file it goes in, above the snippet rather than in the
	 * copy target. Pasting an mcpServers object into a file that reads servers is
	 * ignored without a word, and this line is what prevents it.
	 */
	public function testEachShapeSaysWhichFileItGoesIn(): void {
		$html = $this->render();

		$this->assertStringContainsString( '.vscode/mcp.json in the workspace', $html );
		$this->assertStringContainsString( '~/.cursor/mcp.json', $html );
		$this->assertStringContainsString( '~/.codex/config.toml', $html );
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

	public function testTheScreenAsksHowTheAppSignsInBeforeItShowsAnythingToPaste(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-sitehelm-methods', $html );
		$this->assertStringContainsString( 'Sign in with OAuth', $html );
		$this->assertStringContainsString( 'Application password', $html );
		$this->assertLessThan(
			strpos( $html, 'id="sitehelm-endpoint"' ),
			strpos( $html, 'data-sitehelm-methods' ),
			'The choice has to come before the snippets it governs.'
		);
	}

	public function testOnAnHttpsSiteWithOauthOnTheSignInPathIsOfferedAndPreselected(): void {
		$html = $this->render();

		$this->assertStringContainsString(
			'value="' . ClientConfig::AUTH_OAUTH . '" checked',
			$html
		);
		$this->assertStringNotContainsString(
			'value="' . ClientConfig::AUTH_OAUTH . '" checked disabled',
			$html
		);
		$this->assertStringContainsString( 'sitehelm-oauth-url', $html );
		$this->assertStringContainsString( 'https://example.test/wp-json/sitehelm/v1/mcp', $html );
	}

	public function testWithOauthSwitchedOffTheSignInCardIsDisabledAndSaysSo(): void {
		AdminWordPressStubs::$options[ AuthSettings::OPTION ] = '0';

		$html = $this->render();

		$this->assertStringContainsString(
			'value="' . ClientConfig::AUTH_OAUTH . '" disabled',
			$html
		);
		$this->assertStringContainsString(
			'value="' . ClientConfig::AUTH_PASSWORD . '" checked',
			$html
		);
		$this->assertStringContainsString( 'Signing in is switched off on this site', $html );
		$this->assertStringNotContainsString( 'sitehelm-oauth-url', $html );
	}

	public function testOnAPlainHttpSiteTheScreenNamesTheAddressAndSendsThePersonToThePasswordPath(): void {
		AdminWordPressStubs::$options[ PublicUrl::OPTION ] = 'http://example.com';

		$html = $this->render();

		$this->assertStringContainsString( 'http://example.com', $html );
		$this->assertStringContainsString( 'which is not HTTPS', $html );
		$this->assertStringContainsString( 'use an application password below', $html );
		$this->assertStringContainsString(
			'value="' . ClientConfig::AUTH_PASSWORD . '" checked',
			$html
		);
	}

	public function testTheServerUrlOverrideRulesEveryAddressOnTheScreen(): void {
		AdminWordPressStubs::$options[ PublicUrl::OPTION ] = 'https://public.example';

		$html = $this->render();

		$this->assertStringContainsString( 'https://public.example/wp-json/sitehelm/v1/mcp', $html );
		$this->assertStringNotContainsString( 'https://example.test/wp-json/sitehelm/v1/mcp', $html );
	}
	public function testTheTroubleshootingBlockSitsUnderTheSignInCardAndIsFoldedAway(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<details class="sitehelm-details sitehelm-troubleshooting">', $html );
		$this->assertGreaterThan(
			strpos( $html, 'id="sitehelm-oauth-url"' ),
			strpos( $html, 'sitehelm-troubleshooting' )
		);
	}

	public function testTheTroubleshootingBlockNamesEveryProtocolVersionTheServerSpeaks(): void {
		$html = $this->render();

		foreach ( McpServer::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			$this->assertStringContainsString( $version, $html );
		}
	}

	public function testTheTroubleshootingBlockCoversTheFourWaysDiscoveryFails(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Something else answers the sign-in addresses', $html );
		$this->assertStringContainsString( 'is not the one it can reach', $html );
		$this->assertStringContainsString( 'not served over HTTPS', $html );
		$this->assertStringContainsString( 'cannot reach itself', $html );
	}

	public function testTheSettingsPanelIsOnThisScreenBecauseTheSignInCardPromisesIt(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="' . AuthSettingsAction::FIELD_URL . '"', $html );
		$this->assertStringContainsString( 'Test discovery', $html );
	}
}
