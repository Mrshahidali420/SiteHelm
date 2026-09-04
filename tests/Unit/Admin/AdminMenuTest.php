<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What the console puts in the menu, and — the point of this suite — whether
 * every entry it adds leads anywhere.
 *
 * A submenu entry whose slug names a page nobody registered is not an error at
 * registration time: WordPress accepts it, draws it, and answers the click with
 * "Sorry, you are not allowed to access this page." That is exactly how the
 * upgrade entry failed on a customer's site, so the rule is checked here:
 * anything that is not a full URL has to arrive with a callback.
 */
final class AdminMenuTest extends TestCase {

	/**
	 * Every add_submenu_page() call, in order.
	 *
	 * @var list<array{parent: string, label: string, slug: string, callback: mixed}>
	 */
	private array $submenus = [];

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->submenus = [];

		Functions\when( 'add_menu_page' )->justReturn( '' );
		Functions\when( 'add_submenu_page' )->alias(
			function ( string $parent, string $page_title, string $label, string $capability, string $slug, $callback = '' ): string {
				$this->submenus[] = [
					'parent'   => $parent,
					'label'    => $label,
					'slug'     => $slug,
					'callback' => $callback,
				];

				return $parent . '_page_' . $slug;
			}
		);
	}

	private function build( string $state ): void {
		( new AdminMenu(
			new CapabilityRegistry(),
			[],
			null,
			null,
			new ProCatalogue(
				static fn(): array => [
					'state' => $state,
					'url'   => '',
				]
			)
		) )->add_pages();
	}

	/**
	 * @return list<string>
	 */
	private function slugs(): array {
		return array_column( $this->submenus, 'slug' );
	}

	private function entry( string $slug ): array {
		foreach ( $this->submenus as $submenu ) {
			if ( $slug === $submenu['slug'] ) {
				return $submenu;
			}
		}

		$this->fail( "No menu entry for '{$slug}'." );
	}

	public function testEveryTabIsRegisteredUnderTheConsole(): void {
		$this->build( ProCatalogue::STATE_ABSENT );

		foreach ( array_keys( AdminMenu::tabs() ) as $slug ) {
			$this->assertContains( $slug, $this->slugs() );
			$this->assertSame( AdminMenu::PAGE_HOME, $this->entry( $slug )['parent'] );
		}
	}

	/**
	 * The defect this suite exists for.
	 */
	public function testNoEntryNamesAPageWithNothingToRenderIt(): void {
		$this->build( ProCatalogue::STATE_UNLICENSED );

		foreach ( $this->submenus as $submenu ) {
			if ( str_starts_with( $submenu['slug'], 'http' ) ) {
				continue;
			}

			$this->assertIsArray(
				$submenu['callback'],
				"The '{$submenu['slug']}' entry has no callback, so WordPress will refuse the click."
			);
			$this->assertIsCallable( $submenu['callback'], $submenu['slug'] );
		}
	}

	/**
	 * The community entry is the one deliberate exception: a full URL as the
	 * slug is how WordPress renders an outward link, and it needs no page.
	 */
	public function testTheCommunityEntryIsAPlainOutwardLink(): void {
		$this->build( ProCatalogue::STATE_ABSENT );

		$community = $this->entry( AdminMenu::COMMUNITY_URL );

		$this->assertStringStartsWith( 'https://', $community['slug'] );
		$this->assertSame( '', $community['callback'] );
	}

	public function testASiteWithoutProIsOfferedIt(): void {
		$this->build( ProCatalogue::STATE_ABSENT );

		$this->assertContains( AdminMenu::PAGE_UPGRADE, $this->slugs() );
		$this->assertStringContainsString( 'Upgrade to Pro', $this->entry( AdminMenu::PAGE_UPGRADE )['label'] );
	}

	/**
	 * An installed add-on waiting for a key is not an upsell.
	 */
	public function testAnUnlicensedSiteIsOfferedTheKeyInstead(): void {
		$this->build( ProCatalogue::STATE_UNLICENSED );

		$this->assertStringContainsString( 'Activate Pro', $this->entry( AdminMenu::PAGE_UPGRADE )['label'] );
	}

	/**
	 * A menu that keeps selling to somebody who already paid reads as not
	 * knowing they paid.
	 */
	public function testALicensedSiteIsNotSoldTo(): void {
		$this->build( ProCatalogue::STATE_ACTIVE );

		$this->assertNotContains( AdminMenu::PAGE_UPGRADE, $this->slugs() );
	}
}
