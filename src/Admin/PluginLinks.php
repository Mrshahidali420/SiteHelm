<?php
/**
 * The console's links on the Plugins screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Puts "Connect" and "Status" beside Deactivate on the Plugins screen.
 *
 * The Plugins screen is where an operator is the moment after activating,
 * and the first thing they want is the endpoint and a credential. A link to
 * the Connect screen there saves a hunt through the menu, and is the
 * convention every plugin with a settings screen follows.
 *
 * @package SiteHelm
 */
final class PluginLinks {

	/**
	 * Register the filter for this plugin's row.
	 */
	public static function register(): void {
		if ( ! defined( 'SITEHELM_PLUGIN_FILE' ) ) {
			return;
		}

		add_filter( 'plugin_action_links_' . plugin_basename( SITEHELM_PLUGIN_FILE ), [ self::class, 'add' ] );
	}

	/**
	 * Prepend the console's links to the row's action links.
	 *
	 * @param array<int|string, string> $links The row's existing links.
	 *
	 * @return array<int|string, string>
	 */
	public static function add( array $links ): array {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return $links;
		}

		$ours = [
			'sitehelm-connect' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_CONNECT ) ),
				esc_html__( 'Connect', 'sitehelm' )
			),
			'sitehelm-status'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS ) ),
				esc_html__( 'Status', 'sitehelm' )
			),
		];

		if ( ProCatalogue::STATE_ACTIVE !== ( new ProCatalogue() )->probe()['state'] ) {
			$links['sitehelm-pro'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#b45309;font-weight:600;">%s</a>',
				esc_url( ProCatalogue::PRICING_URL ),
				esc_html__( 'Get Pro', 'sitehelm' )
			);
		}

		return $ours + $links;
	}
}
