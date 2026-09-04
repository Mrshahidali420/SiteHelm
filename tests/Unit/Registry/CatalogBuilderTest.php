<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use Brain\Monkey\Functions;
use SiteHelm\Admin\ProCatalogue;
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
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Tests\Doubles\StubWriteOperation;
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
	 * @param string         $diagnostics_health Health status for the diagnostics module.
	 * @param PermissionMode $mode               The site-level permission mode in force.
	 * @param string         $core_health        Health status for the core module.
	 */
	private function makeContext(
		string $diagnostics_health = 'active',
		PermissionMode $mode = PermissionMode::SafeWrite,
		string $core_health = 'active'
	): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: $mode,
			moduleVersions: [
				'diagnostics' => [
					'version' => null,
					'health'  => $diagnostics_health,
				],
				'core'        => [
					'version' => '6.8.1',
					'health'  => $core_health,
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
		$this->registry->registerWrite(
			$this->makeMultiCapabilityDefinition(),
			new StubWriteOperation()
		);
		$this->allowCapabilities( [ 'edit_posts' ] );

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
	}

	/**
	 * REQ-0076: the gate refuses a write that arrived on an address the site no
	 * longer answers as, so the catalog must say so rather than advertise those
	 * writes as available and send a client into a refusal it could have been
	 * warned about. The reason is its own, not read_only_mode: an operator sent
	 * looking for a permission-mode setting would never find the stale URL.
	 */
	public function test_a_write_reached_on_a_retired_host_is_listed_as_blocked(): void {
		$this->registry->registerWrite(
			$this->makeMultiCapabilityDefinition(),
			new StubWriteOperation()
		);
		$this->allowCapabilities( [ 'edit_posts', 'publish_posts' ] );
		$_SERVER['HTTP_HOST'] = 'old-agency-site.com';

		try {
			$catalog = $this->builder->build( 'content-write', $this->makeContext() );
		} finally {
			unset( $_SERVER['HTTP_HOST'] );
		}

		$this->assertFalse( $catalog['operations'][0]['available'] );
		$this->assertSame( 'retired_host', $catalog['operations'][0]['blockedReason'] );
	}

	/**
	 * Reads stay available: a connector pointed at the wrong domain still needs
	 * the diagnostics that say so.
	 */
	public function test_a_read_reached_on_a_retired_host_stays_available(): void {
		$_SERVER['HTTP_HOST'] = 'old-agency-site.com';

		try {
			$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		} finally {
			unset( $_SERVER['HTTP_HOST'] );
		}

		$this->assertTrue( $catalog['operations'][0]['available'] );
		$this->assertNull( $catalog['operations'][0]['blockedReason'] );
	}

	/**
	 * I3: an empty argument list in a usage example must serialize as {}, not [].
	 */
	public function test_empty_example_arguments_serialize_as_a_json_object(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		$json    = (string) json_encode( $catalog['operations'][0] );

		$this->assertStringContainsString( '"arguments":{}', $json );
		$this->assertStringNotContainsString( '"arguments":[]', $json );
	}

	/**
	 * REQ-0075: a catalog states what exists, not how to call it. Carrying every
	 * schema spent most of a client's context on operations it never called, so
	 * the schemas moved to an operation that returns one on request.
	 */
	public function test_catalog_omits_schemas_and_names_where_to_fetch_one(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		$entry   = $catalog['operations'][0];

		$this->assertArrayNotHasKey( 'inputSchema', $entry );
		$this->assertArrayNotHasKey( 'outputSchema', $entry );
		$this->assertStringContainsString( CatalogBuilder::SCHEMA_OPERATION, $catalog['schemas'] );
	}

	/**
	 * The example survives the trim. It is the one place the catalog still states
	 * an argument shape concretely, so dropping it with the schemas would have
	 * left a listing a client could not act on at all.
	 */
	public function test_catalog_still_states_the_argument_shape_by_example(): void {
		$this->registry->registerWrite(
			$this->makeMultiCapabilityDefinition(),
			new StubWriteOperation()
		);
		$this->allowCapabilities( [ 'edit_posts', 'publish_posts' ] );

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame(
			[
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
			$catalog['operations'][0]['example']
		);
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

	/**
	 * A target meta-capability cannot be evaluated without a concrete target:
	 * WordPress's map_meta_cap resolves a target-less check to do_not_allow, so
	 * user_can() returns false for every user including administrators. The
	 * catalog therefore filters on the meta-capability's PRIMITIVE equivalent —
	 * edit_post becomes edit_posts — so a caller who could plausibly perform the
	 * operation still sees it.
	 */
	public function test_meta_capability_only_operation_stays_in_the_catalog(): void {
		$this->allowCapabilities( [ 'edit_posts' ] );
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
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

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame(
			[ 'content-update' ],
			array_column( $catalog['operations'], 'operation' )
		);
	}

	/**
	 * Mapping meta-capabilities must not weaken the non-meta filter: an
	 * operation that also needs a primitive capability the user does not hold
	 * stays hidden.
	 */
	public function test_missing_primitive_capability_still_hides_a_meta_capability_operation(): void {
		$this->allowCapabilities( [] );
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-term-assign',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Assign existing taxonomy terms to one content item.',
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
				requiredCapabilities: [ 'edit_post', 'edit_posts' ],
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
					'operation' => 'content-term-assign',
					'arguments' => [ 'id' => 42 ],
				],
			),
			new StubWriteOperation()
		);

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
	}

	/**
	 * The failure mode a naive "skip meta-capabilities entirely" fix would
	 * introduce: content-update declares only edit_post, so skipping would leave
	 * it with no filterable capability at all and it would be advertised to
	 * every authenticated caller, a subscriber's catalog included. Mapping
	 * edit_post to edit_posts keeps a real visibility boundary.
	 */
	public function test_a_caller_without_capabilities_sees_no_write_operations_while_an_editor_does(): void {
		$this->registerContentUpdate();

		$this->allowCapabilities( [] );
		$this->assertSame(
			[],
			$this->builder->build( 'content-write', $this->makeContext() )['operations'],
			'A subscriber must not be told the site can be written to.'
		);

		$this->allowCapabilities( [ 'edit_posts' ] );
		$this->assertSame(
			[ 'content-update' ],
			array_column(
				$this->builder->build( 'content-write', $this->makeContext() )['operations'],
				'operation'
			)
		);
	}

	/**
	 * Registers a content write operation shaped like the real content-update.
	 */
	private function registerContentUpdate(): void {
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
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
	 * A catalog exists to tell a client what it can call.
	 *
	 * In read-only mode PolicyEngine refuses every write, but the catalog never
	 * read permissionMode, so every write operation was advertised
	 * `available: true` to every role from Contributor up. Nothing became
	 * invocable, so this over-promised rather than bypassing the gate — but it is
	 * the same catalog-versus-gate divergence that already caused one defect in
	 * this phase.
	 */
	public function test_read_only_mode_marks_a_write_operation_unavailable_with_a_reason(): void {
		$this->registerContentUpdate();
		$this->allowCapabilities( [ 'edit_posts' ] );

		$catalog = $this->builder->build(
			'content-write',
			$this->makeContext( 'active', PermissionMode::ReadOnly )
		);

		$this->assertCount( 1, $catalog['operations'], 'A blocked write must stay explainable, not vanish.' );
		$entry = $catalog['operations'][0];
		$this->assertSame( 'content-update', $entry['operation'] );
		$this->assertFalse( $entry['available'] );
		$this->assertSame( 'read_only_mode', $entry['blockedReason'] );
	}

	/**
	 * Read-only mode disables writes, so reporting a read as blocked would be a
	 * new lie in the opposite direction.
	 */
	public function test_read_only_mode_leaves_read_operations_available(): void {
		$entry = $this->builder->build(
			'system-read',
			$this->makeContext( 'active', PermissionMode::ReadOnly )
		)['operations'][0];

		$this->assertTrue( $entry['available'] );
		$this->assertNull( $entry['blockedReason'] );
	}

	/**
	 * Read-only mode is reported ahead of module health, matching the order the
	 * gate enforces: PolicyEngine::authorize() refuses a write in read-only mode
	 * before the dispatcher consults module health at all.
	 */
	public function test_read_only_mode_is_reported_ahead_of_module_health(): void {
		$this->registerContentUpdate();
		$this->allowCapabilities( [ 'edit_posts' ] );

		$entry = $this->builder->build(
			'content-write',
			$this->makeContext( 'active', PermissionMode::ReadOnly, 'inactive' )
		)['operations'][0];

		$this->assertSame( 'read_only_mode', $entry['blockedReason'] );
	}

	/**
	 * A write-permitting mode must not be affected, or the fix would simply move
	 * the over-promise into an under-promise.
	 */
	public function test_a_write_permitting_mode_still_advertises_the_write_as_available(): void {
		$this->registerContentUpdate();
		$this->allowCapabilities( [ 'edit_posts' ] );

		foreach ( [ PermissionMode::SafeWrite, PermissionMode::TrustedWrite ] as $mode ) {
			$entry = $this->builder->build(
				'content-write',
				$this->makeContext( 'active', $mode )
			)['operations'][0];

			$this->assertTrue( $entry['available'], $mode->value );
			$this->assertNull( $entry['blockedReason'], $mode->value );
		}
	}

	/**
	 * A switched-off operation is simply absent from the catalogue, not listed
	 * as blocked: blocked says "exists, cannot run now"; a switch says the
	 * operator does not want it reachable at all.
	 */
	public function test_a_switched_off_operation_is_absent_from_the_catalog(): void {
		$builder = new CatalogBuilder(
			$this->registry,
			new OperationSwitches( static fn(): array => [ 'system-environment' ] )
		);

		$catalog = $builder->build( 'system-read', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
		$this->assertCount( 1, $this->builder->build( 'system-read', $this->makeContext() )['operations'] );
	}

	/**
	 * A listing is the only place most agents look. An operation absent from it
	 * does not read as locked, it reads as impossible -- so the ones the add-on
	 * would add are named, with somewhere to go.
	 */
	public function test_a_free_listing_names_the_pro_operations_that_dispatcher_would_gain(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );

		$this->assertArrayHasKey( 'proOperations', $catalog );
		$this->assertStringContainsString( ProCatalogue::PRICING_URL, $catalog['proOperations']['note'] );

		$named = array_column( $catalog['proOperations']['operations'], 'description', 'operation' );
		$this->assertArrayHasKey( 'seo-settings-get', $named );
		$this->assertSame( ProCatalogue::OPERATIONS['seo-settings-get']['description'], $named['seo-settings-get'] );
	}

	/**
	 * Clients flatten this payload into one list of operations, and when they do
	 * the key an entry hung under is gone. So each advertised operation says it
	 * is unavailable in the same members a real entry uses, rather than relying
	 * on its position to say it.
	 */
	public function test_every_advertised_pro_operation_is_marked_unavailable_like_any_other_entry(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );

		$this->assertNotSame( [], $catalog['proOperations']['operations'] );

		foreach ( $catalog['proOperations']['operations'] as $entry ) {
			$this->assertArrayHasKey( 'available', $entry, $entry['operation'] );
			$this->assertFalse( $entry['available'], $entry['operation'] );
			$this->assertSame( 'requires_pro', $entry['blockedReason'], $entry['operation'] );
		}
	}

	/**
	 * The member describes this dispatcher, not the whole add-on: a Pro
	 * operation belonging elsewhere has no business in a system-read listing.
	 */
	public function test_the_pro_listing_is_scoped_to_the_dispatcher_being_listed(): void {
		$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		$named   = array_column( $catalog['proOperations']['operations'], 'operation' );

		foreach ( $named as $id ) {
			$this->assertSame( 'system-read', ProCatalogue::OPERATIONS[ $id ]['dispatcher'] );
		}
	}

	/**
	 * With the add-on active the operation is registered, so "buy Pro" would be
	 * false. Dispatcher treats a registered-then-switched-off operation the same
	 * way, and so does this: not offered, not advertised.
	 */
	public function test_a_registered_pro_operation_is_not_advertised_as_purchasable(): void {
		$this->registry->register( $this->proDouble( 'seo-settings-get' ), static fn(): array => [] );

		$catalog = $this->builder->build( 'system-read', $this->makeContext() );
		$named   = array_column( $catalog['proOperations']['operations'], 'operation' );

		$this->assertNotContains( 'seo-settings-get', $named );
	}

	/**
	 * Nothing to sell on this dispatcher, nothing said about it. The member is
	 * absent rather than present and empty.
	 */
	public function test_a_dispatcher_the_add_on_does_not_extend_carries_no_pro_member(): void {
		$this->assertArrayNotHasKey( 'proOperations', $this->builder->build( 'media-read', $this->makeContext() ) );
	}

	/**
	 * Stands in for an operation the add-on registered under its own id.
	 *
	 * @param string $id The Pro operation identifier to occupy.
	 */
	private function proDouble( string $id ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Registered by the add-on.',
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
}
