<?php
/**
 * Tests for ContentList (REQ-0010).
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
use SiteHelm\Modules\Core\ContentList;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0010: content listing.
 */
final class ContentListTest extends TestCase {

	private ContentList $handler;

	/**
	 * The unpaginated total the fake query reports, asserted against rather
	 * than a literal so a coincidentally equal page size cannot pass the test.
	 */
	private int $foundPosts = 9;

	/**
	 * WP_Query is a class, so Brain Monkey cannot fake it. The double stands in
	 * under the global name instead, which is what makes the operation's real
	 * `new WP_Query( … )` observable without loading WordPress.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_Query', false ) ) {
			class_alias( FakeWpQuery::class, 'WP_Query' );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		FakeWpQuery::reset();
		$this->handler = new ContentList();
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

	/**
	 * A post-shaped row. WP_Query returns WP_Post objects; the operation
	 * duck-types them exactly as ContentFields::read() does.
	 */
	private function makeRow( int $id ): stdClass {
		$row                    = new stdClass();
		$row->ID                = $id;
		$row->post_type         = 'post';
		$row->post_status       = 'draft';
		$row->post_title        = 'Item ' . $id;
		$row->post_name         = 'item-' . $id;
		$row->post_parent       = 0;
		$row->post_modified_gmt = '2026-07-26 10:00:00';

		return $row;
	}

