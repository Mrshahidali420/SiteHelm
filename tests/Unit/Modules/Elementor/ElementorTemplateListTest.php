<?php
/**
 * Tests for ElementorTemplateList (REQ-0102).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTemplateLibrary;
use SiteHelm\Modules\Elementor\ElementorTemplateList;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0102: the listing of this site's saved library templates.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). FakeWpQuery reproduces exactly three
 * upstream facts: that constructing a query executes it, that `$posts` holds the
 * matched rows, and that `$found_posts` holds the UNPAGINATED count. It reproduces
 * nothing about turning arguments into SQL — it does not honour `post_type`, does
 * not apply `meta_query`, does not page and does not order. So no assertion below
 * claims the query "returned only sections": the filtering assertions are made
 * against the ARGUMENTS the operation handed the query, which is the only place
 * that behaviour lives in production.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process, so
 * installing them in the shared process would give every later test a site that
 * has Elementor.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTemplateListTest extends TestCase {

	private ElementorTemplateList $handler;

	/**
	 * The unpaginated total the fake query reports, deliberately equal to neither
	 * the row count nor any page size used below.
	 */
	private int $foundPosts = 213;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 201, 202 ];

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

		$this->handler = new ElementorTemplateList(
			new ElementorFields(),
			new ElementorThemeConditions(),
			new ElementorPresence()
		);

		$this->mayEditPosts = true;
		$this->editable     = [ 201, 202 ];
		$this->rows         = [
			$this->makeRow( 201, 'Pricing section', 'publish' ),
			$this->makeRow( 202, 'Campaign header', 'draft' ),
		];
		$this->meta = [
			201 => [ ElementorThemeConditions::META_TYPE => 'section' ],
			202 => [ ElementorThemeConditions::META_TYPE => 'header' ],
		];

		FakeWpQuery::$calls = [];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForTemplateList::class, 'Elementor\Plugin' );
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
	private function listing( array $input = [] ): array {
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
		$this->listing( $input );

		return FakeWpQuery::$calls[0];
	}

	// ---------------------------------------------------------------- payload

	public function test_a_row_carries_exactly_the_five_declared_fields(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'id', 'title', 'status', 'templateType', 'takesConditions' ],
			array_keys( $this->listing()['templates'][0] )
		);
	}

	public function test_a_row_reports_the_stored_template_type(): void {
		$this->withElementor();

		$row = $this->listing()['templates'][0];

		$this->assertSame( 201, $row['id'] );
		$this->assertSame( 'Pricing section', $row['title'] );
		$this->assertSame( 'publish', $row['status'] );
		$this->assertSame( 'section', $row['templateType'] );
	}

	/**
	 * A saved section has no display conditions and never will, so reporting a
	 * condition count of zero for it would read as "displays nowhere" when the
	 * honest answer is that the question does not apply.
	 */
	public function test_takes_conditions_separates_theme_documents_from_saved_content(): void {
		$this->withElementor();

		$rows = $this->listing()['templates'];

		$this->assertFalse( $rows[0]['takesConditions'] );
		$this->assertTrue( $rows[1]['takesConditions'] );
	}

	public function test_the_total_is_the_unpaginated_count_the_query_reported(): void {
		$this->withElementor();

		$this->assertSame( $this->foundPosts, $this->listing()['total'] );
	}

	// ------------------------------------------------------------------ paging

	public function test_the_page_defaults_are_reported_back_to_the_caller(): void {
		$this->withElementor();

		$result = $this->listing();

		$this->assertSame( 20, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	public function test_an_oversized_limit_is_clamped_rather_than_refused(): void {
		$this->withElementor();

		$this->assertSame( 100, $this->listing( [ 'limit' => 5000 ] )['limit'] );
	}

	public function test_a_negative_offset_floors_at_the_first_page(): void {
		$this->withElementor();

		$this->assertSame( 0, $this->listing( [ 'offset' => -40 ] )['offset'] );
	}

	public function test_the_clamped_page_is_the_page_the_query_was_asked_for(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs(
			[
				'limit'  => 5000,
				'offset' => 12,
			]
		);

		$this->assertSame( 100, $args['posts_per_page'] );
		$this->assertSame( 12, $args['offset'] );
	}

	// ------------------------------------------------------------------- query

	/**
	 * Filtering after paging would leave `total` counting templates the caller
	 * asked to exclude, so a paging client would be told there were more matches
	 * than exist and would receive short pages with no explanation.
	 */
	public function test_a_type_filter_reaches_the_query_rather_than_being_applied_after_paging(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs( [ 'type' => 'section' ] );

		$this->assertSame(
			[
				[
					'key'     => ElementorThemeConditions::META_TYPE,
					'value'   => 'section',
					'compare' => '=',
				],
			],
			$args['meta_query']
		);
	}

	public function test_no_type_filter_leaves_the_query_unconstrained(): void {
		$this->withElementor();

		$this->assertArrayNotHasKey( 'meta_query', $this->capturedQueryArgs() );
	}

	public function test_the_listing_asks_only_for_library_posts_of_any_status(): void {
		$this->withElementor();

		$args = $this->capturedQueryArgs();

		$this->assertSame( ElementorFields::LIBRARY_POST_TYPE, $args['post_type'] );
		$this->assertSame( 'any', $args['post_status'] );
	}

	/**
	 * A partial order lets a paging client see one template twice while missing
	 * another, because rows sharing a modified date may come back in any order.
	 */
	public function test_the_order_is_total_so_paging_cannot_repeat_or_skip_a_row(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			$this->capturedQueryArgs()['orderby']
		);
	}

	// ------------------------------------------------------------------ guards

	public function test_a_caller_who_may_not_edit_posts_is_refused_before_the_query_runs(): void {
		$this->withElementor();
		$this->mayEditPosts = false;

		try {
			$this->listing();
			$this->fail( 'Expected the listing to refuse a caller without edit_posts.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [], FakeWpQuery::$calls );
	}

	/**
	 * The capability is checked before presence, so an unauthorised caller learns
	 * nothing about which integrations this site runs.
	 */
	public function test_the_capability_is_checked_before_elementor_presence(): void {
		$this->mayEditPosts = false;

		try {
			$this->listing();
			$this->fail( 'Expected the listing to refuse a caller without edit_posts.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	public function test_a_site_without_elementor_refuses_rather_than_listing_nothing(): void {
		try {
			$this->listing();
			$this->fail( 'Expected the listing to refuse on a site without Elementor.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], FakeWpQuery::$calls );
	}

	/**
	 * The schema already carries the enum. The handler re-checks it because it must
	 * be correct when reached without the schema in front of it.
	 */
	public function test_an_unrecognised_type_is_refused_by_the_handler_itself(): void {
		$this->withElementor();

		try {
			$this->listing( [ 'type' => 'carousel' ] );
			$this->fail( 'Expected the listing to refuse an unrecognised type.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_every_recognised_type_is_accepted(): void {
		$this->withElementor();

		foreach ( ElementorTemplateLibrary::allTypes() as $type ) {
			$this->assertIsArray( $this->listing( [ 'type' => $type ] ) );
		}
	}

	/**
	 * Naming an unpublished template is already a disclosure of content the caller
	 * has no rights to, which is why every listing in this plugin omits.
	 */
	public function test_a_template_the_caller_may_not_edit_is_omitted_rather_than_refused(): void {
		$this->withElementor();
		$this->editable = [ 201 ];

		$rows = $this->listing()['templates'];

		$this->assertCount( 1, $rows );
		$this->assertSame( 201, $rows[0]['id'] );
	}

	public function test_a_row_without_a_usable_identifier_is_skipped(): void {
		$this->withElementor();

		$broken     = new stdClass();
		$broken->ID = 0;

		$this->rows = [ $broken, $this->makeRow( 201, 'Pricing section', 'publish' ) ];

		$rows = $this->listing()['templates'];

		$this->assertCount( 1, $rows );
		$this->assertSame( 201, $rows[0]['id'] );
	}

	// ------------------------------------------------------------------ schema

	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->listing();
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-template-list' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks.
 */
final class ElementorPluginStandInForTemplateList {
}
