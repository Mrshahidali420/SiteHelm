<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Tests\TestCase;

/**
 * @package SiteHelm
 */
final class CatalogBuilderTest extends TestCase {

	private CapabilityRegistry $registry;
	private CatalogBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new CapabilityRegistry();
		$this->builder  = new CatalogBuilder( $this->registry );
		$this->allowCapabilities( [ 'manage_options' ] );
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
					'properties'           => [],
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
			static fn(): array => []
		);
	}

	/**
	 * Stubs user_can so only the listed capabilities are held.
	 *
	 * @param string[] $held Capabilities the resolved user holds.
	 */
	private function allowCapabilities( array $held ): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => in_array( $capability, $held, true )
		);
	}

	/**
	 * Builds a context whose diagnostics module carries the given health.
	 *
	 * @param string $diagnostics_health Health status for the diagnostics module.
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
			],
			requestTime: 1_800_000_000,
		);
	}

	public function test_catalog_lists_active_operation_as_available(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );

		$this->assertSame( 'system-read', $catalog['dispatcher'] );
		$this->assertCount( 1, $catalog['operations'] );
		$entry = $catalog['operations'][0];
		$this->assertSame( 'system-environment', $entry['operation'] );
		$this->assertTrue( $entry['available'] );
		$this->assertNull( $entry['blockedReason'] );
		$this->assertSame( 1, $entry['schemaVersion'] );
		$this->assertSame( [ 'manage_options' ], $entry['requiredCapabilities'] );
		$this->assertSame( 'low', $entry['risk'] );
		$this->assertNotEmpty( $entry['example'] );
	}

	public function test_inactive_module_operation_stays_listed_with_reason(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext( 'inactive' ) );
		$entry   = $catalog['operations'][0];
		$this->assertFalse( $entry['available'] );
		$this->assertSame( 'integration_unavailable', $entry['blockedReason'] );
	}

	public function test_version_blocked_module_reports_unsupported_version(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext( 'version-blocked' ) );
		$this->assertSame( 'unsupported_version', $catalog['operations'][0]['blockedReason'] );
	}

	public function test_empty_dispatcher_returns_empty_catalog_not_error(): void {
		$catalog = $this->builder->build( 'elementor-write', $this->makeContext() );
		$this->assertSame( 'elementor-write', $catalog['dispatcher'] );
		$this->assertSame( [], $catalog['operations'] );
	}

	/**
	 * I1: the contract says the catalog lists every operation the caller is
	 * permitted to SEE. A user holding nothing sees nothing.
	 */
	public function test_catalog_omits_operations_whose_capabilities_are_not_held(): void {
		$this->allowCapabilities( [] );

		$catalog = $this->builder->build( 'system-read', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
	}

	/**
	 * I1: permission-hidden is not the same as dependency-blocked. A permitted
	 * user still sees a blocked operation together with its blockedReason.
	 */
	public function test_permitted_user_still_sees_blocked_operations_with_reason(): void {
		$this->allowCapabilities( [ 'manage_options' ] );

		$inactive = $this->builder->build( 'system-read', $this->makeContext( 'inactive' ) );
		$blocked  = $this->builder->build( 'system-read', $this->makeContext( 'version-blocked' ) );

		$this->assertCount( 1, $inactive['operations'] );
		$this->assertSame( 'integration_unavailable', $inactive['operations'][0]['blockedReason'] );
		$this->assertCount( 1, $blocked['operations'] );
		$this->assertSame( 'unsupported_version', $blocked['operations'][0]['blockedReason'] );
	}

	/**
	 * I1: an operation is hidden unless EVERY required capability is held.
	 */
	public function test_partial_capability_hold_still_hides_the_operation(): void {
		$this->registry->register(
			$this->makeMultiCapabilityDefinition(),
			static fn(): array => []
		);
		$this->allowCapabilities( [ 'edit_posts' ] );

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
	}

	/**
	 * I3: empty JSON Schema object members must serialize as {}, not [].
	 */
	public function test_empty_schema_objects_serialize_as_json_objects(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		$json    = (string) json_encode( $catalog['operations'][0] );

		$this->assertStringContainsString( '"properties":{}', $json );
		$this->assertStringContainsString( '"arguments":{}', $json );
		$this->assertStringNotContainsString( '"properties":[]', $json );
		$this->assertStringNotContainsString( '"arguments":[]', $json );
	}

	/**
	 * I3: list-valued schema members such as required stay JSON arrays.
	 */
	public function test_required_list_still_serializes_as_a_json_array(): void {
		$this->registry->register(
			$this->makeMultiCapabilityDefinition(),
			static fn(): array => []
		);
		$this->allowCapabilities( [ 'edit_posts', 'publish_posts' ] );

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );
		$json    = (string) json_encode( $catalog['operations'][0] );

		$this->assertStringContainsString( '"required":["id"]', $json );
		$this->assertStringContainsString( '"properties":{"id":', $json );
	}

	/**
	 * A write definition requiring two capabilities and a non-empty required list.
	 */
	private function makeMultiCapabilityDefinition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Update one content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts', 'publish_posts' ],
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
		);
	}
}
