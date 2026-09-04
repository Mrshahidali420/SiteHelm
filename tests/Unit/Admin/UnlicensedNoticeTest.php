<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Admin\UnlicensedNotice;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The banner exists for one state only, and the tests that matter are the ones
 * about when it stays quiet.
 */
final class UnlicensedNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		/*
		 * Every test says which screen it is on, including the ones that do not
		 * care: Brain Monkey defines the function for the process the first time
		 * any test doubles it, so a suite that doubled it only sometimes would
		 * pass or fail on the order the tests happened to run in.
		 */
		self::onScreen( 'dashboard' );
	}

	private function render( string $state ): string {
		ob_start();
		( new UnlicensedNotice(
			new ProCatalogue(
				static fn(): array => [
					'state' => $state,
					'url'   => '',
				]
			)
		) )->render();

		return (string) ob_get_clean();
	}

	private static function onScreen( string $id ): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => $id ] );
	}

	public function testTheBannerSaysWhatIsWrongAndWhereToFixIt(): void {
		$html = $this->render( ProCatalogue::STATE_UNLICENSED );

		$this->assertStringContainsString( 'SiteHelm Pro is installed but not licensed', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_UPGRADE, $html );
	}

	/**
	 * The state persists, so the notice does. A dismissible one would disappear
	 * while the add-on stayed locked.
	 */
	public function testTheBannerCannotBeDismissed(): void {
		$this->assertStringNotContainsString( 'is-dismissible', $this->render( ProCatalogue::STATE_UNLICENSED ) );
	}

	/**
	 * Nothing installed is not a problem to report, and a licensed add-on is not
	 * a problem at all.
	 *
	 * @dataProvider quietStates
	 */
	public function testTheBannerIsSilentInEveryOtherState( string $state ): void {
		$this->assertSame( '', $this->render( $state ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function quietStates(): array {
		return [
			'nothing installed' => [ ProCatalogue::STATE_ABSENT ],
			'licensed'          => [ ProCatalogue::STATE_ACTIVE ],
		];
	}

	/**
	 * The Upgrade screen already is the field. A banner above it repeating the
	 * same sentence would push the page down for nothing.
	 */
	public function testTheBannerStandsAsideOnTheUpgradeScreen(): void {
		self::onScreen( 'sitehelm_page_' . AdminMenu::PAGE_UPGRADE );

		$this->assertSame( '', $this->render( ProCatalogue::STATE_UNLICENSED ) );
	}

	public function testTheBannerFollowsOntoScreensThatAreNotOurs(): void {
		self::onScreen( 'dashboard' );

		$this->assertStringContainsString( 'not licensed', $this->render( ProCatalogue::STATE_UNLICENSED ) );
	}

	/**
	 * Somebody who cannot open the console cannot enter a key either, so telling
	 * them is only noise on every page they load.
	 */
	public function testSomebodyWhoCouldNotActOnItIsNotTold(): void {
		AdminWordPressStubs::$canManage = false;

		$this->assertSame( '', $this->render( ProCatalogue::STATE_UNLICENSED ) );
	}

	/**
	 * With no modal on the site, the banner still has to lead somewhere: the
	 * Upgrade screen carries both the field and the sentence naming the Plugins
	 * route, so the button is the whole answer.
	 */
	public function testWithNoModalTheBannerStillLeadsSomewhere(): void {
		$html = $this->render( ProCatalogue::STATE_UNLICENSED );

		$this->assertStringNotContainsString( 'activate-license-trigger', $html );
		$this->assertStringContainsString( 'Open Upgrade', $html );
	}
}
