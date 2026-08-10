<?php
/**
 * Tests for ElementorDocumentGet (REQ-0033).
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
use SiteHelm\Modules\Elementor\ElementorDocumentGet;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0033: the normalized element tree for one Elementor document.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Three doubles are in play, and each
 * states here exactly which upstream behaviours it reproduces and which it
 * deliberately does not, because Phase 5 shipped a data-loss bug behind a double
 * that was faithful to every derivation except the one under test.
 *
 * 1. THE POST STORE — `get_post()` and `get_post_meta()`, stubbed together from
 *    the `$posts`, `$data` and `$editModes` properties below. It reproduces
 *    exactly four upstream facts: that `get_post()` answers null for an
 *    identifier no post carries; that it otherwise answers an object carrying
 *    the four columns the summary projects; that `get_post_meta( id, key, true )`
 *    answers the single stored value; and that an absent meta row answers `''`
 *    rather than null, which is what WordPress actually does and what
 *    ElementorDocument's "absent and malformed are different answers" rule is
 *    written against. It reproduces NOTHING else: no capability filtering inside
 *    `get_post`, no post-status visibility, no autosave or revision handling, no
 *    meta cache, no `the_post` filter. Every stored value is served exactly as
 *    written, which is the property the malformed-JSON cases depend on.
 *
 * 2. `wp_unslash()` — reproduces ONLY `stripslashes_deep()`'s behaviour on a
 *    string, because ElementorDocument's decoder reaches it only on a string and
 *    only after a first decode has already failed. It does not walk arrays or
 *    objects. This double is faithful on the one rule that matters here: a value
 *    that is NOT valid JSON after unslashing must stay invalid, so a malformed
 *    document cannot be rescued into a partial tree.
 *
 * 3. THE ELEMENTOR STAND-IN installed by withElementor() — reproduces exactly the
 *    two facts `ElementorPresence::isLoaded()` reads: that `ELEMENTOR_VERSION` is
 *    defined and that a class named `Elementor\Plugin` exists. It models NO
 *    Elementor API at all: no plugin singleton, no widget manager, and above all
 *    NO DOCUMENT API — which is not an omission but the design (spec Decision 1).
 *    This operation reads stored post meta and must never call
 *    `get_elements_data()`; a stand-in that offered one would let such a call be
 *    written and still pass.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, for the reason ElementorDocumentListTest
 * records: `ELEMENTOR_VERSION` is a constant and `Elementor\Plugin` is a class
 * alias, both permanent for the life of the process. Every test here runs in its
 * own process and the ones that need Elementor say so by calling withElementor().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDocumentGetTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const DOCUMENT_ID = 101;

	private ElementorDocumentGet $handler;

	/**
	 * Whether user_can( 'edit_post', … ) approves the caller for DOCUMENT_ID.
	 */
	private bool $mayEditDocument = true;

	/**
	 * The post rows `get_post()` serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $posts = [];

	/**
	 * The stored `_elementor_data` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $data = [];

	/**
	 * The stored `_elementor_edit_mode` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $editModes = [];

	/**
	 * Every post lookup the operation made, in order.
	 *
	 * This is what makes the ordering test below able to fail: a refusal alone
	 * is thrown whether the capability check sits above or below the lookup, so
	 * the load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler         = new ElementorDocumentGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorPresence()
		);
		$this->mayEditDocument = true;
		$this->lookups         = [];
		$this->posts           = [ self::DOCUMENT_ID => $this->makeRow( self::DOCUMENT_ID, 'page', 'Home', 'publish' ) ];
		$this->editModes       = [ self::DOCUMENT_ID => 'builder' ];
		$this->data            = [ self::DOCUMENT_ID => $this->encode( $this->sampleTree() ) ];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForGet::class, 'Elementor\Plugin' );
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

	/**
	 * Encodes a tree the way Elementor stores one: JSON, then slashed.
	 *
	 * @param array<int, mixed> $tree The raw element list.
	 *
	 * @return string The stored value.
	 */
	private function encode( array $tree ): string {
		return addslashes( (string) json_encode( $tree, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * A container holding a container holding two widgets: three levels, so the
	 * deepest node sits at depth 2 and `maxDepth` must report 3.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function sampleTree(): array {
		return [
			[
				'id'       => 'aaa111',
				'elType'   => 'container',
				'settings' => [ 'background_color' => '#fff' ],
				'elements' => [
					[
						'id'       => 'bbb222',
						'elType'   => 'container',
						'settings' => [ 'padding' => 20 ],
						'elements' => [
							[
								'id'         => 'ccc333',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Secret internal note' ],
								'elements'   => [],
							],
							[
								'id'         => 'ddd444',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Second' ],
								'elements'   => [],
							],
						],
					],
				],
			],
		];
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && self::DOCUMENT_ID === $post_id && $this->mayEditDocument
		);
		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				$this->lookups[] = 'get_post';

				return $this->posts[ $id ] ?? null;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				$this->lookups[] = 'get_post_meta:' . $key;

				if ( ElementorDocument::META_DATA === $key ) {
					return $this->data[ $id ] ?? '';
				}

				return ElementorDocument::META_EDIT_MODE === $key ? ( $this->editModes[ $id ] ?? '' ) : '';
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			static fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value
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
	 * Runs the operation.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function get( array $input = [ 'id' => self::DOCUMENT_ID ] ): array {
		return $this->handler->handle( $input, $this->makeContext() );
	}

	/**
	 * Every node in a result, flattened depth-first.
	 *
	 * @param array<int, mixed> $nodes The normalized node list.
	 *
	 * @return array<int, array<string, mixed>> Every node at every level.
	 */
	private function flatten( array $nodes ): array {
		$flat = [];

		foreach ( $nodes as $node ) {
			$flat[] = $node;
			$flat   = array_merge( $flat, $this->flatten( $node['children'] ) );
		}

		return $flat;
	}

	// ---------------------------------------------------------------- payload

	public function test_the_response_carries_the_document_summary_the_nodes_and_the_totals(): void {
		$this->withElementor();

		$this->assertSame( [ 'document', 'nodes', 'totals' ], array_keys( $this->get() ) );
	}

	public function test_the_document_summary_is_the_shared_projection_the_listing_also_returns(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'id'       => self::DOCUMENT_ID,
				'type'     => 'page',
				'title'    => 'Home',
				'status'   => 'publish',
				'editMode' => 'builder',
			],
			$this->get()['document']
		);
	}

	/**
	 * REQ-0033's acceptance names STABLE identifiers, so Elementor's own element
	 * ids are carried through unchanged rather than re-minted on read. A Phase 6b
	 * write names an element by this id, and an id invented during a read names
	 * nothing on the next one.
	 */
	public function test_element_identifiers_are_elementors_own_and_are_never_reminted(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'aaa111', 'bbb222', 'ccc333', 'ddd444' ],
			array_column( $this->flatten( $this->get()['nodes'] ), 'id' )
		);
	}

	/**
	 * The frozen node shape (spec Decision 4), asserted member by member at every
	 * level rather than on the root alone: a normalizer that produced the eight
	 * members at the top and something else below would still satisfy a root-only
	 * check, and a Phase 6b diff walks every level.
	 */
	public function test_every_node_at_every_level_carries_exactly_the_frozen_eight_members(): void {
		$this->withElementor();

		foreach ( $this->flatten( $this->get()['nodes'] ) as $node ) {
			$this->assertSame(
				[ 'id', 'elType', 'widgetType', 'kind', 'label', 'depth', 'childCount', 'children' ],
				array_keys( $node )
			);
		}
	}

	/**
	 * `settings` ARE NOT RETURNED (spec Decision 4). They are large, they may
	 * carry arbitrary third-party data, and the sample tree deliberately puts a
	 * string in one that must not appear in any envelope. Asserted over the
	 * flattened tree and over the encoded response together, so a settings blob
	 * smuggled under any other key is still caught.
	 */
	public function test_no_element_settings_reach_the_response(): void {
		$this->withElementor();

		$result = $this->get();

		foreach ( $this->flatten( $result['nodes'] ) as $node ) {
			$this->assertArrayNotHasKey( 'settings', $node );
		}

		$encoded = (string) json_encode( $result, JSON_THROW_ON_ERROR );

		$this->assertStringNotContainsString( 'Secret internal note', $encoded );
		$this->assertStringNotContainsString( 'background_color', $encoded );
	}

	/**
	 * `totals.maxDepth` COUNTS LEVELS, not the greatest zero-based `depth`. The
	 * sample tree's deepest node sits at depth 2, so maxDepth is 3. The two
	 * assertions are made together on purpose: asserting the level count alone
	 * would pass against an off-by-one that also shifted `depth`.
	 */
	public function test_totals_max_depth_counts_levels_while_node_depth_stays_zero_based(): void {
		$this->withElementor();

		$result = $this->get();
		$depths = array_column( $this->flatten( $result['nodes'] ), 'depth' );

		$this->assertSame( [ 0, 1, 2, 2 ], $depths );
		$this->assertSame( 3, $result['totals']['maxDepth'] );
	}

	public function test_totals_count_every_node_and_every_widget_type(): void {
		$this->withElementor();

		$totals = $this->get()['totals'];

		$this->assertSame( 4, $totals['nodeCount'] );
		$this->assertSame( [ 'heading' => 2 ], $totals['widgetTypeCounts'] );
	}

	/**
	 * A document Elementor controls but has never had content saved into answers
	 * an empty tree rather than refusing: "this page is empty" is the answer to
	 * the question asked, and an empty tree reports ZERO levels, not one.
	 */
	public function test_a_document_with_no_stored_content_answers_an_empty_tree_and_zero_totals(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = '';

		$result = $this->get();

		$this->assertSame( [], $result['nodes'] );
		$this->assertSame( 0, $result['totals']['nodeCount'] );
		$this->assertSame( 0, $result['totals']['maxDepth'] );
		$this->assertSame( [], $result['totals']['widgetTypeCounts'] );
	}

	/**
	 * The label is DERIVED — computed from `elType` and `widgetType` on every
	 * read, stored nowhere. It is asserted here so that its value is visibly a
	 * derivation of the two members beside it rather than anything a row holds,
	 * which is the property Phase 6b's snapshot must never mistake for state.
	 */
	public function test_the_label_is_derived_from_the_element_type_rather_than_from_stored_content(): void {
		$this->withElementor();

		$nodes = $this->flatten( $this->get()['nodes'] );

		$this->assertSame( 'container', $nodes[0]['label'] );
		$this->assertSame( 'heading', $nodes[2]['label'] );
		$this->assertSame( $nodes[2]['widgetType'], $nodes[2]['label'] );
	}

	// -------------------------------------------------------------- refusals

	/**
	 * THE ORDERING TEST. `edit_post` is checked BEFORE the document is looked up,
	 * the ordering every menus operation and Task 2's listing were mutation-proven
	 * on.
	 *
	 * Asserting only that a refusal happens would pass either way — the refusal is
	 * thrown whether the check sits above or below the lookup. So the load-bearing
	 * assertion is that NO lookup was recorded: move `get_post()` or any meta read
	 * ahead of the capability check and $lookups is no longer empty and this test
	 * fails, while the refusal assertions keep passing.
	 */
	public function test_a_caller_without_edit_post_is_refused_before_the_document_is_looked_up(): void {
		$this->withElementor();

		$this->mayEditDocument = false;

		try {
			$this->get();
			$this->fail( 'A caller without edit_post must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}

		$this->assertSame(
			[],
			$this->lookups,
			'The capability check must run BEFORE any lookup. A lookup here means an unauthorized caller caused a database read.'
		);
	}

	/**
	 * The capability check also precedes the Elementor presence check, so a caller
	 * with no rights over the document cannot learn from the difference between
	 * two refusals whether this site runs Elementor — which is site configuration
	 * they are not entitled to.
	 *
	 * Elementor is deliberately NOT installed in this process, so both refusal
	 * conditions hold at once and only the ordering decides which is raised.
	 */
	public function test_the_capability_check_precedes_the_elementor_presence_check(): void {
		$this->mayEditDocument = false;

		try {
			$this->get();
			$this->fail( 'A caller without edit_post must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [], $this->lookups );
	}

	/**
	 * Elementor absent is the ORDINARY state of most WordPress sites. The call
	 * must refuse through an existing code — IntegrationUnavailable, which is what
	 * Task 2's listing already uses for exactly this condition — and must never
	 * fatal on an unguarded `\Elementor\` symbol.
	 */
	public function test_a_site_without_elementor_refuses_as_integration_unavailable_and_reads_nothing(): void {
		try {
			$this->get();
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertStringContainsStringIgnoringCase( 'elementor', (string) $e->remediation );
		}

		$this->assertSame( [], $this->lookups, 'A refused call must not have read the database.' );
	}

	public function test_an_identifier_naming_no_post_refuses_as_target_not_found(): void {
		$this->withElementor();

		$this->mayEditDocument = true;
		$this->posts           = [];

		$this->expectException( OperationException::class );

		try {
			$this->get();
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );

			throw $e;
		}
	}

	/**
	 * A post that exists but that Elementor does not control is a different site
	 * state from a post that does not exist, and both are TargetNotFound: the
	 * operation's target is "an Elementor document", and a plain post is not one.
	 * The remedy names the listing operation, which is the call that answers which
	 * documents Elementor actually controls.
	 */
	public function test_a_post_that_is_not_an_elementor_document_refuses_with_a_remedy_naming_the_listing(): void {
		$this->withElementor();

		$this->editModes[ self::DOCUMENT_ID ] = '';

		try {
			$this->get();
			$this->fail( 'A post Elementor does not control must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			$this->assertStringContainsString( 'elementor-document-list', (string) $e->remediation );
		}
	}

	/**
	 * Malformed stored JSON surfaces as the refusal ElementorDocument raises, NOT
	 * as an empty or partial tree. Reporting a damaged document as an empty one
	 * would let a Phase 6b write replace real content with nothing and report
	 * success.
	 *
	 * `ExecutionFailed` rather than `IntegrationUnavailable` or `InvalidInput`:
	 * the read could not be completed because of stored site state, and it is
	 * retryable, since re-saving the page in the editor clears it. The negative
	 * assertions are there because those two codes are the plausible wrong
	 * answers, and either would send an operator to the wrong remedy.
	 */
	public function test_malformed_stored_json_refuses_rather_than_answering_an_empty_tree(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = '{"not":"a list of elements"';

		try {
			$this->get();
			$this->fail( 'A document whose stored JSON cannot be read must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsStringIgnoringCase( 'editor', (string) $e->remediation );
		}
	}

	/**
	 * A tree nested deeper than ElementorTree::MAX_DEPTH REFUSES. The assertion
	 * that matters is not merely that an exception was raised but that NO tree
	 * came back: a truncated tree that looks complete is the shape that produces a
	 * wrong diff in Phase 6b, and a wrong diff is an approved plan that does not
	 * describe the change being applied.
	 */
	public function test_a_tree_deeper_than_the_bound_refuses_and_returns_no_truncated_tree(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->chain( ElementorTree::MAX_DEPTH + 1 ) );
		$result                          = null;

		try {
			$result = $this->get();
			$this->fail( 'A tree deeper than the bound must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}

		$this->assertNull( $result, 'A breached bound must yield no tree at all, not a shortened one.' );
	}

	/**
	 * The second bound, reached through the operation rather than only through
	 * the normalizer's own unit test — a guard proven only one level down is a
	 * guard nothing shows to be reachable from a real request.
	 */
	public function test_a_tree_with_more_elements_than_the_bound_refuses(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->siblings( ElementorTree::MAX_NODES + 1 ) );

		try {
			$this->get();
			$this->fail( 'A tree larger than the bound must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * A tree exactly at each bound is ACCEPTED. Without this the two tests above
	 * would pass against a guard that refused every tree, and a bound that refuses
	 * the documents it was meant to admit is the same defect class as one that
	 * never fires.
	 */
	public function test_a_tree_exactly_at_each_bound_is_answered_rather_than_refused(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->chain( ElementorTree::MAX_DEPTH ) );

		$this->assertSame( ElementorTree::MAX_DEPTH, $this->get()['totals']['maxDepth'] );

		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->siblings( ElementorTree::MAX_NODES ) );

		$this->assertSame( ElementorTree::MAX_NODES, $this->get()['totals']['nodeCount'] );
	}

	/**
	 * No envelope may expose secrets, filesystem paths, SQL, or stack traces —
	 * and a refusal caused by stored third-party content must not quote that
	 * content back, which is why the malformed value below is one an operator
	 * would recognise if it were echoed.
	 */
	public function test_no_refusal_message_names_a_table_a_path_or_the_stored_content(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = '{"api_key":"s3cr3t-do-not-echo"';

		try {
			$this->get();
			$this->fail( 'A document whose stored JSON cannot be read must be refused.' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( 's3cr3t', $text );
			$this->assertStringNotContainsString( 'SELECT', $text );
			$this->assertStringNotContainsString( 'wp_post', $text );
			$this->assertStringNotContainsString( '/', $text );
		}
	}

	// ------------------------------------------------------------ tree builders

	/**
	 * A single chain of containers, one per level.
	 *
	 * @param int $levels How many levels the chain has.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function chain( int $levels ): array {
		$node = [
			'id'       => 'leaf',
			'elType'   => 'container',
			'elements' => [],
		];

		for ( $i = 1; $i < $levels; $i++ ) {
			$node = [
				'id'       => 'level' . $i,
				'elType'   => 'container',
				'elements' => [ $node ],
			];
		}

		return [ $node ];
	}

	/**
	 * One flat level of widgets.
	 *
	 * @param int $count How many siblings the level holds.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function siblings( int $count ): array {
		$nodes = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$nodes[] = [
				'id'         => 'n' . $i,
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'elements'   => [],
			];
		}

		return $nodes;
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ElementorDocumentGet::definition();

		$this->assertSame( 'elementor-document-get', $definition->id );
		$this->assertSame( 'elementor-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
	}

	public function test_the_definition_carries_both_the_wordpress_and_elementor_ranges(): void {
		$versions = ElementorDocumentGet::definition()->supportedVersions;

		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . ElementorPresence::MIN_VERSION, $versions['elementor'] );
	}

	/**
	 * The output schema is an embedded SCHEMA RESOURCE. Its node definition is
	 * recursive, so `children` can only be described by a pointer back to it, and
	 * a pointer resolves against the base URI in force where it appears. In the
	 * dispatcher catalog this array is nested at `operations[n].outputSchema`
	 * inside a much larger response whose root has no `$defs` at all — so without
	 * an `$id` the pointer resolves against nothing and dangles. Phase 5 had to
	 * make this fix retroactively; it is made here on the first commit.
	 */
	public function test_the_output_schema_is_an_embedded_resource_whose_recursive_pointer_resolves(): void {
		$schema = ElementorDocumentGet::definition()->outputSchema;

		$this->assertSame( ElementorDocumentGet::OUTPUT_SCHEMA_ID, $schema['$id'] );
		$this->assertArrayHasKey( ElementorFields::NODE_DEF, $schema['$defs'] );
		$this->assertSame(
			'#/$defs/' . ElementorFields::NODE_DEF,
			$schema['$defs'][ ElementorFields::NODE_DEF ]['properties']['children']['items']['$ref'],
			'The node definition must reference itself, which is the only finite description of a recursive shape.'
		);
	}

	/**
	 * `label` IS DERIVED, and the response says so in the one place a client
	 * reads before consuming a field. This codebase has already shipped a bug from
	 * recording a derived display value as though it were a stored column — the
	 * menus module's computed `description` — and a schema that described `label`
	 * as an ordinary field would invite Phase 6b to do it again.
	 */
	public function test_the_output_schema_marks_the_label_as_derived_for_display_only(): void {
		$node = ElementorDocumentGet::definition()->outputSchema['$defs'][ ElementorFields::NODE_DEF ];

		$this->assertStringContainsStringIgnoringCase( 'derived', $node['properties']['label']['description'] );
		$this->assertStringContainsStringIgnoringCase( 'display', $node['properties']['label']['description'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the REGISTERED definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted — and the operation
	 * has to be registered for it to be found at all.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->get();
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-document-get' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks
 * and `class_exists()` is the only thing that answers reads. It deliberately
 * models no `$instance` singleton, no widget manager, and NO DOCUMENT API: this
 * operation reads stored post meta by design (spec Decision 1), and a stand-in
 * offering `get_elements_data()` would let a call to it be written and still pass.
 * (class_alias() refuses an internal class, which is why this exists at all
 * rather than aliasing stdClass.)
 */
final class ElementorPluginStandInForGet {
}
