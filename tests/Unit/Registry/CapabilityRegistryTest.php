<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use InvalidArgumentException;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\StubWriteOperation;
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

	/**
	 * Builds a write definition with the given policies.
	 *
	 * @param string        $id             The operation identifier.
	 * @param Mode          $mode           Read or write.
	 * @param PreviewPolicy $preview_policy The preview policy.
	 */
	private function makeWriteDefinition(
		string $id,
		Mode $mode = Mode::Write,
		PreviewPolicy $preview_policy = PreviewPolicy::Required
	): OperationDefinition {
		$read_shape = Mode::Read === $mode;

		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
			mode: $mode,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
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
			isReadOnly: $read_shape,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: $read_shape ? PreviewPolicy::NotApplicable : $preview_policy,
			snapshotPolicy: $read_shape ? SnapshotPolicy::NotApplicable : SnapshotPolicy::Required,
			rollbackPolicy: $read_shape ? RollbackPolicy::NotApplicable : RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	public function test_register_write_exposes_the_definition_and_the_write_operation(): void {
		$registry  = new CapabilityRegistry();
		$operation = new StubWriteOperation();

		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), $operation );

		$this->assertTrue( $registry->has( 'content-update' ) );
		$this->assertTrue( $registry->hasWriteOperation( 'content-update' ) );
		$this->assertSame( $operation, $registry->writeOperation( 'content-update' ) );
		$this->assertSame(
			[ 'content-update' ],
			array_map(
				static fn( OperationDefinition $d ): string => $d->id,
				$registry->forDispatcher( 'content-write' )
			)
		);
	}

	public function test_a_read_registration_is_not_a_write_operation(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->makeWriteDefinition( 'content-get', Mode::Read ), static fn(): array => [] );

		$this->assertTrue( $registry->has( 'content-get' ) );
		$this->assertFalse( $registry->hasWriteOperation( 'content-get' ) );
	}

	/**
	 * The read path must refuse a write-mode definition rather than silently
	 * accepting a bare callable for it: doing so would let the dispatcher call
	 * that callable directly and skip preview, plan token, snapshot,
	 * verification, and audit for an operation whose own definition advertises
	 * previewPolicy required.
	 */
	public function test_register_refuses_a_write_mode_definition(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->register( $this->makeWriteDefinition( 'content-update' ), static fn(): array => [] );
	}

	/**
	 * The guard must run before either internal map is written. A refusal that
	 * landed after the definition was stored would leave the operation `has()`
	 * true with no handler and no write operation behind it — a half-populated
	 * entry that is exactly the bypass this guard exists to close.
	 */
	public function test_a_refused_write_registration_leaves_the_registry_untouched(): void {
		$registry = new CapabilityRegistry();

		try {
			$registry->register( $this->makeWriteDefinition( 'content-update' ), static fn(): array => [] );
			$this->fail( 'Expected InvalidArgumentException' );
		} catch ( InvalidArgumentException ) {
			// Expected; the registry state is asserted below.
		}

		$this->assertFalse( $registry->has( 'content-update' ) );
		$this->assertFalse( $registry->hasWriteOperation( 'content-update' ) );
		$this->assertSame( [], $registry->forDispatcher( 'content-write' ) );
	}

	public function test_register_write_rejects_a_duplicate_identifier(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), new StubWriteOperation() );

		$this->expectException( InvalidArgumentException::class );
		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), new StubWriteOperation() );
	}

	/**
	 * ASSERTED ON ITS MESSAGE, NOT MERELY ON THE EXCEPTION TYPE. A deletion
	 * sweep showed why: `OperationDefinition` forces a read to carry
	 * `PreviewPolicy::NotApplicable`, so with the mode refusal deleted the
	 * preview refusal two lines below throws the same `InvalidArgumentException`
	 * for a different reason and the test passes anyway. Every refusal in this
	 * class raises one exception class, which makes the class alone worthless as
	 * an identification.
	 */
	public function test_register_write_rejects_a_read_definition(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'is not a write and cannot register a write operation' );
		$registry->registerWrite( $this->makeWriteDefinition( 'content-get', Mode::Read ), new StubWriteOperation() );
	}

	public function test_register_write_rejects_a_definition_without_required_preview(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->registerWrite(
			$this->makeWriteDefinition( 'content-update', Mode::Write, PreviewPolicy::NotApplicable ),
			new StubWriteOperation()
		);
	}

	public function test_write_operation_lookup_rejects_an_unknown_identifier(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->writeOperation( 'content-nuke' );
	}

	/**
	 * An unknown identifier is refused even when other writes are registered.
	 *
	 * A review found the test above passes against a lookup that returns some
	 * arbitrary registered operation, because on an empty registry that
	 * fallback trips PHP's return-type check rather than any assertion. With a
	 * populated registry the wrong operation would be returned happily — and
	 * the dispatcher would drive a caller's request through an implementation
	 * they did not name.
	 */
	public function test_write_operation_lookup_rejects_an_unknown_identifier_with_others_registered(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), new StubWriteOperation() );

		$this->expectException( InvalidArgumentException::class );
		$registry->writeOperation( 'content-nuke' );
	}

	/**
	 * The eleven dispatcher names are frozen, spelled out here on purpose.
	 *
	 * A CLIENT FETCHES THE TOOL LIST ONCE, AT CONNECT, AND KEEPS IT FOR THE
	 * WHOLE SESSION. Catalogs are read live on every call, so an operation
	 * added by a plugin update — or by activating Pro mid-session — is
	 * reachable immediately, with no reconnect. That property holds only while
	 * this list never grows: a twelfth dispatcher would be invisible to every
	 * session already running and to every client that cached the list, and the
	 * server truthfully advertises `listChanged: false`, so nothing would tell
	 * them to look again.
	 *
	 * The names are written out rather than counted because a count is bumped
	 * without thinking. New operations belong in one of these eleven. Adding a
	 * twelfth is a change to the tool surface itself and has to be decided as
	 * one, not arrived at.
	 */
	public function test_the_dispatcher_list_is_frozen(): void {
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
}
