<?php
/**
 * Plugin Name:       SiteHelm
 * Description:       Secure WordPress MCP operations platform: safe, auditable AI-driven site operations.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            SiteHelm
 * License:           GPL-2.0-or-later
 * Text Domain:       sitehelm
 *
 * @package SiteHelm
 */

declare(strict_types=1);

if ( ! defined( 'SITEHELM_VERSION' ) ) {
	define( 'SITEHELM_VERSION', '0.1.0' );
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

if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', 'sitehelm_boot' );
}
