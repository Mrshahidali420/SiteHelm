<?php
/**
 * Operation modes for SiteHelm.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Operation modes: read or write access to the target site.
 */
enum Mode: string {
	case Read  = 'read';
	case Write = 'write';
}
