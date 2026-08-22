<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\RetentionAction;
use SiteHelm\Storage\Retention;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class RetentionActionTest extends TestCase {

	private ?string $redirectedTo = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$_POST              = [];
		$this->redirectedTo = null;
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	private function post( ?string $days ): void {
		$_POST = null === $days ? [] : [ RetentionAction::FIELD => $days ];

		( new RetentionAction(
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	private function stored(): mixed {
		return AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] ?? null;
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( '14' );
	}

	public function testTheNonceIsCheckedAgainstTheRetentionAction(): void {
		$this->post( '14' );

		$this->assertContains( RetentionAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testAValidNumberOfDaysIsStoredAndReportedSaved(): void {
		$this->post( '14' );

		$this->assertSame( 14, $this->stored() );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, (string) $this->redirectedTo );
		$this->assertStringContainsString( 'sitehelm_retention=saved', (string) $this->redirectedTo );
	}

	public function testANumberAboveTheCeilingIsClampedToIt(): void {
		$this->post( '9999' );

		$this->assertSame( Retention::MAX_DAYS, $this->stored() );
	}

	public function testAnEmptyOrZeroFieldChangesNothingAndReportsNothing(): void {
		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = 45;

		$this->post( '0' );
		$this->assertSame( 45, $this->stored() );
		$this->assertStringNotContainsString( 'sitehelm_retention=', (string) $this->redirectedTo );

		$this->post( null );
		$this->assertSame( 45, $this->stored() );
	}

	public function testDaysReadsTheStoredWindowWithTheSameClampAsThePruner(): void {
		$this->assertSame( Retention::DEFAULT_DAYS, RetentionAction::days() );

		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = '90';
		$this->assertSame( 90, RetentionAction::days() );

		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = 'garbage';
		$this->assertSame( Retention::DEFAULT_DAYS, RetentionAction::days() );

		AdminWordPressStubs::$options[ Retention::RETENTION_OPTION ] = 0;
		$this->assertSame( Retention::MIN_DAYS, RetentionAction::days() );
	}
}
