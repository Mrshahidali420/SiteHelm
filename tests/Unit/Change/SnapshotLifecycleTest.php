<?php
/**
 * Tests for the snapshot lifecycle extracted from the change engine.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\SnapshotLifecycle;
use SiteHelm\Change\TargetState;
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
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * The recovery position of a write: what can be captured, and what happens to
 * the captured state when the write fails.
 */
final class SnapshotLifecycleTest extends TestCase {

	private FakeWpdb $wpdb;
	private SnapshotLifecycle $lifecycle;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$this->lifecycle = new SnapshotLifecycle( new SnapshotStore(), new PayloadNormalizer() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_compensation_is_not_attempted_when_no_restore_state_was_captured(): void {
		$this->assertSame(
			'not-attempted',
			$this->lifecycle->compensate( new StubWriteOperation(), null, $this->makeContext() )
		);
	}

	public function test_a_successful_restore_reports_restored(): void {
		$operation = new StubWriteOperation();

		$this->assertSame(
			'restored',
			$this->lifecycle->compensate( $operation, [ 'post_title' => 'Original title' ], $this->makeContext() )
		);
		$this->assertSame( 1, $operation->restoreCalls );
	}

	/**
	 * A failed compensation must report rather than escape: the write already
	 * failed, and a throw here would replace that failure with a less useful one.
	 */
	public function test_a_throwing_restore_reports_failed_rather_than_escaping(): void {
		$operation                = new StubWriteOperation();
		$operation->restoreThrows = new \RuntimeException( 'restore exploded' );

		$this->assertSame(
			'failed',
			$this->lifecycle->compensate( $operation, [ 'post_title' => 'Original title' ], $this->makeContext() )
		);
	}

	public function test_eligibility_reports_not_applicable_when_the_operation_declares_no_snapshot(): void {
		$eligibility = $this->lifecycle->eligibility(
			$this->makeDefinition( SnapshotPolicy::NotApplicable, RollbackPolicy::NotApplicable ),
			new StubWriteOperation(),
			new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] ),
			$this->makeContext()
		);

		$this->assertSame(
			[
				'snapshot' => ChangeEngine::SNAPSHOT_NOT_APPLICABLE,
				'rollback' => ChangeEngine::ROLLBACK_NOT_OFFERED,
			],
			$eligibility
		);
	}

	/**
	 * NOT ASKED, not merely not stored. A deletion sweep found this guard
	 * unpinned because deleting it reaches the same VERDICT by a different
	 * route: the stub's snapshot happens to be null in every other fixture, so
	 * the null check below returns the same empty result and every assertion
	 * still holds. Give the operation something to hand back and the two
	 * routes separate — a row is written for an operation whose contract says
	 * no recoverable state exists, and the operation is interrogated for state
	 * it declared it does not have.
	 *
	 * `NotApplicable` is a statement about the operation, not about this
	 * particular target, so the question must not be asked at all.
	 */
	public function test_a_not_applicable_policy_never_asks_the_operation_for_a_snapshot(): void {
		$operation           = new StubWriteOperation();
		$operation->snapshot = [ 'post_title' => 'Original title' ];

		$captured = $this->lifecycle->capture(
			$this->makeDefinition( SnapshotPolicy::NotApplicable, RollbackPolicy::NotApplicable ),
			$operation,
			new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] ),
			$this->makeContext()
		);

		$this->assertSame( 0, $operation->snapshotCalls );
		$this->assertSame( [], $this->wpdb->inserts );
		$this->assertNull( $captured['restore'] );
		$this->assertNull( $captured['id'] );
		$this->assertNull( $captured['reference'] );
	}
	private function makeDefinition(
		SnapshotPolicy $snapshot = SnapshotPolicy::Required,
		RollbackPolicy $rollback = RollbackPolicy::Supported
	): OperationDefinition {
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
				'properties'           => [ 'target' => [ 'type' => 'string' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: $snapshot,
			rollbackPolicy: $rollback,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	private function makeContext( int $user_id = 7, int $request_time = 1_800_000_100 ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: $user_id,
			clientId: 'demo-client',
			correlationId: 'corr-2',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: $request_time,
		);
	}
}
