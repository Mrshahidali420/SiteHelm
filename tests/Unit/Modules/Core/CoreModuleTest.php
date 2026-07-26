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
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * Tests the core module declaration and its registrations.
 */
final class CoreModuleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];
		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
	}

	public function test_module_is_active_with_the_wordpress_version_when_storage_is_ready(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$module = new CoreModule();

		$this->assertSame( ModuleId::Core, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * The catalog, system-read integration health, and Dispatcher all read this
	 * one value. If it stayed 'active' while the tables were missing, the
	 * content-write catalog would advertise every write as available and every
	 * invocation would still refuse — three surfaces contradicting each other,
	 * and an AI client attempting a write the catalog promised.
	 */
	public function test_module_is_inactive_with_no_version_when_storage_is_unavailable(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$health = ( new CoreModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
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

	public function test_module_registers_content_update_as_a_write_operation(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->hasWriteOperation( 'content-update' ) );
		$definition = $registry->definition( 'content-update' );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'required', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
		$this->assertSame( 'medium', $definition->risk->value );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $definition->isDestructive );
	}
}
