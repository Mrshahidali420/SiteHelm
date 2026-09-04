<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ConnectModal;
use SiteHelm\Admin\ConnectModalAction;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * "Not now", and the two things it has to get right: remember it, and come back.
 */
final class ConnectModalActionTest extends TestCase {

	/**
	 * The URL the handler redirected to, or null if it did not.
	 */
	private ?string $redirectedTo = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->redirectedTo = null;
	}

	private function post(): void {
		( new ConnectModalAction(
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post();
	}

	public function testNothingIsStoredForAUserWithoutTheCapability(): void {
		AdminWordPressStubs::$canManage = false;

		try {
			$this->post();
		} catch ( AdminDied $died ) {
			unset( $died );
		}

		$this->assertSame( [], AdminWordPressStubs::$userMeta );
	}

	public function testTheNonceIsChecked(): void {
		$this->post();

		$this->assertContains( ConnectModalAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	/**
	 * The flag is per account. One administrator saying not now must not decide
	 * for another who has not seen the dialog and still needs what it says.
	 */
	public function testTheDismissalIsStoredAgainstTheSignedInAccountAlone(): void {
		AdminWordPressStubs::$currentUserId = 12;

		$this->post();

		$this->assertSame(
			[ 12 => [ ConnectModal::DISMISSED_META => '1' ] ],
			AdminWordPressStubs::$userMeta
		);
		$this->assertTrue( ConnectModal::is_dismissed( 12 ) );
		$this->assertFalse( ConnectModal::is_dismissed( 13 ) );
	}

	/**
	 * The dialog opens on any console screen, so closing it must not throw
	 * somebody from Permissions back to the dashboard.
	 */
	public function testItComesBackToTheScreenTheDialogWasClosedOn(): void {
		AdminWordPressStubs::$referer = 'https://example.test/wp-admin/admin.php?page=sitehelm-modules';

		$this->post();

		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=sitehelm-modules', $this->redirectedTo );
	}

	public function testWithNoRefererToTrustItGoesHome(): void {
		$this->post();

		$this->assertStringContainsString( 'page=sitehelm', (string) $this->redirectedTo );
	}
}
