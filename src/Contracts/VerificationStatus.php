<?php
/**
 * Verification status for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Verification status for operations.
 */
enum VerificationStatus: string {
	case Verified                = 'verified';
	case VerifiedWithAdjustments = 'verified-with-adjustments';
	case NotApplicable           = 'not-applicable';
}
