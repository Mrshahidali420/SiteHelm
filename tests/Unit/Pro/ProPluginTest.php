<?php
/**
 * The add-on's bootstrap: hook names pinned to the free plugin's constants.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use SiteHelm\Bootstrap\Extensions;
use SiteHelm\Pro\Admin\LicenceAction;
use SiteHelm\Pro\Bootstrap\ProPlugin;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class ProPluginTest extends TestCase {

	public function test_the_hook_literals_match_the_free_plugin_extension_points(): void {
		$this->assertSame( Extensions::FILTER_MODULES, ProPlugin::HOOK_MODULES );
		$this->assertSame( Extensions::ACTION_REGISTER_OPERATIONS, ProPlugin::HOOK_REGISTER_OPERATIONS );
		$this->assertSame( Extensions::ACTION_STATUS_SECTIONS, ProPlugin::HOOK_STATUS_SECTIONS );
	}

	public function test_register_hooks_all_four_extension_points(): void {
		Filters\expectAdded( ProPlugin::HOOK_MODULES )->once();
		Actions\expectAdded( ProPlugin::HOOK_REGISTER_OPERATIONS )->once();
		Actions\expectAdded( ProPlugin::HOOK_STATUS_SECTIONS )->once();
		Actions\expectAdded( 'admin_post_' . LicenceAction::ACTION )->once();

		ProPlugin::instance()->register();

		$this->assertSame( 10, Filters\has( ProPlugin::HOOK_MODULES, [ ProPlugin::instance(), 'add_modules' ] ) );
		$this->assertSame( 10, Actions\has( ProPlugin::HOOK_REGISTER_OPERATIONS, [ ProPlugin::instance(), 'register_operations' ] ) );
	}

	public function test_the_module_filter_is_additive_and_the_registry_is_left_alone(): void {
		$plugin = ProPlugin::instance();

		$this->assertSame( [ 'Some\\Module' ], $plugin->add_modules( [ 'Some\\Module' ] ) );
		$plugin->register_operations( new CapabilityRegistry() );
		$this->assertSame( ProPlugin::instance(), $plugin );
	}
}
