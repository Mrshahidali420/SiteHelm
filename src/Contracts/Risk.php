<?php
/**
 * Risk levels for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Risk levels for operations.
 */
enum Risk: string {
	case Low    = 'low';
	case Medium = 'medium';
	case High   = 'high';
}
