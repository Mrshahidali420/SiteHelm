<?php
/**
 * Tests for Dispatcher.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * Tests Dispatcher routing and catalog behavior.
 */
final class DispatcherTest extends TestCase {

	/**
	 * The capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * The dispatcher under test.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'user_can' )->justReturn( true );
		$this->registry   = new CapabilityRegistry();
		$this->dispatcher = new Dispatcher(
			$this->registry,
			new CatalogBuilder( $this->registry ),
			new PolicyEngine(),
			new SchemaValidator(),
		);
		$this->registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report environment versions.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'wordpress' => [ 'type' => 'string' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'system-environment',
					'arguments' => [],
				],
			),
			static fn( array $input, OperationContext $context ): array => [ 'wordpress' => '6.8.1' ]
		);
	}

	/**
	 * Constructs a test operation context.
	 *
	 * @param string $diagnostics_health The module health status.
	 *
	 * @return OperationContext The test context.
	 */
	private function makeContext( string $diagnostics_health = 'active' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'diagnostics' => [
					'version' => null,
					'health'  => $diagnostics_health,
				],
				'core'        => [
					'version' => null,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Registers a write operation guarded by the edit_post meta-capability.
	 */
	private function registerMetaCapabilityOperation(): void {
		$this->registry->register(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Update one content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'description' => 'Target identifier.' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			static fn( array $input, OperationContext $context ): array => [ 'id' => 42 ]
		);
	}

	/**
	 * Dispatches content-update with the given raw target id, recording every
	 * user_can call the policy engine makes.
	 *
	 * @param mixed $raw_id The raw target identifier from the request.
	 *
	 * @return array<int, array{string, mixed}> Capability and target per call.
	 */
	private function captureCapabilityChecks( mixed $raw_id ): array {
		$captured = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, ...$extra ) use ( &$captured ): bool {
				$captured[] = [ $capability, $extra[0] ?? null ];
				return true;
			}
		);
		$this->registerMetaCapabilityOperation();

		$this->dispatcher->dispatch(
			'content-write',
			[
				'operation' => 'content-update',
				'arguments' => [ 'id' => $raw_id ],
			],
			$this->makeContext()
		);

		return $captured;
	}

	/**
	 * Test that empty operation returns the catalog.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_call_without_operation_returns_catalog(): void {
		$response = $this->dispatcher->dispatch( 'system-read', [], $this->makeContext() );
		$this->assertSame( 'system-read', $response['dispatcher'] );
		$this->assertCount( 1, $response['operations'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * I1: the catalog lists only operations the caller is permitted to see.
	 * An unauthorized caller must not learn that an operation exists at all.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_catalog_omits_operations_the_caller_may_not_see(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$response = $this->dispatcher->dispatch( 'system-read', [], $this->makeContext() );

		$this->assertSame( [], $response['operations'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that successful operation returns result envelope.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_successful_operation_returns_result_envelope(): void {
		$response = $this->dispatcher->dispatch(
			'system-read',
			[
				'operation' => 'system-environment',
				'arguments' => [],
			],
			$this->makeContext()
		);
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'system-environment', $response['operationId'] );
		$this->assertSame( [ 'wordpress' => '6.8.1' ], $response['data'] );
		$this->assertSame( 'not-applicable', $response['verification'] );
		$this->assertSame( 'corr-1', $response['correlationId'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that unknown operation throws InvalidInput exception.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_unknown_operation_is_invalid_input(): void {
		try {
			$this->dispatcher->dispatch( 'system-read', [ 'operation' => 'system-nuke' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that operation on wrong dispatcher throws InvalidInput exception.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_operation_on_wrong_dispatcher_is_invalid_input(): void {
		$this->expectException( OperationException::class );
		$this->dispatcher->dispatch(
			'content-read',
			[ 'operation' => 'system-environment' ],
			$this->makeContext()
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that inactive module throws IntegrationUnavailable exception.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_inactive_module_returns_integration_unavailable(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[ 'operation' => 'system-environment' ],
				$this->makeContext( 'inactive' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that version-blocked module throws UnsupportedVersion exception.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_version_blocked_module_returns_unsupported_version(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[ 'operation' => 'system-environment' ],
				$this->makeContext( 'version-blocked' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $e->errorCode );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * An integer target id reaches the meta-capability check.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_integer_target_reaches_the_meta_capability_check(): void {
		$this->assertSame( [ [ 'edit_post', 42 ] ], $this->captureCapabilityChecks( 42 ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * An integer-like string target id must not silently degrade the check to a
	 * generic capability test: JSON clients routinely send "42".
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_integer_like_string_target_reaches_the_meta_capability_check(): void {
		$this->assertSame( [ [ 'edit_post', 42 ] ], $this->captureCapabilityChecks( '42' ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * A non-numeric target reference resolves to no target, so the policy engine
	 * falls back to the generic capability check.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_non_numeric_target_falls_back_to_the_generic_check(): void {
		$this->assertSame( [ [ 'edit_post', null ] ], $this->captureCapabilityChecks( 'abc' ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Test that unknown argument properties are rejected.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_unknown_argument_property_is_rejected(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[
					'operation' => 'system-environment',
					'arguments' => [ 'verbose' => true ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Authorization must be decided before module health. Otherwise an
	 * unauthorized caller who guesses an operation name learns the operation
	 * exists and learns its dependency state, where it should learn nothing.
	 */
	public function test_authorization_failure_wins_over_module_health(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->registerMetaCapabilityOperation();

		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => null,
					'health'  => 'inactive',
				],
			],
			requestTime: 1_800_000_000,
		);

		try {
			$this->dispatcher->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
				$context
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}
}
