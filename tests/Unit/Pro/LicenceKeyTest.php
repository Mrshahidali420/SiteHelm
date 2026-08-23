<?php
/**
 * Licence keys: issued, verified, and refused when tampered with.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro;

use SiteHelm\Pro\Licence\LicenceKey;
use SiteHelm\Tests\TestCase;

final class LicenceKeyTest extends TestCase {

	private string $public;
	private string $secret;

	protected function setUp(): void {
		parent::setUp();
		$pair         = sodium_crypto_sign_keypair();
		$this->public = bin2hex( sodium_crypto_sign_publickey( $pair ) );
		$this->secret = bin2hex( sodium_crypto_sign_secretkey( $pair ) );
	}

	/** @param array<string, mixed> $overrides */
	private function issue( array $overrides = [] ): string {
		return LicenceKey::issue(
			array_merge( [ 'site' => 'Example.com', 'plan' => 'pro', 'exp' => '2030-01-01', 'id' => 'order-1' ], $overrides ),
			$this->secret
		);
	}

	public function test_an_issued_key_round_trips_with_the_site_lower_cased(): void {
		$payload = LicenceKey::parse( $this->issue(), $this->public );

		$this->assertSame( [ 'site' => 'example.com', 'plan' => 'pro', 'exp' => '2030-01-01', 'id' => 'order-1' ], $payload );
	}

	public function test_a_lifetime_key_carries_a_null_expiry(): void {
		$this->assertNull( LicenceKey::parse( $this->issue( [ 'exp' => null ] ), $this->public )['exp'] );
	}

	public function test_the_key_has_the_prefix_and_three_parts(): void {
		$key = $this->issue();

		$this->assertStringStartsWith( LicenceKey::PREFIX . '.', $key );
		$this->assertCount( 3, explode( '.', $key ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_.-]+$/', $key );
	}

	public function test_surrounding_whitespace_is_tolerated(): void {
		$this->assertNotNull( LicenceKey::parse( "  \n" . $this->issue() . " \t", $this->public ) );
	}

	public function test_a_key_signed_by_another_secret_is_refused(): void {
		$other = bin2hex( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) );
		$key   = LicenceKey::issue( [ 'site' => 'example.com', 'plan' => 'pro', 'exp' => null, 'id' => 'x' ], $other );

		$this->assertNull( LicenceKey::parse( $key, $this->public ) );
	}

	public function test_an_edited_payload_is_refused(): void {
		[ $prefix, , $signature ] = explode( '.', $this->issue() );
		$edited = rtrim( strtr( base64_encode( '{"site":"*","plan":"pro","exp":null,"id":"x"}' ), '+/', '-_' ), '=' );

		$this->assertNull( LicenceKey::parse( "$prefix.$edited.$signature", $this->public ) );
	}

	/**
	 * @dataProvider malformedKeys
	 */
	public function test_a_malformed_key_is_refused_without_error( string $key ): void {
		$this->assertNull( LicenceKey::parse( $key, $this->public ) );
	}

	/** @return array<string, array{string}> */
	public static function malformedKeys(): array {
		return [
			'empty'             => [ '' ],
			'wrong prefix'      => [ 'XXX1.abc.def' ],
			'two parts'         => [ 'SHP1.abc' ],
			'four parts'        => [ 'SHP1.a.b.c' ],
			'not base64url'     => [ 'SHP1.a+b.c/d' ],
			'short signature'   => [ 'SHP1.e30.YWJj' ],
		];
	}

	public function test_a_bad_public_key_refuses_rather_than_warns(): void {
		$this->assertNull( LicenceKey::parse( $this->issue(), 'not-hex' ) );
		$this->assertNull( LicenceKey::parse( $this->issue(), 'abcd' ) );
	}

	/**
	 * @dataProvider incompletePayloads
	 * @param array<string, mixed> $payload
	 */
	public function test_a_signed_but_incomplete_payload_is_refused( array $payload ): void {
		$this->assertNull( LicenceKey::parse( LicenceKey::issue( $payload, $this->secret ), $this->public ) );
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function incompletePayloads(): array {
		$ok = [ 'site' => 'example.com', 'plan' => 'pro', 'exp' => null, 'id' => 'x' ];
		return [
			'no site'        => [ array_diff_key( $ok, [ 'site' => 1 ] ) ],
			'empty site'     => [ [ 'site' => '' ] + $ok ],
			'no plan'        => [ array_diff_key( $ok, [ 'plan' => 1 ] ) ],
			'no id'          => [ array_diff_key( $ok, [ 'id' => 1 ] ) ],
			'non-date exp'   => [ [ 'exp' => 'soon' ] + $ok ],
			'numeric site'   => [ [ 'site' => 7 ] + $ok ],
			'not an object'  => [ [ 'just', 'a', 'list' ] ],
		];
	}

	public function test_the_shipped_public_key_is_a_32_byte_hex_string(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', LicenceKey::PUBLIC_KEY );
	}
}
