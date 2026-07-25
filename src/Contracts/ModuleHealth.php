<?php
/**
 * Module health status for SiteHelm.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Health status of a module.
 */
enum ModuleHealth: string {
	case Active         = 'active';
	case Inactive       = 'inactive';
	case VersionBlocked = 'version-blocked';
}
