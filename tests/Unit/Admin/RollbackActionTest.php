<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\RollbackAction;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The handler is driven through an injected dispatch callable and an injected
 * redirect, so every test sees exactly what the engine was asked and exactly
 * where the browser was sent, with no real dispatcher and no `exit`.
 */
final class RollbackActionTest extends TestCase {

	/**
	 * Every dispatcher call the handler made: [dispatcher, args, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: OperationContext}>
	 */
	private array $calls = [];

	/**
	 * The URL the handler redirected to, or null if it did not.
	 */
	private ?string $redirectedTo = null;

	/**
	 * What the dispatch double returns, or throws when it is an exception.
	 *
	 * @var array<string, mixed>|OperationException
	 */
	private $answer;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$_POST              = [];
		$this->calls        = [];
		$this->redirectedTo = null;
		$this->answer       = $this->previewEnvelope();
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	/**
	 * A preview envelope shaped the way the dispatcher really returns one.
	 *
	 * @return array<string, mixed>
	 */
	private function previewEnvelope(): array {
		return [
			'success'  => true,
			'data'     => [
				'plan' => [
					'planToken'      => 'tok-1',
					'previewSummary' => [
						'human'   => 'Restore post 41',
						'machine' => [
							'target'  => 'post:41',
							'exists'  => true,
							'changes' => [
								[
									'field'  => 'post_title',
									'before' => 'New title',
									'after'  => 'Old title',
								],
							],
						],
					],
				],
			],
			'warnings' => [ 'The post was edited since.' ],
		];
	}

	private function action(): RollbackAction {
		$dispatch = function ( string $dispatcher, array $args, OperationContext $context ): array {
			$this->calls[] = [ $dispatcher, $args, $context ];

			if ( $this->answer instanceof OperationException ) {
				throw $this->answer;
			}

			return $this->answer;
		};

		$redirect = function ( string $url ): void {
			$this->redirectedTo = $url;
		};

		return new RollbackAction( $dispatch, new ContextFactory(), [], $redirect );
	}

	private function post( string $reference, string $step ): void {
		$_POST = [
			RollbackAction::FIELD_REF  => $reference,
			RollbackAction::FIELD_STEP => $step,
		];
		$this->action()->handle();
	}

	private function pendingKey(): string {
		return 'sitehelm_rollback_pending_' . AdminWordPressStubs::$currentUserId;
	}

	/**
	 * @return array<string, string>
	 */
	private function redirectQuery(): array {
		$this->assertNotNull( $this->redirectedTo, 'The handler did not redirect.' );
		$query = [];
		parse_str( (string) parse_url( (string) $this->redirectedTo, PHP_URL_QUERY ), $query );

		return array_map( static fn( $value ): string => rawurldecode( (string) $value ), $query );
	}

	public function testAUserWithoutTheCapabilityIsRefusedBeforeAnythingRuns(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( 'audit-1', RollbackAction::STEP_PREVIEW );
	}

