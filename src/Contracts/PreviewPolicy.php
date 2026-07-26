<?php
/**
 * Preview policy for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Preview policy for operations.
 */
enum PreviewPolicy: string {
	case Required      = 'required';
	case NotApplicable = 'not-applicable';
}
