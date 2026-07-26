<?php
/**
 * Tests for Dispatcher.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Change\ChangeEngine;
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
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
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
		$this->dispatcher = $this->buildDispatcher();
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
	 * Removes the wpdb global here rather than at the end of the one test body
	 * that installs it. Unsetting it inline leaked the double into every later
	 * test as soon as an earlier assertion failed, which is exactly when a clean
	 * teardown matters most. Eleven of the thirteen peer test classes already do
	 * it this way.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Replaces the Dispatcher construction in setUp(). The change engine is a
	 * real one over the FakeWpdb double, so write routing is exercised end to
	 * end without a database.
	 */
	private function buildDispatcher(): Dispatcher {
		return new Dispatcher(
			$this->registry,
			new CatalogBuilder( $this->registry ),
			new PolicyEngine(),
			new SchemaValidator(),
			ChangeEngine::create()
		);
	}

	/**
	 * Registers content-update as a real write operation backed by the stub.
	 */
	private function registerStubWrite(): StubWriteOperation {
		$operation = new StubWriteOperation();
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Revise the title, body, or excerpt of one existing content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'    => [ 'type' => 'integer' ],
						'title' => [ 'type' => 'string' ],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'plan' => [ 'type' => 'object' ] ],
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
			$operation
		);

		return $operation;
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
	 *
	 * Registered through registerWrite() rather than the bare read path: since
	 * CapabilityRegistry::register() now refuses a Mode::Write definition, this
	 * is the only route left to exercise the capability check and health checks
	 * with a write-mode operation.
	 */
	private function registerMetaCapabilityOperation(): void {
		$this->registry->registerWrite(
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
			new StubWriteOperation()
		);
	}

	/**
	 * Dispatches content-update with the given raw target id, recording every
	 * user_can call the policy engine makes.
	 *
	 * Authorization runs before the change engine is ever reached, so what
	 * happens afterward is irrelevant to what this helper verifies. The engine's
	 * local storage is deliberately left unavailable (get_option is stubbed to
	 * report it so) and the resulting refusal is swallowed, rather than standing
	 * up a full FakeWpdb fixture this helper's callers do not need.
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
		Functions\when( 'get_option' )->justReturn( false );
		$this->registerMetaCapabilityOperation();

		try {
			$this->dispatcher->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'arguments' => [ 'id' => $raw_id ],
				],
				$this->makeContext()
			);
		} catch ( OperationException $e ) {
			// The change engine refuses for lack of storage; only the
			// authorization step above is under test here.
		}

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
	 * The client's raw operation string is never echoed back.
	 *
	 * It is untrusted text that would otherwise flow into an outbound envelope
	 * message. A reviewer mutated the guard to interpolate the operation id into
	 * the refusal and the full suite still passed, so nothing pinned it.
	 *
	 * Both refusal sites are covered: an operation that exists nowhere, and one
	 * that exists on another dispatcher. Message and remediation are both checked,
	 * because remediation is surfaced in the same envelope.
	 *
	 * @dataProvider unechoableOperationCalls
	 *
	 * @param string $dispatcher   The dispatcher receiving the call.
	 * @param string $operation_id The raw operation string the client sent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_the_raw_operation_string_is_never_echoed_back( string $dispatcher, string $operation_id ): void {
		try {
			$this->dispatcher->dispatch( $dispatcher, [ 'operation' => $operation_id ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( $operation_id, $e->getMessage() );
			$this->assertStringNotContainsString( $operation_id, (string) $e->remediation );
			$this->assertStringNotContainsString( $operation_id, implode( ' ', $e->completedSteps ) );
			$this->assertStringNotContainsString( $operation_id, (string) $e->compensation );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * @return array<string, array{string, string}> Dispatcher and raw operation string.
	 */
	public static function unechoableOperationCalls(): array {
		return [
			'unregistered operation'          => [ 'system-read', '<script>alert(1)</script>' ],
			'operation on another dispatcher' => [ 'content-read', 'system-environment' ],
		];
	}

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
	 * falls back to the primitive that governs the meta-capability.
	 *
	 * It must NOT ask WordPress for a target-less `edit_post`. WordPress resolves
	 * a meta-capability with no object to `do_not_allow`, so that check refuses
	 * every user including administrators. The live demonstration found this the
	 * hard way: `content-rollback-apply` identifies its target by a rollback
	 * reference rather than a post id, so the dispatcher had no id to pass and
	 * the operation was unusable by anyone, while the catalog — which already
	 * mapped to the primitive — still advertised it as available.
	 *
	 * Falling back to `edit_posts` is deliberately coarse. It is safe because
	 * there is no target for anyone to check against at this point, and the
	 * operations that do resolve one re-check it precisely: rollback calls
	 * authorize() again from inside itself with the concrete target id taken
	 * from the snapshot.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function test_non_numeric_target_falls_back_to_the_governing_primitive(): void {
		$this->assertSame( [ [ 'edit_posts', null ] ], $this->captureCapabilityChecks( 'abc' ) );
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

	public function test_a_write_without_a_plan_token_returns_a_plan_and_mutates_nothing(): void {
		$wpdb                  = new FakeWpdb();
		$GLOBALS['wpdb']       = $wpdb;
		$wpdb->queryRowsQueue  = [ 0 ];
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed => Installer::STATUS_OPTION === $key
				? Installer::STATUS_READY
				: $fallback
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$operation                 = $this->registerStubWrite();
		$operation->snapshot       = [ 'post_title' => 'Original title' ];
		$response                  = $this->buildDispatcher()->dispatch(
			'content-write',
			[
				'operation' => 'content-update',
				'arguments' => [
					'id'    => 42,
					'title' => 'Edited title',
				],
			],
			$this->makeContext()
		);

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'plan', $response['data'] );
		$this->assertSame( 'not-applicable', $response['verification'] );
		$this->assertSame( 0, $operation->applyCalls );
	}

	public function test_a_malformed_plan_token_is_stale_plan(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'planToken' => 'not-a-token',
					'arguments' => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_non_string_plan_token_is_stale_plan(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'planToken' => [ 'nested' => true ],
					'arguments' => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_the_plan_token_is_not_part_of_the_operation_input_schema(): void {
		$this->registerStubWrite();

		$this->assertArrayNotHasKey(
			'planToken',
			$this->registry->definition( 'content-update' )->inputSchema['properties']
		);
	}

	/**
	 * Nothing stores the payload — plan_body holds only the preview renderings
	 * and payload_hash is a one-way digest — so the apply call must carry the
	 * same arguments the preview was generated from. A client that sends only
	 * the token is on the primary happy path and must be told exactly that,
	 * not handed a bare "missing required property 'id'".
	 */
	public function test_approving_a_plan_without_resending_arguments_says_so(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'planToken' => str_repeat( 'a', 64 ),
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'resent', $e->getMessage() );
		}
	}

	/**
	 * Unknown siblings are rejected rather than ignored. Silently dropping them
	 * means a client that mistypes `plan_token` gets a brand-new preview and a
	 * success envelope while believing it just approved a change.
	 */
	public function test_an_unknown_top_level_member_is_invalid_input(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation'  => 'content-update',
					'plan_token' => str_repeat( 'a', 64 ),
					'arguments'  => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			// The client's raw member name is untrusted text and is never echoed.
			// Both fields are checked: remediation is a separate property that
			// ships in the same error envelope, so asserting only the message
			// leaves echoing the name there passing green.
			$this->assertStringNotContainsString( 'plan_token', $e->getMessage() );
			$this->assertStringNotContainsString( 'plan_token', (string) $e->remediation );
		}
	}

	public function test_the_read_path_still_routes_to_a_bare_handler(): void {
		$response = $this->buildDispatcher()->dispatch(
			'system-read',
			[
				'operation' => 'system-environment',
				'arguments' => [],
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'wordpress' => '6.8.1' ], $response['data'] );
	}
	/**
	 * The unknown-member guard does not depend on `arguments` being present.
	 *
	 * A review found no test covered this shape, so gating the guard on
	 * isset( $args['arguments'] ) would pass. The consequence is the exact defect
	 * the guard exists to prevent: a client that mistypes the token key and sends
	 * nothing else gets a brand-new preview and a success envelope while
	 * believing it approved a change.
	 */
	public function test_an_unknown_top_level_member_is_refused_without_arguments(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation'  => 'content-update',
					'plan_token' => str_repeat( 'a', 64 ),
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( 'plan_token', $e->getMessage() );
		}
	}

	/**
	 * A falsy-but-present plan token is refused, never treated as absent.
	 *
	 * A review found nothing pinned this, so a future `if ( ! $raw )` shortcut in
	 * resolve_plan_token() would silently turn an approval into a fresh preview
	 * and return success: the caller believes the change was applied, and it
	 * never was. Every one of these values is falsy in PHP.
	 *
	 * @dataProvider falsyPlanTokens
	 *
	 * @param mixed $token The token value a client might send.
	 */
	public function test_a_falsy_plan_token_is_refused_rather_than_ignored( mixed $token ): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation'  => 'content-update',
					'planToken'  => $token,
					'arguments'  => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	/**
	 * @return array<string, mixed[]> Label to the falsy token value.
	 */
	public static function falsyPlanTokens(): array {
		return [
			'empty string'     => [ '' ],
			'string zero'      => [ '0' ],
			'integer zero'     => [ 0 ],
			'boolean false'    => [ false ],
			'empty array'      => [ [] ],
		];
	}

	/**
	 * A present but non-array `arguments` is refused, not coerced.
	 *
	 * Coercing it to an empty array runs the operation against arguments the
	 * caller never sent. On a write that produces a preview of nothing while
	 * reporting success.
	 */
	public function test_a_non_array_arguments_member_is_invalid_input(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'arguments' => 'id=42',
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( 'id=42', $e->getMessage() );
		}
	}
}
