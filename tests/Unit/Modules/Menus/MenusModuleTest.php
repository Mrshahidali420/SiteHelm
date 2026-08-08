<?php
/**
 * Tests for MenusModule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * Tests the menus module declaration and its registrations.
 */
final class MenusModuleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];
		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
	}

	private function registry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new MenusModule() )->register( $registry );

		return $registry;
	}

	public function test_module_is_active_with_the_wordpress_version_when_storage_is_ready(): void {
		$module = new MenusModule();

		$this->assertSame( ModuleId::Menus, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * The catalog, system-read integration health, and Dispatcher all read this
	 * one value. Reporting active while the change-engine tables are missing
	 * would let the menu-write catalog advertise writes every invocation then
	 * refuses — the same three-surface contradiction CoreModule avoids.
	 */
	public function test_module_is_inactive_with_no_version_when_storage_is_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$health = ( new MenusModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * Terms are declared where MediaModule omits them, because a menu IS a
	 * `nav_menu` term and an item's membership of one is a term relationship. A
	 * reorder or a location change that left the term cache warm would leave the
	 * site rendering the previous menu.
	 */
	public function test_module_declares_the_caches_its_writes_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta', 'terms' ], ( new MenusModule() )->cacheCleanup() );
	}

	/**
	 * The dispatcher an operation lands on is derived from its domain and mode,
	 * so a wrong domain silently relocates it rather than failing loudly.
	 */
	public function test_module_registers_menu_list_on_the_menu_read_dispatcher(): void {
		$registry = $this->registry();

		$this->assertTrue( $registry->has( 'menu-list' ) );
		$definition = $registry->definition( 'menu-list' );
		$this->assertSame( 'menu-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Menus, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $registry->hasWriteOperation( 'menu-list' ) );
	}

	/**
	 * The module must add nothing to any dispatcher other than the two menu
	 * ones. A Domain typo would otherwise plant a menu operation on the content
	 * or system catalog, where a client browsing content would find it.
	 */
	public function test_module_registers_nothing_outside_the_two_menu_dispatchers(): void {
		$registry = $this->registry();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, [ 'menu-read', 'menu-write' ], true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The menus module must register nothing on '{$dispatcher}'."
			);
		}
	}
}
