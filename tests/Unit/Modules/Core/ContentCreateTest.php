<?php
/**
 * Tests for ContentCreate (REQ-0013).
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
use SiteHelm\Modules\Core\ContentCreate;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0013: draft new client content through an AI client.
 */
final class ContentCreateTest extends TestCase {

	private ContentCreate $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentCreate( $fields, new ContentTarget( $fields ) );
		$this->writes    = [];

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$type          = new stdClass();
		$type->public  = true;
		Functions\when( 'get_post_type_object' )->justReturn( $type );
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return 77;
			}
		);

		$this->stubCreatedPost();
	}

	/**
	 * The created post's persisted state, as re-read by readBack(). Needed for
	 * ContentFields::read() to resolve id 77 (rather than null) once
	 * ContentCreate::readBack() calls get_post() during verification.
	 */
	private function stubCreatedPost(): void {
		$post                    = new stdClass();
		$post->ID                = 77;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Brand new page';
		$post->post_name         = 'brand-new-page';
		$post->post_content      = '<p>Body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
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

	/**
	 * @return array<string, mixed> A complete creation payload.
	 */
	private function input( string $status = 'draft' ): array {
		return [
			'type'    => 'post',
			'title'   => 'Brand new page',
			'content' => '<p>Body.</p>',
			'status'  => $status,
		];
	}

	public function test_resolve_target_is_the_pending_target(): void {
		$state = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertSame( 'post:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_plan_change_promises_every_creation_field(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Body.</p>',
				'post_excerpt' => '',
				'post_status'  => 'draft',
				'post_title'   => 'Brand new page',
				'post_type'    => 'post',
			],
			$planned->afterFields
		);
	}

	public function test_plan_change_rejects_an_unregistered_content_type(): void {
		Functions\when( 'post_type_exists' )->justReturn( false );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_plan_change_rejects_a_non_public_content_type(): void {
		$type         = new stdClass();
		$type->public = false;
		Functions\when( 'get_post_type_object' )->justReturn( $type );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_publish_request_requires_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( $this->input( 'publish' ), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_a_publish_request_succeeds_with_the_publish_capability(): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => 'publish_posts' === $capability
		);
		$current = $this->operation->resolveTarget( $this->input( 'publish' ), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( 'publish', $planned->afterFields['post_status'] );
	}

	public function test_a_draft_request_does_not_require_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( 'draft', $planned->afterFields['post_status'] );
	}

	public function test_no_snapshot_is_captured_because_there_is_no_prior_state(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
	}

	public function test_apply_change_inserts_the_post_and_returns_its_target_key(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( 'post:77', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'Brand new page', $this->writes[0]['post_title'] );
		$this->assertSame( 'post', $this->writes[0]['post_type'] );
	}

	public function test_apply_change_reports_a_refused_insert_as_execution_failed(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 0 );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	public function test_restore_is_refused_because_a_creation_has_no_prior_state(): void {
		try {
			$this->operation->restore( [ 'post_id' => 77 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6, as for every other registered
	 * operation: the apply-phase payload is assembled exactly as
	 * ChangeEngine::apply() builds it and checked against the schema the module
	 * actually registered.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( $this->input(), $context );
		$planned = $this->operation->planChange( $current, $this->input(), $context );
		$target  = $this->operation->applyChange( $current, $planned, $context );
		$after   = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-create' )->outputSchema
		);
	}
}
