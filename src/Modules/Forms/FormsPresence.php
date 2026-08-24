<?php
/**
 * Which form plugin serves this site.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Forms;

/**
 * The one gate that asks which form plugin this site runs.
 *
 * THE FREE PLUGIN SHIPS ONE PROVIDER — Contact Form 7 — and an add-on appends
 * more through the `sitehelm_forms_providers` filter. The built-in provider is
 * consulted first and add-on providers follow in filter order, so the answer
 * on a site running several form plugins is stable: install order of add-ons
 * cannot reorder the built-in, and the precedence a read planned against is
 * the precedence the next read sees.
 *
 * THE FILTER IS CONTAINED THE WAY Extensions' hooks are: a value that is not
 * a FormsProvider is dropped and logged rather than allowed to break the
 * gate, because nothing an add-on returns may take the forms surface down.
 *
 * @package SiteHelm
 */
final class FormsPresence {

	/**
	 * The filter through which an add-on appends FormsProvider instances.
	 */
	public const FILTER_PROVIDERS = 'sitehelm_forms_providers';

	/**
	 * The enforced Contact Form 7 floor, re-exported so the module descriptor
	 * and the enforcement come from one constant.
	 */
	public const CF7_MIN_VERSION = Cf7Provider::MIN_VERSION;

	/**
	 * The provider serving this site, or null when none is usable.
	 */
	public function provider(): ?FormsProvider {
		foreach ( $this->candidates() as $candidate ) {
			if ( $candidate->available() ) {
				return $candidate;
			}
		}

		return null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase matches the module vocabulary.
	/**
	 * Whether any supported form plugin is present at all, usable or not.
	 */
	public function isInstalled(): bool {
		foreach ( $this->candidates() as $candidate ) {
			if ( null !== $candidate->version() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a supported form plugin is present AND at or above its floor.
	 */
	public function isLoaded(): bool {
		return null !== $this->provider();
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The version of the highest-precedence plugin that is PRESENT, or null.
	 *
	 * Present rather than usable, for the same reason SeoPresence::version()
	 * records: the version-blocked health state must name the install that is
	 * blocking, with the version the operator would be updating from.
	 */
	public function version(): ?string {
		foreach ( $this->candidates() as $candidate ) {
			$version = $candidate->version();

			if ( null !== $version ) {
				return $version;
			}
		}

		return null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase matches OperationDefinition's field name.
	/**
	 * The version ranges every forms operation declares.
	 *
	 * @return array<string, string> Dependency name to version range.
	 */
	public static function supportedVersions(): array {
		return [
			'wordpress'      => '>=' . SITEHELM_MIN_WP,
			'contact-form-7' => '>=' . self::CF7_MIN_VERSION,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The built-in provider plus every valid provider an add-on appended.
	 *
	 * @return FormsProvider[] Candidates in precedence order.
	 */
	private function candidates(): array {
		$candidates = [ new Cf7Provider() ];
		$extra      = apply_filters( self::FILTER_PROVIDERS, [] );

		if ( ! is_array( $extra ) ) {
			return $candidates;
		}

		foreach ( $extra as $provider ) {
			if ( $provider instanceof FormsProvider ) {
				$candidates[] = $provider;
				continue;
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- contained add-on failure, logged server-side.
			error_log( sprintf( 'SiteHelm ignored a %s entry that is not a FormsProvider.', self::FILTER_PROVIDERS ) );
		}

		return $candidates;
	}
}
