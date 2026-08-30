<?php
/**
 * The extensions module: the plugins and themes this site has installed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Lists the site's plugins and themes and says what has an update waiting.
 *
 * A HYBRID MODULE, the shape SEO and Forms established: the free plugin ships
 * the two reads, and the seven operations that activate, deactivate, update,
 * switch or install arrive from the SiteHelm Pro add-on through
 * `sitehelm_register_operations`. That is why it is not in
 * `ProCatalogue::ADDON_ONLY_MODULES` — the module does something on a site with
 * no add-on — and why the console's Tools tab lists the writes as locked rather
 * than hiding them.
 *
 * ITS OPERATIONS LIVE UNDER `system-read`, not under a dispatcher of their own:
 * the eleven dispatchers are frozen and a dispatcher name is derived from an
 * operation's domain and mode, so `Domain::System` with `Mode::Read` is where a
 * client already looks for facts about how a site is put together. The add-on's
 * writes ride `content-write` for the same frozen-set reason there is no
 * `system-write` — the seam `code-snippet-write` took before them.
 *
 * THE MODULE DEPENDS ON WordPress AND NOTHING ELSE, so health is Active
 * whenever the plugin's own storage is ready. There is no third-party plugin to
 * be missing and no floor to be below, which is also why it is absent from
 * `OperationDefinition::PLUGIN_BACKED_MODULES` and why the modules screen prints
 * no requirement line for it. A request in which WordPress's inventory functions
 * are not loaded is a fact about that request rather than about the site, so
 * each operation refuses it in its own guard instead of the module reporting the
 * whole surface inactive.
 *
 * `cacheCleanup()` is empty because the free half writes nothing.
 *
 * @package SiteHelm
 */
final class ExtensionsModule implements IntegrationModule {

	/**
	 * The gate that says whether WordPress's inventories are reachable.
	 *
	 * @var ExtensionsPresence
	 */
	private readonly ExtensionsPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * Injected so a caller can supply one, defaulted so the boot table can keep
	 * constructing modules with no arguments.
	 *
	 * @param ExtensionsPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?ExtensionsPresence $presence = null ) {
		$this->presence = $presence ?? new ExtensionsPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Extensions;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'Plugins & Themes';
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
	 * Two states rather than four, because there is no third-party plugin to be
	 * absent or below a floor. The change engine's local tables are a dependency
	 * exactly as they are for MenusModule, and their absence is reported the same
	 * way: inactive, with no detected version.
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
	 * Caches this module's writes can invalidate: none, because the free half
	 * of the module ships no writes.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [];
	}

	/**
	 * Registers the extensions module's operations.
	 *
	 * One presence gate is shared by both reads, so a request asks whether the
	 * inventory is loaded once rather than once per operation.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			PluginList::definition(),
			[ new PluginList( $this->presence ), 'handle' ]
		);

		$registry->register(
			ThemeList::definition(),
			[ new ThemeList( $this->presence ), 'handle' ]
		);
	}
}
