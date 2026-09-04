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

	/**
	 * The row's Pro link stays in wp-admin: the Upgrade screen carries the plans
	 * and, on a site that has the add-on, the licence field.
	 */
	public function testASiteWithoutProIsOfferedItAfterTheRowsOwnLinks(): void {
		$links = PluginLinks::add( [ 'deactivate' => '<a href="#">Deactivate</a>' ] );

		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_UPGRADE, $links['sitehelm-pro'] );
		$this->assertStringContainsString( '>Get Pro</a>', $links['sitehelm-pro'] );
		$this->assertStringNotContainsString( ProCatalogue::PRICING_URL, $links['sitehelm-pro'] );
		$this->assertStringNotContainsString( 'target="_blank"', $links['sitehelm-pro'] );
	}

	/**
	 * Somebody who already owns a licence is offered the field, not the price.
	 */
	public function testAnUnlicensedSiteIsOfferedTheLicenceInstead(): void {
		$links = PluginLinks::add(
			[ 'deactivate' => '<a href="#">Deactivate</a>' ],
			new ProCatalogue(
				static fn(): array => [
					'state' => ProCatalogue::STATE_UNLICENSED,
					'url'   => '',
				]
			)
		);

		$this->assertStringContainsString( '>Activate Pro</a>', $links['sitehelm-pro'] );
	}

	public function testALicensedSiteIsNotOfferedPro(): void {
		$links = PluginLinks::add(
			[ 'deactivate' => '<a href="#">Deactivate</a>' ],
			new ProCatalogue(
				static fn(): array => [
					'state' => ProCatalogue::STATE_ACTIVE,
					'url'   => '',
				]
			)
		);

		$this->assertArrayNotHasKey( 'sitehelm-pro', $links );
	}

	public function testAUserWhoCannotOpenTheConsoleIsNotOfferedLinksIntoIt(): void {
		AdminWordPressStubs::$canManage = false;
		$row                            = [ 'deactivate' => '<a href="#">Deactivate</a>' ];

		$this->assertSame( $row, PluginLinks::add( $row ) );
	}
}
