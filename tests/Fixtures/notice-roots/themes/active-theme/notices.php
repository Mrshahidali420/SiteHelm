<?php
/**
 * A notice from the active theme.
 *
 * Themes register admin notices as readily as plugins do — upsells for the pro
 * version, "install our companion plugin" nags — and they live under a root
 * that is a function call rather than a constant, so it is reached by a
 * different branch than the other two. This fixture gives that branch
 * something to remove.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

function sitehelm_fixture_theme_notice(): void {
}
