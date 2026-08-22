<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Admin\CredentialsPanel;
use SiteHelm\Admin\RevokeAction;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class CredentialsPanelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	/**
	 * Renders the panel over a store keyed by user id.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $store   Passwords per user id.
	 * @param array<int, object>                           $users   The accounts offered.
	 */
	private function render( array $store, array $users ): string {
		$credentials = new Credentials(
			static fn( int $user_id ): array => $store[ $user_id ] ?? [],
			static fn(): bool => true
		);

		ob_start();
		( new CredentialsPanel( $credentials ) )->render( $users );

		return (string) ob_get_clean();
	}

	private static function user( int $id, string $login ): object {
		$user             = new \stdClass();
		$user->ID         = $id;
		$user->user_login = $login;

		return $user;
	}

	public function testWithNoCredentialsTheSectionSaysSoRatherThanShowingAnEmptyTable(): void {
		$html = $this->render( [], [ self::user( 7, 'agency' ) ] );

		$this->assertStringContainsString( 'Issued credentials', $html );
		$this->assertStringContainsString( 'No credentials yet', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function testEachCredentialNamesItsAccountCreationAndLastUse(): void {
		$store = [
			7  => [
				[
					'uuid'      => 'sh-7',
					'name'      => ConnectScreen::PASSWORD_NAME,
					'created'   => 1700000000,
					'last_used' => 1700003600,
				],
			],
			12 => [
				[
					'uuid'      => 'sh-12',
					'name'      => ConnectScreen::PASSWORD_NAME,
					'created'   => 1700009999,
					'last_used' => null,
				],
			],
		];

		$html = $this->render( $store, [ self::user( 7, 'agency' ), self::user( 12, 'editorial' ) ] );

		$this->assertStringContainsString( '<code>agency</code>', $html );
		$this->assertStringContainsString( '<code>editorial</code>', $html );
		$this->assertStringContainsString( '2023-11-14 22:13', $html );
		$this->assertStringContainsString( '5 minutes ago', $html );
		$this->assertStringContainsString( '<td>Never</td>', $html );
		// Newest first: editorial's credential was created later.
		$this->assertLessThan( strpos( $html, '<code>agency</code>' ), strpos( $html, '<code>editorial</code>' ) );
	}

	public function testEachRowCarriesARevokeFormNamingTheAccountAndThePassword(): void {
		$store = [
			12 => [
				[
					'uuid'    => 'sh-12',
					'name'    => ConnectScreen::PASSWORD_NAME,
					'created' => 1700009999,
				],
			],
		];

		$html = $this->render( $store, [ self::user( 12, 'editorial' ) ] );

		$this->assertStringContainsString( 'name="action" value="' . RevokeAction::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . RevokeAction::FIELD_USER . '" value="12"', $html );
		$this->assertStringContainsString( 'name="' . RevokeAction::FIELD_UUID . '" value="sh-12"', $html );
		$this->assertStringContainsString( '>Revoke</button>', $html );
	}

	public function testAJustTakenRevocationIsReported(): void {
		$_GET[ RevokeAction::ARG_STATE ] = RevokeAction::STATE_DONE;
		$html                             = $this->render( [], [ self::user( 7, 'agency' ) ] );
		$this->assertStringContainsString( 'Credential revoked.', $html );

		$_GET[ RevokeAction::ARG_STATE ] = RevokeAction::STATE_FAILED;
		$html                             = $this->render( [], [ self::user( 7, 'agency' ) ] );
		$this->assertStringContainsString( 'could not be revoked', $html );
	}

	public function testAnInjectedLoginIsEscapedOnTheWayOut(): void {
		$store = [
			7 => [
				[
					'uuid'    => 'sh-7',
					'name'    => ConnectScreen::PASSWORD_NAME,
					'created' => 1700000000,
				],
			],
		];

		$html = $this->render( $store, [ self::user( 7, '<script>alert(1)</script>' ) ] );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
