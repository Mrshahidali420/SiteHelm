<?php
/**
 * The one gate that asks whether Elementor is installed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Answers two questions and one derived one: is Elementor loaded, what version
 * is it, and which widget types does it register.
 *
 * THIS IS THE ONLY FILE IN THE MODULE PERMITTED TO NAME AN `\Elementor\` SYMBOL
 * OR `ELEMENTOR_VERSION` (spec Decision 2). Every reference below is guarded by
 * `defined()` / `class_exists()` / `property_exists()`, because this is the
 * first SiteHelm module whose dependency is a third-party plugin rather than
 * WordPress itself, and that makes a new failure mode ordinary: a missing
 * plugin is not an exceptional condition, it is the state of most sites. A
 * dispatcher call on a site without Elementor must refuse cleanly through the
 * existing error codes, never fatal, and an unguarded symbol here is the only
 * way it could fatal.
 *
 * Every answer is guarded on its SHAPE rather than cast, the same rule MenuFields
 * follows for core's filtered returns. `ELEMENTOR_VERSION` is a constant any
 * `wp-config.php` or mu-plugin can define to anything at all, and
 * `get_widget_types()` is a public method on a manager a third party may swap
 * out; `(string)` on an array is a fatal and `(array)` on a string is a
 * one-member list of garbage.
 *
 * @package SiteHelm
 */
final class ElementorPresence {

	/**
	 * The oldest Elementor this module's operations claim to support.
	 *
	 * It lives here rather than beside SITEHELM_MIN_PHP and SITEHELM_MIN_WP in
	 * the plugin bootstrap, because those two gate whether SiteHelm boots at all
	 * and Elementor is NOT a boot requirement: a site without it must load
	 * SiteHelm normally and simply report this module inactive. Putting an
	 * optional dependency's floor in the boot gate is how an optional dependency
	 * quietly becomes a mandatory one.
	 */
	public const MIN_VERSION = '3.0.0';

	/**
	 * The constant Elementor defines to announce its version.
	 */
	public const VERSION_CONSTANT = 'ELEMENTOR_VERSION';

	/**
	 * The plugin singleton class Elementor exposes.
	 */
	public const PLUGIN_CLASS = 'Elementor\Plugin';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether Elementor is loaded in this request.
	 *
	 * BOTH tests, not either. The constant alone is satisfied by a `wp-config.php`
	 * that defines it for an unrelated reason, or by Elementor's plugin file
	 * having been read while its classes were never loaded. The class alone is
	 * satisfied by any other plugin that ships a class in that namespace. Only
	 * the pair means "Elementor is here and its API can be addressed".
	 *
	 * @return bool True when Elementor is present.
	 */
	public function isLoaded(): bool {
		return defined( self::VERSION_CONSTANT ) && class_exists( self::PLUGIN_CLASS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The installed Elementor version, or null when Elementor is absent.
	 *
	 * This is what makes an Elementor upgrade between preview and apply
	 * invalidate a plan, the same role the WordPress version plays for the menus
	 * module, so it is read from the constant rather than from a class property
	 * that a partially booted Elementor may not have set yet.
	 *
	 * A constant holding a non-scalar answers null rather than being cast:
	 * `(string)` on an array is a fatal, and a version this code cannot read is
	 * indistinguishable, for planning purposes, from no version at all.
	 *
	 * @return string|null The version string, or null.
	 */
	public function version(): ?string {
		if ( ! defined( self::VERSION_CONSTANT ) ) {
			return null;
		}

		$version = constant( self::VERSION_CONSTANT );

		return is_scalar( $version ) ? (string) $version : null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The widget type names the installed Elementor registers.
	 *
	 * NULL AND `[]` ARE DIFFERENT ANSWERS AND THE DIFFERENCE IS THE POINT. Null
	 * means the registry could not be reached — Elementor absent, its singleton
	 * not built yet, its widget manager swapped out or missing. `[]` means the
	 * registry WAS read and registers nothing. REQ-0034 refuses on null; were
	 * this to answer `[]` instead, that operation would tell an operator that
	 * every widget type they proposed is missing from their site when in fact
	 * nothing was checked. "Unavailable" and "I could not check" are different
	 * answers, and reporting the second as the first is a lie an operator acts
	 * on.
	 *
	 * The names are the registry's KEYS. `Widgets_Manager::get_widget_types()`
	 * called with no argument returns an associative array keyed by widget type
	 * name whose values are `Widget_Base` instances; the values are never read
	 * here, so no `Widget_Base` method is called and no widget is instantiated.
	 *
	 * @return string[]|null The registered type names, or null when unreachable.
	 */
	public function widgetTypes(): ?array {
		$manager = $this->widgets_manager();

		if ( null === $manager ) {
			return null;
		}

		$types = $manager->get_widget_types();

		if ( ! is_array( $types ) ) {
			return null;
		}

		return array_values( array_map( 'strval', array_keys( $types ) ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Elementor's widget manager, or null when it cannot be addressed.
	 *
	 * Four separate ways to be unreachable, each guarded rather than assumed:
	 * Elementor absent; `\Elementor\Plugin` carrying no `$instance` property at
	 * all (a `??` would not save us — an undefined STATIC property is an Error,
	 * not a notice); the singleton null, which is the real state between the
	 * plugin header defining the constant and `Plugin::instance()` running on
	 * `plugins_loaded`; and a `widgets_manager` that some other plugin has
	 * replaced with something that does not answer the registry call.
	 *
	 * @return object|null The manager, or null.
	 */
	private function widgets_manager(): ?object {
		if ( ! $this->isLoaded() || ! property_exists( self::PLUGIN_CLASS, 'instance' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! is_object( $plugin ) ) {
			return null;
		}

		$manager = $plugin->widgets_manager ?? null;

		return is_object( $manager ) && method_exists( $manager, 'get_widget_types' ) ? $manager : null;
	}
}
