<?php
/**
 * The one gate that asks which SEO plugin this site runs.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Answers three questions: is a supported SEO plugin loaded, which one, and what
 * version.
 *
 * THIS IS THE ONLY FILE IN THE MODULE PERMITTED TO NAME A PLUGIN SYMBOL —
 * `WPSEO_VERSION` and `RANK_MATH_VERSION`. The providers address post meta only,
 * which is why the containment here is one constant per plugin rather than a
 * wrapper around an API. Every reference is `defined()`-guarded, because an absent
 * SEO plugin is the ordinary condition of a WordPress site rather than an error,
 * and an unguarded constant is the one way this module could fatal on such a site.
 *
 * TWO PLUGINS, ONE ANSWER PER REQUEST. A site can have both installed, and some
 * do during a migration. Rather than refuse — which would leave the site's SEO
 * unreachable for exactly the period an agent is most useful — the gate picks by a
 * fixed precedence and the read REPORTS WHICH STORE IT ANSWERED FROM. Precedence
 * is Yoast SEO first, on install base alone; the ordering is arbitrary but it must
 * be stable, because a precedence that varied by request would make a write land
 * in a different plugin's store than the read that planned it.
 *
 * THE VERSION FLOORS ARE CONSERVATIVE CHOICES, NOT THE EARLIEST TECHNICALLY
 * COMPATIBLE RELEASES. The meta keys this module reads are far older than either
 * floor; what the floors buy is that a refusal on an unusually old install says
 * "update the plugin", which is a cheap and reversible outcome, where a write
 * against a store whose encoding has since changed would corrupt a page's search
 * settings silently. Erring high is the safe direction, and lowering either number
 * later is a behaviour change nothing else depends on.
 *
 * @package SiteHelm
 */
final class SeoPresence {

	/**
	 * The oldest Yoast SEO this module claims to support.
	 */
	public const YOAST_MIN_VERSION = '14.0';

	/**
	 * The oldest Rank Math this module claims to support.
	 */
	public const RANK_MATH_MIN_VERSION = '1.0.40';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The version ranges both SEO operations declare.
	 *
	 * BOTH PLUGINS ARE NAMED, because either one satisfies this module and an
	 * operation that declared one range would misdescribe a site running the other.
	 * The keys are the plugins' own slugs rather than the module id, for the same
	 * reason: `seo` is not a plugin anyone can install.
	 *
	 * OperationDefinition's PLUGIN_BACKED_MODULES rule — which would require a
	 * range keyed by the module id — deliberately does not list this module, since
	 * the one key it would demand is the one key that names nothing. The ranges are
	 * declared here instead, built from the same constants the gate enforces.
	 *
	 * @return array<string, string> Dependency name => version range.
	 */
	public static function supportedVersions(): array {
		return [
			'wordpress' => '>=' . SITEHELM_MIN_WP,
			'yoast-seo' => '>=' . self::YOAST_MIN_VERSION,
			'rank-math' => '>=' . self::RANK_MATH_MIN_VERSION,
		];
	}

	/**
	 * Whether a supported SEO plugin is loaded and in range.
	 *
	 * @return bool True when an operation can be served.
	 */
	public function isLoaded(): bool {
		return null !== $this->provider();
	}

	/**
	 * The provider for this site's SEO plugin, or null when there is none.
	 *
	 * @return SeoProvider|null The provider, or null.
	 */
	public function provider(): ?SeoProvider {
		if ( $this->supported( 'WPSEO_VERSION', self::YOAST_MIN_VERSION ) ) {
			return new YoastProvider();
		}

		if ( $this->supported( 'RANK_MATH_VERSION', self::RANK_MATH_MIN_VERSION ) ) {
			return new RankMathProvider();
		}

		return null;
	}

	/**
	 * The detected plugin's name, or null when none is usable.
	 *
	 * @return string|null The provider name.
	 */
	public function providerName(): ?string {
		return $this->provider()?->name();
	}

	/**
	 * The detected plugin's version, or null when none is installed.
	 *
	 * REPORTED EVEN WHEN IT IS BELOW THE FLOOR, and reported for the
	 * highest-precedence plugin that is present rather than for the one that is
	 * usable. An operator told "your SEO plugin is too old" needs to see the
	 * version they are updating from; a null there reads as "nothing detected",
	 * which is a different diagnosis with a different fix.
	 *
	 * @return string|null The version.
	 */
	public function version(): ?string {
		return $this->constantVersion( 'WPSEO_VERSION' ) ?? $this->constantVersion( 'RANK_MATH_VERSION' );
	}

	/**
	 * Whether a plugin is installed at all, whatever its version.
	 *
	 * @return bool True when either plugin's version constant is readable.
	 */
	public function isInstalled(): bool {
		return null !== $this->version();
	}

	/**
	 * Whether the named constant reports a version at or above the floor.
	 *
	 * @param string $constant The version constant name.
	 * @param string $floor    The minimum version.
	 *
	 * @return bool True when the plugin is present and in range.
	 */
	private function supported( string $constant, string $floor ): bool {
		$version = $this->constantVersion( $constant );

		return null !== $version && version_compare( $version, $floor, '>=' );
	}

	/**
	 * One version constant, guarded on shape.
	 *
	 * A version constant is defined by plugin code, but `wp-config.php` or an
	 * mu-plugin can define it first and to anything at all, so the value is checked
	 * rather than cast: `(string)` on an array is a fatal, and that fatal would
	 * happen inside a presence check whose entire job is to not fatal.
	 *
	 * @param string $constant The constant name.
	 *
	 * @return string|null The version, or null when it is absent or unusable.
	 */
	private function constantVersion( string $constant ): ?string {
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );

		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$version = trim( (string) $value );

		return '' === $version ? null : $version;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
