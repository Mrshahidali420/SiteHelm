<?php
/**
 * Extension points: additive, validated, contained.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use RuntimeException;
use SiteHelm\Bootstrap\Extensions;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\IntegrationDirectory;
use SiteHelm\Tests\TestCase;

final class ExtensionsTest extends TestCase {

	/** @var string[] */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->logged = [];
		Functions\when( 'error_log' )->alias(
			function ( string $message ): bool {
				$this->logged[] = $message;
				return true;
			}
		);
	}

	public function test_without_an_add_on_the_built_in_table_is_returned_unchanged(): void {
		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Extensions::module_classes() );
	}

	public function test_a_valid_module_class_from_the_filter_is_appended_once(): void {
		Filters\expectApplied( Extensions::FILTER_MODULES )->once()->with( [] )->andReturn( [ FakeAddOnModule::class, FakeAddOnModule::class ] );

		$classes = Extensions::module_classes();

		$this->assertSame( array_merge( IntegrationDirectory::MODULE_CLASSES, [ FakeAddOnModule::class ] ), $classes );
		$this->assertSame( [], $this->logged );
	}

	public function test_the_filter_cannot_remove_or_reorder_a_built_in_module(): void {
		Filters\expectApplied( Extensions::FILTER_MODULES )->once()->andReturn( [ DiagnosticsModule::class ] );

		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Extensions::module_classes() );
	}

	/**
	 * @dataProvider rejectedEntries
	 */
	public function test_an_entry_that_is_not_a_module_class_is_dropped_and_logged( mixed $entry ): void {
		Filters\expectApplied( Extensions::FILTER_MODULES )->once()->andReturn( [ $entry ] );

		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Extensions::module_classes() );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( Extensions::FILTER_MODULES, $this->logged[0] );
	}

	/** @return array<string, array{mixed}> */
	public static function rejectedEntries(): array {
		return [
			'not a string'        => [ 42 ],
			'unknown class'       => [ 'SiteHelm\\Nowhere\\Missing' ],
			'not a module'        => [ \stdClass::class ],
			'the interface name'  => [ \SiteHelm\Contracts\IntegrationModule::class ],
		];
	}

	public function test_a_filter_that_returns_a_non_array_is_ignored(): void {
		Filters\expectApplied( Extensions::FILTER_MODULES )->once()->andReturn( 'nope' );

		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Extensions::module_classes() );
	}

	public function test_register_operations_hands_the_live_registry_to_the_action(): void {
		$registry = new CapabilityRegistry();
		$received = null;
		Actions\expectDone( Extensions::ACTION_REGISTER_OPERATIONS )->once()->whenHappen(
			static function ( $arg ) use ( &$received ): void {
				$received = $arg;
			}
		);

		Extensions::register_operations( $registry );

		$this->assertSame( $registry, $received );
	}

	public function test_a_throwing_register_handler_is_logged_and_does_not_escape(): void {
		Actions\expectDone( Extensions::ACTION_REGISTER_OPERATIONS )->once()->whenHappen(
			static function (): void {
				throw new RuntimeException( 'add-on broke' );
			}
		);

		Extensions::register_operations( new CapabilityRegistry() );

		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'add-on broke', $this->logged[0] );
		$this->assertStringContainsString( Extensions::ACTION_REGISTER_OPERATIONS, $this->logged[0] );
	}

	public function test_status_sections_fires_its_action_and_contains_a_throwing_handler(): void {
		Actions\expectDone( Extensions::ACTION_STATUS_SECTIONS )->once()->whenHappen(
			static function (): void {
				throw new RuntimeException( 'section broke' );
			}
		);

		Extensions::status_sections();

		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( Extensions::ACTION_STATUS_SECTIONS, $this->logged[0] );
	}

	public function test_the_plugin_boot_loads_the_add_on_module_and_fires_the_register_action(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Filters\expectApplied( Extensions::FILTER_MODULES )->once()->andReturn( [ FakeAddOnModule::class ] );
		Actions\expectDone( Extensions::ACTION_REGISTER_OPERATIONS )->once();
		FakeAddOnModule::$registered = 0;

		\SiteHelm\Bootstrap\Plugin::instance()->register();

		$this->assertSame( 1, FakeAddOnModule::$registered );
	}
}

/**
 * A module an add-on might contribute; counts how often the boot registered it.
 */
final class FakeAddOnModule implements \SiteHelm\Contracts\IntegrationModule {

	public static int $registered = 0;

	public function id(): \SiteHelm\Contracts\ModuleId {
		return \SiteHelm\Contracts\ModuleId::Diagnostics;
	}

	public function displayName(): string {
		return 'Fake add-on';
	}

	public function dependency(): array {
		return [];
	}

	public function health(): array {
		return [
			'version' => null,
			'health'  => \SiteHelm\Contracts\ModuleHealth::Active->value,
		];
	}

	public function cacheCleanup(): array {
		return [];
	}

	public function register( CapabilityRegistry $registry ): void {
		unset( $registry );
		++self::$registered;
	}
}
