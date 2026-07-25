<?php
/**
 * Snapshot policy for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Snapshot policy for operations.
 */
enum SnapshotPolicy: string {
	case Required      = 'required';
	case Supported     = 'supported';
	case NotApplicable = 'not-applicable';
}
