<?php
/**
 * Permission modes for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Permission modes for operations.
 */
enum PermissionMode: string {
	case ReadOnly     = 'read-only';
	case SafeWrite    = 'safe-write';
	case TrustedWrite = 'trusted-write';
}
