<?php
/**
 * Tests for comment-list.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\CommentFields;
use SiteHelm\Modules\Core\CommentList;
use SiteHelm\Tests\Doubles\CommentWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, driven over a store that actually holds rows.
 *
 * THE FILTER-SHARING TEST IS THE ONE THAT MATTERS MOST. `total` comes from a
 * second query, and a total computed under different filters than the page is
 * worse than no total at all — it would tell a moderator a queue of eight is a
 * queue of eight hundred. The test asserts the two recorded queries carry
 * identical filters, so a future edit that adds a filter to one and not the other
 * fails here rather than in production.
 */
final class CommentListTest extends TestCase {

	use CommentWordPressStubs;

	private CommentList $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installCommentStubs();
		$this->operation = new CommentList();
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_declares_a_read_on_the_content_read_dispatcher(): void {
		$definition = CommentList::definition();

		$this->assertSame( 'comment-list', $definition->id );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'moderate_comments' ], $definition->requiredCapabilities );
		$this->assertSame( false, $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * The enum is rendered from the constant rather than restated, so a status the
	 * module learns to report cannot become one the read refuses to filter by.
	 */
	public function test_the_status_filter_offers_every_reportable_status(): void {
		$definition = CommentList::definition();

		$this->assertSame(
			CommentFields::REPORTABLE_STATUSES,
			$definition->inputSchema['properties']['status']['enum']
		);
	}

	public function test_a_user_without_the_moderation_capability_is_refused(): void {
		$this->mayModerate = false;

		try {
			$this->operation->handle( [], $this->context() );
			$this->fail( 'A user who may not moderate comments must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [ [ 'user' => 7, 'capability' => 'moderate_comments' ] ], $this->capabilityChecks );
		$this->assertSame( [], $this->queries, 'The refusal must come before any query runs.' );
	}

	public function test_the_default_page_is_approved_and_pending_together(): void {
		$this->seedComment( 1, [ 'comment_approved' => '1' ] );
		$this->seedComment( 2, [ 'comment_approved' => '0' ] );
		$this->seedComment( 3, [ 'comment_approved' => 'spam' ] );
		$this->seedComment( 4, [ 'comment_approved' => 'trash' ] );

		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 2, 1 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( 'all', $this->queries[0]['status'] );
	}

	public function test_spam_is_returned_only_when_asked_for_by_name(): void {
		$this->seedComment( 1, [ 'comment_approved' => '1' ] );
		$this->seedComment( 3, [ 'comment_approved' => 'spam' ] );

		$result = $this->operation->handle(
			[ 'status' => CommentFields::STATUS_SPAM ],
			$this->context()
		);

		$this->assertSame( [ 3 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( 'spam', $this->queries[0]['status'] );
	}

	/**
	 * The comment query's own vocabulary is a third set of words again. A status
	 * translated wrongly here returns the wrong rows while every type still holds.
	 *
	 * @dataProvider statusTranslations
	 *
	 * @param string $status The reported status a caller filters by.
	 * @param string $query  The word the comment query takes for it.
	 */
	public function test_each_reportable_status_translates_to_the_query_vocabulary( string $status, string $query ): void {
		$this->operation->handle( [ 'status' => $status ], $this->context() );

		$this->assertSame( $query, $this->queries[0]['status'] );
	}

	/**
	 * @return array<string, string[]> Reported status and the query's word for it.
	 */
	public static function statusTranslations(): array {
		return [
			'approved'     => [ 'approved', 'approve' ],
			'pending'      => [ 'pending', 'hold' ],
			'spam'         => [ 'spam', 'spam' ],
			'trash'        => [ 'trash', 'trash' ],
			'post-trashed' => [ 'post-trashed', 'post-trashed' ],
		];
	}

	public function test_the_post_filter_narrows_to_one_content_item(): void {
		$this->seedComment( 1, [ 'comment_post_ID' => '42' ] );
		$this->seedComment( 2, [ 'comment_post_ID' => '99' ] );

		$result = $this->operation->handle( [ 'postId' => 99 ], $this->context() );

		$this->assertSame( [ 2 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_a_blank_search_term_is_not_sent_as_a_filter(): void {
		$this->operation->handle( [ 'search' => '   ' ], $this->context() );

		$this->assertArrayNotHasKey( 'search', $this->queries[0] );
	}

	public function test_the_search_term_narrows_the_page(): void {
		$this->seedComment( 1, [ 'comment_content' => 'The link is broken.' ] );
		$this->seedComment( 2, [ 'comment_content' => 'Great post.' ] );

		$result = $this->operation->handle( [ 'search' => 'broken' ], $this->context() );

		$this->assertSame( [ 1 ], array_column( $result['items'], 'id' ) );
	}

	public function test_the_page_size_is_clamped_and_echoed_back(): void {
		$result = $this->operation->handle( [ 'limit' => 5000 ], $this->context() );

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 100, $this->queries[0]['number'] );
	}

	public function test_the_default_page_size_is_twenty(): void {
		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( 20, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	public function test_the_offset_pages_through_the_match_set_without_changing_the_total(): void {
		foreach ( range( 1, 5 ) as $id ) {
			$this->seedComment( $id );
		}

		$result = $this->operation->handle( [ 'limit' => 2, 'offset' => 2 ], $this->context() );

		$this->assertSame( [ 3, 2 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( 5, $result['total'] );
		$this->assertSame( 2, $result['offset'] );
	}

	/**
	 * The count query and the page query must be asking about the same set.
	 */
	public function test_the_total_is_counted_under_the_same_filters_as_the_page(): void {
		$this->seedComment( 1, [ 'comment_post_ID' => '42', 'comment_content' => 'broken link' ] );

		$this->operation->handle(
			[
				'postId' => 42,
				'status' => CommentFields::STATUS_APPROVED,
				'search' => 'broken',
			],
			$this->context()
		);

		$this->assertCount( 2, $this->queries );

		$page  = array_diff_key( $this->queries[0], array_flip( [ 'number', 'offset', 'orderby', 'order' ] ) );
		$count = array_diff_key( $this->queries[1], array_flip( [ 'count' ] ) );

		$this->assertSame( $page, $count );
		$this->assertTrue( $this->queries[1]['count'] );
	}

	public function test_the_page_is_ordered_newest_first(): void {
		$this->operation->handle( [], $this->context() );

		$this->assertSame( 'comment_date_gmt', $this->queries[0]['orderby'] );
		$this->assertSame( 'DESC', $this->queries[0]['order'] );
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->seedComment( 1 );

		$this->assertConformsToOutputSchema(
			$this->operation->handle( [], $this->context() ),
			CommentList::definition()->outputSchema
		);
	}

	public function test_each_listed_comment_carries_every_declared_field(): void {
		$this->seedComment( 1 );

		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( CommentFields::FIELD_ORDER, array_keys( $result['items'][0] ) );
	}
}
