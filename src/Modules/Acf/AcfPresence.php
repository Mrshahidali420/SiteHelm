<?php
/**
 * The one gate that asks whether Advanced Custom Fields is installed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Answers two questions: is ACF loaded, and what version is it.
 *
 * THIS IS ONE OF ONLY TWO FILES IN THE MODULE PERMITTED TO NAME AN ACF SYMBOL —
 * an `acf_*` function, `get_field`, `update_field`, `delete_field`, or
 * `ACF_VERSION` (spec Decision 2). AcfApi is the other. Every reference below is
 * guarded by `defined()` / `function_exists()`, because ACF is a third-party
 * plugin rather than WordPress itself, and that makes a failure mode ordinary
 * rather than exceptional: a missing plugin is the state of most sites. A
 * dispatcher call on a site without ACF must refuse cleanly through the existing
 * error codes, never fatal, and an unguarded symbol here is the only way it
 * could fatal.
 *
 * Every answer is guarded on its SHAPE rather than cast, the same rule
 * ElementorPresence follows. `ACF_VERSION` is a constant any `wp-config.php` or
 * mu-plugin can define to anything at all, and `(string)` on an array is a
 * fatal.
 *
 * @package SiteHelm
 */
final class AcfPresence {

	/**
	 * The oldest ACF this module's operations claim to support.
	 *
	 * It lives here rather than beside SITEHELM_MIN_PHP and SITEHELM_MIN_WP in
	 * the plugin bootstrap, because those two gate whether SiteHelm boots at all
	 * and ACF is NOT a boot requirement: a site without it must load SiteHelm
	 * normally and simply report this module inactive. Putting an optional
	 * dependency's floor in the boot gate is how an optional dependency quietly
	 * becomes a mandatory one — the reason ElementorPresence records for the same
	 * placement.
	 *
	 * 5.9 is the floor at which `ACF_VERSION`, `acf_get_field_groups()` location
	 * matching, and `acf_get_setting( 'pro' )` are all present and stable.
	 */
	public const MIN_VERSION = '5.9.0';

	/**
	 * The constant ACF defines to announce its version.
	 */
	public const VERSION_CONSTANT = 'ACF_VERSION';

	/**
	 * The ACF function this gate probes for.
	 *
	 * It is the exact API surface AcfApi calls rather than some other ACF
	 * function that happens to exist, so a site that passes this gate is a site
	 * whose calls will resolve.
	 */
	public const PROBE_FUNCTION = 'acf_get_field_groups';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether ACF is loaded in this request.
	 *
	 * BOTH tests, not either. The reference implementation gates on the function
	 * alone; one signal is not enough for the same reason it was not enough for
	 * Elementor. The constant alone is satisfied by a `wp-config.php` that defines
	 * it for an unrelated reason, or by a deactivated ACF whose bootstrap file was
	 * still read — and it leaves no API to call. The function alone is the weakest
	 * possible uniqueness claim on a WordPress site, and it leaves no version for
	 * the policy layer to version-block against. Only the pair means "ACF is here
	 * and its API can be addressed".
	 *
	 * `class_exists( 'ACF' )` is deliberately NOT the second signal: `ACF` is an
	 * unnamespaced three-letter class name, which claims less uniqueness than
	 * either of the two used here.
	 *
	 * @return bool True when ACF is present.
	 */
	public function isLoaded(): bool {
		return defined( self::VERSION_CONSTANT ) && function_exists( self::PROBE_FUNCTION );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether the installed ACF is at or above this module's floor.
	 *
	 * SEPARATE FROM isLoaded() BECAUSE "ABSENT" AND "TOO OLD" ARE DIFFERENT
	 * ANSWERS AND LEAD AN OPERATOR TO DIFFERENT ACTIONS — install ACF, or update
	 * it. The operations refuse the first with `IntegrationUnavailable` and the
	 * second with `UnsupportedVersion`, and an operator who reads the wrong one
	 * of those goes looking in the plugin installer for something their site
	 * already has.
	 *
	 * MIN_VERSION is the floor at which `ACF_VERSION`, `acf_get_field_groups()`
	 * location matching and `acf_get_setting( 'pro' )` are all present, which is
	 * to say the floor below which this module's reads do not merely report less
	 * — they report a field schema assembled from an API that answers
	 * differently. Refusing is the honest answer.
	 *
	 * AN UNREADABLE VERSION IS NOT TREATED AS AN OLD ONE. version() answers null
	 * when `ACF_VERSION` holds something that is not a scalar, and that is a
	 * claim this gate cannot substantiate in either direction; refusing on it
	 * would block a working site over a constant another plugin mangled. A
	 * version this code cannot read is not evidence of an unsupported one.
	 *
	 * @return bool True when ACF is loaded and not known to be below the floor.
	 */
	public function isSupported(): bool {
		if ( ! $this->isLoaded() ) {
			return false;
		}

		$version = $this->version();

		return null === $version || version_compare( $version, self::MIN_VERSION, '>=' );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The installed ACF version, or null when ACF is absent.
	 *
	 * This is what makes an ACF upgrade between preview and apply invalidate a
	 * plan, the same role the Elementor version plays for that module, so it is
	 * read from the constant rather than from a class property that a partially
	 * booted ACF may not have set yet.
	 *
	 * A constant holding a non-scalar answers null rather than being cast:
	 * `(string)` on an array is a fatal, and a version this code cannot read is
	 * indistinguishable, for planning purposes, from no version at all.
	 *
	 * @return string|null The version string, or null.
	 */
	public function version(): ?string {
		if ( ! defined( self::VERSION_CONSTANT ) ) {
			return null;
		}

		$version = constant( self::VERSION_CONSTANT );

		return is_scalar( $version ) ? (string) $version : null;
	}
}
