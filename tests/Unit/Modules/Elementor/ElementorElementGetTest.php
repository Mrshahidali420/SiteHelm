<?php
/**
 * Tests for ElementorElementGet (REQ-0065).
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
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorElementGet;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0065: one element's stored settings, verbatim.
 *
 * THE ASSERTION THIS FILE EXISTS FOR is the Decision 2 regression lock:
 * `storedSettings` reports what the document holds and nothing else. A widget
 * that never had its colour changed stores no colour key, and the response must
 * not grow one. Merging a control default in would put a value in the response
 * that no row holds, in the same shape as values that are stored, and a client
 * writing that map back would convert every default into a permanent explicit
 * override. This codebase shipped that defect twice before — the menus module's
 * computed `description`, and the derived `label` on the normalized node — which
 * is why the lock here asserts the absence of a key rather than the presence of
 * the right ones.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Three doubles are in play:
 *
 * 1. THE POST STORE — `get_post()` and `get_post_meta()`, served from the
 *    `$posts`, `$data` and `$editModes` properties. It reproduces exactly four
 *    upstream facts: `get_post()` answers null for an identifier no post
 *    carries; it otherwise answers an object carrying the four columns the
 *    summary projects; `get_post_meta( id, key, true )` answers the single
 *    stored value; and an absent meta row answers `''` rather than null. It
 *    reproduces NOTHING else — no capability filtering inside `get_post`, no
 *    post-status visibility, no revisions, no meta cache, no filters.
 *
 * 2. `wp_unslash()` — reproduces ONLY `stripslashes_deep()` on a string, which
 *    is the only shape ElementorDocument's decoder reaches it with. Faithful on
 *    the rule that matters: a value that is not valid JSON after unslashing
 *    stays invalid, so a damaged document cannot be rescued into a partial tree.
 *
 * 3. THE ELEMENTOR STAND-IN installed by withElementor() — reproduces exactly
 *    the two facts `ElementorPresence::isLoaded()` reads, and NO Elementor API
 *    at all. That is the design, not an omission (spec Decision 1): this
 *    operation answers from stored post meta and must never call a document
 *    API. A stand-in that offered one would let such a call be written and still
 *    pass — and the answer would then be what the page RENDERS AS rather than
 *    what it HOLDS, which is a different question from the one asked.
 *
 * NO DOUBLE HERE KNOWS ANY CONTROL DEFAULT, deliberately. The operation has no
 * route to one, and a double that supplied defaults would let a future merge be
 * written and pass its own tests.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementGetTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const DOCUMENT_ID = 101;

	/**
	 * The heading widget every ordinary case addresses.
	 */
	private const WIDGET_ID = 'ccc333';

	private ElementorElementGet $handler;

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
	 * thrown whether the capability check sits above or below the lookup, so the
	 * load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler         = new ElementorElementGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorTreeEdit(),
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
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForElementGet::class, 'Elementor\Plugin' );
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
	 * A container holding a container holding two widgets.
	 *
	 * The heading at WIDGET_ID stores a `title` and NOTHING ELSE. Every real
	 * heading control — `title_color`, `align`, `header_size` — is absent
	 * exactly as Elementor stores it when the operator never touched them, which
	 * is what the Decision 2 lock reads.
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
								'id'         => self::WIDGET_ID,
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Our opening hours' ],
								'elements'   => [],
							],
							[
								'id'         => 'ddd444',
								'elType'     => 'widget',
								'widgetType' => 'heading',
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
	 * @param int    $document The document identifier.
	 * @param string $element  The element identifier.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function get( int $document = self::DOCUMENT_ID, string $element = self::WIDGET_ID ): array {
		return $this->handler->handle(
			[
				ElementorWriteFields::INPUT_DOCUMENT   => $document,
				ElementorWriteFields::INPUT_ELEMENT_ID => $element,
			],
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
	 * @param int    $document The document identifier.
	 * @param string $element  The element identifier.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( int $document = self::DOCUMENT_ID, string $element = self::WIDGET_ID ): OperationException {
		try {
			$this->get( $document, $element );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}

	// ---------------------------------------------------------------- payload

	public function test_the_response_carries_the_document_the_element_and_the_stored_settings(): void {
		$this->withElementor();

		$this->assertSame( [ 'document', 'element', 'storedSettings' ], array_keys( $this->get() ) );
	}

	public function test_the_document_summary_is_the_shared_projection_every_other_read_returns(): void {
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

	public function test_the_element_is_projected_in_the_tree_reads_own_vocabulary(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'id'         => self::WIDGET_ID,
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'kind'       => 'widget',
				'label'      => 'heading',
				'path'       => 'bbb222/0',
				'childCount' => 0,
			],
			$this->get()['element']
		);
	}

	public function test_a_container_is_reported_as_a_container_with_its_direct_child_count(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'id'         => 'bbb222',
				'elType'     => 'container',
				'widgetType' => null,
				'kind'       => 'container',
				'label'      => 'container',
				'path'       => 'aaa111/0',
				'childCount' => 2,
			],
			$this->get( self::DOCUMENT_ID, 'bbb222' )['element']
		);
	}

	// -------------------------------------------------- decision 2: verbatim

	/**
	 * THE REGRESSION LOCK. See the class docblock.
	 *
	 * The stored heading carries `title` and nothing else. Every other heading
	 * control this widget really accepts — the colour, the alignment, the header
	 * size — must be ABSENT from the response rather than present holding a
	 * default, because a client that wrote this map back would otherwise stamp
	 * three explicit overrides onto an element that had none.
	 */
	public function test_a_setting_the_element_does_not_store_is_absent_rather_than_defaulted(): void {
		$this->withElementor();

		$settings = $this->get()['storedSettings'];

		$this->assertSame( [ 'title' => 'Our opening hours' ], $settings );

		foreach ( [ 'title_color', 'align', 'header_size', 'typography_typography' ] as $control ) {
			$this->assertArrayNotHasKey(
				$control,
				$settings,
				"A control the element does not store must not appear in storedSettings: {$control}."
			);
		}
	}

	/**
	 * The other half of the lock: an element left entirely at its defaults
	 * answers an empty map and is not a refusal.
	 *
	 * "This element has been left alone" is the answer to the question that was
	 * asked, and it is the ordinary state of a freshly dropped widget.
	 */
	public function test_an_element_storing_no_settings_answers_an_empty_map_rather_than_refusing(): void {
		$this->withElementor();

		$this->assertSame( [], $this->get( self::DOCUMENT_ID, 'ddd444' )['storedSettings'] );
	}

	/**
	 * A stored `settings` holding something that is not a map.
	 *
	 * Third-party writable, so this is an outcome rather than a theory. Casting
	 * it would invent a one-member list of garbage and report it as this
	 * element's settings, which a client would then write a partial update
	 * against.
	 */
	public function test_a_settings_value_that_is_not_a_map_answers_an_empty_map(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = $this->encode(
			[
				[
					'id'         => self::WIDGET_ID,
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => 'Our opening hours',
					'elements'   => [],
				],
			]
		);

		$this->assertSame( [], $this->get()['storedSettings'] );
	}

	public function test_a_nested_setting_value_is_returned_whole_and_unflattened(): void {
		$this->withElementor();

		$stored = [
			'title_link' => [
				'url'         => 'https://example.com/hours',
				'is_external' => true,
			],
		];

		$this->data[ self::DOCUMENT_ID ] = $this->encode(
			[
				[
					'id'         => self::WIDGET_ID,
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => $stored,
					'elements'   => [],
				],
			]
		);

		$this->assertSame( $stored, $this->get()['storedSettings'] );
	}

	// --------------------------------------------------------------- refusals

	/**
	 * THE CAPABILITY IS CHECKED FIRST, and the empty lookup log is what proves
	 * it. The refusal alone is raised whether the check sits above or below the
	 * store read, so asserting only the refusal would be a test that cannot
	 * fail.
	 */
	public function test_a_caller_without_rights_causes_no_store_read_at_all(): void {
		$this->withElementor();
		$this->mayEditDocument = false;

		$refusal = $this->refusal();

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->lookups, 'No lookup may happen before the capability check.' );
	}

	/**
	 * The capability check also sits ABOVE the presence gate.
	 *
	 * Elementor is deliberately not installed in this process. A caller with no
	 * rights must be told the same thing either way, because the difference
	 * between the two refusals is site configuration they are not entitled to.
	 */
	public function test_a_caller_without_rights_cannot_learn_whether_the_site_runs_elementor(): void {
		$this->mayEditDocument = false;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal()->errorCode );
	}

	public function test_a_site_without_elementor_refuses_as_an_unavailable_integration(): void {
		$this->assertSame( ErrorCode::IntegrationUnavailable, $this->refusal()->errorCode );
	}

	public function test_an_identifier_no_post_carries_is_not_found(): void {
		$this->withElementor();
		$this->mayEditDocument = true;
		$this->posts           = [];

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal()->errorCode );
	}

	public function test_a_post_elementor_does_not_control_is_not_found(): void {
		$this->withElementor();
		$this->editModes = [];

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal()->errorCode );
	}

	/**
	 * The element refusal is a DIFFERENT refusal from the document one.
	 *
	 * By this line the caller has already proven they may edit the document, so
	 * naming the element as the missing thing discloses nothing — and
	 * conflating the two would send an operator looking for a page that was
	 * there the whole time.
	 */
	public function test_an_element_the_page_does_not_hold_is_refused_in_its_own_words(): void {
		$this->withElementor();

		$element  = $this->refusal( self::DOCUMENT_ID, 'nosuchid' );
		$document = $this->refusal( 4242, self::WIDGET_ID );

		$this->assertSame( ErrorCode::TargetNotFound, $element->errorCode );
		$this->assertNotSame(
			$document->getMessage(),
			$element->getMessage(),
			'A missing element and a missing document must not read alike.'
		);
	}

	/**
	 * An element with no stored identifier cannot be addressed by one.
	 *
	 * It is not "not found" by accident — nothing this operation accepts could
	 * name it, which is why REQ-0066's search reports such elements with a null
	 * id and this operation refuses.
	 */
	public function test_an_element_storing_no_identifier_cannot_be_addressed(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = $this->encode(
			[
				[
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [ 'title' => 'Our opening hours' ],
					'elements'   => [],
				],
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal()->errorCode );
	}

	/**
	 * A damaged document refuses as ExecutionFailed and is not reported as an
	 * empty one.
	 *
	 * Retryable, because re-saving the page in the Elementor editor clears it;
	 * `InvalidInput` would misdirect an operator into correcting a request that
	 * was never wrong.
	 */
	public function test_a_document_whose_stored_data_cannot_be_read_refuses_as_execution_failed(): void {
		$this->withElementor();
		$this->data[ self::DOCUMENT_ID ] = '{not json at all';

		$this->assertSame( ErrorCode::ExecutionFailed, $this->refusal()->errorCode );
	}

	/**
	 * No refusal echoes the identifier the caller supplied.
	 *
	 * The field is named; the value never is. An element id is low-sensitivity,
	 * but the rule is the rule — the same message shape carries a search needle
	 * in REQ-0066, where the value is client content.
	 */
	public function test_no_refusal_echoes_the_requested_identifier(): void {
		$this->withElementor();

		$refusal = $this->refusal( self::DOCUMENT_ID, 'zzz999' );

		$this->assertStringNotContainsString( 'zzz999', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'zzz999', (string) $refusal->remediation );
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_a_read_that_changes_nothing(): void {
		$definition = ElementorElementGet::definition();

		$this->assertSame( 'elementor-element-get', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
	}

	/**
	 * The input is the SAME pair every Elementor write takes.
	 *
	 * A read whose identifiers were spelled differently from the write it
	 * precedes would make a client rename its own fields between two calls about
	 * the same element, and a rename is where an identifier gets lost.
	 */
	public function test_the_input_reuses_the_shared_write_identifiers(): void {
		$schema = ElementorElementGet::definition()->inputSchema;

		$this->assertSame(
			array_keys( ElementorWriteFields::documentInput() ),
			array_keys( $schema['properties'] )
		);
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ ElementorWriteFields::INPUT_DOCUMENT, ElementorWriteFields::INPUT_ELEMENT_ID ],
			$schema['required']
		);
	}

	/**
	 * The output schema promises no defaults, in the words a client reads.
	 *
	 * The prohibition is only enforceable if the contract states it, because a
	 * client that assumed a merged map would read absence as null.
	 */
	public function test_the_output_schema_says_stored_settings_carry_no_defaults(): void {
		$description = ElementorElementGet::definition()->outputSchema['properties']['storedSettings']['description'];

		$this->assertStringContainsString( 'ABSENT', $description );
		$this->assertStringContainsString( 'elementor-control-schema', $description );
	}

	/**
	 * `path` and `label` are marked derived where a client will see it.
	 *
	 * Both are computed on every read and neither is stored. A snapshot that
	 * recorded either would write a rendering back over its source — the shape
	 * of the menus module's `description` bug.
	 */
	public function test_the_derived_members_are_declared_as_derived(): void {
		$properties = ElementorElementGet::definition()->outputSchema['properties']['element']['properties'];

		$this->assertStringContainsString( 'DERIVED', $properties['path']['description'] );
		$this->assertStringContainsString( 'DERIVED', $properties['label']['description'] );
		$this->assertStringContainsString( 'not an address', $properties['path']['description'] );
	}
}

/**
 * Stands in for `\Elementor\Plugin`, carrying no API at all.
 *
 * See the test class docblock: the emptiness is the point. This operation reads
 * stored post meta, and a stand-in offering a document API would let a call to
 * one be written and still pass.
 *
 * phpcs:disable
 */
final class ElementorPluginStandInForElementGet {
}
