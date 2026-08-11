<?php
/**
 * The one gate that asks whether Meta Box is installed.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

/**
 * Answers two questions: is Meta Box loaded, and what version is it.
 *
 * THIS IS ONE OF ONLY TWO FILES IN THE MODULE PERMITTED TO NAME AN RWMB SYMBOL —
 * `RWMB_VER`, `rwmb_get_registry`, `rwmb_meta`, `rwmb_set_meta`, or any other
 * (spec §4). MetaboxApi is the other. Every reference below is guarded by
 * `defined()` / `function_exists()`, because Meta Box is a third-party plugin
 * rather than WordPress itself, and that makes a failure mode ordinary rather
 * than exceptional: a missing plugin is the state of most sites. A dispatcher
 * call on a site without Meta Box must refuse cleanly through the existing error
 * codes, never fatal, and an unguarded symbol here is the only way it could
 * fatal.
 *
 * Every answer is guarded on its SHAPE rather than cast, the same rule
 * AcfPresence and ElementorPresence follow. `RWMB_VER` is a constant any
 * `wp-config.php` or mu-plugin can define to anything at all, and `(string)` on
 * an array is a fatal.
 *
 * @package SiteHelm
 */
final class MetaboxPresence {

	/**
	 * The oldest Meta Box this module's operations claim to support.
	 *
	 * It lives here rather than beside SITEHELM_MIN_PHP and SITEHELM_MIN_WP in the
	 * plugin bootstrap, because those two gate whether SiteHelm boots at all and
	 * Meta Box is NOT a boot requirement: a site without it must load SiteHelm
	 * normally and simply report this module inactive. Putting an optional
	 * dependency's floor in the boot gate is how an optional dependency quietly
	 * becomes a mandatory one — the reason AcfPresence records for the same
	 * placement.
	 *
	 * HOW 5.3.0 WAS ESTABLISHED. It is the lowest published Meta Box release on
	 * which EVERY symbol this module calls exists at once, read off the tagged
	 * sources of the plugin's own public repository (`wpmetabox/meta-box`) rather
	 * than inferred from a changelog summary:
	 *
	 *   - `rwmb_get_registry()` appears in `inc/functions.php` from 4.11.
	 *   - The meta box registry gains `get_by()` alongside `all()` in
	 *     `inc/meta-box-registry.php` at 4.13.0; 4.11 and 4.12 expose `all()`
	 *     only, and this module's target applicability read is written against
	 *     `get_by( [ 'object_type' => … ] )`.
	 *   - `rwmb_set_meta()` first appears in `inc/functions.php` at 5.3.0. It is
	 *     the write REQ-0051 is built on, so a floor below it would advertise
	 *     support for sites where the module's one write cannot run at all.
	 *
	 * 5.3.0 is therefore the first release where the discovery reads, the value
	 * read and the value write are all addressable. It is deliberately the
	 * ALL-SYMBOLS floor rather than the registry-only floor, matching the rule
	 * AcfPresence::MIN_VERSION records: a module advertises the version at which
	 * everything it declares actually works.
	 *
	 * `rwmb_delete_meta()` is NOT part of this arithmetic. It arrives only at
	 * 5.14.0, far above any defensible floor, so MetaboxApi::deleteValue() removes
	 * the post meta row through core WordPress instead — see its docblock.
	 */
	public const MIN_VERSION = '5.3.0';

	/**
	 * The constant Meta Box defines to announce its version.
	 */
	public const VERSION_CONSTANT = 'RWMB_VER';

	/**
	 * The Meta Box function this gate probes for.
	 *
	 * It is the exact API surface MetaboxApi enters through rather than some other
	 * Meta Box function that happens to exist, so a site that passes this gate is a
	 * site whose calls will resolve.
	 */
	public const PROBE_FUNCTION = 'rwmb_get_registry';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether Meta Box is loaded in this request.
	 *
	 * BOTH tests, not either (spec §4). The constant alone is satisfied by a
	 * `wp-config.php` that defines it for an unrelated reason, or by a deactivated
	 * Meta Box whose bootstrap file was still read — and it leaves no API to call.
	 * The function alone leaves no version for the policy layer to version-block
	 * against, so a site passing on that signal would be served by operations that
	 * could never be refused as unsupported. Only the pair means "Meta Box is here
	 * and its registry can be addressed".
	 *
	 * `class_exists( 'RW_Meta_Box' )` is deliberately NOT the second signal: it is
	 * an unnamespaced class name a theme or a compatibility shim can declare, which
	 * claims less uniqueness than either of the two used here.
	 *
	 * @return bool True when Meta Box is present.
	 */
	public function isLoaded(): bool {
		return defined( self::VERSION_CONSTANT ) && function_exists( self::PROBE_FUNCTION );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The installed Meta Box version, or null when Meta Box is absent.
	 *
	 * This is what makes a Meta Box upgrade between preview and apply invalidate a
	 * plan, the same role the ACF version plays for that module, so it is read from
	 * the constant rather than from a class property a partially booted Meta Box may
	 * not have set yet.
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
