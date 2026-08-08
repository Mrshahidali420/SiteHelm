<?php
/**
 * The menus module: WordPress navigation menus.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * WordPress navigation menu operations. Depends only on WordPress core, so it is
 * always active when the plugin boots and storage is ready. Its detected
 * dependency version is the WordPress version, which is what makes a WordPress
 * upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class MenusModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Menus;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Navigation Menus';
	}

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The change engine's local tables are a dependency exactly like a
	 * third-party plugin would be, so their absence is reported the same way
	 * CoreModule and MediaModule report it: inactive, with no detected version.
	 * Reporting it here rather than at each call site is what keeps the three
	 * surfaces that read health in agreement — the dispatcher catalog,
	 * system-read integration health, and Dispatcher's own refusal.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		if ( ! ( new Installer() )->isAvailable() ) {
			return [
				'version' => null,
				'health'  => ModuleHealth::Inactive->value,
			];
		}

		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	/**
	 * Caches this module's writes can invalidate.
	 *
	 * Terms are present here where MediaModule omits them, and the reason is the
	 * data model: a menu IS a `nav_menu` term and a menu item is a
	 * `nav_menu_item` post whose membership is a term relationship. A write that
	 * reorders items or reassigns a location moves both.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms' ];
	}

	/**
	 * Registers the menus module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the code
	 * that produces the payload; this method is only the registration table.
	 * Registration order is the order the dispatcher catalog advertises, and it
	 * is pinned by MenusDefinitionInvariantsTest and the golden fixture.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields  = new MenuFields();
		$targets = new MenuTarget( $fields );

		$registry->register( MenuList::definition(), [ new MenuList(), 'handle' ] );
		$registry->register( MenuGet::definition(), [ new MenuGet( $fields ), 'handle' ] );
		$registry->registerWrite( MenuItemCreate::definition(), new MenuItemCreate( $fields, $targets ) );
		$registry->registerWrite( MenuItemUpdate::definition(), new MenuItemUpdate( $fields, $targets ) );
		$registry->registerWrite( MenuItemsReorder::definition(), new MenuItemsReorder( $fields ) );
		$registry->registerWrite( MenuLocationAssign::definition(), new MenuLocationAssign( $fields ) );
	}
}
