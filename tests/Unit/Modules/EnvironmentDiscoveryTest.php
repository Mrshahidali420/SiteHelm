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
use SiteHelm\Contracts\OperationDefinition;
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Both Diagnostics operations reach the system-read catalog, and nothing
	 * else does.
	 *
	 * The catalog is asserted as a whole list rather than by two `has()` calls,
	 * because a registered operation that never reaches a dispatcher catalog is
	 * an operation no client can see: `has()` would be satisfied by a Domain
	 * typo that parked `system-integrations` on the content catalog. Asserting
	 * the identifiers in registration order also fails when a third operation
	 * arrives unannounced, which is what makes this a net rather than a pair of
	 * existence checks.
	 */
	public function test_module_registers_both_system_reads_and_nothing_else(): void {
		$registry = new CapabilityRegistry();
		( new DiagnosticsModule() )->register( $registry );

		$this->assertSame(
			[ 'system-environment', 'system-integrations' ],
			array_map(
				static fn( OperationDefinition $d ): string => $d->id,
				$registry->forDispatcher( 'system-read' )
			)
		);

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( 'system-read' === $dispatcher ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The Diagnostics module must register nothing on '{$dispatcher}'."
			);
		}

		$integrations = $registry->definition( 'system-integrations' );

		$this->assertSame( 'system-read', $integrations->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $integrations->requiredCapabilities );
		$this->assertSame( 'low', $integrations->risk->value );
		$this->assertTrue( $integrations->isReadOnly );
		$this->assertFalse( $registry->hasWriteOperation( 'system-integrations' ) );
		$this->assertSame( false, $integrations->inputSchema['additionalProperties'] ?? null );
		$this->assertSame( [ 'wordpress' => '>=' . SITEHELM_MIN_WP ], $integrations->supportedVersions );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
