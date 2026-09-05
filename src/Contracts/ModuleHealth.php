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
	case Unconfigured   = 'unconfigured';

	/**
	 * Whether this module can serve a call.
	 *
	 * THE FOURTH STATE IS NOT A FAILURE, and this method is what keeps every
	 * caller from treating it as one. `Unconfigured` means the plugin behind the
	 * module is present, in range and loaded — every operation works and every
	 * value it writes is stored — but the plugin has parked itself behind its own
	 * onboarding and is not yet acting on what it holds. Rank Math is the case
	 * that produced it: from roughly 1.0.200 it registers no `wp_head` output at
	 * all until an owner finishes its setup, so a site could be told its SEO
	 * module was active, write a description into it, read the same description
	 * back, and serve a page carrying none of it.
	 *
	 * So the distinction is deliberate and narrow. Availability asks whether a
	 * call will be answered, and the answer is yes. What is missing is the
	 * plugin's effect on the front end, which is a caveat to report, not a reason
	 * to refuse.
	 *
	 * @return bool True when a call to this module's operations will be answered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isOperational(): bool {
		return self::Active === $this || self::Unconfigured === $this;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
