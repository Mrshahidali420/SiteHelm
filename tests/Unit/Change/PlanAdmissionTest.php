<?php
/**
 * Tests for the plan admission gate (REQ-0006).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlanAdmission;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
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
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0006: a write executes only when the exact previewed plan is approved by
 * the same authenticated user.
 *
 * The gate is the one place every write passes through, so each guard is tested
 * on its own. Two properties are asserted beyond "it refuses":
 *
 * - Every binding refusal carries the IDENTICAL code and message, so a caller
 *   cannot probe the gate to learn which element of the binding was wrong. The
 *   message is asserted as well as the code, because a maintainer who gave one
 *   binding its own message would leak exactly that.
 * - A target that moved between the preview and the approval is `conflict`, not
 *   `stale_plan`. A spent token and a target somebody else edited are different
 *   situations and the operator needs to be told which one happened.
 */
final class PlanAdmissionTest extends TestCase {

	private const TOKEN = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

	private const SHARED_REFUSAL = 'expired, already used, or bound to a different request';

	private FakeWpdb $wpdb;
	private PlanAdmission $admission;
	private PayloadNormalizer $normalizer;

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

		$this->normalizer = new PayloadNormalizer();
		$this->admission  = new PlanAdmission(
			new PlanStore(),
			$this->normalizer,
			new StateFingerprint( $this->normalizer )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function digest(): string {
		return PlanStore::digest( self::TOKEN );
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

	private function currentTarget(): TargetState {
		return new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
	}

	/**
	 * The stored plan row bound to the target and payload the tests approve.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> The plan row.
	 */
	private function planRow( array $overrides = [] ): array {
		$fingerprint = ( new StateFingerprint( $this->normalizer ) )
			->compute( $this->currentTarget(), $this->makeContext() );

		return array_merge(
			[
				'id'                => 1,
				'token_hash'        => PlanStore::digest( self::TOKEN ),
				'site_id'           => 'example.com',
				'user_id'           => 7,
				'operation_id'      => 'content-update',
				'schema_version'    => 1,
				'target_key'        => 'post:42',
				'payload_hash'      => $this->normalizer->fingerprint( [ 'title' => 'Edited title' ] ),
				'state_fingerprint' => $fingerprint,
				'plan_body'         => '{}',
				'created_at'        => 1_800_000_000,
				'expires_at'        => 1_800_000_900,
				'consumed_at'       => null,
			],
			$overrides
		);
	}

	/**
	 * Runs findValidPlan() against one stored plan row.
	 *
	 * @param array<string, mixed> $overrides Plan-row fields to replace.
	 * @param bool                 $found     Whether the store resolves a row at all.
	 *
	 * @return array<string, mixed> The admitted plan row.
	 */
	private function findValidPlan( array $overrides = [], bool $found = true ): array {
		$this->wpdb->rowQueue = $found ? [ $this->planRow( $overrides ) ] : [ null ];

		return $this->admission->findValidPlan( $this->digest(), $this->makeDefinition(), $this->makeContext() );
	}

	/**
	 * Asserts a refusal is the one shared stale-plan failure.
	 *
	 * Every caller routes through this single helper deliberately: the guarantee
	 * under test is that the refusals are indistinguishable, and a per-test copy
	 * of the assertion would be free to drift into accepting a message that
	 * names the binding that failed.
	 *
	 * @param callable $act The admission call expected to refuse.
	 */
	private function assertRefusedAsStale( callable $act ): void {
		try {
			$act();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertStringContainsString( self::SHARED_REFUSAL, $e->getMessage() );
		}
	}

	public function test_a_missing_plan_row_is_refused_as_stale(): void {
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [], false ) );
	}

	public function test_an_expired_plan_is_refused_as_stale(): void {
		// The comparison is `<=`, so a plan expiring exactly on the request
		// second is already gone. Nothing else about this row differs, so the
		// expiry term is the only guard that can refuse it.
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [ 'expires_at' => 1_800_000_100 ] ) );
	}

	public function test_a_plan_bound_to_another_site_is_refused_as_stale(): void {
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [ 'site_id' => 'other.example' ] ) );
	}

	public function test_a_plan_bound_to_another_user_is_refused_as_stale(): void {
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [ 'user_id' => 8 ] ) );
	}

	public function test_a_plan_bound_to_another_operation_is_refused_as_stale(): void {
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [ 'operation_id' => 'content-create' ] ) );
	}

	public function test_a_plan_bound_to_another_schema_version_is_refused_as_stale(): void {
		$this->assertRefusedAsStale( fn(): array => $this->findValidPlan( [ 'schema_version' => 2 ] ) );
	}

	public function test_a_target_that_no_longer_matches_is_refused_as_stale(): void {
		$other = new TargetState( 'post:99', true, [ 'post_title' => 'Original title' ] );

		$this->assertRefusedAsStale(
			fn() => $this->admission->assertTargetMatches( $this->planRow(), $other )
		);
	}

	public function test_a_target_that_changed_under_the_operator_is_a_conflict_not_a_stale_plan(): void {
		// Same target key, different contents: somebody edited the post between
		// the preview and this approval. The operator approved a preview of how
		// it used to look, which is not the same thing as a spent token.
		$moved = new TargetState( 'post:42', true, [ 'post_title' => 'Edited by somebody else' ] );

		try {
			$this->admission->assertStateUnchanged( $this->planRow(), $moved, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );
			$this->assertStringContainsString( 'changed between the preview and this approval', $e->getMessage() );
			$this->assertStringNotContainsString( self::SHARED_REFUSAL, $e->getMessage() );
		}
	}

	public function test_a_payload_that_no_longer_matches_is_refused_as_stale(): void {
		$planned = new PlannedChange(
			[ 'title' => 'A title the preview never saw' ],
			[ 'post_title' => 'A title the preview never saw' ],
			[ 'post_title' ]
		);

		$this->assertRefusedAsStale(
			fn() => $this->admission->assertPayloadMatches( $this->planRow(), $planned )
		);
	}

	public function test_a_plan_that_cannot_be_consumed_is_refused_as_stale(): void {
		// Real wpdb returns 0 — not false — when the UPDATE matched no row, and
		// that is what a concurrent apply which already claimed this token looks
		// like. The claim must be reported as a failure, not as a success with
		// zero rows.
		$this->wpdb->queryRowsQueue = [ 0 ];

		$this->assertRefusedAsStale(
			fn() => $this->admission->consumeOrFail( $this->digest(), 1_800_000_100 )
		);
	}
}
