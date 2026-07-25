<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use RuntimeException;
use SiteHelm\Bootstrap\ModuleLoader;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class ModuleLoaderTest extends TestCase {

	private function makeModule( ModuleId $id, ?callable $onRegister = null, string $health = 'active' ): IntegrationModule {
		return new class( $id, $onRegister, $health ) implements IntegrationModule {
			public function __construct(
				private ModuleId $moduleId,
				private mixed $onRegister,
				private string $healthValue,
			) {
			}
			public function id(): ModuleId {
				return $this->moduleId;
			}
			public function displayName(): string {
				return ucfirst( $this->moduleId->value );
			}
			public function dependency(): array {
				return [ 'name' => 'wordpress', 'versionRange' => '>=6.6' ];
			}
			public function health(): array {
				return [ 'version' => '1.0', 'health' => $this->healthValue ];
			}
			public function cacheCleanup(): array {
				return [];
			}
			public function register( CapabilityRegistry $registry ): void {
				if ( null !== $this->onRegister ) {
					( $this->onRegister )( $registry );
				}
			}
		};
	}

	public function test_loads_healthy_modules_and_reports_health_map(): void {
		$health = ( new ModuleLoader() )->load(
			[ $this->makeModule( ModuleId::Diagnostics ), $this->makeModule( ModuleId::Media, null, 'inactive' ) ],
			new CapabilityRegistry()
		);
		$this->assertSame( 'active', $health['diagnostics']['health'] );
		$this->assertSame( 'inactive', $health['media']['health'] );
	}

	public function test_throwing_module_is_contained_and_marked_inactive(): void {
		$exploding = $this->makeModule(
			ModuleId::Elementor,
			static function (): void {
				throw new RuntimeException( 'plugin exploded' );
			}
		);
		$survivor = $this->makeModule( ModuleId::Diagnostics );

		$health = ( new ModuleLoader() )->load( [ $exploding, $survivor ], new CapabilityRegistry() );

		$this->assertSame( 'inactive', $health['elementor']['health'] );
		$this->assertSame( 'active', $health['diagnostics']['health'] );
	}

	/**
	 * I4: id() was called outside the try, so a module whose id() throws took the
	 * whole boot down instead of being contained.
	 */
	public function test_module_whose_id_throws_is_contained_and_marked_inactive(): void {
		$broken = new class() implements IntegrationModule {
			public function id(): ModuleId {
				throw new RuntimeException( 'id exploded' );
			}
			public function displayName(): string {
				return 'Broken';
			}
			public function dependency(): array {
				return [ 'name' => 'wordpress', 'versionRange' => '>=6.6' ];
			}
			public function health(): array {
				return [ 'version' => null, 'health' => 'active' ];
			}
			public function cacheCleanup(): array {
				return [];
			}
			public function register( CapabilityRegistry $registry ): void {
			}
		};
		$survivor = $this->makeModule( ModuleId::Diagnostics );

		$health = ( new ModuleLoader() )->load( [ $broken, $survivor ], new CapabilityRegistry() );

		// The sibling still loads.
		$this->assertSame( 'active', $health['diagnostics']['health'] );

		// The failure is still recorded, under a stable fallback key.
		$fallback_keys = array_keys( array_diff_key( $health, [ 'diagnostics' => null ] ) );
		$this->assertCount( 1, $fallback_keys );
		$this->assertSame( 'inactive', $health[ $fallback_keys[0] ]['health'] );
		$this->assertNull( $health[ $fallback_keys[0] ]['version'] );
	}

	public function test_inactive_module_still_registers_its_operations(): void {
		$registry   = new CapabilityRegistry();
		$registered = false;
		$inactive   = $this->makeModule(
			ModuleId::Acf,
			static function () use ( &$registered ): void {
				$registered = true;
			},
			'inactive'
		);

		( new ModuleLoader() )->load( [ $inactive ], $registry );

		// Inactive modules still register definitions so catalogs can list them
		// with a blocked reason (contract: blocked operations never disappear).
		$this->assertTrue( $registered );
	}
}
