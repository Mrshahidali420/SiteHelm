<?php
/**
 * Rollback policy for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Rollback policy for operations.
 */
enum RollbackPolicy: string {
	case Required      = 'required';
	case Supported     = 'supported';
	case NotApplicable = 'not-applicable';
}
