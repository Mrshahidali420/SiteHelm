<?php
/**
 * A licensed or unlicensed site for the Pro SEO operation tests.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Pro\Licence\LicenceKey;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;

/**
 * Shared by the operation tests: a throw-away keypair, a licence that reads
 * today as 2026-08-23, the option store from AdminWordPressStubs, and the two
 * SEO plugin version constants.
 *
 * THE TESTS USING IT RUN IN SEPARATE PROCESSES: a version constant is permanent
 * for the life of a PHP process, so each test defines the one it wants.
 */
trait ProLicenceFixture {

	private string $public;
	private string $secret;

	/**
	 * Installs the option stubs and mints a keypair.
	 */
	private function installLicenceFixture(): void {
		AdminWordPressStubs::install();
		AdminWordPressStubs::$options = [];
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

	/**
	 * A licence verifying with this test's public key.
	 */
	private function licence(): Licence {
		return new Licence( $this->public, static fn(): string => '2026-08-23' );
	}

	/**
	 * Stores an active key for this site.
	 */
	private function license(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = LicenceKey::issue(
			[ 'site' => '*', 'plan' => 'pro', 'exp' => null, 'id' => 'id-1' ],
			$this->secret
		);
	}

	/**
	 * Puts a supported Yoast on this process's site.
	 */
	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	/**
	 * Puts a supported Rank Math on this process's site.
	 */
	private function installRankMath(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.220' );
		}
	}

	/**
	 * A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}
}
