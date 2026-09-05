<?php
/**
 * Tests for ContentRead (REQ-0011).
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
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentRead;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0011: content retrieval.
 */
final class ContentReadTest extends TestCase {

	private ContentRead $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ContentRead( new ContentFields() );

		// Every content read now asks for the page template, which lives in
		// protected meta rather than in a post column.
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
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

	private function stubPost(): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->menu_order        = 4;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
	}

	public function test_returns_the_normalized_record_for_a_permitted_target(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubPost();

		$data = $this->handler->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'Original title', $data['title'] );
		$this->assertSame( '<p>Original body.</p>', $data['content'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertArrayHasKey( 'terms', $data );
		$this->assertArrayHasKey( 'meta', $data );
	}

	public function test_unpermitted_target_is_target_not_found_not_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->stubPost();

		try {
			$this->handler->handle( [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_missing_target_is_target_not_found(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->handler->handle( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_returned_record_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->stubPost();

		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$this->handler->handle( [ 'id' => 42 ], $this->makeContext() ),
			$registry->definition( 'content-get' )->outputSchema
		);
	}
}
