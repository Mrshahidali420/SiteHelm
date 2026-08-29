<?php
/**
 * Supported module identifiers for SiteHelm.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Supported module identifiers.
 */
enum ModuleId: string {
	case Core        = 'core';
	case Diagnostics = 'diagnostics';
	case Media       = 'media';
	case Menus       = 'menus';
	case Elementor   = 'elementor';
	case Acf         = 'acf';
	case Metabox     = 'metabox';
	case Seo         = 'seo';
	case Forms       = 'forms';

	/**
	 * WooCommerce. The only identifier no built-in module implements: the
	 * operations behind it ship in the SiteHelm Pro add-on and reach the
	 * registry through `sitehelm_modules`. The case lives here because the
	 * console's permission levels, the operation switches and the health
	 * report are all keyed by this enum, and an add-on cannot add a case.
	 */
	case Woocommerce = 'woocommerce';

	/**
	 * Code snippets, custom CSS and custom JavaScript.
	 *
	 * The only module that SHIPS DISABLED, and the distinction that makes that
	 * different from every other module here is worth stating: Elementor, ACF,
	 * Meta Box and WooCommerce report themselves *unavailable* when the plugin
	 * behind them is missing, which is a fact about the site. This one is
	 * *off*, which is a decision by the owner. It has no external dependency —
	 * the default host is SiteHelm's own runner — so it is never unavailable,
	 * and `system-integrations` says so in those words rather than borrowing
	 * the vocabulary of a missing plugin.
	 *
	 * Deliberately NOT in `OperationDefinition::PLUGIN_BACKED_MODULES` for the
	 * same reason.
	 */
	case Code = 'code';
}
