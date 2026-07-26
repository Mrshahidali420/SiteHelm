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
}
