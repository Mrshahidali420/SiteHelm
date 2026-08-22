<?php
/**
 * Tests for the system-operation-schema lookup.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

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
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Diagnostics\OperationSchema;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0075: one operation's full schema, on request.
 *
 * The catalog no longer carries schemas, so this is the only surface that states
 * how to call an operation. Two properties matter beyond returning the schema at
 * all: it must return the SAME schema the dispatcher validates against, and it
 * must not describe an operation the catalog would have hidden.
 */
final class OperationSchemaTest extends TestCase {

	private CapabilityRegistry $registry;
	private OperationSchema $lookup;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( [] );

		$this->registry = new CapabilityRegistry();
		$this->lookup   = new OperationSchema( $this->registry );

		$this->registry->register( $this->readDefinition(), static fn(): array => [] );
		$this->registry->registerWrite( $this->writeDefinition(), new StubWriteOperation() );

		$this->allowCapabilities( [ 'read', 'edit_posts' ] );
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

	private function context(): OperationContext {
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

	public function test_it_returns_the_named_operation_input_and_output_schema(): void {
		$result = $this->lookup->handle( [ 'operation' => 'content-update' ], $this->context() );

		$this->assertSame( 'content-update', $result['operation'] );
		$this->assertSame( 'content-write', $result['dispatcher'] );
		$this->assertSame( 'Update one content item.', $result['description'] );
		$this->assertSame( 1, $result['schemaVersion'] );
		$this->assertSame( [ 'edit_posts' ], $result['requiredCapabilities'] );
		$this->assertSame( [ 'id' => [ 'type' => 'integer' ] ], $result['inputSchema']['properties'] );
		$this->assertSame( [ 'id' ], $result['inputSchema']['required'] );
		$this->assertSame( [ 'id' => [ 'type' => 'integer' ] ], $result['outputSchema']['properties'] );
	}

	/**
	 * The schema returned is the one the dispatcher validates against, not a copy
	 * assembled for display. A second rendering would be free to drift, and the
	 * client would be validating its arguments against a document the gate does
	 * not use.
	 */
	public function test_the_returned_schema_is_the_registered_definition_schema(): void {
		$result = $this->lookup->handle( [ 'operation' => 'content-update' ], $this->context() );

		$this->assertSame(
			$this->registry->definition( 'content-update' )->inputSchema,
			$result['inputSchema']
		);
	}

	/**
	 * An operation taking no arguments must advertise properties as an empty JSON
	 * object. PHP cannot tell one from an empty list, and a strict client rejects
	 * `"properties": []` outright.
	 */
	public function test_an_empty_property_set_serializes_as_a_json_object(): void {
		$result = $this->lookup->handle( [ 'operation' => 'system-environment' ], $this->context() );
		$json   = (string) json_encode( $result );

		$this->assertStringContainsString( '"properties":{}', $json );
		$this->assertStringNotContainsString( '"properties":[]', $json );
	}

	/**
	 * List-valued schema members stay JSON arrays. JSON_FORCE_OBJECT would have
	 * turned `required` into an object keyed by position.
	 */
	public function test_a_required_list_still_serializes_as_a_json_array(): void {
		$json = (string) json_encode(
			$this->lookup->handle( [ 'operation' => 'content-update' ], $this->context() )
		);

		$this->assertStringContainsString( '"required":["id"]', $json );
	}

	public function test_an_unregistered_name_is_refused_as_not_found(): void {
		try {
			$this->lookup->handle( [ 'operation' => 'content-obliterate' ], $this->context() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			return;
		}

		$this->fail( 'An unregistered operation name must be refused with ErrorCode::TargetNotFound.' );
	}

	/**
	 * The catalog omits operations whose capabilities the caller does not hold, so
	 * that a listing does not disclose the site's surface area. Answering here for
	 * any registered id would hand back exactly what the listing withheld, one
	 * name at a time.
	 */
	public function test_an_operation_the_caller_cannot_see_does_not_surrender_its_schema(): void {
		$this->allowCapabilities( [ 'read' ] );

		try {
			$this->lookup->handle( [ 'operation' => 'content-update' ], $this->context() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			return;
		}

		$this->fail( 'A hidden operation must not be described to a caller who cannot see it.' );
	}

	/**
	 * Interpretation I6's interim mitigation: nothing validates output at
	 * runtime, so the declared outputSchema is checked against a real payload
	 * here, where drift originates.
	 *
	 * The lookup runs against the plugin's OWN registry rather than this file's
	 * two stubs, because the schema being conformed to is the one
	 * DiagnosticsModule declares, and a payload built from a stub definition
	 * would prove the shape of the stub instead.
	 */
	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$registry = new CapabilityRegistry();
		( new DiagnosticsModule() )->register( $registry );

		$this->allowCapabilities( [ 'read', 'manage_options' ] );

		$data = ( new OperationSchema( $registry ) )->handle(
			[ 'operation' => 'system-environment' ],
			$this->context()
		);

		$this->assertConformsToOutputSchema(
			$data,
			$registry->definition( 'system-operation-schema' )->outputSchema
		);
	}

	/**
	 * Hidden and unregistered must be reported identically. A caller able to tell
	 * the two apart could enumerate the site's surface area one refusal at a time,
	 * which is the disclosure the catalog's own omission exists to prevent.
	 */
	public function test_a_hidden_operation_is_indistinguishable_from_an_absent_one(): void {
		$this->allowCapabilities( [ 'read' ] );

		$hidden  = $this->refusalFor( 'content-update' );
		$unknown = $this->refusalFor( 'content-obliterate' );

		$this->assertSame( $unknown->errorCode, $hidden->errorCode );
		$this->assertSame( $unknown->getMessage(), $hidden->getMessage() );
		$this->assertSame( $unknown->remediation, $hidden->remediation );
	}

	/**
	 * An operation the operator switched off is as unknown here as it is to the
	 * catalogue and the dispatcher; otherwise this lookup would describe what
	 * the other two hide.
	 */
	public function test_a_switched_off_operation_is_indistinguishable_from_an_absent_one(): void {
		$lookup = new OperationSchema(
			$this->registry,
			new OperationSwitches( static fn(): array => [ 'system-environment' ] )
		);

		try {
			$lookup->handle( [ 'operation' => 'system-environment' ], $this->context() );
			$this->fail( 'A switched-off operation must be refused.' );
		} catch ( OperationException $e ) {
			$unknown = $this->refusalFor( 'content-obliterate' );
			$this->assertSame( $unknown->errorCode, $e->errorCode );
			$this->assertSame( $unknown->getMessage(), $e->getMessage() );
		}

		$this->assertSame( 'system-environment', $this->lookup->handle( [ 'operation' => 'system-environment' ], $this->context() )['operation'] );
	}

	/**
	 * The refusal a lookup of the given name produces.
	 *
	 * @param string $name The operation name to look up.
	 */
	private function refusalFor( string $name ): OperationException {
		try {
			$this->lookup->handle( [ 'operation' => $name ], $this->context() );
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( sprintf( 'Looking up "%s" was expected to be refused.', $name ) );
	}

	public function test_a_caller_who_cannot_read_the_site_is_refused(): void {
		$this->allowCapabilities( [] );

		try {
			$this->lookup->handle( [ 'operation' => 'system-environment' ], $this->context() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			return;
		}

		$this->fail( 'A caller without read must be refused with ErrorCode::Forbidden.' );
	}

	/**
	 * A read definition taking no arguments.
	 */
	private function readDefinition(): OperationDefinition {
		return new OperationDefinition(
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
			requiredCapabilities: [ 'read' ],
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
		);
	}

	/**
	 * A write definition with a non-empty required list and a capability the
	 * read-only caller does not hold.
	 */
	private function writeDefinition(): OperationDefinition {
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
			requiredCapabilities: [ 'edit_posts' ],
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
