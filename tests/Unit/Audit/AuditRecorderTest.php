<?php
/**
 * Tests for AuditRecorder.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
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
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Tests audit record creation, finalization, and reference derivation.
 */
final class AuditRecorderTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditRecorder $recorder;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->recorder  = new AuditRecorder( new AuditStore(), new AuditRedactor() );

		$user             = new stdClass();
		$user->user_login = 'operator';
		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(): OperationDefinition {
		return new OperationDefinition(
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
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
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

	public function test_reference_is_derived_from_the_row_id(): void {
		$this->assertSame( 'audit-42', AuditRecorder::reference( 42 ) );
	}

	public function test_start_records_actor_client_operation_target_and_fingerprint(): void {
		$id = $this->recorder->start(
			$this->makeDefinition(),
			$this->makeContext(),
			'post:42',
			str_repeat( 'b', 64 ),
			9,
			'rb-0123456789abcdef01234567'
		);

		$this->assertSame( 1, $id );
		$this->assertSame( Installer::tableName( Installer::TABLE_AUDIT ), $this->wpdb->inserts[0]['table'] );
		$row = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 'corr-1', $row['correlation_id'] );
		$this->assertSame( 'example.com', $row['site_id'] );
		$this->assertSame( 7, $row['actor_id'] );
		$this->assertSame( 'operator', $row['actor_login'] );
		$this->assertSame( 'demo-client', $row['client_id'] );
		$this->assertSame( 'content-update', $row['operation_id'] );
		$this->assertSame( 'post:42', $row['target_key'] );
		$this->assertSame( str_repeat( 'b', 64 ), $row['plan_fingerprint'] );
		$this->assertSame( AuditRecorder::OUTCOME_STARTED, $row['outcome'] );
		$this->assertSame( 1_800_000_000, $row['recorded_at'] );
	}

	/**
	 * The opening row must already carry the recovery handle. If it did not, a
	 * fatal inside the write — an out-of-memory in a third-party save_post hook,
	 * a killed worker — would leave a permanently 'started' row with a NULL
	 * rollback_ref beside a real snapshot row nothing points at, reachable only
	 * through direct database access. That is exactly what interpretation I4's
	 * rationale promises will never be necessary.
	 */
	public function test_start_writes_the_recovery_handle_on_the_opening_row(): void {
		$this->recorder->start(
			$this->makeDefinition(),
			$this->makeContext(),
			'post:42',
			str_repeat( 'b', 64 ),
			9,
			'rb-0123456789abcdef01234567'
		);

		$row = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 9, $row['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $row['rollback_ref'] );
	}

	public function test_start_accepts_an_operation_that_captured_no_snapshot(): void {
		$this->recorder->start(
			$this->makeDefinition(),
			$this->makeContext(),
			'post:new',
			str_repeat( 'b', 64 ),
			null,
			null
		);

		$row = $this->wpdb->inserts[0]['data'];
		$this->assertNull( $row['snapshot_id'] );
		$this->assertNull( $row['rollback_ref'] );
	}

	public function test_start_returns_zero_when_storage_refuses(): void {
		$this->wpdb->failInsert = true;

		$this->assertSame(
			0,
			$this->recorder->start( $this->makeDefinition(), $this->makeContext(), 'post:42', str_repeat( 'b', 64 ), 9, 'rb-0123456789abcdef01234567' )
		);
	}

	public function test_start_tolerates_an_unresolvable_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->recorder->start( $this->makeDefinition(), $this->makeContext(), 'post:42', str_repeat( 'b', 64 ), null, null );

		$this->assertSame( '', $this->wpdb->inserts[0]['data']['actor_login'] );
	}

	public function test_finish_stores_a_redacted_summary_and_the_final_outcome(): void {
		$this->assertTrue(
			$this->recorder->finish(
				3,
				AuditRecorder::OUTCOME_APPLIED,
				9,
				'rb-0123456789abcdef01234567',
				'post:42',
				[ 'post_title' => 'Confidential launch name' ],
				[ 'post_title' => 'Public launch name' ]
			)
		);

		$this->assertSame( Installer::tableName( Installer::TABLE_AUDIT ), $this->wpdb->updates[0]['table'] );
		$this->assertSame( [ 'id' => 3 ], $this->wpdb->updates[0]['where'] );
		$update = $this->wpdb->updates[0];
		$this->assertSame( AuditRecorder::OUTCOME_APPLIED, $update['data']['outcome'] );
		$this->assertSame( 9, $update['data']['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $update['data']['rollback_ref'] );
		$this->assertSame( 'post:42', $update['data']['target_key'] );
		$this->assertStringContainsString( 'post_title', $update['data']['summary'] );
		$this->assertStringNotContainsString( 'Confidential launch name', $update['data']['summary'] );
	}

	public function test_finish_returns_false_when_storage_refuses(): void {
		$this->wpdb->failUpdate = true;

		$this->assertFalse(
			$this->recorder->finish(
				3,
				AuditRecorder::OUTCOME_APPLIED,
				null,
				null,
				'post:42',
				[],
				[ 'post_title' => 'x' ]
			)
		);
	}
	/**
	 * A null recovery handle at finish means unchanged, never cleared.
	 *
	 * This is the guarantee interpretation I4 rests on. start() writes the
	 * snapshot id and rollback reference onto the opening row so a crash between
	 * capturing a snapshot and finalizing the record cannot leave a real
	 * snapshot no audit row points at. A review found finish() then overwrote
	 * both columns unconditionally, so a caller finalizing a failed write from
	 * an error branch — which legitimately has no handle to pass — would null
	 * them back out and orphan the snapshot through the ordinary API.
	 */
	public function test_finish_does_not_clear_the_recovery_handle_it_was_not_given(): void {
		$this->recorder->finish( 7, AuditRecorder::OUTCOME_EXECUTION_FAILED, null, null, 'post:42', [], [ 'post_title' => 'x' ] );

		$data = $this->wpdb->updates[0]['data'];
		$this->assertArrayNotHasKey( 'snapshot_id', $data );
		$this->assertArrayNotHasKey( 'rollback_ref', $data );
		$this->assertSame( AuditRecorder::OUTCOME_EXECUTION_FAILED, $data['outcome'] );
	}

	/**
	 * A supplied handle is still written.
	 *
	 * The preserving behaviour above must not become "never writes them".
	 */
	public function test_finish_writes_a_recovery_handle_when_one_is_supplied(): void {
		$this->recorder->finish( 7, AuditRecorder::OUTCOME_APPLIED, 12, 'rb-xyz', 'post:42', [], [ 'post_title' => 'x' ] );

		$data = $this->wpdb->updates[0]['data'];
		$this->assertSame( 12, $data['snapshot_id'] );
		$this->assertSame( 'rb-xyz', $data['rollback_ref'] );
	}

	/**
	 * Every outcome reaches the row, not just the successful one.
	 *
	 * A review found only OUTCOME_APPLIED was ever passed, so hard-coding
	 * finish() to write "applied" left the suite green — an audit trail in which
	 * every failed write is recorded as having succeeded.
	 *
	 * @dataProvider outcomes
	 *
	 * @param string $outcome The outcome constant under test.
	 */
	public function test_each_outcome_is_persisted_verbatim( string $outcome ): void {
		$this->recorder->finish( 7, $outcome, 3, 'rb-1', 'post:42', [], [ 'post_title' => 'x' ] );

		$this->assertSame( $outcome, $this->wpdb->updates[0]['data']['outcome'] );
	}

	/**
	 * @return array<string, string[]> Outcome label to the constant value.
	 */
	public static function outcomes(): array {
		return [
			'applied'             => [ AuditRecorder::OUTCOME_APPLIED ],
			'execution failed'    => [ AuditRecorder::OUTCOME_EXECUTION_FAILED ],
			'verification failed' => [ AuditRecorder::OUTCOME_VERIFICATION_FAILED ],
		];
	}

	/**
	 * Finalizing a row that does not exist is a failure, not a success.
	 *
	 * A review found the store reported true whenever the UPDATE did not error,
	 * including zero matched rows — so finish() on an unknown id, or on the id 0
	 * a refused start() returns, claimed to have written an audit record that
	 * does not exist.
	 */
	public function test_finishing_an_unknown_record_reports_failure(): void {
		// 0, not false: real wpdb distinguishes "no row matched" from an error,
		// and only the former is what an unknown id produces.
		$this->wpdb->updateRowsQueue = [ 0 ];

		$this->assertFalse(
			$this->recorder->finish( 999_999, AuditRecorder::OUTCOME_APPLIED, null, null, 'post:42', [], [ 'post_title' => 'x' ] )
		);
	}

	public function test_finishing_a_record_the_database_refuses_reports_failure(): void {
		$this->wpdb->failUpdate = true;

		$this->assertFalse(
			$this->recorder->finish( 7, AuditRecorder::OUTCOME_APPLIED, null, null, 'post:42', [], [ 'post_title' => 'x' ] )
		);
	}

	/**
	 * A multi-byte login is truncated on a character boundary.
	 *
	 * The column holds 60 characters. Cutting at 60 bytes splits a multi-byte
	 * login mid-character and stores invalid UTF-8, which a strict utf8mb4
	 * server rejects — losing the whole audit row rather than shortening a name.
	 */
	public function test_a_multibyte_login_is_truncated_without_corrupting_it(): void {
		// 70 characters is over the 60-character column, and 210 bytes, so a
		// byte-wise cut at 60 lands mid-character.
		Functions\when( 'get_userdata' )->justReturn( (object) [ 'user_login' => str_repeat( 'あ', 70 ) ] );

		$this->recorder->start(
			$this->makeDefinition(),
			$this->makeContext(),
			'post:42',
			str_repeat( 'b', 64 ),
			null,
			null
		);

		$stored = $this->wpdb->inserts[0]['data']['actor_login'];
		$this->assertSame( str_repeat( 'あ', 60 ), $stored );
		$this->assertTrue( mb_check_encoding( $stored, 'UTF-8' ) );
	}
}
