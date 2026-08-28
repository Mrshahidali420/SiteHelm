<?php
/**
 * Tests for ContentSearch (REQ-0092, free half).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentSearch;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0092: site-wide content search.
 */
final class ContentSearchTest extends TestCase {

	private ContentSearch $handler;

	/**
	 * Stored documents, keyed by identifier, that the WordPress function fakes
	 * answer from. A test writes the site it wants here and then searches it.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $site = [];

	/**
	 * Identifiers the caller is NOT allowed to edit.
	 *
	 * @var int[]
	 */
	private array $forbidden = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler   = new ContentSearch();
		$this->site      = [];
		$this->forbidden = [];
		$this->stubWordPress();
	}

	/**
	 * The WordPress surface the operation touches.
	 *
	 * `user_can` answers from `$this->forbidden`, which is what makes the
	 * per-document capability filter observable: the guard is only real if a
	 * document the caller may not edit disappears from a result the query
	 * returned.
	 */
	private function stubWordPress(): void {
		Functions\when( 'post_type_exists' )->alias(
			static fn( string $type ): bool => in_array( $type, [ 'post', 'page' ], true )
		);

		Functions\when( 'get_post_status_object' )->alias(
			static fn( string $status ): ?object => in_array( $status, [ 'publish', 'draft', 'private', 'trash' ], true )
				? new stdClass()
				: null
		);

		Functions\when( 'user_can' )->alias(
			fn( int $user, string $cap, int $id ): bool => ! in_array( $id, $this->forbidden, true )
		);

		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?object {
				if ( ! isset( $this->site[ $id ] ) ) {
					return null;
				}

				$post               = new stdClass();
				$post->ID           = $id;
				$post->post_type    = $this->site[ $id ]['type'] ?? 'post';
				$post->post_status  = $this->site[ $id ]['status'] ?? 'publish';
				$post->post_title   = $this->site[ $id ]['title'] ?? '';
				$post->post_content = $this->site[ $id ]['content'] ?? '';
				$post->post_excerpt = $this->site[ $id ]['excerpt'] ?? '';

				return $post;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single ): string => $this->site[ $id ]['elementor'] ?? ''
		);

		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 'https://example.com/?p=' . $id
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => (string) preg_replace( '/<[^>]*>/', '', $text )
		);
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
	 * Queues what the post-table query and the Elementor meta query each return.
	 *
	 * @param int[] $inPost The identifiers WordPress's own search matches.
	 * @param int[] $inMeta The identifiers the Elementor meta query matches.
	 */
	private function queueQueries( array $inPost, array $inMeta ): void {
		FakeWpQuery::$queue = [ $inPost, $inMeta ];
	}

	public function test_it_unions_the_post_search_and_the_elementor_meta_search(): void {
		$this->site = [
			1 => [ 'title' => 'Acme returns' ],
			2 => [ 'elementor' => '[{"settings":{"title":"Acme"}}]' ],
		];
		$this->queueQueries( [ 1 ], [ 2 ] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 1, 2 ], array_column( $result['matches'], 'id' ) );
		$this->assertSame( 1, $result['matches'][0]['fields']['title'] );
		$this->assertSame( 1, $result['matches'][1]['fields']['elementor'] );
	}

	public function test_a_document_matched_by_both_queries_is_reported_once(): void {
		$this->site = [ 5 => [ 'content' => 'Acme', 'elementor' => 'Acme' ] ];
		$this->queueQueries( [ 5 ], [ 5 ] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 2, $result['matches'][0]['matchCount'] );
	}

	/**
	 * The load-bearing guard. `edit_posts` gets the caller through the front
	 * door; whether they may see any given draft is a per-document question, and
	 * a search that skips it is a way to read the whole site through an account
	 * that may not open one page of it.
	 */
	public function test_a_document_the_caller_may_not_edit_is_not_reported(): void {
		$this->site      = [
			1 => [ 'title' => 'Acme one' ],
			2 => [ 'title' => 'Acme two', 'status' => 'draft' ],
		];
		$this->forbidden = [ 2 ];
		$this->queueQueries( [ 1, 2 ], [] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( [ 1 ], array_column( $result['matches'], 'id' ) );
	}

	/**
	 * `LIKE` against Elementor's JSON matches the raw stored string, so a
	 * document can come back from the query holding no occurrence a person would
	 * recognise. Reporting it with every count at zero would be a row that says
	 * nothing about why it is there.
	 */
	public function test_a_candidate_with_no_real_occurrence_is_dropped(): void {
		$this->site = [
			1 => [ 'title' => 'Acme' ],
			2 => [ 'title' => 'Something else', 'content' => 'nothing here' ],
		];
		$this->queueQueries( [ 1 ], [ 2 ] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertSame( [ 1 ], array_column( $result['matches'], 'id' ) );
	}

	/**
	 * Without 'sentence' WordPress splits the search on spaces, and "old company
	 * name" starts matching every page carrying the word "name".
	 */
	public function test_the_phrase_is_searched_whole(): void {
		$this->queueQueries( [], [] );

		$this->handler->handle( [ 'phrase' => 'old company name' ], $this->makeContext() );

		$this->assertSame( 'old company name', FakeWpQuery::$calls[0]['s'] );
		$this->assertTrue( FakeWpQuery::$calls[0]['sentence'] );
	}

	public function test_it_defaults_to_the_four_editable_statuses_and_omits_trash(): void {
		$this->queueQueries( [], [] );

		$this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertSame( [ 'publish', 'draft', 'pending', 'private' ], FakeWpQuery::$calls[0]['post_status'] );
	}

	public function test_an_unknown_post_type_is_dropped_rather_than_refused(): void {
		$this->queueQueries( [], [] );

		$this->handler->handle(
			[
				'phrase'    => 'Acme',
				'postTypes' => [ 'page', 'no_such_type' ],
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'page' ], FakeWpQuery::$calls[0]['post_type'] );
	}

	public function test_naming_only_unknown_types_falls_back_to_searching_everything(): void {
		$this->queueQueries( [], [] );

		$this->handler->handle(
			[
				'phrase'    => 'Acme',
				'postTypes' => [ 'no_such_type' ],
			],
			$this->makeContext()
		);

		$this->assertSame( 'any', FakeWpQuery::$calls[0]['post_type'] );
	}

	public function test_case_sensitivity_changes_the_count_but_not_the_document(): void {
		$this->site = [ 1 => [ 'content' => 'Acme and acme and ACME' ] ];

		$this->queueQueries( [ 1 ], [] );
		$insensitive = $this->handler->handle( [ 'phrase' => 'acme' ], $this->makeContext() );

		FakeWpQuery::reset();
		$this->queueQueries( [ 1 ], [] );
		$sensitive = $this->handler->handle(
			[
				'phrase'        => 'acme',
				'caseSensitive' => true,
			],
			$this->makeContext()
		);

		$this->assertSame( 3, $insensitive['matches'][0]['fields']['content'] );
		$this->assertSame( 1, $sensitive['matches'][0]['fields']['content'] );
		$this->assertSame( 1, $sensitive['total'] );
	}

	public function test_the_excerpt_quotes_the_first_occurrence_without_markup(): void {
		$this->site = [ 1 => [ 'content' => '<p>Before the <strong>Acme</strong> mention.</p>' ] ];
		$this->queueQueries( [ 1 ], [] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertStringContainsString( 'Acme', (string) $result['matches'][0]['excerpt'] );
		$this->assertStringNotContainsString( '<strong>', (string) $result['matches'][0]['excerpt'] );
	}

	/**
	 * A phrase found only in the Elementor tree has no excerpt: quoting raw JSON
	 * back at the caller is noise, and there is a named operation for looking
	 * inside a document's elements.
	 */
	public function test_an_elementor_only_match_reports_a_null_excerpt(): void {
		$this->site = [ 1 => [ 'elementor' => '[{"settings":{"title":"Acme"}}]' ] ];
		$this->queueQueries( [], [ 1 ] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertNull( $result['matches'][0]['excerpt'] );
		$this->assertSame( 1, $result['matches'][0]['fields']['elementor'] );
	}

	/**
	 * Elementor's meta is JSON, so a quote is stored escaped and a literal LIKE
	 * misses documents that do contain the phrase. The answer says so instead of
	 * presenting a partial Elementor result as a complete one.
	 */
	public function test_a_phrase_json_would_escape_is_flagged_as_inexact(): void {
		$this->queueQueries( [], [] );
		$plain = $this->handler->handle( [ 'phrase' => 'Acme Ltd' ], $this->makeContext() );

		FakeWpQuery::reset();
		$this->queueQueries( [], [] );
		$quoted = $this->handler->handle( [ 'phrase' => 'the "Acme" brand' ], $this->makeContext() );

		FakeWpQuery::reset();
		$this->queueQueries( [], [] );
		$accented = $this->handler->handle( [ 'phrase' => 'Café Acme' ], $this->makeContext() );

		$this->assertTrue( $plain['elementorExact'] );
		$this->assertFalse( $quoted['elementorExact'] );
		$this->assertFalse( $accented['elementorExact'] );
	}

	public function test_results_are_paged_over_the_capability_filtered_list(): void {
		$ids = range( 1, 7 );

		foreach ( $ids as $id ) {
			$this->site[ $id ] = [ 'title' => 'Acme ' . $id ];
		}

		$this->forbidden = [ 3 ];
		$this->queueQueries( $ids, [] );

		$result = $this->handler->handle(
			[
				'phrase'  => 'Acme',
				'page'    => 2,
				'perPage' => 2,
			],
			$this->makeContext()
		);

		$this->assertSame( 6, $result['total'] );
		$this->assertSame( 3, $result['pageCount'] );
		$this->assertSame( [ 4, 5 ], array_column( $result['matches'], 'id' ) );
	}

	/**
	 * A phrase that matches most of the site stops the scan and says so, rather
	 * than turning a read into an outage.
	 */
	public function test_hitting_the_scan_ceiling_is_reported_as_truncated(): void {
		$ids = range( 1, ContentSearch::MAX_SCANNED + 50 );

		foreach ( $ids as $id ) {
			$this->site[ $id ] = [ 'title' => 'Acme' ];
		}

		$this->queueQueries( $ids, [] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertTrue( $result['truncated'] );
		$this->assertSame( ContentSearch::MAX_SCANNED, $result['scanned'] );
		$this->assertSame( ContentSearch::MAX_SCANNED, $result['total'] );
	}

	public function test_a_result_within_the_ceiling_is_not_truncated(): void {
		$this->site = [ 1 => [ 'title' => 'Acme' ] ];
		$this->queueQueries( [ 1 ], [] );

		$result = $this->handler->handle( [ 'phrase' => 'Acme' ], $this->makeContext() );

		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 1, $result['scanned'] );
	}

	public function test_the_definition_declares_a_read_that_needs_no_plan(): void {
		$definition = ContentSearch::definition();

		$this->assertSame( 'content-search', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
	}
}
