<?php
/**
 * Plugin Name:       SiteHelm Pro
 * Plugin URI:        https://github.com/shahidalisa/sitehelm
 * Description:       The Pro add-on for SiteHelm — deeper SEO, more integrations and more console control, licensed per site. Requires SiteHelm.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  sitehelm
 * Author:            Shahid Ali
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitehelm-pro
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

if ( ! defined( 'SITEHELM_PRO_VERSION' ) ) {
	define( 'SITEHELM_PRO_VERSION', '0.1.0' );
	define( 'SITEHELM_PRO_PLUGIN_FILE', __FILE__ );
}

/**
 * Boot SiteHelm Pro, or explain why it cannot.
 *
 * Runs at `plugins_loaded` priority 5 — before SiteHelm's own boot at 10 —
 * because the add-on's hooks must be registered by the time SiteHelm builds
 * its module directory. SiteHelm's classes are not loaded yet at that point,
 * so the presence test is the bootstrap function its main file defines.
 */
function sitehelm_pro_boot(): void {
	if ( ! function_exists( 'sitehelm_boot' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'SiteHelm Pro needs the SiteHelm plugin to be installed and active. Nothing Pro is loaded until it is.', 'sitehelm-pro' )
				);
			}
		);
		return;
	}

	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	\SiteHelm\Pro\Bootstrap\ProPlugin::instance()->register();
}

if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', 'sitehelm_pro_boot', 5 );
}
