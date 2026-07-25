<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use InvalidArgumentException;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * @package SiteHelm
 */
final class CapabilityRegistryTest extends TestCase {

	private CapabilityRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new CapabilityRegistry();
	}

	private function makeReadDefinition( string $id = 'system-environment' ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
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
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	public function test_dispatcher_list_is_exactly_the_contract_eleven(): void {
		$this->assertSame(
			[
				'content-read',
				'content-write',
				'media-read',
				'media-write',
				'menu-read',
				'menu-write',
				'elementor-read',
				'elementor-write',
				'fields-read',
				'fields-write',
				'system-read',
			],
			CapabilityRegistry::DISPATCHERS
		);
	}

	public function test_register_and_lookup(): void {
		$definition = $this->makeReadDefinition();
		$handler    = static fn( array $input, $context ): array => [ 'ok' => true ];

		$this->registry->register( $definition, $handler );

		$this->assertTrue( $this->registry->has( 'system-environment' ) );
		$this->assertSame( $definition, $this->registry->definition( 'system-environment' ) );
		$this->assertSame( [ $definition ], $this->registry->forDispatcher( 'system-read' ) );
	}

	public function test_duplicate_id_is_rejected(): void {
		$this->registry->register( $this->makeReadDefinition(), static fn(): array => [] );
		$this->expectException( InvalidArgumentException::class );
		$this->registry->register( $this->makeReadDefinition(), static fn(): array => [] );
	}

	public function test_unknown_dispatcher_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry->forDispatcher( 'plugins-write' );
	}

	public function test_unknown_operation_lookup_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry->definition( 'does-not-exist' );
	}
}
