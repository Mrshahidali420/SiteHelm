<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AuthSettingsAction;
use SiteHelm\Admin\AuthSettingsPanel;
use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class AuthSettingsPanelTest extends TestCase {

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
	 * Renders the panel over whatever the options currently hold.
	 */
	private function render(): string {
		ob_start();
		( new AuthSettingsPanel() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Stores a discovery result as if the button had been pressed.
	 *
	 * @param string $outcome The outcome every row carries.
	 * @param string $detail  What the row says about it.
	 */
	private function tested( string $outcome, string $detail = '' ): void {
		AdminWordPressStubs::$options[ DiscoverySelfTest::OPTION_LAST ] = [
			'at'   => 1_700_000_000,
			'rows' => [
				[
					'url'     => 'https://example.test/.well-known/oauth-protected-resource',
					'status'  => 200,
					'outcome' => $outcome,
					'detail'  => $detail,
				],
			],
		];
	}

	public function testTheSwitchAndTheAddressAreBothPostedToTheOneHandler(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'value="' . AuthSettingsAction::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . AuthSettingsAction::FIELD_ENABLED . '"', $html );
		$this->assertStringContainsString( 'name="' . AuthSettingsAction::FIELD_URL . '"', $html );
	}

	public function testTheSwitchShowsTheStateTheSiteIsActuallyIn(): void {
		AdminWordPressStubs::$options[ AuthSettings::OPTION ] = '0';

		$this->assertStringNotContainsString( 'value="1" checked', $this->render() );

		AdminWordPressStubs::$options[ AuthSettings::OPTION ] = '1';

		$this->assertStringContainsString( 'value="1" checked', $this->render() );
	}

	public function testTheAddressFieldCarriesTheStoredOverrideAndOffersTheDerivedOneAsAPlaceholder(): void {
		AdminWordPressStubs::$options[ PublicUrl::OPTION ] = 'https://live.example';

		$html = $this->render();

		$this->assertStringContainsString( 'value="https://live.example"', $html );
		$this->assertStringContainsString( 'placeholder="https://live.example"', $html );
	}

	public function testTheThreeAddressesBuiltFromTheOverrideArePrintedRatherThanDescribed(): void {
		AdminWordPressStubs::$options[ PublicUrl::OPTION ] = 'https://live.example';

		$html = $this->render();

		$this->assertStringContainsString( 'https://live.example/wp-json/sitehelm/v1/mcp', $html );
		$this->assertStringContainsString( 'https://live.example/.well-known/oauth-protected-resource', $html );
		$this->assertStringContainsString( 'https://live.example/.well-known/oauth-authorization-server', $html );
	}

	public function testBothButtonsSubmitTheSameFormAndSayWhichWasPressed(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'value="' . AuthSettingsAction::INTENT_SAVE . '"', $html );
		$this->assertStringContainsString( 'value="' . AuthSettingsAction::INTENT_TEST . '"', $html );
		$this->assertStringContainsString( 'Test discovery', $html );
	}

	public function testARefusedAddressIsReportedAsARefusalRatherThanASuccess(): void {
		$_GET[ AuthSettingsAction::ARG_STATE ] = AuthSettingsAction::STATE_INSECURE;

		$html = $this->render();

		$this->assertStringContainsString( 'sitehelm-note--refused', $html );
		$this->assertStringContainsString( 'Nothing was saved', $html );
	}

	public function testASaveIsReportedInWords(): void {
		$_GET[ AuthSettingsAction::ARG_STATE ] = AuthSettingsAction::STATE_SAVED;

		$html = $this->render();

		$this->assertStringContainsString( 'sitehelm-note--ok', $html );
		$this->assertStringContainsString( 'Saved.', $html );
	}

	public function testWithNoTestEverRunNoResultTableIsShown(): void {
		$this->assertStringNotContainsString( 'sitehelm-discovery', $this->render() );
	}

	public function testAPassingTestIsShownAgainstEachAddressWithTheTimeItWasRun(): void {
		$this->tested( DiscoverySelfTest::PASS );

		$html = $this->render();

		$this->assertStringContainsString( 'Last tested 5 minutes ago.', $html );
		$this->assertStringContainsString( 'Answered', $html );
		$this->assertStringContainsString( 'https://example.test/.well-known/oauth-protected-resource', $html );
	}

	public function testAnAddressAnsweredBySomethingElseSaysWhatItReturned(): void {
		$this->tested( DiscoverySelfTest::WRONG_OWNER, 'It returned https://someone-else.example.' );

		$html = $this->render();

		$this->assertStringContainsString( 'Not this site', $html );
		$this->assertStringContainsString( 'It returned https://someone-else.example.', $html );
	}
}
