<?php
/**
 * Tests for ElementorElementSearch (REQ-0066).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorElementSearch;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorTreeSearch;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0066: finding the elements on a page that match a filter.
 *
 * THE ASSERTION THIS FILE EXISTS FOR is that a searched setting VALUE never
 * reaches the response. The client supplies the needle, so returning what it
 * matched adds nothing the client does not already hold while turning a search
 * into a bulk read of page content under one capability check. The lock below
 * asserts against the ENCODED response rather than against named members,
 * because the defect it guards is a helpful new member nobody thought to check.
 *
 * THE SECOND ASSERTION is that `matchCount` may exceed `count( matches )`. A
 * truncating search whose count stopped where its collection stopped would
 * report every over-large result as exactly `limit` and `truncated` would never
 * be true, which reads as a complete answer and is not one.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Three doubles are in play, and
 * they are the same three `ElementorElementGetTest` installs, reproducing the
 * same facts and nothing else:
 *
 * 1. THE POST STORE — `get_post()` and `get_post_meta()`. Reproduces exactly:
 *    null for an identifier no post carries; otherwise the four columns the
 *    summary projects; `get_post_meta( id, key, true )` answering the single
 *    stored value; and an absent row answering `''` rather than null. No
 *    capability filtering inside `get_post`, no status visibility, no
 *    revisions, no meta cache, no filters.
 *
 * 2. `wp_unslash()` — reproduces ONLY `stripslashes_deep()` on a string, the
 *    only shape the decoder reaches it with, and faithfully on the rule that
 *    matters: a value that is still not valid JSON after unslashing stays
 *    invalid rather than being rescued into a partial tree.
 *
 * 3. THE ELEMENTOR STAND-IN — reproduces exactly the two facts
 *    `ElementorPresence::isLoaded()` reads and NO Elementor API at all. That is
 *    the design (spec Decision 1): this operation searches STORED settings, and
 *    a stand-in offering a document API would let a call be written that
 *    searched what the page RENDERS AS instead of what it HOLDS.
 *
 * `ElementorTreeSearch` IS NOT DOUBLED HERE. It is a pure walk pinned in full by
 * `ElementorTreeSearchTest`, and this file exercises the real one so that the
 * seam between the operation and the walk is covered by something. A double
 * there would let the two agree with each other and with nothing else.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of a process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementSearchTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const DOCUMENT_ID = 202;

	private ElementorElementSearch $handler;

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
	 * Every store lookup the operation made, in order.
	 *
	 * This is what makes the ordering test able to fail: the refusal alone is
	 * raised whether the capability check sits above or below the lookup, so the
	 * load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler         = new ElementorElementSearch(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTreeSearch(),
			new ElementorTreeEdit(),
			new ElementorPresence()
		);
		$this->mayEditDocument = true;
		$this->lookups         = [];
		$this->posts           = [ self::DOCUMENT_ID => $this->makeRow( self::DOCUMENT_ID, 'page', 'Contact', 'publish' ) ];
		$this->editModes       = [ self::DOCUMENT_ID => 'builder' ];
		$this->data            = [ self::DOCUMENT_ID => $this->encode( $this->sampleTree() ) ];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForElementSearch::class, 'Elementor\Plugin' );
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
	 * A nested page holding three headings, one of them unaddressable.
	 *
	 * The heading at root index 1 stores NO identifier, which is how Elementor
	 * leaves an element written into `_elementor_data` by something other than
	 * the editor. It is in the tree because a search that silently dropped it
	 * would tell an operator their page does not hold text they can see on it.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function sampleTree(): array {
		return [
			[
				'id'       => 'aaa111',
				'elType'   => 'container',
				'settings' => [ 'background_color' => '#ffffff' ],
				'elements' => [
					[
						'id'       => 'bbb222',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'ccc333',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Call us on 0800 000 000' ],
								'elements'   => [],
							],
							[
								'id'         => 'ddd444',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Our opening hours' ],
								'elements'   => [],
							],
							[
								'id'         => 'eee555',
								'elType'     => 'widget',
								'widgetType' => 'button',
								'settings'   => [
									'text' => 'Book',
									'link' => [ 'url' => 'https://example.test/book' ],
								],
								'elements'   => [],
							],
						],
					],
				],
			],
			[
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => 'Unaddressable notice' ],
				'elements'   => [],
			],
		];
	}

	/**
	 * A flat document of the requested width, every element a heading.
	 *
	 * @param int $count How many widgets to store.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function wideTree( int $count ): array {
		$tree = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$tree[] = [
				'id'         => 'w' . $i,
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'elements'   => [],
			];
		}

		return $tree;
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
	 * @param array<string, mixed> $arguments The request beyond the document.
	 * @param int                  $document  The document identifier.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function searched( array $arguments = [], int $document = self::DOCUMENT_ID ): array {
		return $this->handler->handle(
			[ ElementorWriteFields::INPUT_DOCUMENT => $document ] + $arguments,
			$this->makeContext()
		);
	}

	/**
	 * Runs the operation expecting a refusal, and answers it.
	 *
	 * Returned rather than asserted here, so each caller asserts the specific
	 * ErrorCode. A bare expectException( OperationException::class ) passes for
	 * any of the eleven codes and proves nothing about which one was raised.
	 *
	 * @param array<string, mixed> $arguments The request beyond the document.
	 * @param int                  $document  The document identifier.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $arguments = [], int $document = self::DOCUMENT_ID ): OperationException {
		try {
			$this->searched( $arguments, $document );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}

	/**
	 * The ids in one response, in order.
	 *
	 * @param array<string, mixed> $result The response.
	 *
	 * @return array<int, string|null> The ids.
	 */
	private function ids( array $result ): array {
		return array_map( static fn( array $match ): ?string => $match['id'], $result['matches'] );
	}

	/**
	 * A widget-type search, the ordinary request.
	 *
	 * @return array<string, mixed> The request beyond the document.
	 */
	private function headings(): array {
		return [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading' ];
	}

	// ---------------------------------------------------------------- payload

	public function test_the_response_carries_the_document_the_matches_and_the_totals(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame( [ 'document', 'matches', 'matchCount', 'truncated' ], array_keys( $result ) );
	}

	public function test_the_document_summary_is_the_shared_projection(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame( self::DOCUMENT_ID, $result['document']['id'] );
		$this->assertSame( 'Contact', $result['document']['title'] );
		$this->assertSame( 'page', $result['document']['type'] );
	}

	public function test_every_match_carries_the_declared_members(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame(
			[ 'id', 'elType', 'widgetType', 'kind', 'depth', 'path', 'matchedSettingKeys' ],
			array_keys( $result['matches'][0] )
		);
	}

	public function test_a_widget_search_returns_the_matching_widgets_in_document_order(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame( [ 'ccc333', 'ddd444', null ], $this->ids( $result ) );
		$this->assertSame( 3, $result['matchCount'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_a_match_is_positioned_by_the_addressing_walk(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame( 'bbb222/0', $result['matches'][0]['path'] );
		$this->assertSame( 'bbb222/1', $result['matches'][1]['path'] );
	}

	public function test_a_root_level_match_reads_as_a_path_with_no_parent(): void {
		$this->withElementor();

		$result = $this->searched( [ ElementorTreeSearch::FILTER_EL_TYPE => 'container' ] );

		$this->assertSame( '/0', $result['matches'][0]['path'] );
		$this->assertSame( 'aaa111/0', $result['matches'][1]['path'] );
	}

	public function test_an_element_storing_no_identifier_is_returned_with_a_null_id_and_no_path(): void {
		$this->withElementor();

		$result = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'Unaddressable' ] );

		$this->assertCount( 1, $result['matches'] );
		$this->assertNull( $result['matches'][0]['id'] );
		$this->assertNull( $result['matches'][0]['path'] );
		$this->assertSame( [ 'title' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	public function test_the_matched_keys_are_empty_when_no_setting_text_was_searched(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() );

		$this->assertSame( [], $result['matches'][0]['matchedSettingKeys'] );
	}

	public function test_a_nested_match_is_reported_under_its_top_level_key(): void {
		$this->withElementor();

		$result = $this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'example.test' ] );

		$this->assertSame( [ 'eee555' ], $this->ids( $result ) );
		$this->assertSame( [ 'link' ], $result['matches'][0]['matchedSettingKeys'] );
	}

	public function test_filters_are_conjunctive(): void {
		$this->withElementor();

		$narrowed = $this->searched(
			[
				ElementorTreeSearch::FILTER_WIDGET_TYPE      => 'heading',
				ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800',
			]
		);

		$this->assertSame( [ 'ccc333' ], $this->ids( $narrowed ) );
		$this->assertCount( 3, $this->searched( $this->headings() )['matches'] );
	}

	// ------------------------------------------------------------- disclosure

	/**
	 * THE REGRESSION LOCK FOR DECISION 5.
	 *
	 * Asserted against the whole encoded response rather than against named
	 * members, because what it guards against is a member nobody thought to
	 * check. The positive assertion is here too: the KEY does travel, and a
	 * response that disclosed neither would pass a negative assertion alone.
	 */
	public function test_no_matched_setting_value_appears_anywhere_in_the_response(): void {
		$this->withElementor();

		$encoded = (string) json_encode(
			$this->searched( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800' ] )
		);

		$this->assertStringNotContainsString( '0800 000 000', $encoded );
		$this->assertStringNotContainsString( 'Call us on', $encoded );
		$this->assertStringContainsString( 'title', $encoded );
	}

	public function test_no_refusal_echoes_the_searched_text(): void {
		$this->withElementor();
		$this->mayEditDocument = false;

		$refusal = $this->refusal( [ ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => 'a-secret-needle' ] );

		$this->assertStringNotContainsString( 'a-secret-needle', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'a-secret-needle', (string) $refusal->remediation );
	}

	// ------------------------------------------------------------------ bound

	public function test_the_count_exceeds_the_returned_length_when_truncated(): void {
		$this->withElementor();

		$result = $this->searched(
			$this->headings() + [ 'limit' => 1 ]
		);

		$this->assertCount( 1, $result['matches'] );
		$this->assertSame( 3, $result['matchCount'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_a_limit_equal_to_the_match_count_is_not_truncated(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() + [ 'limit' => 3 ] );

		$this->assertCount( 3, $result['matches'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_a_request_naming_no_limit_returns_at_most_the_default(): void {
		$this->withElementor();
		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->wideTree( 60 ) );

		$result = $this->searched( $this->headings() );

		$this->assertCount( ElementorElementSearch::LIMIT_DEFAULT, $result['matches'] );
		$this->assertSame( 60, $result['matchCount'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * A limit past the ceiling is clamped rather than honoured.
	 *
	 * The schema refuses one first, so this covers the handler reached any other
	 * way. A bound that lives only in a schema stops existing the moment
	 * anything calls the handler directly.
	 */
	public function test_a_limit_past_the_ceiling_is_clamped(): void {
		$this->withElementor();
		$this->data[ self::DOCUMENT_ID ] = $this->encode( $this->wideTree( 250 ) );

		$result = $this->searched( $this->headings() + [ 'limit' => 5000 ] );

		$this->assertCount( ElementorElementSearch::LIMIT_MAX, $result['matches'] );
		$this->assertSame( 250, $result['matchCount'] );
	}

	public function test_a_limit_below_one_is_clamped_to_one(): void {
		$this->withElementor();

		$result = $this->searched( $this->headings() + [ 'limit' => 0 ] );

		$this->assertCount( 1, $result['matches'] );
		$this->assertSame( 3, $result['matchCount'] );
	}

	// --------------------------------------------------------------- refusals

	public function test_a_request_naming_no_filter_is_refused(): void {
		$this->withElementor();

		$refusal = $this->refusal();

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'at least one', $refusal->getMessage() );
	}

	public function test_a_filter_given_as_an_empty_string_counts_as_no_filter(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => '' ] );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
	}

	public function test_a_filter_given_as_something_other_than_a_string_counts_as_no_filter(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ ElementorTreeSearch::FILTER_EL_TYPE => [ 'widget' ] ] );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
	}

	/**
	 * The capability is checked before any store lookup.
	 *
	 * The refusal is raised either way, so the load-bearing assertion is that no
	 * lookup happened: a caller who may not edit the post must not be able to
	 * learn whether it exists.
	 */
	public function test_the_capability_is_checked_before_the_document_is_looked_up(): void {
		$this->withElementor();
		$this->mayEditDocument = false;

		$refusal = $this->refusal( $this->headings() );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->lookups );
	}

	/**
	 * The capability is checked before Elementor's presence.
	 *
	 * Elementor is deliberately NOT installed here, so a presence check placed
	 * above the capability check would answer IntegrationUnavailable and this
	 * assertion would fail.
	 */
	public function test_the_capability_is_checked_before_elementor_presence(): void {
		$this->mayEditDocument = false;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal( $this->headings() )->errorCode );
	}

	public function test_a_site_without_elementor_cannot_be_searched(): void {
		$refusal = $this->refusal( $this->headings() );

		$this->assertSame( ErrorCode::IntegrationUnavailable, $refusal->errorCode );
	}

	public function test_an_identifier_no_post_carries_is_refused(): void {
		$this->withElementor();
		$this->mayEditDocument = true;
		$this->posts           = [];

		$refusal = $this->refusal( $this->headings() );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	public function test_a_post_elementor_does_not_control_is_refused(): void {
		$this->withElementor();
		$this->editModes = [];

		$refusal = $this->refusal( $this->headings() );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	/**
	 * The refusal is the one the other document reads raise, word for word.
	 *
	 * Three conditions share it — no such post, a post the caller may not edit,
	 * and a post Elementor does not control — and a message that varied by which
	 * would let a caller without the capability enumerate the site's posts.
	 */
	public function test_the_document_refusal_is_the_shared_wording(): void {
		$this->withElementor();
		$this->mayEditDocument = false;

		$this->assertSame(
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			$this->refusal( $this->headings() )->getMessage()
		);
	}

	public function test_a_document_stored_as_damaged_json_is_refused(): void {
		$this->withElementor();
		$this->data[ self::DOCUMENT_ID ] = 'not json at all';

		$refusal = $this->refusal( $this->headings() );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_a_search_matching_nothing_answers_an_empty_list_rather_than_refusing(): void {
		$this->withElementor();

		$result = $this->searched( [ ElementorTreeSearch::FILTER_WIDGET_TYPE => 'nothing-like-this' ] );

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['matchCount'] );
		$this->assertFalse( $result['truncated'] );
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_a_read(): void {
		$definition = ElementorElementSearch::definition();

		$this->assertSame( 'elementor-element-search', $definition->id );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
	}

	/**
	 * Only the document is required, so any one filter alone is a valid request.
	 *
	 * JSON Schema cannot say "at least one of these three", which is why the
	 * handler enforces it and why none of the three may be listed here.
	 */
	public function test_only_the_document_is_a_required_input(): void {
		$schema = ElementorElementSearch::definition()->inputSchema;

		$this->assertSame( [ ElementorWriteFields::INPUT_DOCUMENT ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[
				ElementorWriteFields::INPUT_DOCUMENT,
				ElementorTreeSearch::FILTER_EL_TYPE,
				ElementorTreeSearch::FILTER_WIDGET_TYPE,
				ElementorTreeSearch::FILTER_SETTINGS_CONTAIN,
				'limit',
			],
			array_keys( $schema['properties'] )
		);
	}

	/**
	 * The document input is the one the writes declare, not a second one.
	 */
	public function test_the_document_input_is_the_shared_declaration(): void {
		$schema = ElementorElementSearch::definition()->inputSchema;

		$this->assertSame(
			ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_DOCUMENT ],
			$schema['properties'][ ElementorWriteFields::INPUT_DOCUMENT ]
		);
	}

	public function test_the_limit_is_bounded_by_the_declared_ceiling(): void {
		$limit = ElementorElementSearch::definition()->inputSchema['properties']['limit'];

		$this->assertSame( 1, $limit['minimum'] );
		$this->assertSame( ElementorElementSearch::LIMIT_MAX, $limit['maximum'] );
		$this->assertStringContainsString( (string) ElementorElementSearch::LIMIT_DEFAULT, $limit['description'] );
	}

	/**
	 * The output schema states the two things a client cannot infer.
	 */
	public function test_the_output_schema_states_the_disclosure_and_truncation_rules(): void {
		$schema = ElementorElementSearch::definition()->outputSchema;
		$match  = $schema['properties']['matches']['items'];

		$this->assertStringContainsString( 'NEVER the values', $match['properties']['matchedSettingKeys']['description'] );
		$this->assertStringContainsString( 'GREATER THAN', $schema['properties']['matchCount']['description'] );
		$this->assertSame( [ 'document', 'matches', 'matchCount', 'truncated' ], $schema['required'] );
	}

	/**
	 * The two derived members say they are derived, and the path says it is not
	 * an address.
	 */
	public function test_the_derived_members_are_marked_derived(): void {
		$match = ElementorElementSearch::definition()->outputSchema['properties']['matches']['items']['properties'];

		$this->assertStringContainsString( 'DERIVED', $match['kind']['description'] );
		$this->assertStringContainsString( 'DERIVED', $match['depth']['description'] );
		$this->assertStringContainsString( 'DERIVED', $match['path']['description'] );
		$this->assertStringContainsString( 'NOT an address', $match['path']['description'] );
	}
}

/**
 * The two facts ElementorPresence::isLoaded() reads, and nothing else.
 *
 * A separate class from the one ElementorElementGetTest installs, because both
 * are aliased to `Elementor\Plugin` and a shared one would make each file's
 * stand-in depend on the other file still existing.
 */
final class ElementorPluginStandInForElementSearch {

	/**
	 * The singleton Elementor exposes.
	 *
	 * @return self The instance.
	 */
	public static function instance(): self {
		return new self();
	}
}
