<?php
/**
 * Plugin Name:       SiteHelm
 * Description:       Secure WordPress MCP operations platform: safe, auditable AI-driven site operations.
 * Version:           0.13.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Plugin URI:        https://wpsitehelm.com/
 * Author:            SiteHelm
 * Author URI:        https://wpsitehelm.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       sitehelm
 * Update URI:        https://github.com/Mrshahidali420/SiteHelm
 *
 * @package SiteHelm
 */

declare(strict_types=1);

if ( ! defined( 'SITEHELM_VERSION' ) ) {
	define( 'SITEHELM_VERSION', '0.13.0' );
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
					// The Add-Ons submenu is hidden: it lists exactly one add-on, it
					// has to reach Freemius over the network to draw itself, and on a
					// host that blocks outbound calls it renders "We couldn't load the
					// add-ons list" — which was one of the two routes the console
					// offered for buying Pro. SiteHelm's own Upgrade screen answers the
					// same question from prices it already has. Add-on support itself is
					// untouched; only the SDK's page is.
					//
					// The Account submenu is hidden for a different reason: SiteHelm is
					// installed on sites its buyer does not own. That page prints the
					// licence holder's real name, email address, billing address, payment
					// history and API keys, and it sits in the menu of every site the
					// licence covers — so an agency's client, or anyone else with an
					// administrator login, reads the agency owner's personal details on
					// their way past. The page itself still answers at its own address,
					// which is what the licence screen links to, so syncing or moving a
					// licence is unaffected; it is only no longer advertised in the menu
					// of somebody else's site. It is also the only route to the Add-Ons
					// page hidden above, which is how that page kept appearing.
					'menu'             => [
						'slug'       => 'sitehelm',
						'first-path' => 'admin.php?page=sitehelm',
						'contact'    => false,
						'support'    => false,
						'addons'     => false,
						'account'    => false,
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
