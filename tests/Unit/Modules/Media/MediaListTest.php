<?php
/**
 * Tests for MediaList (REQ-0020).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaList;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0020: media listing.
 *
 * WP_Query is a class, so Brain Monkey cannot fake it. FakeWpQuery stands in
 * under the global name — installed process-wide by tests/bootstrap.php and
 * reset before every test by TestCase — which is what makes the operation's
 * real `new WP_Query( … )` observable without loading WordPress.
 */
final class MediaListTest extends TestCase {

	private MediaList $handler;

	/**
	 * The unpaginated total the fake query reports, asserted against rather
	 * than a literal so a coincidentally equal page size cannot pass the test.
	 */
	private int $foundPosts = 9;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 41, 42 ];

	/**
	 * The rows get_post() serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler  = new MediaList( new MediaFields() );
		$this->editable = [ 41, 42 ];
		$this->rows     = [
			41 => $this->makeAttachment( 41, 'attachment' ),
			42 => $this->makeAttachment( 42, 'attachment' ),
		];
		$this->stubWordPress();
	}

	private function makeAttachment( int $id, string $type ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = $type;
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Asset ' . $id;
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 0;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && in_array( $post_id, $this->editable, true )
		);
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => $this->rows[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/asset.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/asset.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'filesize' => 1024 ] );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Lists both queued rows.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function list( array $input ): array {
		FakeWpQuery::$rows       = [ $this->rows[41], $this->rows[42] ];
		FakeWpQuery::$foundPosts = $this->foundPosts;

		return $this->handler->handle( $input, $this->makeContext() );
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
		$result = $this->list( [] );

		$this->assertSame(
			[ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
			array_keys( $result['items'][0] )
		);
	}

	/**
	 * A listing is not a place to ship every rendition of every asset:
	 * media-get already returns one item in full.
	 */
	public function test_the_summary_carries_no_alt_caption_description_or_renditions(): void {
		$entry = $this->list( [] )['items'][0];

		$this->assertArrayNotHasKey( 'alt', $entry );
		$this->assertArrayNotHasKey( 'caption', $entry );
		$this->assertArrayNotHasKey( 'description', $entry );
		$this->assertArrayNotHasKey( 'sizes', $entry );
		$this->assertArrayNotHasKey( 'filesize', $entry );
	}

	public function test_the_summary_values_come_from_the_matched_attachment(): void {
		$entry = $this->list( [] )['items'][0];

		$this->assertSame( 41, $entry['id'] );
		$this->assertSame( 'Asset 41', $entry['title'] );
		$this->assertSame( 'asset.png', $entry['filename'] );
		$this->assertSame( 'image/png', $entry['mimeType'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/07/asset.png', $entry['url'] );
		$this->assertSame( 0, $entry['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $entry['uploadedGmt'] );
	}

	/**
	 * upload_files is a site-wide primitive, so holding it does not mean the
	 * caller may edit every match. An item they cannot edit is omitted rather
	 * than listed-then-refused, because naming it discloses an asset they have
	 * no rights to — exactly as ContentList::editable_summaries() omits.
	 */
	public function test_an_item_the_caller_cannot_edit_is_omitted(): void {
		$this->editable = [ 42 ];

		$this->assertSame( [ 42 ], array_column( $this->list( [] )['items'], 'id' ) );
	}

	public function test_no_editable_match_yields_an_empty_item_list_not_a_refusal(): void {
		$this->editable = [];
		$result         = $this->list( [] );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	/**
	 * A matched row whose post has since vanished, or which is not an
	 * attachment at all, must not become a summary of nulls.
	 */
	public function test_a_matched_row_that_is_no_longer_a_readable_attachment_is_omitted(): void {
		$this->rows[41]->post_type = 'post';

		$this->assertSame( [ 42 ], array_column( $this->list( [] )['items'], 'id' ) );
	}

	public function test_limit_and_offset_are_echoed_and_total_is_the_unpaginated_count(): void {
		$result = $this->list(
			[
				'limit'  => 2,
				'offset' => 4,
			]
		);

		$this->assertSame( 2, $result['limit'] );
		$this->assertSame( 4, $result['offset'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	public function test_an_absent_limit_and_offset_default_to_one_page_from_the_start(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 20, $args['posts_per_page'] );
		$this->assertSame( 0, $args['offset'] );
	}

	/**
	 * Identical clamps to ContentList, so the two listings are learnable
	 * together: DEFAULT_LIMIT 20, MAX_LIMIT 100.
	 */
	public function test_an_oversized_limit_is_clamped_and_a_negative_offset_floored(): void {
		$result = $this->list(
			[
				'limit'  => 5000,
				'offset' => -10,
			]
		);

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	public function test_a_limit_below_one_is_raised_to_one(): void {
		$this->assertSame( 1, $this->list( [ 'limit' => 0 ] )['limit'] );
	}

	public function test_only_attachments_in_the_inherit_status_are_queried(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 'attachment', $args['post_type'] );
		$this->assertSame( 'inherit', $args['post_status'] );
	}

	public function test_results_are_ordered_most_recently_uploaded_first(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
	}

	/**
	 * A sticky post is hoisted to the front of a default query, which would
	 * silently contradict the ordering the operation advertises.
	 */
	public function test_sticky_posts_cannot_displace_the_declared_ordering(): void {
		$this->assertTrue( $this->capturedQueryArgs( [] )['ignore_sticky_posts'] );
	}

	/**
	 * The deliberate divergence from ContentList: every field of a media
	 * summary — filename, URL, and the metadata behind them — is post meta, so
	 * NOT priming the meta cache would cost several uncached meta reads per row
	 * instead of one primed read per page. Terms are still not primed, because
	 * no media summary field is a term.
	 */
	public function test_the_query_primes_the_meta_cache_the_summary_reads_but_not_terms(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertTrue( $args['update_post_meta_cache'] );
		$this->assertFalse( $args['update_post_term_cache'] );
	}

	public function test_search_mime_type_and_parent_are_forwarded_when_given(): void {
		$args = $this->capturedQueryArgs(
			[
				'search'   => 'hero',
				'mimeType' => 'image/png',
				'parent'   => 12,
			]
		);

		$this->assertSame( 'hero', $args['s'] );
		$this->assertSame( 'image/png', $args['post_mime_type'] );
		$this->assertSame( 12, $args['post_parent'] );
	}

	/**
	 * A bare top-level type is a documented input shape, and WP_Query accepts
	 * it, so it must reach the query unmangled.
	 */
	public function test_a_bare_top_level_mime_type_is_forwarded_unchanged(): void {
		$this->assertSame( 'image', $this->capturedQueryArgs( [ 'mimeType' => 'image' ] )['post_mime_type'] );
	}

	/**
	 * An empty search term must not narrow the query to nothing, an empty MIME
	 * type must not narrow it to no type, and an absent parent must not be read
	 * as "unattached only".
	 */
	public function test_empty_or_absent_filters_add_no_query_terms(): void {
		$args = $this->capturedQueryArgs(
			[
				'search'   => '',
				'mimeType' => '',
			]
		);

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'post_mime_type', $args );
		$this->assertArrayNotHasKey( 'post_parent', $args );
	}

	/**
	 * A parent of 0 is the documented way to ask for unattached assets, so it
	 * must reach the query rather than being discarded as falsy.
	 */
	public function test_a_parent_of_zero_asks_for_unattached_assets(): void {
		$this->assertSame( 0, $this->capturedQueryArgs( [ 'parent' => 0 ] )['post_parent'] );
	}

	/**
	 * The closed filter set is a security boundary, not a convenience: a meta
	 * query is a caller-shaped query surface pointed straight at the database.
	 */
	public function test_no_meta_taxonomy_author_or_date_terms_reach_the_query(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertArrayNotHasKey( 'date_query', $args );
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MediaList::definition();

		$this->assertSame( 'media-list', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertArrayNotHasKey( 'required', $definition->inputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$result   = $this->list( [] );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-list' )->outputSchema
		);
	}
}