	/**
	 * 'post' and 'page' are public, 'wp_internal' is registered but not public,
	 * and anything else is not registered at all.
	 */
	private function stubPostTypes(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				if ( ! in_array( $type, [ 'post', 'page', 'wp_internal' ], true ) ) {
					return null;
				}
				$object         = new stdClass();
				$object->public = 'wp_internal' !== $type;

				return $object;
			}
		);
	}

	/**
	 * Lists with one matching row the caller may edit.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function list( array $input ): array {
		$this->stubPostTypes();
		Functions\when( 'user_can' )->justReturn( true );
		FakeWpQuery::$rows       = [ $this->makeRow( 42 ) ];
		FakeWpQuery::$foundPosts = $this->foundPosts;

		return $this->handler->handle( $input, $this->makeContext() );
	}

	/**
	 * Lists two matching rows, 41 and 42, of which only the given identifiers
	 * are editable by the caller.
	 *
	 * @param int[] $editable The identifiers user_can() approves.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function listWithEditableIds( array $editable ): array {
		$this->stubPostTypes();
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, int $post_id = 0 ) use ( $editable ): bool {
				return 'edit_post' === $capability && in_array( $post_id, $editable, true );
			}
		);
		FakeWpQuery::$rows       = [ $this->makeRow( 41 ), $this->makeRow( 42 ) ];
		FakeWpQuery::$foundPosts = 2;

		return $this->handler->handle( [ 'type' => 'post' ], $this->makeContext() );
	}

	/**
	 * The arguments the operation handed to WP_Query.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The captured query arguments.
	 */
	private function capturedQueryArgs( array $input ): array {
		$this->list( $input );

		return FakeWpQuery::$calls[0];
	}

	public function test_the_summary_carries_exactly_the_seven_declared_fields(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame(
			[ 'id', 'type', 'status', 'title', 'slug', 'modifiedGmt', 'parent' ],
			array_keys( $result['items'][0] )
		);
	}

	/**
	 * A list is not a place to ship post bodies: fifty full records is a large
	 * response whose bulk is discarded, and content-get already returns one.
	 */
	public function test_the_summary_carries_no_body_excerpt_meta_or_terms(): void {
		$entry = $this->list( [ 'type' => 'post' ] )['items'][0];

		$this->assertArrayNotHasKey( 'content', $entry );
		$this->assertArrayNotHasKey( 'excerpt', $entry );
		$this->assertArrayNotHasKey( 'meta', $entry );
		$this->assertArrayNotHasKey( 'terms', $entry );
	}

	public function test_the_summary_values_come_from_the_matched_row(): void {
		$entry = $this->list( [ 'type' => 'post' ] )['items'][0];

		$this->assertSame( 42, $entry['id'] );
		$this->assertSame( 'post', $entry['type'] );
		$this->assertSame( 'draft', $entry['status'] );
		$this->assertSame( 'Item 42', $entry['title'] );
		$this->assertSame( 'item-42', $entry['slug'] );
		$this->assertSame( '2026-07-26 10:00:00', $entry['modifiedGmt'] );
		$this->assertSame( 0, $entry['parent'] );
	}

	/**
	 * edit_posts is a site-wide primitive, so holding it does not mean the caller
	 * may edit every match. An item they cannot edit is omitted rather than
	 * listed-then-refused, because naming it discloses content they have no
	 * rights to.
	 */
	public function test_an_item_the_caller_cannot_edit_is_omitted(): void {
		$result = $this->listWithEditableIds( [ 42 ] );

		$this->assertSame( [ 42 ], array_column( $result['items'], 'id' ) );
	}

	public function test_no_editable_match_yields_an_empty_item_list_not_a_refusal(): void {
		$result = $this->listWithEditableIds( [] );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_limit_and_offset_are_echoed_and_total_is_the_unpaginated_count(): void {
		$result = $this->list(
			[
				'type'   => 'post',
				'limit'  => 2,
				'offset' => 4,
			]
		);

		$this->assertSame( 2, $result['limit'] );
		$this->assertSame( 4, $result['offset'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	public function test_an_absent_limit_and_offset_default_to_one_page_from_the_start(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertSame( 20, $args['posts_per_page'] );
		$this->assertSame( 0, $args['offset'] );
	}

	public function test_an_oversized_limit_is_clamped_and_a_negative_offset_floored(): void {
		$result = $this->list(
			[
				'type'   => 'post',
				'limit'  => 5000,
				'offset' => -10,
			]
		);

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	public function test_a_non_public_post_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'wp_internal' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_an_unregistered_post_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'not_a_type' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The refusal must not name the site's registered types, which would turn a
	 * bad guess into an enumeration of content the caller cannot otherwise see.
	 */
	public function test_the_refusal_names_neither_internals_nor_the_registered_types(): void {
		try {
			$this->list( [ 'type' => 'wp_internal' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'wp_internal', $e->getMessage() );
			$this->assertStringNotContainsString( 'WP_Query', $e->getMessage() );
			$this->assertStringNotContainsString( 'page', $e->getMessage() );
		}
	}

	public function test_results_are_ordered_most_recently_modified_first(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertSame( 'modified', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
	}

	/**
	 * A sticky post is hoisted to the front of a default query, which would
	 * silently contradict the ordering the operation advertises.
	 */
	public function test_sticky_posts_cannot_displace_the_declared_ordering(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertTrue( $args['ignore_sticky_posts'] );
	}

	/**
	 * A caller naming no status wants the content they could act on, which is
	 * the set content-create can produce. Trash is not in it: REQ-0019 makes
	 * trash a destination chosen deliberately, so it is listed only on request.
	 */
	public function test_an_absent_status_queries_the_actionable_statuses_and_not_trash(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertSame( [ 'draft', 'pending', 'private', 'publish' ], $args['post_status'] );
		$this->assertNotContains( 'trash', $args['post_status'] );
	}

	public function test_a_named_status_replaces_the_default_set(): void {
		$args = $this->capturedQueryArgs(
			[
				'type'   => 'post',
				'status' => 'trash',
			]
		);

		$this->assertSame( 'trash', $args['post_status'] );
	}

	public function test_the_requested_type_is_the_queried_type(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'page' ] );

		$this->assertSame( 'page', $args['post_type'] );
	}

	public function test_an_absent_type_defaults_to_post(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 'post', $args['post_type'] );
	}

	public function test_search_and_parent_are_forwarded_when_given(): void {
		$args = $this->capturedQueryArgs(
			[
				'type'   => 'post',
				'search' => 'launch',
				'parent' => 12,
			]
		);

		$this->assertSame( 'launch', $args['s'] );
		$this->assertSame( 12, $args['post_parent'] );
	}

	/**
	 * An empty search term must not narrow the query to nothing, and an absent
	 * parent must not be read as "top level only".
	 */
	public function test_an_absent_or_empty_search_and_absent_parent_add_no_query_terms(): void {
		$args = $this->capturedQueryArgs(
			[
				'type'   => 'post',
				'search' => '',
			]
		);

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'post_parent', $args );
	}

	/**
	 * The closed filter set is a security boundary, not a convenience: a meta
	 * query is a caller-shaped query surface pointed at the database.
	 */
	public function test_no_meta_taxonomy_author_or_date_terms_reach_the_query(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertArrayNotHasKey( 'date_query', $args );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$result = $this->list( [ 'type' => 'post' ] );

		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'content-list' )->outputSchema
		);
	}
}
