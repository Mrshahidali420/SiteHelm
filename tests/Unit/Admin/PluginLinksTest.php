<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\PluginLinks;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class PluginLinksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	public function testConnectAndStatusComeBeforeTheRowsOwnLinks(): void {
		$links = PluginLinks::add( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertSame( [ 'sitehelm-connect', 'sitehelm-status', 'deactivate', 'sitehelm-pro' ], array_keys( $links ) );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT, $links['sitehelm-connect'] );
		$this->assertStringContainsString( '>Connect</a>', $links['sitehelm-connect'] );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, $links['sitehelm-status'] );
	}

	public function testAnUnlicensedSiteIsOfferedProAfterTheRowsOwnLinks(): void {
		$links = PluginLinks::add( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertStringContainsString( ProCatalogue::PRICING_URL, $links['sitehelm-pro'] );
		$this->assertStringContainsString( '>Get Pro</a>', $links['sitehelm-pro'] );
	}

	public function testAUserWhoCannotOpenTheConsoleIsNotOfferedLinksIntoIt(): void {
		AdminWordPressStubs::$canManage = false;
		$row                            = [ 'deactivate' => '<a href="#">Deactivate</a>' ];

		$this->assertSame( $row, PluginLinks::add( $row ) );
	}
}
