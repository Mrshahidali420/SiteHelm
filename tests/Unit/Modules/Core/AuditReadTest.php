<?php
/**
 * Tests for AuditRead (REQ-0009).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\AuditRead;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0009: review who changed what and when, with secrets redacted.
 */
final class AuditReadTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditRead $handler;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$this->handler = new AuditRead( new AuditStore(), new Installer() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-4',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> One stored audit row.
	 */
	private function row(): array {
		return [
			'id'               => 12,
			'correlation_id'   => 'corr-2',
			'actor_id'         => 7,
			'actor_login'      => 'operator',
			'client_id'        => 'demo-client',
			'operation_id'     => 'content-update',
			'target_key'       => 'post:42',
			'plan_fingerprint' => str_repeat( 'b', 64 ),
			'outcome'          => 'applied',
			'summary'          => '{"changed":["post_title"],"metrics":{"post_title":{"before":14,"after":12}}}',
			'snapshot_id'      => 5,
			'rollback_ref'     => 'rb-0123456789abcdef01234567',
			'recorded_at'      => 1_799_999_000,
		];
	}

	public function test_entries_carry_actor_client_operation_target_fingerprint_time_and_outcome(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$data  = $this->handler->handle( [], $this->makeContext() );
		$entry = $data['entries'][0];

		$this->assertSame( 'audit-12', $entry['auditRef'] );
		$this->assertSame( 'corr-2', $entry['correlationId'] );
		$this->assertSame( 7, $entry['actor']['id'] );
		$this->assertSame( 'operator', $entry['actor']['login'] );
		$this->assertSame( 'demo-client', $entry['client'] );
		$this->assertSame( 'content-update', $entry['operation'] );
		$this->assertSame( 'post:42', $entry['target'] );
		$this->assertSame( str_repeat( 'b', 64 ), $entry['planFingerprint'] );
		$this->assertSame( 'applied', $entry['outcome'] );
		$this->assertSame( 1_799_999_000, $entry['timestamp'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $entry['rollbackRef'] );
		$this->assertSame( 1, $data['total'] );
	}

	public function test_the_summary_carries_names_and_sizes_but_no_values(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$entry = $this->handler->handle( [], $this->makeContext() )['entries'][0];

		$this->assertSame( [ 'post_title' ], $entry['summary']['changed'] );
		$this->assertSame( 14, $entry['summary']['metrics']['post_title']['before'] );
	}

	public function test_a_row_without_a_snapshot_offers_no_rollback_reference(): void {
		$row                     = $this->row();
		$row['snapshot_id']      = null;
		$row['rollback_ref']     = null;
		$this->wpdb->resultQueue = [ [ $row ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$entry = $this->handler->handle( [], $this->makeContext() )['entries'][0];

		$this->assertNull( $entry['rollbackRef'] );
	}

	public function test_the_default_page_size_and_offset_are_applied(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$data = $this->handler->handle( [], $this->makeContext() );

		$this->assertSame( AuditRead::DEFAULT_LIMIT, $data['limit'] );
		$this->assertSame( 0, $data['offset'] );
	}

	public function test_an_oversized_limit_is_clamped(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$data = $this->handler->handle( [ 'limit' => 5000 ], $this->makeContext() );

		$this->assertSame( AuditStore::MAX_LIMIT, $data['limit'] );
	}

	public function test_only_whitelisted_filters_are_forwarded(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$this->handler->handle(
			[
				'operationId'   => 'content-update',
				'correlationId' => 'corr-2',
				'actorId'       => 7,
				'since'         => 1_700_000_000,
				'until'         => 1_900_000_000,
			],
			$this->makeContext()
		);

		$this->assertStringContainsString( 'operation_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertStringContainsString( 'correlation_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertStringContainsString( 'actor_id = %d', $this->wpdb->prepared[0]['query'] );
	}

	/**
	 * Defence in depth rather than the primary guard. Since Task 3 the core
	 * module reports itself inactive when storage is unavailable, so Dispatcher
	 * refuses this operation before the handler runs. This keeps a direct caller
	 * — a future WP-CLI path, an internal call — from querying a table that does
	 * not exist, and keeps the error code the same either way.
	 */
	public function test_unavailable_storage_degrades_to_integration_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->handler->handle( [], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated.
	 */
	public function test_the_returned_page_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->wpdb->resultQueue = [ [ $this->row() ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$this->handler->handle( [], $this->makeContext() ),
			$registry->definition( 'audit-list' )->outputSchema
		);
	}
}