	public function testTheNonceIsCheckedAgainstTheRollbackAction(): void {
		$this->post( 'audit-1', RollbackAction::STEP_PREVIEW );

		$this->assertContains( RollbackAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testAnEmptyReferenceIsRefusedWithoutAskingTheEngine(): void {
		$this->post( '', RollbackAction::STEP_PREVIEW );

		$this->assertSame( [], $this->calls );
		$this->assertArrayHasKey( RollbackAction::ARG_ERROR, $this->redirectQuery() );
	}

	public function testPreviewAsksTheRollbackOperationWithoutAPlanToken(): void {
		$this->post( 'audit-1', RollbackAction::STEP_PREVIEW );

		$this->assertCount( 1, $this->calls );
		[ $dispatcher, $args, $context ] = $this->calls[0];

		$this->assertSame( 'content-write', $dispatcher );
		$this->assertSame( 'content-rollback-apply', $args['operation'] );
		$this->assertSame( [ 'rollbackRef' => 'audit-1' ], $args['arguments'] );
		$this->assertArrayNotHasKey( 'planToken', $args );
		$this->assertSame( 'wp-admin', $context->clientId );
		$this->assertSame( AdminWordPressStubs::$currentUserId, $context->userId );
	}

	public function testPreviewParksThePlanAndSendsTheOperatorToConfirm(): void {
		$this->post( 'audit-1', RollbackAction::STEP_PREVIEW );

		$pending = AdminWordPressStubs::$transients[ $this->pendingKey() ] ?? null;
		$this->assertIsArray( $pending );
		$this->assertSame( 'audit-1', $pending['reference'] );
		$this->assertSame( 'tok-1', $pending['token'] );
		$this->assertSame( 'post:41', $pending['target'] );
		$this->assertSame( 'post_title', $pending['changes'][0]['field'] );
		$this->assertSame( [ 'The post was edited since.' ], $pending['warnings'] );

		$query = $this->redirectQuery();
		$this->assertSame( 'sitehelm-activity', $query['page'] );
		$this->assertSame( RollbackAction::STATE_CONFIRM, $query[ RollbackAction::ARG_STATE ] );
		$this->assertStringNotContainsString( 'tok-1', (string) $this->redirectedTo );
	}

	public function testPreviewRefusalIsCarriedBackInTheEnginesOwnWords(): void {
		$this->answer = new OperationException( ErrorCode::InvalidInput, 'That reference is unknown.', 'Copy it from the Activity row.' );

		$this->post( 'audit-9', RollbackAction::STEP_PREVIEW );

		$this->assertArrayNotHasKey( $this->pendingKey(), AdminWordPressStubs::$transients );
		$this->assertSame( 'That reference is unknown. Copy it from the Activity row.', $this->redirectQuery()[ RollbackAction::ARG_ERROR ] );
	}

	public function testPreviewWithoutAPlanTokenIsRefused(): void {
		$this->answer = [ 'success' => true, 'data' => [], 'warnings' => [] ];

		$this->post( 'audit-1', RollbackAction::STEP_PREVIEW );

		$this->assertArrayNotHasKey( $this->pendingKey(), AdminWordPressStubs::$transients );
		$this->assertArrayHasKey( RollbackAction::ARG_ERROR, $this->redirectQuery() );
	}

	public function testApplySpendsTheParkedTokenAndReportsDone(): void {
		AdminWordPressStubs::$transients[ $this->pendingKey() ] = [
			'reference' => 'audit-1',
			'token'     => 'tok-1',
			'target'    => 'post:41',
			'changes'   => [],
			'warnings'  => [],
		];
		$this->answer = [ 'success' => true, 'data' => [ 'restored' => true ], 'warnings' => [] ];

		$this->post( 'audit-1', RollbackAction::STEP_APPLY );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'tok-1', $this->calls[0][1]['planToken'] );
		$this->assertSame( [ 'rollbackRef' => 'audit-1' ], $this->calls[0][1]['arguments'] );
		$this->assertContains( $this->pendingKey(), AdminWordPressStubs::$deletedTransients );

		$query = $this->redirectQuery();
		$this->assertSame( RollbackAction::STATE_DONE, $query[ RollbackAction::ARG_STATE ] );
		$this->assertSame( 'audit-1', $query[ RollbackAction::FIELD_REF ] );
	}

	public function testApplyForADifferentReferenceThanThePreviewIsRefused(): void {
		AdminWordPressStubs::$transients[ $this->pendingKey() ] = [
			'reference' => 'audit-1',
			'token'     => 'tok-1',
		];

		$this->post( 'audit-2', RollbackAction::STEP_APPLY );

		$this->assertSame( [], $this->calls );
		$this->assertContains( $this->pendingKey(), AdminWordPressStubs::$deletedTransients );
		$this->assertArrayHasKey( RollbackAction::ARG_ERROR, $this->redirectQuery() );
	}

	public function testApplyWithNoParkedPreviewIsRefused(): void {
		$this->post( 'audit-1', RollbackAction::STEP_APPLY );

		$this->assertSame( [], $this->calls );
		$this->assertArrayHasKey( RollbackAction::ARG_ERROR, $this->redirectQuery() );
	}

	public function testApplyRefusalIsCarriedBackAndTheTokenIsNotReusable(): void {
		AdminWordPressStubs::$transients[ $this->pendingKey() ] = [
			'reference' => 'audit-1',
			'token'     => 'tok-1',
		];
		$this->answer = new OperationException( ErrorCode::InvalidInput, 'The target changed since the preview.', null );

		$this->post( 'audit-1', RollbackAction::STEP_APPLY );

		$this->assertSame( 'The target changed since the preview.', $this->redirectQuery()[ RollbackAction::ARG_ERROR ] );
		$this->assertArrayNotHasKey( $this->pendingKey(), AdminWordPressStubs::$transients );
	}

	public function testPendingNormalisesAParkedPreviewAndIsNullWithoutOne(): void {
		$this->assertNull( RollbackAction::pending( 7 ) );

		AdminWordPressStubs::$transients[ $this->pendingKey() ] = [
			'reference' => 'audit-1',
			'token'     => 'tok-1',
			'warnings'  => [ 1, 'two' ],
		];

		$pending = RollbackAction::pending( 7 );
		$this->assertNotNull( $pending );
		$this->assertSame( '', $pending['target'] );
		$this->assertSame( [], $pending['changes'] );
		$this->assertSame( [ '1', 'two' ], $pending['warnings'] );
		$this->assertNull( RollbackAction::pending( 8 ) );
	}
}
