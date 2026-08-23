<?php
/**
 * The Licence section on the Health tab.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro;

use SiteHelm\Pro\Admin\LicenceAction;
use SiteHelm\Pro\Admin\LicenceSection;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Pro\Licence\LicenceKey;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class LicenceSectionTest extends TestCase {

	private string $public;
	private string $secret;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		$_GET         = [];
		$pair         = sodium_crypto_sign_keypair();
		$this->public = bin2hex( sodium_crypto_sign_publickey( $pair ) );
		$this->secret = bin2hex( sodium_crypto_sign_secretkey( $pair ) );
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new LicenceSection( new Licence( $this->public, static fn(): string => '2026-08-23' ) ) )->render();

		return (string) ob_get_clean();
	}

	private function store( string $site, ?string $exp = null ): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = LicenceKey::issue(
			[ 'site' => $site, 'plan' => 'pro', 'exp' => $exp, 'id' => 'id-1' ],
			$this->secret
		);
	}

	public function test_without_a_key_the_form_invites_one_and_offers_no_remove_button(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'SiteHelm Pro licence', $html );
		$this->assertStringContainsString( 'No licence key yet', $html );
		$this->assertStringContainsString( 'sitehelm-note--waiting', $html );
		$this->assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $html );
		$this->assertStringContainsString( 'name="action" value="' . LicenceAction::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . LicenceAction::FIELD_KEY . '"', $html );
		$this->assertStringNotContainsString( LicenceAction::FIELD_REMOVE, $html );
	}

	public function test_an_active_licence_reads_ok_with_its_expiry_and_offers_remove(): void {
		$this->store( 'example.test', '2030-01-01' );

		$html = $this->render();

		$this->assertStringContainsString( 'sitehelm-note--ok', $html );
		$this->assertStringContainsString( 'active on this site until 2030-01-01', $html );
		$this->assertStringContainsString( 'name="' . LicenceAction::FIELD_REMOVE . '"', $html );
	}

	public function test_a_lifetime_licence_reads_active_without_a_date(): void {
		$this->store( '*' );

		$this->assertStringContainsString( 'Pro is active on this site.', $this->render() );
	}

	public function test_each_failing_state_gets_its_own_sentence(): void {
		$this->store( 'other.example' );
		$this->assertStringContainsString( 'issued for a different site; this site is example.test', $this->render() );

		$this->store( 'example.test', '2020-01-01' );
		$this->assertStringContainsString( 'has expired', $this->render() );

		AdminWordPressStubs::$options[ Licence::OPTION ] = 'SHP1.x.y';
		$this->assertStringContainsString( 'not a valid SiteHelm Pro licence', $this->render() );
	}

	public function test_the_stored_key_is_echoed_escaped_into_the_field(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = '"><script>x</script>';

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}

	public function test_the_redirect_state_becomes_a_note(): void {
		$_GET[ LicenceAction::ARG_STATE ] = LicenceAction::STATE_SAVED;
		$this->assertStringContainsString( 'Licence key saved.', $this->render() );

		$_GET[ LicenceAction::ARG_STATE ] = LicenceAction::STATE_REMOVED;
		$this->assertStringContainsString( 'Licence key removed.', $this->render() );

		$_GET[ LicenceAction::ARG_STATE ] = 'bogus';
		$html = $this->render();
		$this->assertStringNotContainsString( 'Licence key saved.', $html );
		$this->assertStringNotContainsString( 'Licence key removed.', $html );
	}
}
