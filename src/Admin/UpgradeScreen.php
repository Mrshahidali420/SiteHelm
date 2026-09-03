<?php
/**
 * The Upgrade screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * One screen, two questions: which plan, or where do I put my key.
 *
 * Which of the two it answers is not a choice the screen offers — it is decided
 * by what is on the site. With no add-on installed the question is which plan,
 * so the screen is the plan list. With the add-on installed and no licence the
 * question is only ever where the key goes, so the plan list steps aside and the
 * licence field takes the screen: somebody who has already paid must not be sold
 * to again, and somebody who has not cannot activate anything.
 *
 * Buying happens in the checkout overlay, inside wp-admin. Sending a buyer out
 * to the website loses the thread mid-decision and lands them on a page that has
 * to re-explain what they were already reading. The overlay is Freemius's own,
 * hosted by them, and it is the same checkout the website opens — so there is
 * one place a price is charged, however a person got there. When the overlay
 * script cannot load, every button is still a plain link to that same checkout,
 * which is why they are written as links and upgraded by script rather than
 * rendered as buttons that need it.
 *
 * The screen never states a price it cannot back: {@see Pricing} either has a
 * fetched list or the compiled one, and both are real.
 *
 * @package SiteHelm
 */
final class UpgradeScreen {

	/**
	 * Freemius's checkout overlay library.
	 */
	public const CHECKOUT_JS = 'https://checkout.freemius.com/js/v1/';

	/**
	 * The plan list.
	 *
	 * @var Pricing
	 */
	private Pricing $pricing;

	/**
	 * What the site has of the add-on.
	 *
	 * @var ProCatalogue
	 */
	private ProCatalogue $pro;

