<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\LicenceDialog;
use SiteHelm\Admin\Pricing;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Admin\UpgradeScreen;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The screen has no add-on installed in this process, so the SDK's licence
 * modal is never available here: {@see LicenceDialogTest} owns the trigger's
 * markup, and what is tested here is what the screen shows in each state and
 * what it must never show.
 */
final class UpgradeScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	private static function pricing(): Pricing {
		return new Pricing( static fn(): ?string => null );
	}

	private function render( string $state ): string {
		ob_start();
		( new UpgradeScreen(
			self::pricing(),
			new ProCatalogue(
				static fn(): array => [
					'state' => $state,
					'url'   => '',
				]
			)
		) )->render();

		return (string) ob_get_clean();
	}

	public function testAVisitorWithoutTheCapabilityIsStopped(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		try {
			$this->render( ProCatalogue::STATE_ABSENT );
		} finally {
			// The gate throws mid-render, so the buffer the helper opened is ours to close.
			ob_end_clean();
		}
	}

	/**
	 * Nothing installed: every plan, with the price and the checkout link that
	 * charges it.
	 */
	public function testTheAbsentStateShowsEveryPlanWithItsOwnCheckout(): void {
		$html = $this->render( ProCatalogue::STATE_ABSENT );

		foreach ( Pricing::FALLBACK_PLANS as $plan ) {
			$this->assertStringContainsString( $plan['name'], $html );
			$this->assertStringContainsString( Pricing::money( $plan['annual']['now'] ), $html );
			$this->assertStringContainsString( 'pricing_id=' . $plan['pricingId'], $html );
		}

		$this->assertStringContainsString( 'billing_cycle=annual', $html );
		$this->assertStringContainsString( 'billing_cycle=lifetime', $html );
	}

	/**
	 * One plan is recommended, and it is the one the pricing data marks — the
	 * screen does not pick a favourite of its own.
	 */
	public function testTheRecommendedPlanIsTheOneTheDataMarks(): void {
		$html = $this->render( ProCatalogue::STATE_ABSENT );

		$this->assertSame( 1, substr_count( $html, 'sitehelm-plan--featured' ) );
		$this->assertSame( 1, substr_count( $html, 'sitehelm-plan__flag' ) );

		foreach ( Pricing::FALLBACK_PLANS as $plan ) {
			if ( ! $plan['featured'] ) {
				continue;
			}

			$featured = strpos( $html, 'sitehelm-plan--featured' );
			$this->assertIsInt( $featured );
			$this->assertStringContainsString( $plan['name'], substr( $html, $featured, 600 ) );
		}
	}

	/**
	 * Every buy control is a real link to the hosted checkout before any script
	 * touches it. The overlay is an improvement on that link, never a
	 * replacement for it, so a page whose script never arrives still sells.
	 */
	public function testEveryBuyControlIsALinkThatWorksWithoutScript(): void {
		$html = $this->render( ProCatalogue::STATE_ABSENT );

		preg_match_all( '/<a [^>]*data-sitehelm-checkout=[^>]*>/', $html, $found );

		$this->assertNotSame( [], $found[0] );

		foreach ( $found[0] as $tag ) {
			$this->assertStringContainsString( 'href="https://checkout.freemius.com/', $tag );
		}
	}

	/**
	 * The overlay script is Freemius's, from Freemius's own domain, and is
	 * loaded on this screen alone.
	 */
	public function testTheCheckoutOverlayIsLoadedFromFreemius(): void {
		$html = $this->render( ProCatalogue::STATE_ABSENT );

		$this->assertStringContainsString( UpgradeScreen::CHECKOUT_JS, $html );
		$this->assertStringStartsWith( 'https://checkout.freemius.com/', UpgradeScreen::CHECKOUT_JS );
	}

	/**
	 * A coupon code is copied in at the checkout. A link carrying one would
	 * charge something other than the number printed beside it.
	 */
	public function testNoBuyLinkCarriesACoupon(): void {
		$this->assertStringNotContainsString( 'coupon', $this->render( ProCatalogue::STATE_ABSENT ) );
	}

	/**
	 * Installed and unlicensed: the question is where the key goes, so the
	 * screen leads with that and never opens with a price.
	 */
	public function testTheUnlicensedStateLeadsWithTheLicence(): void {
		$html = $this->render( ProCatalogue::STATE_UNLICENSED );

		$this->assertStringContainsString( 'Activate SiteHelm Pro', $html );
		$this->assertStringContainsString( 'installed but not licensed', $html );

		$licence = strpos( $html, 'Enter your licence key' );
		$plans   = strpos( $html, 'sitehelm-plans' );

		$this->assertIsInt( $licence );
		$this->assertIsInt( $plans );
		$this->assertLessThan( $plans, $licence, 'Somebody who has already paid is not sold to first.' );
	}

	/**
	 * With no modal to open, the screen says where the field is in words rather
	 * than printing a button that would do nothing.
	 */
	public function testWithNoModalAvailableTheScreenSaysWhereTheFieldIsInstead(): void {
		$html = $this->render( ProCatalogue::STATE_UNLICENSED );

		$this->assertStringContainsString( LicenceDialog::fallback_sentence(), $html );
		$this->assertStringNotContainsString( LicenceDialog::TRIGGER_CLASS, $html );
	}

	/**
	 * A licensed site is not sold to. The screen is off the menu in that state,
	 * but a bookmark still reaches it.
	 */
	public function testALicensedSiteIsNotSoldTo(): void {
		$html = $this->render( ProCatalogue::STATE_ACTIVE );

		$this->assertStringContainsString( 'Pro is active', $html );
		$this->assertStringNotContainsString( 'checkout.freemius.com', $html );
		$this->assertStringNotContainsString( 'sitehelm-plans', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_OPERATIONS, $html );
	}

	/**
	 * The screen renders inside the console's own shell, like every other.
	 */
	public function testTheScreenOpensTheConsoleShell(): void {
		$html = $this->render( ProCatalogue::STATE_ABSENT );

		$this->assertStringContainsString( 'sitehelm-app', $html );
	}

	/**
	 * A feed is display text, not markup. Everything it carries is escaped,
	 * whatever it says.
	 */
	public function testFeedTextIsEscaped(): void {
		$body = (string) wp_json_encode(
			[
				'version' => Pricing::FEED_VERSION,
				'note'    => '<script>alert(1)</script>',
				'plans'   => [
					[
						'id'        => 'single',
						'name'      => '<img src=x onerror=alert(1)>',
						'sites'     => 'One site',
						'who'       => 'Anyone.',
						'pricingId' => '83841',
						'annual'    => [
							'list' => 39,
							'now'  => 24.99,
						],
						'lifetime'  => null,
					],
				],
			]
		);

		ob_start();
		( new UpgradeScreen(
			new Pricing( static fn(): string => $body ),
			new ProCatalogue(
				static fn(): array => [
					'state' => ProCatalogue::STATE_ABSENT,
					'url'   => '',
				]
			)
		) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'pricing_id=83841', $html );
	}
}
