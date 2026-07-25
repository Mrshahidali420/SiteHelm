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

	private function makeReadDefinition(
		string $id = 'system-environment',
		Domain $domain = Domain::System
	): OperationDefinition {
		$module       = Domain::System === $domain ? ModuleId::Diagnostics : ModuleId::Core;
		$capabilities = Domain::System === $domain ? [ 'manage_options' ] : [ 'edit_posts' ];
		$description  = Domain::System === $domain ? 'Report environment versions.' : "Perform $id operation.";

		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: Mode::Read,
			description: $description,
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
			requiredCapabilities: $capabilities,
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: $module,
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

	/**
	 * T6: the handler() unknown-operation throw path had no test at all. Nothing
	 * routes around the registry, so this is the last guard before a call would
	 * reach a non-existent handler.
	 */
	public function test_unknown_operation_handler_lookup_throws(): void {
		$this->registry->register( $this->makeReadDefinition(), static fn(): array => [] );

		$this->expectException( InvalidArgumentException::class );
		$this->registry->handler( 'does-not-exist' );
	}

	public function test_handler_lookup_returns_the_registered_handler(): void {
		$handler = static fn( array $input, $context ): array => [ 'ok' => true ];
		$this->registry->register( $this->makeReadDefinition(), $handler );

		$this->assertSame( $handler, $this->registry->handler( 'system-environment' ) );
	}

	public function test_for_dispatcher_filters_and_preserves_registration_order(): void {
		// Register three operations: two Content domain, one System domain.
		$content_list       = $this->makeReadDefinition( 'content-list', Domain::Content );
		$system_environment = $this->makeReadDefinition( 'system-environment', Domain::System );
		$content_get        = $this->makeReadDefinition( 'content-get', Domain::Content );

		$this->registry->register( $content_list, static fn(): array => [] );
		$this->registry->register( $system_environment, static fn(): array => [] );
		$this->registry->register( $content_get, static fn(): array => [] );

		// Assert content-read returns both Content operations in registration order.
		$content_read = $this->registry->forDispatcher( 'content-read' );
		$this->assertSame(
			[ 'content-list', 'content-get' ],
			array_map( static fn( OperationDefinition $d ): string => $d->id, $content_read )
		);

		// Assert the returned array is reindexed as a list.
		$this->assertSame( [ 0, 1 ], array_keys( $content_read ) );

		// Assert system-read returns only the System operation.
		$system_read = $this->registry->forDispatcher( 'system-read' );
		$this->assertSame( [ 'system-environment' ], array_map( static fn( OperationDefinition $d ): string => $d->id, $system_read ) );
		$this->assertSame( [ 0 ], array_keys( $system_read ) );
	}
}
