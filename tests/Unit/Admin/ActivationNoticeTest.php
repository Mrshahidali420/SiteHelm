<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\ActivationNotice;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ActivationNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	private function render(): string {
		ob_start();
		( new ActivationNotice() )->render();

		return (string) ob_get_clean();
	}

	public function testArmingSetsTheTransient(): void {
		ActivationNotice::arm();

		$this->assertSame( 1, AdminWordPressStubs::$transients[ ActivationNotice::TRANSIENT ] );
	}

	public function testNothingShowsWhenTheNoticeIsNotArmed(): void {
		$this->assertSame( '', $this->render() );
	}

	public function testAnArmedNoticeShowsOnceAndPointsAtConnect(): void {
		ActivationNotice::arm();

		$html = $this->render();

		$this->assertStringContainsString( 'SiteHelm is active.', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT, $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertArrayNotHasKey( ActivationNotice::TRANSIENT, AdminWordPressStubs::$transients );
		$this->assertSame( '', $this->render() );
	}

	public function testSomeoneWhoCannotOpenTheConsoleIsNotToldAndTheNoticeStaysArmed(): void {
		ActivationNotice::arm();
		AdminWordPressStubs::$canManage = false;

		$this->assertSame( '', $this->render() );
		$this->assertArrayHasKey( ActivationNotice::TRANSIENT, AdminWordPressStubs::$transients );
	}

	public function testLoadingAConsoleScreenDisarmsTheNoticeWithoutShowingIt(): void {
		ActivationNotice::arm();
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_' . AdminMenu::PAGE_HOME ] );

		$this->assertSame( '', $this->render() );
		$this->assertArrayNotHasKey( ActivationNotice::TRANSIENT, AdminWordPressStubs::$transients );
	}
}
