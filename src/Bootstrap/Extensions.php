<?php
/**
 * The three extension points an add-on plugin (SiteHelm Pro) hooks into.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\IntegrationDirectory;
use Throwable;

/**
 * Names and guards the hooks through which another plugin extends SiteHelm.
 *
 * An add-on can add modules (`sitehelm_modules`), register operations into
 * an existing module (`sitehelm_register_operations`), and render sections
 * on the Health tab (`sitehelm_status_sections`). Every hook is additive and
 * contained: nothing an add-on returns can remove a built-in module, and a
 * throwing handler is logged rather than allowed to break the boot.
 *
 * The hook names are plain strings by contract — an add-on registers its
 * handlers before this plugin's autoloader has run, so it cannot read these
 * constants; the constants exist so the names are pinned by a test.
 */
final class Extensions {

	public const FILTER_MODULES = 'sitehelm_modules';

	public const ACTION_REGISTER_OPERATIONS = 'sitehelm_register_operations';

	public const ACTION_STATUS_SECTIONS = 'sitehelm_status_sections';

	/**
	 * The built-in module classes plus every valid class an add-on appended.
	 *
	 * A value that is not a string, not a loadable class, or not an
	 * IntegrationModule is dropped and logged; a duplicate is ignored.
	 *
	 * @return string[] Class names in load order.
	 */
	public static function module_classes(): array {
		$classes = IntegrationDirectory::MODULE_CLASSES;
		$extra   = apply_filters( self::FILTER_MODULES, [] );

		if ( ! is_array( $extra ) ) {
			return $classes;
		}

		foreach ( $extra as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, IntegrationModule::class ) ) {
				if ( ! in_array( $class, $classes, true ) ) {
					$classes[] = $class;
				}
				continue;
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- contained add-on failure, logged server-side.
			error_log( sprintf( 'SiteHelm ignored a %s entry that is not an IntegrationModule class.', self::FILTER_MODULES ) );
		}

		return $classes;
	}

	/**
	 * Let an add-on register operations into the live registry.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway will serve from.
	 */
	public static function register_operations( CapabilityRegistry $registry ): void {
		self::contained(
			static function () use ( $registry ): void {
				do_action( self::ACTION_REGISTER_OPERATIONS, $registry );
			},
			self::ACTION_REGISTER_OPERATIONS
		);
	}

	/**
	 * Let an add-on render sections at the foot of the Health tab.
	 */
	public static function status_sections(): void {
		self::contained(
			static function (): void {
				do_action( self::ACTION_STATUS_SECTIONS );
			},
			self::ACTION_STATUS_SECTIONS
		);
	}

	/**
	 * Run one hook; a throwing handler is logged, never fatal.
	 *
	 * @param callable $run  The hook invocation.
	 * @param string   $hook The hook name, for the log line.
	 */
	private static function contained( callable $run, string $hook ): void {
		try {
			$run();
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- contained add-on failure, logged server-side.
			error_log( sprintf( 'SiteHelm add-on handler on %s failed: %s', $hook, $e->getMessage() ) );
		}
	}
}
