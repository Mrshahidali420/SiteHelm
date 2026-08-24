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
}
