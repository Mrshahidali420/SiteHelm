<?php
/**
 * The site's licence: five states and one gate.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Pro\Licence\LicenceKey;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class LicenceTest extends TestCase {

	private string $public;
	private string $secret;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ): bool {
				unset( AdminWordPressStubs::$options[ $name ] );
				return true;
			}
		);
		$pair         = sodium_crypto_sign_keypair();
		$this->public = bin2hex( sodium_crypto_sign_publickey( $pair ) );
		$this->secret = bin2hex( sodium_crypto_sign_secretkey( $pair ) );
	}

	private function licence( string $today = '2026-08-23' ): Licence {
		return new Licence( $this->public, static fn(): string => $today );
	}

	private function store( string $site, ?string $exp = null ): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = LicenceKey::issue(
			[ 'site' => $site, 'plan' => 'pro', 'exp' => $exp, 'id' => 'id-1' ],
			$this->secret
		);
	}

	public function test_no_stored_key_is_missing_and_not_active(): void {
		$this->assertSame( Licence::STATE_MISSING, $this->licence()->state() );
		$this->assertFalse( $this->licence()->active() );
		$this->assertNull( $this->licence()->payload() );
		$this->assertSame( '', $this->licence()->key() );
	}

	public function test_a_garbage_key_is_invalid(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = 'SHP1.not.real';

		$this->assertSame( Licence::STATE_INVALID, $this->licence()->state() );
	}

	public function test_a_non_string_option_reads_as_missing(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = [ 'odd' ];

		$this->assertSame( Licence::STATE_MISSING, $this->licence()->state() );
	}

	public function test_a_key_for_another_site_is_refused_by_host(): void {
		$this->store( 'other.example' );

		$this->assertSame( Licence::STATE_OTHER_SITE, $this->licence()->state() );
	}

	public function test_a_key_for_this_site_is_active_and_www_and_case_do_not_matter(): void {
		$this->store( 'Example.Test' );
		$this->assertSame( Licence::STATE_ACTIVE, $this->licence()->state() );

		Functions\when( 'home_url' )->justReturn( 'https://WWW.Example.test/' );
		$this->assertSame( 'example.test', Licence::host() );
		$this->assertTrue( $this->licence()->active() );
	}

	public function test_a_wildcard_key_fits_any_host(): void {
		$this->store( '*' );

		$this->assertTrue( $this->licence()->active() );
	}

	public function test_an_expiry_in_the_past_is_expired_and_today_still_counts(): void {
		$this->store( 'example.test', '2026-08-22' );
		$this->assertSame( Licence::STATE_EXPIRED, $this->licence( '2026-08-23' )->state() );

		$this->store( 'example.test', '2026-08-23' );
		$this->assertSame( Licence::STATE_ACTIVE, $this->licence( '2026-08-23' )->state() );
	}

	public function test_the_wall_clock_is_used_when_no_clock_is_injected(): void {
		$this->store( 'example.test', '2000-01-01' );

		$this->assertSame( Licence::STATE_EXPIRED, ( new Licence( $this->public ) )->state() );
	}

	public function test_save_trims_and_stores_and_remove_forgets(): void {
		$licence = $this->licence();

		$licence->save( "  SHP1.a.b \n" );
		$this->assertSame( 'SHP1.a.b', AdminWordPressStubs::$options[ Licence::OPTION ] );
		$this->assertSame( 'SHP1.a.b', $licence->key() );

		$licence->remove();
		$this->assertArrayNotHasKey( Licence::OPTION, AdminWordPressStubs::$options );
	}

	public function test_payload_returns_the_verified_claims(): void {
		$this->store( 'example.test', '2030-01-01' );

		$this->assertSame(
			[ 'site' => 'example.test', 'plan' => 'pro', 'exp' => '2030-01-01', 'id' => 'id-1' ],
			$this->licence()->payload()
		);
	}

	public function test_the_gate_opens_only_for_an_active_licence(): void {
		$this->store( 'example.test' );
		$this->licence()->gate();

		$this->store( 'other.example' );
		try {
			$this->licence()->gate();
			$this->fail( 'gate() should have refused' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertStringContainsString( 'Health tab', (string) $e->remediation );
			$this->assertStringContainsString( 'no active Pro licence', $e->getMessage() );
		}
	}

	public function test_a_key_verified_against_the_shipped_public_key_is_invalid_when_signed_by_a_test_key(): void {
		$this->store( 'example.test' );

		$this->assertSame( Licence::STATE_INVALID, ( new Licence() )->state() );
	}
}
