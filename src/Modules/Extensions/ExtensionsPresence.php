<?php
/**
 * Whether this request can see WordPress's own plugin and theme inventories.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

/**
 * The one gate the extensions reads ask before they read anything.
 *
 * THERE IS NO THIRD-PARTY DEPENDENCY HERE, which is what makes this class
 * different from every other presence gate in the plugin. Forms asks which form
 * plugin a site runs; SEO asks which SEO plugin. What this one asks is narrower
 * and entirely about WordPress: whether the inventory functions are loaded in
 * THIS request. `get_plugins()` lives in `wp-admin/includes/plugin.php`, which
 * WordPress loads on admin screens and on nothing else — a REST request has not
 * seen it — so the gate's job is to load that file and then say whether the
 * function arrived.
 *
 * THE CLASS IS NOT FINAL, AND THE TWO PROBES ARE PROTECTED, for the reason
 * MediaFetch::curlOptionsAvailable() records: `function_exists()` cannot be made
 * to answer false for a function that is defined, so the refusal branch the
 * probes guard would have nothing pinning it. Each probe contains its probe and
 * nothing else — a subclass may say the inventory is unreachable, and may not
 * change what the operations then do about it.
 *
 * @package SiteHelm
 */
class ExtensionsPresence {

	/**
	 * The admin include that defines the plugin inventory.
	 */
	private const PLUGIN_ADMIN_INCLUDE = 'wp-admin/includes/plugin.php';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase matches OperationDefinition's field name.
	/**
	 * The version ranges every extensions operation declares.
	 *
	 * WordPress alone. The module reads WordPress's own inventories, so there is
	 * no plugin floor to name and no plugin whose absence could block it.
	 *
	 * @return array<string, string> Dependency name to version range.
	 */
	public static function supportedVersions(): array {
		return [ 'wordpress' => '>=' . SITEHELM_MIN_WP ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's surface is camelCase because its callers are.
	/**
	 * Loads the plugin inventory API and reports whether it is usable.
	 *
	 * The include is attempted before the probe rather than instead of it: a
	 * request that already has the file loads nothing, and a request that does
	 * not gets one `require_once` whose success is then measured rather than
	 * assumed.
	 */
	public function pluginInventoryAvailable(): bool {
		$this->loadPluginAdminApi();

		return $this->adminPluginApiAvailable();
	}

	/**
	 * Whether the theme inventory is usable.
	 *
	 * `wp_get_themes()` lives in `wp-includes/theme.php` and is loaded on every
	 * request, so there is nothing to include here. The probe stays because the
	 * refusal it guards must be reachable in a test, and because a caller of
	 * this class should not have to know which of the two inventories happens to
	 * need an include today.
	 */
	public function themeInventoryAvailable(): bool {
		return $this->adminThemeApiAvailable();
	}

	/**
	 * Whether the plugin inventory function is defined.
	 *
	 * THE TEST SEAM. Contains the probe and nothing else.
	 */
	protected function adminPluginApiAvailable(): bool {
		return function_exists( 'get_plugins' ) && function_exists( 'is_plugin_active' );
	}

	/**
	 * Whether the theme inventory function is defined.
	 *
	 * THE TEST SEAM, for the same reason as the one above.
	 */
	protected function adminThemeApiAvailable(): bool {
		return function_exists( 'wp_get_themes' );
	}

	/**
	 * Includes WordPress's plugin administration API once.
	 *
	 * Guarded on ABSPATH as well as on the function, because nothing may be
	 * required from a path that does not exist: this class is constructed in
	 * suites where no site is booted, and an unguarded include would fail there
	 * as a fatal rather than as the typed refusal the operations raise.
	 */
	private function loadPluginAdminApi(): void {
		if ( function_exists( 'get_plugins' ) || ! defined( 'ABSPATH' ) ) {
			return;
		}

		$path = ABSPATH . self::PLUGIN_ADMIN_INCLUDE;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
