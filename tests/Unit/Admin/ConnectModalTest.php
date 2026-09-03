<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectModal;
use SiteHelm\Admin\ConnectModalAction;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * The first-run dialog: when it opens, and what it offers when it does.
 *
 * The rule it exists to hold is that the dialog asks for one thing. A test that
 * finds a second call to action in here has found the regression the split was
 * made to prevent, so the markup assertions are as much about what is absent as
 * about what is present.
 */
final class ConnectModalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	public function testItOpensOnlyWhenNothingCanReachTheSiteAndNobodySaidNo(): void {
		$this->assertTrue( ConnectModal::should_open( false, false ) );
		$this->assertFalse( ConnectModal::should_open( true, false ) );
		$this->assertFalse( ConnectModal::should_open( false, true ) );
		$this->assertFalse( ConnectModal::should_open( true, true ) );
	}

	public function testTheDismissalIsReadBackFromTheAccountThatMadeIt(): void {
		AdminWordPressStubs::$userMeta[ 7 ] = [ ConnectModal::DISMISSED_META => '1' ];

		$this->assertTrue( ConnectModal::is_dismissed( 7 ) );
		$this->assertFalse( ConnectModal::is_dismissed( 9 ) );
	}

	/**
	 * A logged-out request has no account to have dismissed anything, and asking
	 * user meta about account zero would answer for whatever is stored there.
	 */
	public function testNobodyIsNotAnAccountThatDismissedIt(): void {
		$this->assertFalse( ConnectModal::is_dismissed( 0 ) );
	}

	public function testACredentialCountsAsConnected(): void {
		$this->assertTrue( ConnectModal::is_connected( self::credentials() ) );
	}

	public function testNoCredentialAndNoSignInIsNotConnected(): void {
		$this->assertFalse( ConnectModal::is_connected( new Credentials( static fn(): array => [] ) ) );
	}

	/**
	 * The OAuth half is asked through a callable seam so the console does not
	 * fatal on a build where browser sign-in has not landed. An authorized
	 * registration is enough on its own: it means something has already reached
	 * the site, credential or no credential.
	 */
	public function testABrowserSignInCountsAsConnectedWithoutAnyCredential(): void {
		$wpdb            = new FakeWpdb();
		$wpdb->varQueue  = [ 'client-1' ];
		$GLOBALS['wpdb'] = $wpdb;

		try {
			$this->assertTrue( ConnectModal::oauth_seen() );
			$wpdb->varQueue = [ 'client-1' ];
			$this->assertTrue( ConnectModal::is_connected( new Credentials( static fn(): array => [] ) ) );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * No authorized registration is not an answer on its own -- the credential
	 * store still gets asked.
	 */
	public function testNoBrowserSignInFallsThroughToTheCredentials(): void {
		$wpdb            = new FakeWpdb();
		$wpdb->varQueue  = [ null, null ];
		$GLOBALS['wpdb'] = $wpdb;

		try {
			$this->assertFalse( ConnectModal::oauth_seen() );
			$this->assertTrue( ConnectModal::is_connected( self::credentials() ) );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function testItPrintsNothingWhenItShouldNotOpen(): void {
		$this->assertSame( '', self::render( true, false ) );
		$this->assertSame( '', self::render( false, true ) );
	}

	/**
	 * Printed closed, because `showModal()` is what supplies the backdrop, the
	 * focus trap and Escape. An `open` attribute would nail an untrapped panel
	 * over the screen for anyone whose script never ran.
	 */
	public function testTheDialogIsPrintedClosed(): void {
		$html = self::render( false, false );

		$this->assertStringContainsString( '<dialog id="' . ConnectModal::DIALOG_ID . '"', $html );
		$this->assertStringNotContainsString( '<dialog id="' . ConnectModal::DIALOG_ID . '" open', $html );
		$this->assertStringContainsString( 'data-sitehelm-connect-modal', $html );
	}

	public function testItAsksForOneThingAndSendsThemToConnect(): void {
		$html = self::render( false, false );

		$this->assertStringContainsString( 'Connect your first AI app', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT, $html );
		$this->assertSame( 1, substr_count( $html, 'sitehelm-btn--primary' ) );
	}

	/**
	 * The list of optional things lives further down Home, not in here. If any of
	 * it reappeared the dialog would be a checklist again, which is exactly what
	 * the split undid.
	 */
	public function testTheDialogCarriesNoStepsNoTallyAndNoOtherErrands(): void {
		$html = self::render( false, false );

		$this->assertStringNotContainsString( 'Step ', $html );
		$this->assertStringNotContainsString( 'sitehelm-walkthrough', $html );
		$this->assertStringNotContainsString( 'Make a test call', $html );
		$this->assertStringNotContainsString( 'Undo it', $html );
	}

	/**
	 * BOTH ways out post. A close button that only hid the dialog would have it
	 * back on the next page load, which reads as the console ignoring the person.
	 */
	public function testBothWaysOutPostTheSameDismissal(): void {
		$html = self::render( false, false );

		$this->assertSame( 2, substr_count( $html, 'name="action" value="' . ConnectModalAction::ACTION . '"' ) );
		$this->assertSame( 2, substr_count( $html, 'admin-post.php' ) );
		$this->assertStringContainsString( 'Not now', $html );
		$this->assertStringContainsString( 'Close and do not ask again', $html );
	}

	public function testSomebodyWhoCannotManageTheSiteIsNotShownIt(): void {
		AdminWordPressStubs::$canManage = false;

		ob_start();
		ConnectModal::render_if_needed( new Credentials( static fn(): array => [] ) );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function testAnAdministratorWithNothingConnectedIsShownIt(): void {
		ob_start();
		ConnectModal::render_if_needed( new Credentials( static fn(): array => [] ) );

		$this->assertStringContainsString( 'Connect your first AI app', (string) ob_get_clean() );
	}

	public function testTheirOwnDismissalIsHonouredOnEveryScreen(): void {
		AdminWordPressStubs::$userMeta[ AdminWordPressStubs::$currentUserId ] = [ ConnectModal::DISMISSED_META => '1' ];

		ob_start();
		ConnectModal::render_if_needed( new Credentials( static fn(): array => [] ) );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @param bool $connected Whether anything can reach the site.
	 * @param bool $dismissed Whether this administrator closed it before.
	 */
	private static function render( bool $connected, bool $dismissed ): string {
		ob_start();
		ConnectModal::render( $connected, $dismissed );

		return (string) ob_get_clean();
	}

	/**
	 * One SiteHelm application password on the signed-in account.
	 */
	private static function credentials(): Credentials {
		return new Credentials(
			static fn( int $user_id ): array => [
				[
					'name'      => ConnectScreen::PASSWORD_NAME,
					'uuid'      => 'aaaaaaaa-0000-4000-8000-000000000000',
					'created'   => 1755200000,
					'last_used' => 0,
					'last_ip'   => '',
				],
			]
		);
	}
}
