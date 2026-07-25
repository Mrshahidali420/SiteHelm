<?php
/**
 * Product domains for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Product domains. A domain determines which dispatcher pair may expose an operation.
 */
enum Domain: string {
	case System    = 'system';
	case Content   = 'content';
	case Media     = 'media';
	case Menu      = 'menu';
	case Elementor = 'elementor';
	case Fields    = 'fields';
}
