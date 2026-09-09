<?php
/**
 * Tests for ContentResave.
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
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentResave;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Saving a content item again without changing anything in it.
 */
final class ContentResaveTest extends TestCase {

	/**
	 * Content with backslashes in it, which is the whole reason the escaping in
	 * applyChange() is written the way it is. A shortcode argument, a Windows
	 * path and a regular expression are the three shapes that actually turn up
	 * in a page.
	 */
	private const CONTENT = '<p>Match \\d+ items, see C:\\Users\\site, and \\[not a shortcode\\].</p>';

	private ContentResave $operation;

	/**
	 * Every row wp_update_post() was handed, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * What wp_update_post() answers next.
	 *
	 * @var mixed
	 */
	private mixed $updateAnswer = 42;

	/**
	 * Whether get_post() knows about post 42 at all.
	 */
	private bool $postExists = true;

	protected function setUp(): void {
		parent::setUp();

		$fields          = new ContentFields();
		$this->operation = new ContentResave( $fields, new ContentTarget( $fields ) );

		$this->written      = [];
		$this->updateAnswer = 42;
		$this->postExists   = true;

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key = '', bool $single = false ) => $single ? '' : []
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof stdClass && isset( $thing->wpError )
		);

		// The real wp_slash() escapes; a stub that returned its argument would
		// make the difference between an escaped row and a raw one invisible,
		// which is the one thing these tests exist to see.
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				if ( is_array( $value ) ) {
					return array_map(
						static fn( $item ) => is_string( $item ) ? addslashes( $item ) : $item,
						$value
					);
				}

				return is_string( $value ) ? addslashes( $value ) : $value;
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( array $row ) {
				$this->written[] = $row;

				return $this->updateAnswer;
			}
		);

		Functions\when( 'get_post' )->alias(
			function ( int $id, string $output = 'OBJECT' ) {
				if ( 42 !== $id || ! $this->postExists ) {
					return null;
				}

				$row = [
					'ID'                => 42,
					'post_type'         => 'page',
					'post_status'       => 'publish',
					'post_title'        => 'Our services',
					'post_name'         => 'our-services',
					'post_content'      => self::CONTENT,
					'post_excerpt'      => '',
					'post_parent'       => 0,
					'menu_order'        => 0,
					'post_modified_gmt' => '2026-09-07 10:00:00',
				];

				return ARRAY_A === $output ? $row : (object) $row;
			}
		);
	}

	private function context(): OperationContext {
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

	public function test_the_definition_asks_for_one_identifier_and_nothing_else(): void {
		$definition = ContentResave::definition();

		$this->assertSame( 'content-resave', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertSame( [ 'id' ], array_keys( $definition->inputSchema['properties'] ) );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertFalse( $definition->losesStateWithoutSnapshot );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	public function test_a_content_item_that_is_not_there_is_refused_before_anything_is_saved(): void {
		$this->postExists = false;

		try {
			$this->operation->resolveTarget( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [], $this->written );
	}

	public function test_the_plan_writes_nothing_and_promises_the_item_back_exactly_as_it_is(): void {
		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$this->assertSame( [], $planned->payload );
		$this->assertSame(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Our services',
				'post_name'    => 'our-services',
				'post_content' => self::CONTENT,
				'post_excerpt' => '',
			],
			$planned->afterFields
		);
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * The promise must not cover the two places the plugins being woken up write
	 * their recalculated answers, nor the timestamp whose moving is the point.
	 * A promise that covered them would fail verification on precisely the sites
	 * where this operation is doing its job.
	 */
	public function test_the_promise_leaves_out_meta_terms_and_the_modified_time(): void {
		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$this->assertArrayNotHasKey( 'meta', $planned->afterFields );
		$this->assertArrayNotHasKey( 'terms', $planned->afterFields );
		$this->assertArrayNotHasKey( 'post_modified_gmt', $planned->afterFields );
	}

	public function test_the_plan_says_that_other_peoples_code_runs(): void {
		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'plugin code', $planned->warnings[0] );
	}

	/**
	 * The defect this operation is written around. WordPress unescapes whatever
	 * it is handed before storing it, so the item's own row has to be escaped on
	 * the way back in. Handing wp_update_post() the identifier alone makes it
	 * read the stored row itself and unescape text that was never escaped, and
	 * every backslash in the content disappears — on a save that reports success.
	 */
	public function test_the_whole_row_goes_back_escaped_so_backslashes_survive(): void {
		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$key = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'post:42', $key );
		$this->assertCount( 1, $this->written );

		$row = $this->written[0];

		$this->assertSame( 42, $row['ID'] );
		$this->assertSame( addslashes( self::CONTENT ), $row['post_content'] );
		$this->assertSame( 'Our services', $row['post_title'] );
		$this->assertSame( 'publish', $row['post_status'] );
	}

	public function test_an_item_that_disappeared_between_the_plan_and_the_save_is_refused(): void {
		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		$this->postExists = false;

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}

		$this->assertSame( [], $this->written );
	}

	/**
	 * A plugin hooked into the save can veto it, and WordPress reports that as an
	 * error object rather than by throwing. Reporting a save that did not happen
	 * would leave the operator believing everything downstream had recalculated.
	 *
	 * @dataProvider refusedAnswers
	 *
	 * @param mixed $answer What wp_update_post() returns.
	 */
	public function test_a_save_wordpress_refused_is_reported_as_a_failure( mixed $answer ): void {
		$this->updateAnswer = $answer;

		$context = $this->context();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange( $current, [ 'id' => 42 ], $context );

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedAnswers(): array {
		return [
			'an error object' => [ (object) [ 'wpError' => 'invalid_post' ] ],
			'a zero'          => [ 0 ],
		];
	}

	public function test_a_snapshot_is_still_taken_so_a_damaging_plugin_can_be_undone(): void {
		$context  = $this->context();
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->assertIsArray( $snapshot );
		$this->assertSame( 42, $snapshot['post_id'] ?? null );
		$this->assertSame( self::CONTENT, $snapshot['post_content'] ?? null );
	}

	public function test_the_read_back_reports_the_item_as_it_now_stands(): void {
		$state = $this->operation->readBack( 'post:42', $this->context() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertSame( self::CONTENT, $state->fields['post_content'] );
	}
}
