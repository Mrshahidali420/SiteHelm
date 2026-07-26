<?php
/**
 * Tests for environment discovery.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Diagnostics\EnvironmentDiscovery;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Tests for EnvironmentDiscovery and DiagnosticsModule.
 */
final class EnvironmentDiscoveryTest extends TestCase {

	/**
	 * Creates a test operation context.
	 *
	 * @return OperationContext The test context.
	 */
	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Tests that the environment report contains no paths or credentials.
	 */
	public function test_reports_environment_without_paths_or_credentials(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				/**
				 * Gets a theme property.
				 *
				 * @param string $header The property name.
				 * @return string The property value.
				 */
				public function get( string $header ): string {
					return 'Name' === $header ? 'Twenty Twenty-Five' : '1.2';
				}
			}
		);

		$data = ( new EnvironmentDiscovery() )->handle( [], $this->makeContext() );

		$this->assertSame( '6.8.1', $data['wordpress'] );
		$this->assertSame( PHP_VERSION, $data['php'] );
		$this->assertSame( SITEHELM_VERSION, $data['sitehelm'] );
		$this->assertSame(
			[
				'name'    => 'Twenty Twenty-Five',
				'version' => '1.2',
			],
			$data['theme']
		);
		$this->assertSame( 'safe-write', $data['permissionMode'] );
		$this->assertArrayHasKey( 'diagnostics', $data['modules'] );

		// REQ-0001 evidence: no filesystem paths or credentials in the payload.
		$serialized = (string) wp_json_encode( $data );
		$this->assertDoesNotMatchRegularExpression( '/\/var\/|\/home\/|wp-content|[A-Z]:\\\\/', $serialized );
		$this->assertDoesNotMatchRegularExpression( '/password|secret|authorization/i', $serialized );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Tests that DiagnosticsModule registers the system-environment operation.
	 */
	public function test_module_registers_system_environment_operation(): void {
		$registry = new CapabilityRegistry();
		$module   = new DiagnosticsModule();

		$this->assertSame( 'diagnostics', $module->id()->value );
		$this->assertSame( 'active', $module->health()['health'] );

		$module->register( $registry );

		$this->assertTrue( $registry->has( 'system-environment' ) );
		$definition = $registry->definition( 'system-environment' );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