	/**
	 * Constructs the screen.
	 *
	 * @param Pricing|null      $pricing The plan list; null reads the feed.
	 * @param ProCatalogue|null $pro     The add-on's state; null probes the site.
	 */
	public function __construct( ?Pricing $pricing = null, ?ProCatalogue $pro = null ) {
		$this->pricing = $pricing ?? new Pricing();
		$this->pro     = $pro ?? new ProCatalogue();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'sitehelm' ) );
		}

		$state = (string) $this->pro->probe()['state'];

		Ui::app_open( AdminMenu::PAGE_UPGRADE );

		if ( ProCatalogue::STATE_ACTIVE === $state ) {
			$this->render_active();
		} elseif ( ProCatalogue::STATE_UNLICENSED === $state ) {
			$this->render_licence();
		} else {
			$this->render_plans();
		}

		Ui::app_close();
	}

	/**
	 * A licence is already active. Nothing to sell, nothing to enter.
	 *
	 * The screen is unreachable from the menu in this state, but a bookmark or
	 * a browser's back button still lands here, and a page that tried to sell
	 * to a paying customer would read as not knowing they had paid.
	 */
	private function render_active(): void {
		Ui::page_head(
			__( 'SiteHelm Pro', 'sitehelm' ),
			__( 'The add-on is licensed on this site.', 'sitehelm' )
		);

		Ui::verdict( 'ok', __( 'Pro is active. Everything it adds is switched on in Tools.', 'sitehelm' ) );

		printf(
			'<p class="sitehelm-note"><a class="sitehelm-btn" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_OPERATIONS ) ),
			esc_html__( 'Open Tools', 'sitehelm' )
		);
	}

	/**
	 * The add-on is installed and waiting on a key.
	 */
	private function render_licence(): void {
		Ui::page_head(
			__( 'Activate SiteHelm Pro', 'sitehelm' ),
			__( 'The add-on is installed on this site. It stays locked until a licence key is entered.', 'sitehelm' )
		);

		Ui::verdict(
			'warn',
			__( 'SiteHelm Pro is installed but not licensed.', 'sitehelm' ),
			__( 'Its operations are listed in Tools and refuse to run.', 'sitehelm' )
		);

		Ui::section_open(
			__( 'Enter your licence key', 'sitehelm' ),
			__( 'The key was emailed to you the moment the purchase went through.', 'sitehelm' )
		);

		if ( LicenceDialog::is_available() ) {
			printf(
				'<p class="sitehelm-place__action">%s</p>',
				LicenceDialog::trigger( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- LicenceDialog::trigger() escapes its own markup.
					__( 'Enter licence key', 'sitehelm' ),
					'sitehelm-btn sitehelm-btn--primary'
				)
			);
		}

		printf( '<p class="sitehelm-note">%s</p>', esc_html( LicenceDialog::fallback_sentence() ) );

		Ui::section_close();

		Ui::section_open(
			__( 'No key yet?', 'sitehelm' ),
			__( 'The add-on is installed, so all that is missing is the licence.', 'sitehelm' )
		);

		$this->render_plan_table();

		Ui::section_close();
	}

	/**
	 * Nothing installed: the plans.
	 */
	private function render_plans(): void {
		Ui::page_head(
			__( 'SiteHelm Pro', 'sitehelm' ),
			sprintf(
				/* translators: %s: number of operations the Pro add-on adds. */
				__( '%s more operations, and the same safety gates on every one of them.', 'sitehelm' ),
				number_format_i18n( count( ProCatalogue::OPERATIONS ) )
			)
		);

		$this->render_plan_table();
		$this->render_includes();
	}

	/**
	 * The plan cards.
	 */
	private function render_plan_table(): void {
		echo '<div class="sitehelm-plans">';

		foreach ( $this->pricing->plans() as $plan ) {
			$this->render_plan( $plan );
		}

		echo '</div>';

		printf( '<p class="sitehelm-note">%s</p>', esc_html( $this->pricing->note() ) );

		printf(
			'<p class="sitehelm-note"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( Pricing::PRICING_PAGE ),
			esc_html__( 'Compare everything, including current offers, on wpsitehelm.com', 'sitehelm' )
		);

		$this->print_checkout_script();
	}

	/**
	 * One plan.
	 *
	 * @param array{id: string, name: string, sites: string, who: string, featured: bool, pricingId: string, annual: array{list: float, now: float}, lifetime: array{list: float, now: float}|null} $plan The plan.
	 */
	private function render_plan( array $plan ): void {
		$classes = 'sitehelm-plan' . ( $plan['featured'] ? ' sitehelm-plan--featured' : '' );

		printf( '<article class="%s">', esc_attr( $classes ) );

		if ( $plan['featured'] ) {
			printf(
				'<p class="sitehelm-plan__flag">%s</p>',
				esc_html__( 'Most people managing client sites take this one', 'sitehelm' )
			);
		}

		printf(
			'<h3 class="sitehelm-plan__name">%s</h3><p class="sitehelm-plan__sites">%s</p>',
			esc_html( $plan['name'] ),
			esc_html( $plan['sites'] )
		);

		printf(
			'<p class="sitehelm-plan__price"><span class="sitehelm-plan__now">%s</span>'
				. '<span class="sitehelm-plan__cycle">%s</span></p>',
			esc_html( Pricing::money( $plan['annual']['now'] ) ),
			esc_html__( 'per year', 'sitehelm' )
		);

		printf( '<p class="sitehelm-plan__who">%s</p>', esc_html( $plan['who'] ) );

		$this->render_buy(
			$plan,
			'annual',
			$plan['annual']['now'],
			__( 'Buy a year', 'sitehelm' ),
			'sitehelm-btn sitehelm-btn--primary'
		);

		if ( null !== $plan['lifetime'] ) {
			echo '<p class="sitehelm-plan__once">';

			$this->render_buy(
				$plan,
				'lifetime',
				$plan['lifetime']['now'],
				sprintf(
					/* translators: %s: a price, such as $299. */
					__( 'or pay %s once', 'sitehelm' ),
					Pricing::money( $plan['lifetime']['now'] )
				),
				''
			);

			echo '</p>';
		}

		echo '</article>';
	}

	/**
	 * A buy link.
	 *
	 * Always a real link to the hosted checkout. The data attributes are what
	 * the overlay script reads to open the same purchase in place instead; a
	 * page where that script never arrives keeps a working link rather than a
	 * dead button.
	 *
	 * @param array{pricingId: string, name: string} $plan    The plan.
	 * @param string                                 $cycle   'annual' or 'lifetime'.
	 * @param float                                  $price   What it charges, for the accessible label.
	 * @param string                                 $label   The link text.
	 * @param string                                 $classes Extra classes.
	 */
	private function render_buy( array $plan, string $cycle, float $price, string $label, string $classes ): void {
		printf(
			'<a class="%s" href="%s" data-sitehelm-checkout="%s" data-sitehelm-cycle="%s" aria-label="%s">%s</a>',
			esc_attr( trim( $classes . ' sitehelm-buy' ) ),
			esc_url( Pricing::checkout_url( $plan['pricingId'], $cycle ) ),
			esc_attr( $plan['pricingId'] ),
			esc_attr( $cycle ),
			esc_attr(
				sprintf(
					/* translators: 1: plan name, such as Unlimited. 2: a price, such as $299. */
					__( 'Buy %1$s for %2$s', 'sitehelm' ),
					$plan['name'],
					Pricing::money( $price )
				)
			),
			esc_html( $label )
		);
	}

	/**
	 * What every licence carries, whichever plan it is.
	 */
	private function render_includes(): void {
		Ui::section_open( __( 'On every plan', 'sitehelm' ) );

		echo '<ul class="sitehelm-list">';

		foreach ( $this->pricing->includes() as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}

		echo '</ul>';

		Ui::section_close();
	}

	/**
	 * Load the checkout overlay and bind it to the buy links.
	 *
	 * The script is Freemius's, from Freemius's domain, and is the only outside
	 * script the console loads anywhere. It loads on this screen alone, and only
	 * for someone who has already navigated to it — a plugin that fetched a
	 * vendor's script on every admin page would be doing something else.
	 *
	 * Every failure path ends at the href that is already on the link: no
	 * script, a blocked domain, or an overlay that throws all leave the click
	 * doing exactly what it would have done anyway.
	 */
	private function print_checkout_script(): void {
		printf(
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- The vendor's checkout overlay, printed on this one screen rather than enqueued so it never loads on any other admin page; the buy links work without it.
			'<script src="%s" async></script>',
			esc_url( self::CHECKOUT_JS )
		);

		printf(
			'<script>%s</script>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled below from constants and JSON-encoded values.
			sprintf(
				'(function(){document.addEventListener("click",function(e){'
					. 'var a=e.target.closest?e.target.closest("[data-sitehelm-checkout]"):null;'
					. 'if(!a||!window.FS||!window.FS.Checkout){return;}'
					. 'e.preventDefault();'
					. 'try{new window.FS.Checkout({plugin_id:%1$s,plan_id:%2$s,public_key:%3$s})'
					. '.open({pricing_id:a.getAttribute("data-sitehelm-checkout"),'
					. 'billing_cycle:a.getAttribute("data-sitehelm-cycle")});}'
					. 'catch(err){window.location.href=a.href;}'
					. '});})();',
				wp_json_encode( Pricing::PLUGIN_ID ),
				wp_json_encode( Pricing::PLAN_ID ),
				wp_json_encode( Pricing::PUBLIC_KEY )
			)
		);
	}
}
