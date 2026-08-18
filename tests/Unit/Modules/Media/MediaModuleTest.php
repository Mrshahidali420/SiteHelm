<?php
/**
 * Tests for MediaModule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * Tests the media module declaration and its registrations.
 */
final class MediaModuleTest extends TestCase {

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
		( new MediaModule() )->register( $registry );

		return $registry;
	}

	public function test_module_is_active_with_the_wordpress_version_when_storage_is_ready(): void {
		$module = new MediaModule();

		$this->assertSame( ModuleId::Media, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * The catalog, `system-integrations`, and Dispatcher all read this
	 * one value. Reporting active while the change-engine tables are missing
	 * would let the media-write catalog advertise writes every invocation then
	 * refuses — the same three-surface contradiction CoreModule avoids.
	 */
	public function test_module_is_inactive_with_no_version_when_storage_is_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$health = ( new MediaModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * A media write invalidates the post and post-meta caches; nothing in this
	 * module touches terms.
	 */
	public function test_module_declares_the_caches_its_writes_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta' ], ( new MediaModule() )->cacheCleanup() );
	}

	/**
	 * The dispatcher an operation lands on is derived from its domain and mode,
	 * so a wrong domain silently relocates it rather than failing loudly.
	 */
	public function test_module_registers_media_get_on_the_media_read_dispatcher(): void {
		$registry = $this->registry();

		$this->assertTrue( $registry->has( 'media-get' ) );
		$definition = $registry->definition( 'media-get' );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $registry->hasWriteOperation( 'media-get' ) );
	}

	/**
	 * The module must add nothing to any dispatcher other than the two media
	 * ones. A Domain typo would otherwise plant a media operation on the
	 * content or system catalog, where a client browsing content would find it.
	 */
	public function test_module_registers_nothing_outside_the_two_media_dispatchers(): void {
		$registry = $this->registry();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, [ 'media-read', 'media-write' ], true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The media module must register nothing on '{$dispatcher}'."
			);
		}
	}
}
