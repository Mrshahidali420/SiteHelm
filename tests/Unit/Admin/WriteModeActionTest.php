<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\WriteModeAction;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class WriteModeActionTest extends TestCase {

	/**
	 * The URL the handler redirected to, or null if it did not.
	 */
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

	private function post( string $wanted ): void {
		$_POST = [ WriteModeAction::FIELD => $wanted ];

		( new WriteModeAction(
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	private function storedMode(): ?string {
		$mode = AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] ?? null;

		return null === $mode ? null : (string) $mode;
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( WriteModeAction::PAUSE );
	}

	public function testTheNonceIsCheckedAgainstTheWriteModeAction(): void {
		$this->post( WriteModeAction::PAUSE );

		$this->assertContains( WriteModeAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testPauseStoresReadOnlyAndReportsPaused(): void {
		$this->post( WriteModeAction::PAUSE );

		$this->assertSame( PermissionMode::ReadOnly->value, $this->storedMode() );
		$this->assertTrue( WriteModeAction::is_paused() );
		$this->assertStringContainsString( 'page=sitehelm-status', (string) $this->redirectedTo );
		$this->assertStringContainsString( 'sitehelm_write_mode=paused', (string) $this->redirectedTo );
	}

	public function testResumeFromPausedStoresSafeWrite(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = PermissionMode::ReadOnly->value;

		$this->post( WriteModeAction::RESUME );

		$this->assertSame( PermissionMode::SafeWrite->value, $this->storedMode() );
		$this->assertFalse( WriteModeAction::is_paused() );
		$this->assertStringContainsString( 'sitehelm_write_mode=resumed', (string) $this->redirectedTo );
	}

	/**
	 * Pausing and resuming must not quietly rewrite a mode the operator set some
	 * other way: a trusted-write site that is resumed stays trusted-write.
	 */
	public function testResumeLeavesAModeThatWasNotPausedAlone(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = PermissionMode::TrustedWrite->value;

		$this->post( WriteModeAction::RESUME );

		$this->assertSame( PermissionMode::TrustedWrite->value, $this->storedMode() );
		$this->assertStringContainsString( 'sitehelm_write_mode=resumed', (string) $this->redirectedTo );
	}

	public function testAnUnknownRequestChangesNothingAndReportsNothing(): void {
		$this->post( 'explode' );

		$this->assertNull( $this->storedMode() );
		$this->assertStringNotContainsString( 'sitehelm_write_mode=', (string) $this->redirectedTo );
		$this->assertStringContainsString( 'page=sitehelm-status', (string) $this->redirectedTo );
	}

	public function testCurrentFallsBackToSafeWriteWhenTheOptionIsMissingOrGarbage(): void {
		$this->assertSame( PermissionMode::SafeWrite, WriteModeAction::current() );

		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = 'whatever';
		$this->assertSame( PermissionMode::SafeWrite, WriteModeAction::current() );
		$this->assertFalse( WriteModeAction::is_paused() );
	}
}
