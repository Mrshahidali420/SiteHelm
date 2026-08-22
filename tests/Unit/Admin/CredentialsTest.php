<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Tests\TestCase;

final class CredentialsTest extends TestCase {

	/**
	 * Passwords per user id, shaped the way WordPress stores them.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every (user, uuid) pair the delete callable was asked to remove.
	 *
	 * @var array<int, array{int, string}>
	 */
	private array $deleted = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store   = [];
		$this->deleted = [];
	}

	private function credentials( bool $deleteOutcome = true ): Credentials {
		return new Credentials(
			fn( int $user_id ): array => $this->store[ $user_id ] ?? [],
			function ( int $user_id, string $uuid ) use ( $deleteOutcome ): bool {
				$this->deleted[] = [ $user_id, $uuid ];

				return $deleteOutcome;
			}
		);
	}

	private static function password( string $uuid, string $name, int $created, int $lastUsed = 0 ): array {
		return [
			'uuid'      => $uuid,
			'app_id'    => '',
			'name'      => $name,
			'password'  => 'hash',
			'created'   => $created,
			'last_used' => $lastUsed > 0 ? $lastUsed : null,
			'last_ip'   => $lastUsed > 0 ? '203.0.113.9' : null,
		];
	}

	private static function user( int $id, string $login ): object {
		$user             = new \stdClass();
		$user->ID         = $id;
		$user->user_login = $login;

		return $user;
	}

	public function testOnlyPasswordsCarryingSiteHelmsNameAreListed(): void {
		$this->store[7] = [
			self::password( 'aaa', ConnectScreen::PASSWORD_NAME, 100 ),
			self::password( 'bbb', 'Some other app', 200 ),
		];

		$rows = $this->credentials()->for_users( [ self::user( 7, 'agency' ) ] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'aaa', $rows[0]['uuid'] );
		$this->assertSame( 'agency', $rows[0]['login'] );
		$this->assertSame( 7, $rows[0]['user_id'] );
	}

	public function testCredentialsAcrossAccountsAreListedNewestFirst(): void {
		$this->store[7]  = [ self::password( 'old', ConnectScreen::PASSWORD_NAME, 100, 150 ) ];
		$this->store[12] = [ self::password( 'new', ConnectScreen::PASSWORD_NAME, 300 ) ];

		$rows = $this->credentials()->for_users( [ self::user( 7, 'agency' ), self::user( 12, 'editorial' ) ] );

		$this->assertSame( [ 'new', 'old' ], array_column( $rows, 'uuid' ) );
		$this->assertSame( 0, $rows[0]['last_used'] );
		$this->assertSame( 150, $rows[1]['last_used'] );
		$this->assertSame( '203.0.113.9', $rows[1]['last_ip'] );
	}

	public function testThingsThatAreNotAccountsAreSkipped(): void {
		$rows = $this->credentials()->for_users( [ 'nope', 42, new \stdClass() ] );

		$this->assertSame( [], $rows );
	}

	public function testRevokingASiteHelmCredentialDeletesIt(): void {
		$this->store[7] = [ self::password( 'aaa', ConnectScreen::PASSWORD_NAME, 100 ) ];

		$this->assertTrue( $this->credentials()->revoke( 7, 'aaa' ) );
		$this->assertSame( [ [ 7, 'aaa' ] ], $this->deleted );
	}

	/**
	 * A forged form naming another application's uuid must not be able to use
	 * this route to revoke a credential this plugin never made.
	 */
	public function testAPasswordThatIsNotSiteHelmsIsNeverDeleted(): void {
		$this->store[7] = [ self::password( 'bbb', 'Some other app', 200 ) ];

		$this->assertFalse( $this->credentials()->revoke( 7, 'bbb' ) );
		$this->assertSame( [], $this->deleted );
	}

	public function testAnUnknownOrEmptyUuidIsRefusedWithoutTouchingTheStore(): void {
		$this->store[7] = [ self::password( 'aaa', ConnectScreen::PASSWORD_NAME, 100 ) ];

		$this->assertFalse( $this->credentials()->revoke( 7, '' ) );
		$this->assertFalse( $this->credentials()->revoke( 7, 'zzz' ) );
		$this->assertFalse( $this->credentials()->revoke( 8, 'aaa' ) );
		$this->assertSame( [], $this->deleted );
	}

	public function testWordPressRefusingTheDeleteIsReportedAsNotRevoked(): void {
		$this->store[7] = [ self::password( 'aaa', ConnectScreen::PASSWORD_NAME, 100 ) ];

		$this->assertFalse( $this->credentials( false )->revoke( 7, 'aaa' ) );
	}
}
