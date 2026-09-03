<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AuthSettingsAction;
use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Auth\PublicUrl;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class AuthSettingsActionTest extends TestCase {

	/**
	 * Where the handler sent the browser, if anywhere.
	 *
	 * @var array<int, string>
	 */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->sent = [];
		$_POST      = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	/**
	 * Builds a handler that records its redirect instead of exiting.
	 */
	private function action(): AuthSettingsAction {
		return new AuthSettingsAction(
			null,
			null,
			null,
			function ( string $url ): void {
				$this->sent[] = $url;
			}
		);
	}

	/**
	 * Answers every discovery fetch with this site's own documents.
	 */
	private function siteAnswersForItself(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn(): string => (string) wp_json_encode(
				[
					'issuer'   => 'https://example.test',
					'resource' => 'https://example.test/wp-json/sitehelm/v1/mcp',
				]
			)
		);
	}

	public function testAVisitorWithoutTheCapabilityIsRefusedBeforeAnythingIsWritten(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		$this->action()->handle();
	}

	public function testThePostIsVerifiedAgainstItsOwnNonce(): void {
		$this->action()->handle();

		$this->assertSame( [ AuthSettingsAction::NONCE ], AdminWordPressStubs::$refererChecks );
	}

	public function testSavingWritesBothTheSwitchAndTheAddressAndSaysSo(): void {
		$_POST[ AuthSettingsAction::FIELD_ENABLED ] = '1';
		$_POST[ AuthSettingsAction::FIELD_URL ]     = 'https://live.example/';

		$this->action()->handle();

		$this->assertSame( '1', AdminWordPressStubs::$options[ AuthSettings::OPTION ] );
		$this->assertSame( 'https://live.example', AdminWordPressStubs::$options[ PublicUrl::OPTION ] );
		$this->assertStringContainsString( AuthSettingsAction::STATE_SAVED, $this->sent[0] );
		$this->assertStringContainsString( 'page=sitehelm-connect', $this->sent[0] );
	}

	public function testAnAbsentCheckboxTurnsTheSwitchOffRatherThanLeavingItAsItWas(): void {
		AdminWordPressStubs::$options[ AuthSettings::OPTION ] = '1';

		$this->action()->handle();

		$this->assertSame( '0', AdminWordPressStubs::$options[ AuthSettings::OPTION ] );
	}

	public function testAnEmptyAddressClearsTheOverrideRatherThanBeingRefused(): void {
		AdminWordPressStubs::$options[ PublicUrl::OPTION ] = 'https://old.example';

		$_POST[ AuthSettingsAction::FIELD_URL ] = '';

		$this->action()->handle();

		$this->assertSame( '', AdminWordPressStubs::$options[ PublicUrl::OPTION ] );
		$this->assertStringContainsString( AuthSettingsAction::STATE_SAVED, $this->sent[0] );
	}

	public function testAnAddressThatIsNotAnAddressIsRefusedAndNothingElseIsSavedEither(): void {
		$_POST[ AuthSettingsAction::FIELD_ENABLED ] = '1';
		$_POST[ AuthSettingsAction::FIELD_URL ]     = 'example.com';

		$this->action()->handle();

		$this->assertArrayNotHasKey( PublicUrl::OPTION, AdminWordPressStubs::$options );
		$this->assertArrayNotHasKey( AuthSettings::OPTION, AdminWordPressStubs::$options );
		$this->assertStringContainsString( AuthSettingsAction::STATE_BAD_URL, $this->sent[0] );
	}

	public function testAPlainHttpAddressIsRefusedBecauseATokenWouldTravelInClearText(): void {
		$_POST[ AuthSettingsAction::FIELD_URL ] = 'http://live.example';

		$this->action()->handle();

		$this->assertArrayNotHasKey( PublicUrl::OPTION, AdminWordPressStubs::$options );
		$this->assertStringContainsString( AuthSettingsAction::STATE_INSECURE, $this->sent[0] );
	}

	public function testAPlainHttpAddressOnTheDevelopersOwnMachineIsAccepted(): void {
		$_POST[ AuthSettingsAction::FIELD_URL ] = 'http://sitehelm.test';

		$this->action()->handle();

		$this->assertSame( 'http://sitehelm.test', AdminWordPressStubs::$options[ PublicUrl::OPTION ] );
		$this->assertStringContainsString( AuthSettingsAction::STATE_SAVED, $this->sent[0] );
	}

	public function testTestingDiscoveryFetchesTheDocumentsAndKeepsWhatItFound(): void {
		$this->siteAnswersForItself();

		$_POST[ AuthSettingsAction::FIELD_INTENT ] = AuthSettingsAction::INTENT_TEST;

		$this->action()->handle();

		$last = DiscoverySelfTest::last();

		$this->assertCount( 4, $last['rows'] );
		$this->assertSame( DiscoverySelfTest::PASS, DiscoverySelfTest::worst( $last['rows'] ) );
		$this->assertStringContainsString( AuthSettingsAction::STATE_TESTED, $this->sent[0] );
	}

	public function testTestingDiscoveryChangesNeitherTheSwitchNorTheAddress(): void {
		$this->siteAnswersForItself();

		$_POST[ AuthSettingsAction::FIELD_INTENT ] = AuthSettingsAction::INTENT_TEST;
		$_POST[ AuthSettingsAction::FIELD_URL ]    = 'https://typed-but-not-saved.example';

		$this->action()->handle();

		$this->assertArrayNotHasKey( PublicUrl::OPTION, AdminWordPressStubs::$options );
		$this->assertArrayNotHasKey( AuthSettings::OPTION, AdminWordPressStubs::$options );
	}
}
