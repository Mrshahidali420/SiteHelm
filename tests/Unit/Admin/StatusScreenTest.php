<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectionProbe;
use SiteHelm\Admin\RetentionAction;
use SiteHelm\Admin\StatusScreen;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\Retention;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class StatusScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$GLOBALS['wp_version'] = '6.8.1';

		AdminWordPressStubs::$options[ Installer::STATUS_OPTION ]     = Installer::STATUS_READY;
		AdminWordPressStubs::$options[ Installer::DB_VERSION_OPTION ] = (string) Installer::DB_VERSION;
	}

	/**
	 * Renders the screen with the given health map and returns the markup.
	 *
	 * @param array<string, array{version: ?string, health: string}> $health The loader's map.
	 */
	private function render( array $health ): string {
		ob_start();
		( new StatusScreen( $health ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Every module reported active, so no module is holding anything back.
	 *
	 * @return array<string, array{version: ?string, health: string}>
	 */
	private function allActive(): array {
		$health = [];

		foreach ( ModuleId::cases() as $module ) {
			$health[ $module->value ] = [
				'version' => '1.0.0',
				'health'  => ModuleHealth::Active->value,
			];
		}

		return $health;
	}

	public function testAVisitorWithoutTheCapabilityIsStoppedRatherThanShownTheScreen(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		ob_start();

		try {
			( new StatusScreen( [] ) )->render();
		} finally {
			ob_end_clean();
		}
	}

	public function testTheVerdictReportsEverythingActiveWhenNoModuleIsBlocked(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Everything available is active', $html );
		$this->assertStringContainsString( 'sitehelm-dot--ok', $html );
	}

	public function testTheVerdictCountsTheModulesThatAreNotActive(): void {
		$health = $this->allActive();
		unset( $health[ ModuleId::Elementor->value ], $health[ ModuleId::Acf->value ] );

		$html = $this->render( $health );

		$this->assertStringContainsString( 'Some modules are unavailable', $html );
		$this->assertStringContainsString( '2 modules are not active', $html );
	}

	/**
	 * The count belongs here; the reason lives on Modules. A verdict that names a
	 * number without pointing at the explanation leaves the operator to guess.
	 */
	public function testABlockedVerdictLinksToTheModulesScreenWhereTheReasonLives(): void {
		$health = $this->allActive();
		unset( $health[ ModuleId::Elementor->value ] );

		$html = $this->render( $health );

		$this->assertStringContainsString(
			'href="https://example.test/wp-admin/admin.php?page=' . AdminMenu::PAGE_MODULES . '"',
			$html
		);
		$this->assertStringContainsString( 'what each one is waiting on', $html );
	}

	public function testAnAllActiveVerdictCarriesNoFollowUpLink(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringNotContainsString( 'sitehelm-followup', $html );
	}

	/**
	 * Storage failure outranks every module verdict. Without the tables a change
	 * cannot be recorded or put back, which is a worse state than any module being
	 * absent, and reporting "everything active" over it would be a lie.
	 */
	public function testStorageBeingUnavailableOverridesTheModuleVerdict(): void {
		AdminWordPressStubs::$options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Storage unavailable', $html );
		$this->assertStringNotContainsString( 'Everything available is active', $html );
	}

	public function testStorageBeingUnavailableExplainsHowToRecover(): void {
		AdminWordPressStubs::$options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'could not create its tables', $html );
		$this->assertStringContainsString( 'Deactivating and reactivating', $html );
	}

	public function testAReadyStoreStatesItsSchemaVersion(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Schema version', $html );
		$this->assertStringContainsString( (string) Installer::DB_VERSION, $html );
	}

	/**
	 * Per-module detail lives on the Modules screen; Status carries the count and
	 * nothing more. {@see ModulesScreenTest} covers the cards themselves.
	 */
	public function testStatusReportsTheModuleCountWithoutRepeatingTheModulesScreen(): void {
		$html = $this->render( [] );

		$this->assertStringContainsString( '0 of 8', $html );
		$this->assertStringNotContainsString( 'Advanced Custom Fields', $html );
	}

	public function testTheEnvironmentReportsTheVersionsAnOperatorWouldBeAskedFor(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( SITEHELM_VERSION, $html );
		$this->assertStringContainsString( '6.8.1', $html );
		$this->assertStringContainsString( PHP_VERSION, $html );
		$this->assertStringContainsString( McpServer::PROTOCOL_VERSION, $html );
	}

	public function testTheEnvironmentStatesWhetherTheSiteIsServedOverHttps(): void {
		AdminWordPressStubs::$isSsl = false;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'No, this site is not served over HTTPS', $html );
	}

	public function testTheEnvironmentSaysSoWhenTheSiteIsServedOverHttps(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Yes, this site is served over HTTPS', $html );
	}

	public function testWriteAccessOffersToPauseWhenWritesAreAllowed(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'sitehelm-writemode--open', $html );
		$this->assertStringContainsString( 'Writes allowed', $html );
		$this->assertStringContainsString( 'name="action" value="sitehelm_write_mode"', $html );
		$this->assertStringContainsString( 'name="sitehelm_write_mode" value="pause"', $html );
		$this->assertStringContainsString( '>Pause all writes</button>', $html );
		$this->assertStringNotContainsString( 'value="resume"', $html );
	}

	public function testWriteAccessOffersToResumeWhenWritesArePaused(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = PermissionMode::ReadOnly->value;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'sitehelm-writemode--paused', $html );
		$this->assertStringContainsString( 'Writes paused', $html );
		$this->assertStringContainsString( 'name="sitehelm_write_mode" value="resume"', $html );
		$this->assertStringContainsString( '>Resume writes</button>', $html );
		$this->assertStringNotContainsString( 'value="pause"', $html );
	}

	public function testAJustTakenPauseOrResumeIsReported(): void {
		$_GET['sitehelm_write_mode'] = 'paused';
		$html                        = $this->render( $this->allActive() );
		$this->assertStringContainsString( 'Writes are now paused.', $html );

		$_GET['sitehelm_write_mode'] = 'resumed';
		$html                        = $this->render( $this->allActive() );
		$this->assertStringContainsString( 'Writes are allowed again.', $html );

		$_GET = [];
	}

	public function testRetentionShowsTheStoredWindowInAFormThatSavesIt(): void {
		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = 45;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Record retention', $html );
		$this->assertStringContainsString( 'name="action" value="sitehelm_retention"', $html );
		$this->assertStringContainsString( 'name="sitehelm_retention_days" value="45" min="1" max="365"', $html );
		$this->assertStringContainsString( '>Save</button>', $html );
	}

	public function testAJustSavedRetentionIsReportedInDays(): void {
		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = 14;
		$_GET[ RetentionAction::ARG_STATE ]                          = RetentionAction::STATE_SAVED;

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Records are now kept for 14 days.', $html );
	}

	public function testReadinessSaysWhenTheAuthorizationHeaderReachesWordPress(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Authorization header', $html );
		$this->assertStringContainsString( 'Reaches WordPress', $html );
		$this->assertStringNotContainsString( 'sitehelm-probe-advice', $html );
	}

	public function testAStrippedHeaderIsNamedAndTheApacheFixIsGiven(): void {
		AdminWordPressStubs::$probeResponse = [
			'response' => [ 'code' => 401 ],
			'body'     => '{"code":"rest_not_logged_in"}',
		];

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Stripped by the server', $html );
		$this->assertStringContainsString( 'drops the Authorization header', $html );
		$this->assertStringContainsString( 'E=HTTP_AUTHORIZATION:%{HTTP:Authorization}', $html );
	}

	public function testAnUnreachableLoopbackIsReportedWithoutAlarm(): void {
		AdminWordPressStubs::$probeResponse = new \RuntimeException( 'cURL error 7' );

		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Could not be tested', $html );
		$this->assertStringContainsString( 'could not reach its own endpoint', $html );
		$this->assertStringNotContainsString( 'cURL error', $html );
	}

	public function testAnInjectedProbeDrivesTheCard(): void {
		ob_start();
		( new StatusScreen( $this->allActive(), new ConnectionProbe( static fn(): ?array => null ) ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Could not be tested', $html );
	}
}
