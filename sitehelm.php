<?php
/**
 * Plugin Name:       SiteHelm
 * Description:       Secure WordPress MCP operations platform: safe, auditable AI-driven site operations.
 * Version:           0.8.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            SiteHelm
 * License:           GPL-2.0-or-later
 * Text Domain:       sitehelm
 * Update URI:        https://github.com/Mrshahidali420/SiteHelm
 *
 * @package SiteHelm
 */

declare(strict_types=1);

if ( ! defined( 'SITEHELM_VERSION' ) ) {
	define( 'SITEHELM_VERSION', '0.8.0' );
	define( 'SITEHELM_MIN_PHP', '8.1' );
	define( 'SITEHELM_MIN_WP', '6.6' );
	define( 'SITEHELM_PLUGIN_FILE', __FILE__ );
}

/**
 * Whether the runtime meets the SiteHelm platform floor.
 *
 * @param string $php_version Current PHP version.
 * @param string $wp_version  Current WordPress version.
 */
function sitehelm_requirements_met( string $php_version, string $wp_version ): bool {
	return version_compare( $php_version, SITEHELM_MIN_PHP, '>=' )
		&& version_compare( $wp_version, SITEHELM_MIN_WP, '>=' );
}

/**
 * Boot the plugin when WordPress is loading it (not when the test suite includes it).
 */
function sitehelm_boot(): void {
	global $wp_version;

	if ( ! sitehelm_requirements_met( PHP_VERSION, (string) $wp_version ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'SiteHelm requires PHP 8.1+ and WordPress 6.6+. The plugin is inactive on this site.', 'sitehelm' )
				);
			}
		);
		return;
	}

	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	\SiteHelm\Bootstrap\Plugin::instance()->register();
}

/**
 * Create the plugin's local tables, schedule retention pruning and arm the
 * one-time notice that points at the Connect screen.
 *
 * The autoloader is required here explicitly: `plugins_loaded` has already
 * fired by the time an activation callback runs, so `sitehelm_boot()` has not
 * loaded it for this request.
 */
function sitehelm_activate(): void {
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	( new \SiteHelm\Storage\Installer() )->install();
	\SiteHelm\Storage\Retention::schedule();
	\SiteHelm\Admin\ActivationNotice::arm();
}

/**
 * Clear the retention pruning event. Recorded audit events and snapshots are
 * deliberately left in place: deactivating a plugin must not destroy an
 * accountability record.
 */
function sitehelm_deactivate(): void {
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	\SiteHelm\Storage\Retention::unschedule();
}

if ( defined( 'ABSPATH' ) && ! function_exists( 'sitehelm_fs' ) ) {
	/**
	 * The Freemius instance for the free plugin: insights, the Account page and
	 * the Add-Ons page. The free plugin has no paid plan of its own; SiteHelm Pro
	 * is a Freemius add-on and asks its own instance for the licence.
	 *
	 * Initialised at file load, as the SDK requires (it hooks the boot itself),
	 * and only when WordPress loads the file: the test suite includes it too.
	 */
	function sitehelm_fs(): Freemius {
		global $sitehelm_fs;

		if ( ! isset( $sitehelm_fs ) ) {
			require_once __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';

			$sitehelm_fs = fs_dynamic_init(
				[
					'id'               => '37703',
					'slug'             => 'sitehelm',
					'type'             => 'plugin',
					'public_key'       => 'pk_4120cc42347ac2cb9e62d0d1ba8fc',
					'is_premium'       => false,
					'has_addons'       => true,
					'has_paid_plans'   => false,
					'is_org_compliant' => true,
					'menu'             => [
						'slug'       => 'sitehelm',
						'first-path' => 'admin.php?page=sitehelm',
						'contact'    => false,
						'support'    => false,
					],
				]
			);
		}

		return $sitehelm_fs;
	}

	sitehelm_fs();
	do_action( 'sitehelm_fs_loaded' );
}

if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', 'sitehelm_boot' );
	register_activation_hook( __FILE__, 'sitehelm_activate' );
	register_deactivation_hook( __FILE__, 'sitehelm_deactivate' );
}
