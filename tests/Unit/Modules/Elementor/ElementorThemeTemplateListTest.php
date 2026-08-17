<?php
/**
 * Tests for ElementorThemeTemplateList (REQ-0080).
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
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorThemeTemplateList;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0080: the listing of this site's theme-builder templates.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). FakeWpQuery reproduces exactly three
 * upstream facts: that constructing a query executes it, that `$posts` holds the
 * matched rows, and that `$found_posts` holds the UNPAGINATED count. It reproduces
 * NOTHING about turning arguments into SQL — it does not honour `post_type`, does
 * not apply `meta_query`, does not page and does not order. So no assertion below
 * may claim the query "returned only theme templates": the filtering assertions
 * are made against the ARGUMENTS the operation handed the query, which is the only
 * place that behaviour lives in production.
 *
 * The Elementor stand-in installed by withElementor() reproduces exactly the two
 * facts `ElementorPresence::isLoaded()` reads. It models no Elementor API, because
 * this operation calls none.
 *
 * PROCESS ISOLATION IS LOAD-BEARING for the same reason as
 * ElementorDocumentListTest: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of the process,
 * so a test that installs them in the shared process makes every later test run
 * against a site that has Elementor.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorThemeTemplateListTest extends TestCase {

	private ElementorThemeTemplateList $handler;

	/**
	 * The unpaginated total the fake query reports, deliberately equal to neither
	 * the row count nor any page size used below.
	 */
	private int $foundPosts = 137;

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
	 * The single-value meta store, keyed by post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	/**
	 * The rows the fake query reports.
	 *
	 * @var stdClass[]
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new ElementorThemeTemplateList(
			new ElementorFields(),
			new ElementorThemeConditions(),
			new ElementorPresence()
		);

		$this->mayEditPosts = true;
		$this->editable     = [ 101, 102 ];
		$this->rows         = [
			$this->makeRow( 101, 'Site Header', 'publish' ),
			$this->makeRow( 102, 'Blog Archive', 'draft' ),
		];
		$this->meta = [
			101 => [
				ElementorThemeConditions::META_TYPE       => 'header',
				ElementorThemeConditions::META_CONDITIONS => [ 'include/general', 'exclude/singular/page/12' ],
			],
			102 => [
				ElementorThemeConditions::META_TYPE => 'archive',
			],
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
			class_alias( ElementorPluginStandInForThemeList::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	private function makeRow( int $id, string $title, string $status ): stdClass {
		$row              = new stdClass();
		$row->ID          = $id;
		$row->post_type   = 'elementor_library';
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
			function ( int $id, string $key, bool $single = false ): mixed {
				if ( ! array_key_exists( $key, $this->meta[ $id ] ?? [] ) ) {
					return $single ? '' : [];
				}

				return $single ? $this->meta[ $id ][ $key ] : [ $this->meta[ $id ][ $key ] ];
			}
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

	public function test_a_row_carries_exactly_the_six_declared_fields(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'id', 'title', 'status', 'templateType', 'conditions', 'conditionCount' ],
			array_keys( $this->list()['templates'][0] )
		);
	}

	/**
	 * The type and the conditions are the whole reason this listing exists beside
	 * `elementor-document-list`: a site can hold four headers, three of which apply
	 * nowhere, and the document listing cannot tell them apart.
	 */
	public function test_a_row_reports_the_template_type_and_its_stored_conditions(): void {
		$this->withElementor();

		$row = $this->list()['templates'][0];

		$this->assertSame( 101, $row['id'] );
		$this->assertSame( 'Site Header', $row['title'] );
		$this->assertSame( 'publish', $row['status'] );
		$this->assertSame( 'header', $row['templateType'] );
		$this->assertSame( [ 'include/general', 'exclude/singular/page/12' ], $row['conditions'] );
		$this->assertSame( 2, $row['conditionCount'] );
	}

	/**
	 * A theme template with no conditions is the ordinary state of a template
	 * somebody built and never attached, and it is an ANSWER — the one this listing
	 * exists to give. It must not be omitted and must not be a refusal.
	 */
	public function test_a_template_with_no_conditions_is_listed_with_an_empty_list(): void {
		$this->withElementor();

		$row = $this->list()['templates'][1];

		$this->assertSame( 102, $row['id'] );
		$this->assertSame( [], $row['conditions'] );
		$this->assertSame( 0, $row['conditionCount'] );
	}

	/**
	 * A row the query matched but which no longer carries a usable identifier — a
	 * filtered `posts` array, a partially built row — must be omitted rather than
	 * emitted as a row of zeroes.
	 */
	public function test_a_matched_row_without_a_usable_identifier_is_omitted(): void {
		$this->withElementor();

		$this->rows[0]->ID = 0;

		$this->assertSame( [ 102 ], array_column( $this->list()['templates'], 'id' ) );
	}

	/**
	 * `the_posts` is a public filter, so the rows a query returns are whatever the
	 * last plugin in the chain left behind — including a scalar. A `(string)` cast
	 * of an array is a fatal, and a fatal inside the gateway is a 500 with no error
	 * code, which the dispatcher contract forbids.
	 */
	public function test_a_row_that_is_not_a_post_object_is_omitted_rather_than_fataling(): void {
		$this->withElementor();

		$this->rows[] = 'a string a plugin left in the_posts';

		$this->assertSame( [ 101, 102 ], array_column( $this->list()['templates'], 'id' ) );
	}

	/**
	 * `edit_posts` is a site-wide primitive: holding it does not mean the caller may
	 * edit every template a query happens to match. A match they cannot edit is
	 * OMITTED rather than listed-then-refused, because naming an unpublished
	 * template is already a disclosure — the rule every listing in this plugin
	 * applies.
	 */
	public function test_a_template_the_caller_cannot_edit_is_omitted(): void {
		$this->withElementor();

		$this->editable = [ 102 ];

		$this->assertSame( [ 102 ], array_column( $this->list()['templates'], 'id' ) );
	}

	/**
	 * A site running Elementor without Elementor Pro has no theme templates at all,
	 * and that is the state of most Elementor sites. It is an answer, not a failure.
	 */
	public function test_a_site_with_no_theme_templates_reports_an_empty_list_not_a_refusal(): void {
		$this->withElementor();

		$this->rows       = [];
		$this->foundPosts = 0;

		$result = $this->list();

		$this->assertSame( [], $result['templates'] );
		$this->assertSame( 0, $result['total'] );
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

	public function test_an_absent_limit_and_offset_still_bound_the_query(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertSame( 20, $args['posts_per_page'] );
		$this->assertSame( 0, $args['offset'] );
		$this->assertNotSame( -1, $args['posts_per_page'], 'posts_per_page -1 is WP_Query\'s unbounded form and must never be reachable here.' );
	}

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

	public function test_the_query_is_restricted_to_the_library_post_type(): void {
		$this->withElementor();

		$this->assertSame( 'elementor_library', $this->capturedQueryArgs()['post_type'] );
	}

	/**
	 * THE FILTER LIVES IN THE QUERY, NOT AFTER PAGING, and that is load-bearing
	 * rather than an optimisation: filtering a page in PHP would make `total` count
	 * the saved sections and popups too, so a caller paging through would be told
	 * there were more theme templates than exist and would receive short pages with
	 * no explanation.
	 */
	public function test_the_query_selects_theme_documents_by_the_stored_template_type(): void {
		$this->withElementor();

		$meta_query = $this->capturedQueryArgs()['meta_query'];

		$this->assertSame( ElementorThemeConditions::META_TYPE, $meta_query[0]['key'] );
		$this->assertSame( 'IN', $meta_query[0]['compare'] );
		$this->assertSame( ElementorThemeConditions::THEME_TYPES, $meta_query[0]['value'] );
	}

	/**
	 * The clause is OWNED BY THE OPERATION and carries no caller input, which is
	 * what separates it from a caller-shaped meta query — a surface this module
	 * refuses on principle.
	 */
	public function test_no_caller_supplied_term_reaches_the_query(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs(
			[
				'limit'  => 5,
				'offset' => 1,
			]
		);

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertArrayNotHasKey( 'date_query', $args );
		$this->assertCount( 1, $args['meta_query'], 'The meta query is owned by the operation and carries exactly the one type clause.' );
	}

	/**
	 * A theme template can be a draft, and a draft displays nowhere whatever its
	 * conditions say — which is precisely the misconfiguration this listing exists
	 * to surface. Restricting to published templates would hide it.
	 */
	public function test_the_query_does_not_restrict_the_post_status(): void {
		$this->withElementor();

		$this->assertSame( 'any', $this->capturedQueryArgs()['post_status'] );
	}

	/**
	 * Identical site state must produce an identical response, so the order has to
	 * be a TOTAL one. `modified DESC` alone is not: two templates saved in the same
	 * second tie, and the tie is broken by whatever order the database returns,
	 * which is not stable across pages.
	 */
	public function test_results_are_ordered_by_a_total_order_so_paging_is_stable(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			$this->capturedQueryArgs()['orderby']
		);
	}

	/**
	 * No `$wpdb` and no raw SQL anywhere in this module (Global Constraints). There
	 * is no direct way to assert the absence of a global, so the positive form is
	 * asserted: the operation made exactly one query and got its whole answer from
	 * it, including the unpaginated total.
	 */
	public function test_the_operation_makes_exactly_one_query_and_no_second_counting_query(): void {
		$this->withElementor();

		$this->list();

		$this->assertCount( 1, FakeWpQuery::$calls );
	}

	public function test_the_query_primes_the_meta_cache_the_rows_read_but_not_terms(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertTrue( $args['update_post_meta_cache'] );
		$this->assertFalse( $args['update_post_term_cache'] );
	}

	// -------------------------------------------------------------- refusals

	/**
	 * THE ORDERING TEST. Asserting only that a refusal happens would pass whether
	 * the capability check sits above or below the query, so the load-bearing
	 * assertion is that NO query was constructed.
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
	 * The capability check precedes the presence check, so a caller with no rights
	 * learns nothing about which plugins the site runs.
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

	public function test_a_site_without_elementor_refuses_cleanly_and_runs_no_query(): void {
		// No constant defined and no class aliased in this isolated process.
		try {
			$this->list();
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}

		$this->assertSame( [], FakeWpQuery::$calls, 'A refused call must not have queried the database.' );
	}

	/**
	 * No envelope may expose secrets, filesystem paths, SQL, or stack traces.
	 */
	public function test_no_refusal_message_names_a_table_a_path_or_a_query(): void {
		try {
			$this->list();
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( 'SELECT', $text );
			$this->assertStringNotContainsString( 'wp_post', $text );
			$this->assertStringNotContainsString( '_elementor_', $text );
			$this->assertStringNotContainsString( '/', $text );
		}
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_the_read_shape_the_catalog_requires(): void {
		$definition = ElementorThemeTemplateList::definition();

		$this->assertSame( 'elementor-theme-template-list', $definition->id );
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

	public function test_the_definition_carries_both_the_wordpress_and_elementor_ranges(): void {
		$versions = ElementorThemeTemplateList::definition()->supportedVersions;

		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . ElementorPresence::MIN_VERSION, $versions['elementor'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The schema
	 * is read from the registered definition rather than restated, so the test
	 * cannot pass against a schema that has since drifted.
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
			$registry->definition( 'elementor-theme-template-list' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks.
 * It deliberately models no singleton, no widget manager and no document API.
 */
final class ElementorPluginStandInForThemeList {
}
