<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use Brain\Monkey\Functions;
use RuntimeException;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\IntegrationDirectory;
use SiteHelm\Tests\TestCase;

/**
 * The directory owns the plugin's boot table so that a module can read what
 * the plugin boots without depending on the bootstrap layer.
 *
 * @package SiteHelm
 */
final class IntegrationDirectoryTest extends TestCase {

	/**
	 * There is exactly one boot table, and `Plugin::MODULE_CLASSES` names it.
	 *
	 * While the constant is an alias this comparison cannot fail — the language
	 * copies the value at compile time. It is kept because the failure it guards
	 * against is not a wrong value but a second definition: the cheapest way to
	 * "fix" a future merge conflict here is to paste the seven class names back
	 * into `Plugin`, and the moment anyone does, the two lists can drift and
	 * this test starts doing real work. The catalog-wide invariant tests read
	 * the plugin's name for the table, so a drifted copy would silently exempt
	 * whichever module only the directory boots.
	 */
	public function test_the_directory_and_the_plugin_boot_the_same_table(): void {
		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Plugin::MODULE_CLASSES );
	}

	/**
	 * Every class in the table constructs with no arguments and is a module.
	 * A constructor that grew a required parameter would throw inside the
	 * isolation boundary and the module would simply vanish — silently, with
	 * the rest of the plugin still healthy.
	 */
	public function test_every_listed_class_constructs_into_an_integration_module(): void {
		$modules = ( new IntegrationDirectory() )->modules();

		$this->assertCount( count( IntegrationDirectory::MODULE_CLASSES ), $modules );
		foreach ( $modules as $module ) {
			$this->assertInstanceOf( IntegrationModule::class, $module );
		}
	}

	/**
	 * A module whose constructor throws is logged and skipped; the directory
	 * still answers with every other module. This is the isolation boundary
	 * that used to live in Plugin::constructModules().
	 *
	 * The log is asserted alongside the survivor because "skipped" and
	 * "silently swallowed" are the same observable outcome without it, and the
	 * whole point of catching here is that an operator can still find out why a
	 * module went missing.
	 */
	public function test_a_throwing_constructor_is_skipped_rather_than_fatal(): void {
		$logged = [];
		Functions\when( 'error_log' )->alias(
			static function ( string $message ) use ( &$logged ): bool {
				$logged[] = $message;

				return true;
			}
		);

		$modules = ( new IntegrationDirectory( [ ThrowingFakeModule::class, DiagnosticsModule::class ] ) )->modules();

		$this->assertCount( 1, $modules );
		$this->assertSame( ModuleId::Diagnostics, $modules[0]->id() );
		$this->assertCount( 1, $logged );
		$this->assertStringContainsString( ThrowingFakeModule::FAILURE, $logged[0] );
	}

	/**
	 * The descriptor carries what the health map cannot: the name an operator
	 * reads and the dependency they must install. Keyed by module id, in boot
	 * order, so a report built from it lists modules the way the plugin does.
	 */
	public function test_the_descriptor_carries_display_name_and_dependency_per_module(): void {
		$described = ( new IntegrationDirectory() )->describe();

		$this->assertSame( array_keys( $described )[0], ModuleId::Diagnostics->value );
		$this->assertSame( 'Meta Box', $described[ ModuleId::Metabox->value ]['displayName'] );
		$this->assertSame( 'meta-box', $described[ ModuleId::Metabox->value ]['dependency']['name'] );
		$this->assertSame(
			'>=' . MetaboxPresence::MIN_VERSION,
			$described[ ModuleId::Metabox->value ]['dependency']['versionRange']
		);
	}
}

/**
 * A module that is well-formed in every respect except that constructing it
 * throws.
 *
 * The directory catches around `new $module_class()` and nothing else, so a
 * fake that failed in `id()` or `register()` would exercise a different
 * boundary — ModuleLoader's — and pass whether or not the directory's own
 * try/catch exists.
 *
 * @package SiteHelm
 */
final class ThrowingFakeModule implements IntegrationModule {

	/**
	 * The message the constructor throws, asserted on in the log.
	 *
	 * @var string
	 */
	public const FAILURE = 'this module cannot be constructed';

	/**
	 * Fails the way the isolation boundary exists to contain.
	 */
	public function __construct() {
		throw new RuntimeException( self::FAILURE );
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 */
	public function id(): ModuleId {
		return ModuleId::Diagnostics;
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 */
	public function displayName(): string {
		return 'Throwing Fake';
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 *
	 * @return array<string, string> Dependency info.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=6.6',
		];
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 *
	 * @return array<string, mixed> Health info.
	 */
	public function health(): array {
		return [
			'version' => null,
			'health'  => 'inactive',
		];
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 *
	 * @return array<int, string> Cache cleanup operations.
	 */
	public function cacheCleanup(): array {
		return [];
	}

	/**
	 * Never reached; declared so the fake is a real module.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
	}
}
