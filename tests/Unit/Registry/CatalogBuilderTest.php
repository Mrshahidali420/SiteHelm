<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
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

	public function test_catalog_lists_active_operation_as_available(): void {
		$catalog = $this->builder->build(
			'system-read',
			[
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			]
		);

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
		$catalog = $this->builder->build(
			'system-read',
			[
				'diagnostics' => [
					'version' => null,
					'health'  => 'inactive',
				],
			]
		);
		$entry   = $catalog['operations'][0];
		$this->assertFalse( $entry['available'] );
		$this->assertSame( 'integration_unavailable', $entry['blockedReason'] );
	}

	public function test_version_blocked_module_reports_unsupported_version(): void {
		$catalog = $this->builder->build(
			'system-read',
			[
				'diagnostics' => [
					'version' => '0.9',
					'health'  => 'version-blocked',
				],
			]
		);
		$this->assertSame( 'unsupported_version', $catalog['operations'][0]['blockedReason'] );
	}

	public function test_empty_dispatcher_returns_empty_catalog_not_error(): void {
		$catalog = $this->builder->build( 'elementor-write', [] );
		$this->assertSame( 'elementor-write', $catalog['dispatcher'] );
		$this->assertSame( [], $catalog['operations'] );
	}
}
