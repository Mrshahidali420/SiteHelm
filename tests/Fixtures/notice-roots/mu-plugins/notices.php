<?php
/**
 * A notice from a must-use plugin.
 *
 * Must-use plugins live outside the ordinary plugins directory and load on
 * every request, so a pruner that only knew about WP_PLUGIN_DIR would leave
 * their banners in place. This fixture exists so that the WPMU_PLUGIN_DIR root
 * has something to remove, and its absence is therefore visible to a test.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

function sitehelm_fixture_mu_plugin_notice(): void {
}
