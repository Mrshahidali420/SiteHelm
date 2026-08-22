<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Admin\RevokeAction;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class RevokeActionTest extends TestCase {

	private ?string $redirectedTo = null;

	/**
	 * Every (user, uuid) pair the delete callable was asked to remove.
	 *
	 * @var array<int, array{int, string}>
	 */
	private array $deleted = [];

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$_POST              = [];
		$this->redirectedTo = null;
		$this->deleted      = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	/**
	 * A store where every user holds exactly one SiteHelm credential, uuid "sh-1".
	 */
	private function post( int $user, string $uuid ): void {
		$_POST = [
			RevokeAction::FIELD_USER => (string) $user,
			RevokeAction::FIELD_UUID => $uuid,
		];

		$credentials = new Credentials(
			static fn( int $user_id ): array => [
				[
					'uuid'    => 'sh-1',
					'name'    => ConnectScreen::PASSWORD_NAME,
					'created' => 100,
				],
			],
			function ( int $user_id, string $uuid ): bool {
				$this->deleted[] = [ $user_id, $uuid ];

				return true;
			}
		);

		( new RevokeAction(
			$credentials,
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( 7, 'sh-1' );
	}

	public function testTheNonceIsCheckedAgainstTheRevokeAction(): void {
		$this->post( 7, 'sh-1' );

		$this->assertContains( RevokeAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testRevokingOnesOwnCredentialDeletesItAndReportsDone(): void {
		$this->post( 7, 'sh-1' );

		$this->assertSame( [ [ 7, 'sh-1' ] ], $this->deleted );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT . '&', (string) $this->redirectedTo );
		$this->assertStringContainsString( 'sitehelm_revoked=done', (string) $this->redirectedTo );
	}

	public function testRevokingForAnAccountThisPersonMayEditIsAllowed(): void {
		AdminWordPressStubs::$editableUsers = [ 12 ];

		$this->post( 12, 'sh-1' );

		$this->assertSame( [ [ 12, 'sh-1' ] ], $this->deleted );
		$this->assertStringContainsString( 'sitehelm_revoked=done', (string) $this->redirectedTo );
	}

	/**
	 * The listing is not the boundary; the handler is. A forged POST naming an
	 * account this person may not edit is refused whatever the page offered.
	 */
	public function testRevokingForAnAccountThisPersonMayNotEditIsRefused(): void {
		AdminWordPressStubs::$editableUsers = [];

		try {
			$this->post( 13, 'sh-1' );
			$this->fail( 'Expected the handler to stop.' );
		} catch ( AdminDied $died ) {
			$this->assertStringContainsString( 'may not revoke', $died->getMessage() );
		}

		$this->assertSame( [], $this->deleted );
		$this->assertNull( $this->redirectedTo );
	}

	public function testAUuidThatIsNotASiteHelmCredentialReportsFailedWithoutDeleting(): void {
		$this->post( 7, 'not-ours' );

		$this->assertSame( [], $this->deleted );
		$this->assertStringContainsString( 'sitehelm_revoked=failed', (string) $this->redirectedTo );
	}
}
