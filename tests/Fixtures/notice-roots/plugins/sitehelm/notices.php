<?php
/**
 * A notice belonging to SiteHelm itself.
 *
 * IT SITS INSIDE THE PLUGINS ROOT ON PURPOSE, because that is where SiteHelm
 * actually lives on a real site. An earlier version of these fixtures put it
 * one directory over from the plugins root instead, and that made the pruner's
 * own-directory check impossible to test: this notice was outside every
 * removable root, so it was kept whether that check ran or not. A deletion
 * sweep caught it — the check could be deleted entirely and every test still
 * passed, while on a real site the plugin would have removed its own banners.
 *
 * See the sibling fixture under other-plugin for why these files exist at all.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

function sitehelm_fixture_own_notice(): void {
}
