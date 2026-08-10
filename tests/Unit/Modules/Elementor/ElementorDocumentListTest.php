<?php
/**
 * Tests for ElementorDocumentList (REQ-0032).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentList;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0032: the paginated listing of the documents Elementor controls.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Two doubles are in play and each
 * reproduces a deliberately narrow slice of upstream behaviour:
 *
 * 1. FakeWpQuery (tests/Doubles, aliased onto the global `WP_Query` name by
 *    tests/bootstrap.php) reproduces exactly three things: that constructing a
 *    query executes it, that `$posts` holds the matched rows, and that
 *    `$found_posts` holds the UNPAGINATED match count. It reproduces NOTHING
 *    about how WordPress turns arguments into SQL — it does not filter by
 *    `post_type`, does not apply `meta_query`, does not honour `posts_per_page`
 *    or `offset`, and does not order anything. So no assertion below may claim
 *    that the query "returned only Elementor documents": the assertions on
 *    filtering are made against the ARGUMENTS the operation handed the query,
 *    which is the only place that behaviour actually lives in production.
 *
 * 2. The Elementor stand-in installed by withElementor() reproduces exactly the
 *    two facts `ElementorPresence::isLoaded()` reads: that `ELEMENTOR_VERSION`
 *    is defined, and that a class named `Elementor\Plugin` exists. It models NO
 *    Elementor API whatsoever — no plugin singleton, no widget manager, no
 *    document API — because this operation calls none of them. A later test that
 *    needs a real Elementor API surface must not build on this.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias; both are permanent for the life of a PHP
 * process. Installing either in the shared process would make every later test
 * in the suite — ElementorPresenceTest's absent-Elementor cases above all — run
 * against a site that has Elementor installed. So EVERY test in this class runs
 * in its own process, and the tests that need Elementor say so by calling
 * withElementor() rather than inheriting it from setUp(). A reader can tell
 * which site state a test assumes by looking at the test.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDocumentListTest extends TestCase {

	private ElementorDocumentList $handler;

	/**
	 * The unpaginated total the fake query reports.
	 *
	 * Deliberately not equal to the row count and not equal to any page size
	 * used below, so a `total` that was accidentally sourced from the page
	 * rather than from the query cannot pass.
	 */
	private int $foundPosts = 5137;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 101, 102 ];

	/**
	 * Whether user_can( 'edit_posts' ) approves the caller.
	 */
	private bool $mayEditPosts = true;

	/**
	 * The `_elementor_edit_mode` meta value per post identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $editModes = [];

	/**
	 * The rows the fake query reports.
	 *
	 * @var stdClass[]
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler      = new ElementorDocumentList( new ElementorFields(), new ElementorPresence() );
		$this->mayEditPosts = true;
		$this->editable     = [ 101, 102 ];
		$this->editModes    = [
			101 => 'builder',
			102 => 'builder',
		];
		$this->rows = [
			$this->makeRow( 101, 'page', 'Home', 'publish' ),
			$this->makeRow( 102, 'elementor_library', 'Hero Template', 'draft' ),
		];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForList::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	private function makeRow( int $id, string $type, string $title, string $status ): stdClass {
		$row              = new stdClass();
		$row->ID          = $id;
		$row->post_type   = $type;
		$row->post_title  = $title;
		$row->post_status = $status;

		return $row;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability, int $post_id = 0 ): bool {
				if ( 'edit_posts' === $capability ) {
					return $this->mayEditPosts;
				}

				return 'edit_post' === $capability && in_array( $post_id, $this->editable, true );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ): mixed =>
				ElementorDocument::META_EDIT_MODE === $key ? ( $this->editModes[ $id ] ?? '' ) : ''
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
				'elementor' => [
					'version' => '3.25.0',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation against the queued rows.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function list( array $input = [] ): array {
		FakeWpQuery::$rows       = $this->rows;
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
	private function capturedQueryArgs( array $input = [] ): array {
		$this->list( $input );

		return FakeWpQuery::$calls[0];
	}

	// ---------------------------------------------------------------- payload

	public function test_the_summary_carries_exactly_the_five_declared_fields(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'id', 'type', 'title', 'status', 'editMode' ],
			array_keys( $this->list()['documents'][0] )
		);
	}

	public function test_the_summary_values_come_from_the_matched_row_and_its_edit_mode_meta(): void {
		$this->withElementor();

		$entry = $this->list()['documents'][0];

		$this->assertSame( 101, $entry['id'] );
		$this->assertSame( 'page', $entry['type'] );
		$this->assertSame( 'Home', $entry['title'] );
		$this->assertSame( 'publish', $entry['status'] );
		$this->assertSame( 'builder', $entry['editMode'] );
	}

	/**
	 * The `elementor_library` template post type is in scope for this listing
	 * exactly as pages and posts are, which the spec's operations table requires
	 * and which a query restricted to `page`/`post` would silently drop.
	 */
	public function test_an_elementor_library_template_is_listed_alongside_pages(): void {
		$this->withElementor();

		$this->assertSame( [ 101, 102 ], array_column( $this->list()['documents'], 'id' ) );
		$this->assertSame( 'elementor_library', $this->list()['documents'][1]['type'] );
	}

	/**
	 * `_elementor_edit_mode` is what the query filters on, so every listed row
	 * has the meta; but the STORED value is third-party writable and a
	 * non-scalar must not be cast. `(string)` on an array is a fatal.
	 */
	public function test_an_unreadable_stored_edit_mode_reports_an_empty_string_rather_than_fataling(): void {
		$this->withElementor();

		$this->editModes[101] = [ 'builder' ];

		$this->assertSame( '', $this->list()['documents'][0]['editMode'] );
	}

	/**
	 * A row the query matched but which no longer carries a usable identifier —
	 * a filtered `posts` array, a partially built row — must be omitted rather
	 * than emitted as a summary of zeroes and empty strings.
	 */
	public function test_a_matched_row_without_a_usable_identifier_is_omitted(): void {
		$this->withElementor();

		$this->rows[0]->ID = 0;

		$this->assertSame( [ 102 ], array_column( $this->list()['documents'], 'id' ) );
	}

	/**
	 * An identifier is guarded on its SHAPE, not merely cast. `(int)` of an array
	 * is 1 — a valid-looking post id pointing at a document the row was never
	 * about — so a non-scalar identifier must be treated as no identifier.
	 */
	public function test_a_row_whose_identifier_is_not_scalar_is_omitted_rather_than_becoming_post_one(): void {
		$this->withElementor();

		$this->rows[0]->ID = [ 101 ];

		$this->assertSame( [ 102 ], array_column( $this->list()['documents'], 'id' ) );
	}

	/**
	 * The same shape rule on the way out: post columns reach this projection
	 * through the same public filter, and `(string)` of an array is a fatal. An
	 * unusable column answers the empty string, which keeps the declared output
	 * schema satisfied, while the row itself — which has a usable id — is still
	 * listed rather than silently disappearing.
	 */
	public function test_a_post_column_that_is_not_scalar_reports_an_empty_string(): void {
		$this->withElementor();

		$this->rows[0]->post_title = [ 'Home' ];

		$documents = $this->list()['documents'];

		$this->assertSame( 101, $documents[0]['id'] );
		$this->assertSame( '', $documents[0]['title'] );
	}

	/**
	 * `the_posts` is a public filter, so the rows a query returns are whatever the
	 * last plugin in the chain left behind — including a scalar. The row must be
	 * omitted, and above all the operation must not fatal on it: a `(string)` cast
	 * of an array is a fatal error, and a fatal inside the gateway is a 500 with no
	 * error code, which is the one outcome the dispatcher contract forbids.
	 */
	public function test_a_row_that_is_not_a_post_object_is_omitted_rather_than_fataling(): void {
		$this->withElementor();

		$this->rows[] = 'a string a plugin left in the_posts';

		$this->assertSame( [ 101, 102 ], array_column( $this->list()['documents'], 'id' ) );
	}

	/**
	 * `edit_posts` is a site-wide primitive: holding it does not mean the caller
	 * may edit every document a query happens to match. A match they cannot edit
	 * is omitted rather than listed-then-refused, because naming a draft page is
	 * already a disclosure — the rule MediaList and ContentList already apply.
	 */
	public function test_a_document_the_caller_cannot_edit_is_omitted(): void {
		$this->withElementor();

		$this->editable = [ 102 ];

		$this->assertSame( [ 102 ], array_column( $this->list()['documents'], 'id' ) );
	}

	public function test_no_editable_match_yields_an_empty_document_list_not_a_refusal(): void {
		$this->withElementor();

		$this->editable = [];
		$result         = $this->list();

		$this->assertSame( [], $result['documents'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	// ------------------------------------------------------------- pagination

	public function test_limit_and_offset_are_echoed_and_total_is_the_unpaginated_count(): void {
		$this->withElementor();

		$result = $this->list(
			[
				'limit'  => 25,
				'offset' => 50,
			]
		);

		$this->assertSame( 25, $result['limit'] );
		$this->assertSame( 50, $result['offset'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	/**
	 * The bound exists from this first commit rather than being deferred, which
	 * is the whole point of REQ-0032's pagination note: a site with 5,000
	 * Elementor documents is ordinary, and the menus module's unbounded listing
	 * is an open defect precisely because it shipped without this.
	 */
	public function test_an_absent_limit_and_offset_still_bound_the_query(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertSame( 20, $args['posts_per_page'] );
		$this->assertSame( 0, $args['offset'] );
		$this->assertNotSame( -1, $args['posts_per_page'], 'posts_per_page -1 is WP_Query\'s unbounded form and must never be reachable here.' );
	}

	/**
	 * THE DOCUMENTED PAGINATION DECISION: an over-large page size is CLAMPED,
	 * not refused. The rationale is on ElementorDocumentList::MAX_LIMIT. What is
	 * pinned here is that the clamp is observable — the echoed `limit` reports
	 * what the caller actually got, so a client that asked for 5,000 can tell it
	 * received 100 without inspecting the array length.
	 */
	public function test_an_oversized_limit_is_clamped_and_reported_rather_than_refused(): void {
		$this->withElementor();

		$result = $this->list( [ 'limit' => 5000 ] );

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 100, FakeWpQuery::$calls[0]['posts_per_page'] );
	}

	public function test_a_negative_offset_is_floored_and_a_limit_below_one_is_raised(): void {
		$this->withElementor();

		$result = $this->list(
			[
				'limit'  => 0,
				'offset' => -10,
			]
		);

		$this->assertSame( 1, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	// ------------------------------------------------------------------ query

	public function test_the_query_is_restricted_to_the_three_document_post_types(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'page', 'post', 'elementor_library' ],
			$this->capturedQueryArgs()['post_type']
		);
	}

	/**
	 * Detection of "Elementor controls this document" is the PRESENCE of
	 * `_elementor_edit_mode`, expressed through WP_Query's meta arguments. The
	 * comparison must be EXISTS rather than an equality test against 'builder':
	 * a document saved by an older Elementor, or by a third party writing the
	 * meta with a different mode, is still an Elementor document.
	 */
	public function test_the_query_selects_documents_by_the_presence_of_the_edit_mode_meta(): void {
		$this->withElementor();

		$meta_query = $this->capturedQueryArgs()['meta_query'];

		$this->assertSame( ElementorDocument::META_EDIT_MODE, $meta_query[0]['key'] );
		$this->assertSame( 'EXISTS', $meta_query[0]['compare'] );
	}

	/**
	 * No `$wpdb` and no raw SQL anywhere in this module (Global Constraints).
	 * There is no direct way to assert the absence of a global, so the positive
	 * form is asserted instead: the operation made exactly one WP_Query and got
	 * its whole answer from it, including the unpaginated total.
	 */
	public function test_the_operation_makes_exactly_one_wp_query_and_no_second_counting_query(): void {
		$this->withElementor();

		$this->list();

		$this->assertCount( 1, FakeWpQuery::$calls );
	}

	/**
	 * Identical site state must produce an identical response, so the ordering
	 * has to be a TOTAL order. `modified DESC` alone is not one: two documents
	 * saved in the same second tie, and the tie is broken by whatever order the
	 * database returns, which is not stable across pages.
	 */
	public function test_results_are_ordered_by_a_total_order_so_paging_is_stable(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertSame(
			[
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			$args['orderby']
		);
	}

	/**
	 * A sticky post is hoisted to the front of a default query, which would
	 * silently contradict the ordering the operation advertises.
	 */
	public function test_sticky_posts_cannot_displace_the_declared_ordering(): void {
		$this->withElementor();

		$this->assertTrue( $this->capturedQueryArgs()['ignore_sticky_posts'] );
	}

	/**
	 * The summary's `editMode` is post meta and is read once per row, so the
	 * meta cache is primed for the page. No summary field is a term.
	 */
	public function test_the_query_primes_the_meta_cache_the_summary_reads_but_not_terms(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertTrue( $args['update_post_meta_cache'] );
		$this->assertFalse( $args['update_post_term_cache'] );
	}

	/**
	 * The caller shapes nothing but the page. A search term, a taxonomy filter,
	 * or a second meta clause would be a caller-shaped query surface pointed at
	 * the database, and REQ-0032 asks for none of them.
	 */
	public function test_no_caller_supplied_term_reaches_the_query(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertArrayNotHasKey( 'date_query', $args );
		$this->assertCount( 1, $args['meta_query'], 'The meta query is owned by the operation and carries exactly the one EXISTS clause.' );
	}

	// -------------------------------------------------------------- refusals

	/**
	 * THE ORDERING TEST. Capability is checked BEFORE any query runs, which is
	 * the ordering every menus operation was mutation-proven on.
	 *
	 * Asserting only that a refusal happens would pass either way — the refusal
	 * is thrown whether the check sits above or below the query. So the load-
	 * bearing assertion is that NO query was constructed: move the query ahead
	 * of the capability check and FakeWpQuery::$calls is no longer empty and
	 * this test fails, while the refusal assertions keep passing.
	 */
	public function test_a_caller_without_edit_posts_is_refused_before_any_query_runs(): void {
		$this->withElementor();

		$this->mayEditPosts = false;

		try {
			$this->list();
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}

		$this->assertSame(
			[],
			FakeWpQuery::$calls,
			'The capability check must run BEFORE the query. A query constructed here means an unauthorized caller caused a database read.'
		);
	}

	/**
	 * The capability check also precedes the Elementor presence check, so a
	 * caller with no rights learns nothing about which plugins the site runs.
	 */
	public function test_the_capability_check_precedes_the_elementor_presence_check(): void {
		// Elementor deliberately NOT installed in this process, so both refusal
		// conditions hold at once and only the ordering decides which is raised.
		$this->mayEditPosts = false;

		try {
			$this->list();
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * Elementor absent is the ORDINARY state of most WordPress sites, not an
	 * exceptional one, and this is the first SiteHelm module whose dependency is
	 * a third-party plugin. The call must refuse through an existing error code
	 * and must never fatal on an unguarded `\Elementor\` symbol.
	 */
	public function test_a_site_without_elementor_refuses_cleanly_and_runs_no_query(): void {
		// No constant defined and no class aliased in this isolated process.
		try {
			$this->list();
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation );
			$this->assertStringContainsStringIgnoringCase( 'elementor', (string) $e->remediation );
		}

		$this->assertSame( [], FakeWpQuery::$calls, 'A refused call must not have queried the database.' );
	}

	/**
	 * No envelope may expose secrets, filesystem paths, SQL, or stack traces.
	 */
	public function test_no_refusal_message_names_a_table_a_path_or_a_query(): void {
		$this->mayEditPosts = true;

		try {
			$this->list();
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( 'SELECT', $text );
			$this->assertStringNotContainsString( 'wp_post', $text );
			$this->assertStringNotContainsString( '/', $text );
		}
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ElementorDocumentList::definition();

		$this->assertSame( 'elementor-document-list', $definition->id );
		$this->assertSame( 'elementor-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Both ranges, not one. OperationDefinition throws without the plugin range
	 * for a plugin-backed module, so the WordPress range alone cannot even be
	 * constructed — what this pins is that the Elementor range is the module's
	 * own floor rather than a literal that could drift away from it.
	 */
	public function test_the_definition_carries_both_the_wordpress_and_elementor_ranges(): void {
		$versions = ElementorDocumentList::definition()->supportedVersions;

		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . ElementorPresence::MIN_VERSION, $versions['elementor'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->list();
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-document-list' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks
 * and `class_exists()` is the only thing that answers reads. It deliberately
 * models no `$instance` singleton, no widget manager, and no document API. A
 * test that needs any of those must not build on this. (class_alias() refuses an
 * internal class, which is why this exists at all rather than aliasing stdClass.)
 */
final class ElementorPluginStandInForList {
}
