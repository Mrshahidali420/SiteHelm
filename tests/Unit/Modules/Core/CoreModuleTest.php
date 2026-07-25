<?php
/**
 * Tests for CoreModule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Tests the core module declaration and its registrations.
 */
final class CoreModuleTest extends TestCase {

	public function test_module_declares_core_identity_and_active_health(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$module = new CoreModule();

		$this->assertSame( ModuleId::Core, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	public function test_module_registers_content_get_on_the_content_read_dispatcher(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->has( 'content-get' ) );
		$definition = $registry->definition( 'content-get' );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Core, $definition->module );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertTrue( $definition->isReadOnly );
	}
}
