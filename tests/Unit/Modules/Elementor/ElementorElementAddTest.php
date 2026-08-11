<?php
/**
 * Tests for ElementorElementAdd: the definition, the guard order, and planning.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorElementAdd;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\ElementAddFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0036, the first of the six Elementor writes.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering assertions below distinguish "Elementor is absent" from
 * "you may not edit this", and without isolation whether Elementor is absent
 * would depend on the alphabetical position of some other test file — which
 * would make those assertions true for the wrong reason, or true by accident.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as the
 * module wires it; only WordPress functions and the `\Elementor\` symbols are
 * doubled. In particular the mint is real, because the determinism test below
 * is the one that protects the whole write phase and a stubbed mint would make
 * it a claim about the stub.
 *
 * This file covers the definition, the guard order, and `planChange()`.
 * Snapshot, apply, read-back and restore live in ElementorElementAddApplyTest.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementAddTest extends TestCase {

	use ElementAddFixtures;

	/**
	 * The document every case operates on.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair get_post_meta() was asked for.
	 *
	 * @var array[]
	 */
	private array $reads = [];

	/**
	 * Every ( post id, meta key ) pair a mutating call was made with.
	 *
	 * @var array[]
	 */
	private array $writes = [];

	/**
	 * Whether the caller may edit the document.
	 */
	private bool $mayEdit = true;

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = [];
		$this->reads   = [];
		$this->writes  = [];
		$this->mayEdit = true;

		$this->stubWordPress();
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape the matrix pins for REQ-0036.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementAdd::definition();

		$this->assertSame( 'elementor-element-add', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertArrayHasKey( 'elementor', $definition->supportedVersions );
	}

	/**
	 * The input schema is CLOSED and declares exactly the six documented members.
	 *
	 * A write whose schema admitted an undeclared member would let a caller send
	 * something the payload never carries and read the silence as acceptance.
	 */
	public function test_the_input_schema_is_closed_and_declares_the_six_documented_members(): void {
		$schema = ElementorElementAdd::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'document', 'parentElementId', 'index', 'elType', 'widgetType', 'settings' ],
			array_keys( $schema['properties'] )
		);
		$this->assertSame( [ 'document', 'elType' ], $schema['required'] );
		$this->assertSame( 0, $schema['properties']['index']['minimum'] );
		$this->assertSame( ElementorElementAddInput::ALLOWED_EL_TYPES, $schema['properties']['elType']['enum'] );
		$this->assertSame( [ 'string', 'null' ], $schema['properties']['parentElementId']['type'] );
	}

	/**
	 * `parentElementId` inherits the shared element-id bounds rather than
	 * restating them, so the six writes cannot drift apart on what an id may be.
	 */
	public function test_the_parent_identifier_reuses_the_shared_element_id_bounds(): void {
		$parent = ElementorElementAdd::definition()->inputSchema['properties']['parentElementId'];
		$shared = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared['pattern'], $parent['pattern'] );
		$this->assertSame( $shared['maxLength'], $parent['maxLength'] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 *
	 * Mutation-proved: moving the presence check above the capability check in
	 * `ElementorWriteTarget::resolve()` turns this into IntegrationUnavailable
	 * and the test fails. That refusal would tell a caller with no rights over
	 * the document whether the site runs Elementor at all, which is site
	 * configuration they are not entitled to.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		try {
			$this->resolved( $this->arguments( [ 'elType' => 'container' ] ) );
			$this->fail( 'An unauthorized caller must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the document lookup.
	 *
	 * Mutation-proved: moving the presence check below `isElementorDocument()`
	 * makes this answer a target that does not exist instead of
	 * IntegrationUnavailable, and the read recorder stops being empty.
	 */
	public function test_an_absent_elementor_is_reported_before_any_document_lookup(): void {
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		try {
			$this->resolved( $this->arguments( [ 'elType' => 'container' ] ) );
			$this->fail( 'A site without Elementor must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * TARGET THIRD, before any argument is judged.
	 *
	 * The request below is wrong in two ways at once — the page is not an
	 * Elementor document AND the element kind is not one that exists. The target
	 * refusal is the one that must surface, because telling an operator to
	 * correct their arguments for a page that was never an Elementor document
	 * sends them to fix the wrong thing.
	 *
	 * Mutation-proved: moving the `$current->exists` guard below the input
	 * validation in `planChange()` turns this into InvalidInput and the test
	 * fails.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan( $this->arguments( [ 'elType' => 'not-a-kind' ] ) );
			$this->fail( 'A page Elementor does not control must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- determinism

	/**
	 * THE TEST THAT PROTECTS THE WHOLE PHASE.
	 *
	 * `planChange()` runs once to build the preview and again immediately before
	 * the write, and the engine compares the two payloads by digest. A timestamp,
	 * a `wp_unique_id()` or a random element id anywhere in this operation would
	 * make every plan it issues un-appliable — and would do so intermittently,
	 * which is the worst way for it to be found.
	 */
	public function test_planning_the_same_change_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input = $this->arguments(
			[
				'parentElementId' => 'c111111',
				'index'           => 1,
				'elType'          => 'widget',
				'widgetType'      => 'e-heading',
				'settings'        => [ 'title' => 'Our services' ],
			]
		);

		$first  = $this->plan( $input );
		$second = $this->plan( $input );

		$this->assertSame(
			json_encode( $first->payload ),
			json_encode( $second->payload ),
			'Two plans for the same change against the same state must be byte-identical.'
		);
		$this->assertNotSame( '', $first->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ] );
	}

	/**
	 * The identifier is DERIVED from the request, so two different requests
	 * against the same document do not collide on it.
	 */
	public function test_two_different_requests_against_one_document_mint_different_identifiers(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$heading = $this->plan(
			$this->arguments( [ 'elType' => 'widget', 'widgetType' => 'e-heading' ] )
		);
		$box     = $this->plan( $this->arguments( [ 'elType' => 'container' ] ) );

		$this->assertNotSame(
			$heading->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ],
			$box->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ]
		);
	}

	/**
	 * The minted identifier is never one the document already holds.
	 */
	public function test_the_minted_identifier_is_not_one_the_document_already_holds(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$minted = $this->plan( $this->arguments( [ 'elType' => 'container' ] ) )
			->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ];

		$this->assertNotContains( $minted, [ 'c111111', 'w111111', 'w222222', 'w333333' ] );
	}

	// ------------------------------------------------------- the shared bounds

	/**
	 * THE LENGTH BOUND IS ONE NUMBER, SPELLED ONCE.
	 *
	 * `WIDGET_TYPE_PATTERN` is built by concatenating `WIDGET_TYPE_MAX_LENGTH`
	 * into it rather than repeating the digits, and this pins that: a widget type
	 * of exactly the declared length is accepted and one character more is
	 * refused. The pattern is private, so the pin has to be behavioural — which
	 * is the stronger form anyway, because it is the accepted LENGTH that matters
	 * to a caller and not how the class spells it.
	 *
	 * Were the two ever to drift — the constant raised without the pattern, or
	 * the reverse — exactly one of these two assertions goes red.
	 */
	public function test_the_widget_type_length_bound_is_the_one_the_constant_declares(): void {
		$inputs = new ElementorElementAddInput(
			new ElementorPropCoercion( new ElementorApi( new ElementorPresence() ) ),
			new ElementorTreeEdit()
		);
		$longest = str_repeat( 'a', ElementorElementAddInput::WIDGET_TYPE_MAX_LENGTH );

		$this->assertSame(
			$longest,
			$inputs->requestedWidgetType( ElementorElementAddInput::EL_TYPE_WIDGET, [ 'widgetType' => $longest ] ),
			'A widget type of exactly the declared length must be accepted.'
		);

		try {
			$inputs->requestedWidgetType( ElementorElementAddInput::EL_TYPE_WIDGET, [ 'widgetType' => $longest . 'a' ] );
			$this->fail( 'A widget type one character over the declared length must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- what a plan says

	/**
	 * The plan places the element where it was asked to, and promises the three
	 * fields an operator reads a preview for.
	 */
	public function test_a_plan_places_the_element_and_promises_the_three_document_fields(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan(
			$this->arguments(
				[
					'parentElementId' => 'c111111',
					'index'           => 1,
					'elType'          => 'widget',
					'widgetType'      => 'e-heading',
				]
			)
		);

		$minted   = $planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ];
		$children = $planned->payload[ ElementorElementAdd::PAYLOAD_TREE ][0]['elements'];

		$this->assertSame( $minted, $children[1]['id'], 'The element must land at the requested position.' );
		$this->assertSame(
			[
				ElementorWriteFields::FIELD_DIGEST,
				ElementorWriteFields::FIELD_COUNT,
				ElementorWriteFields::FIELD_WIDGETS,
			],
			array_keys( $planned->afterFields )
		);
		$this->assertSame( 5, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
		$this->assertSame( 3, $planned->afterFields[ ElementorWriteFields::FIELD_WIDGETS ]['e-heading'] );
	}

	/**
	 * The promised digest is the digest of the bytes a READ of the written
	 * document produces — the encoded tree, unslashed.
	 *
	 * Promising the slashed form the writer hands `update_post_meta()` would
	 * promise a value no read can ever produce, and every correct write would
	 * then verify as adjusted rather than as matching.
	 *
	 * THE SETTING VALUE CARRIES A QUOTE AND A BACKSLASH ON PURPOSE. `wp_slash()`
	 * is the identity function on a string holding neither, so a fixture of plain
	 * words would make the slashed and unslashed encodings the same byte string
	 * and this assertion would hold whichever of the two `promise()` digested —
	 * a test that cannot fail. With these two characters in the tree the encodings
	 * genuinely differ, and digesting the slashed form fails here.
	 */
	public function test_the_promised_digest_is_taken_over_the_bytes_a_read_produces(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan(
			$this->arguments(
				[
					'elType'   => 'container',
					'settings' => [ 'title' => 'Our "best" services \\ everywhere' ],
				]
			)
		);

		$encoded = (string) json_encode( $planned->payload[ ElementorElementAdd::PAYLOAD_TREE ] );

		$this->assertNotSame( $encoded, addslashes( $encoded ), 'The fixture must hold a character wp_slash() escapes, or this test cannot fail.' );
		$this->assertSame(
			hash( 'sha256', $encoded ),
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ]
		);
	}

	/**
	 * The structural diff rides in `previewDetail`, which is what an operator
	 * approving a change is shown.
	 */
	public function test_the_preview_detail_names_the_added_element(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan( $this->arguments( [ 'elType' => 'container' ] ) );
		$minted  = $planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ];

		$added = array_values(
			array_filter(
				$planned->previewDetail['changes'],
				fn( array $change ): bool => ElementorTreeDiff::OP_ADDED === $change['op']
			)
		);

		$this->assertCount( 1, $added );
		$this->assertSame( $minted, $added[0]['elementId'] );
		$this->assertNull( $added[0]['fromPath'] );
	}

	/**
	 * A widget's settings are coerced into the shape the running Elementor
	 * declares, and the payload carries the coerced tree the write will store.
	 */
	public function test_a_widget_setting_is_coerced_into_its_declared_envelope(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan(
			$this->arguments(
				[
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
					'settings'   => [ 'title' => 'Our services' ],
				]
			)
		);

		$node = $this->flatten( $planned->payload[ ElementorElementAdd::PAYLOAD_TREE ] )[ $planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ] ];

		$this->assertSame(
			'Our services',
			$node['settings']['title'][ ElementorPropCoercion::ENVELOPE_VALUE_KEY ]
		);
		$this->assertSame(
			[ 'title' => 'Our services' ],
			$planned->payload['settings'],
			'The payload records the settings as the caller asked for them.'
		);
	}

	/**
	 * No parent means the document root, and the element lands there.
	 */
	public function test_an_element_with_no_parent_is_added_at_the_top_level(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan( $this->arguments( [ 'elType' => 'container', 'index' => 1 ] ) );
		$tree    = $planned->payload[ ElementorElementAdd::PAYLOAD_TREE ];

		$this->assertCount( 2, $tree );
		$this->assertSame( $planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ], $tree[1]['id'] );
		$this->assertNull( $planned->payload['parentElementId'] );
	}

	/**
	 * A position past the last child appends, which is what the shared insert
	 * primitive's clamp is for: a plan built against one state and applied
	 * against a slightly different one must still land somewhere sensible.
	 */
	public function test_a_position_past_the_last_child_appends(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan(
			$this->arguments( [ 'parentElementId' => 'c111111', 'index' => 99, 'elType' => 'container' ] )
		);

		$children = $planned->payload[ ElementorElementAdd::PAYLOAD_TREE ][0]['elements'];

		$this->assertSame(
			$planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ],
			$children[3]['id']
		);
	}

	// ------------------------------------------------------- refusals

	/**
	 * A NEGATIVE position is refused rather than clamped.
	 *
	 * The shared insert primitive clamps, and clamping is right for it — but it
	 * means a caller error would otherwise be absorbed as "first" and never
	 * reported. The bound is asserted at the boundary, where a caller error is
	 * still a caller error.
	 */
	public function test_a_negative_position_is_refused_rather_than_absorbed_by_the_clamp(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'index' => -1, 'elType' => 'container' ] )
		);
	}

	/**
	 * An element kind Elementor cannot render is refused, rather than stored as
	 * an element that is counted by every total and invisible on the page.
	 */
	public function test_an_unknown_element_kind_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'elType' => 'marquee' ] )
		);
	}

	/**
	 * A widget with no widget type names nothing Elementor can render.
	 */
	public function test_a_widget_with_no_widget_type_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'elType' => 'widget' ] ) );
	}

	/**
	 * A layout element carrying a widget type is refused too, and that direction
	 * matters as much: storing a member the editor ignores would let the request
	 * read as though it had been honoured.
	 */
	public function test_a_layout_element_carrying_a_widget_type_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'elType' => 'container', 'widgetType' => 'e-heading' ] )
		);
	}

	/**
	 * Issue #102 at the front door: a setting the widget does not declare is
	 * refused before the write, because Elementor would otherwise accept the
	 * save and silently discard the key.
	 */
	public function test_a_setting_the_widget_does_not_declare_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments(
				[
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
					'settings'   => [ 'subtitle' => 'Nope' ],
				]
			)
		);
	}

	/**
	 * A parent the document does not hold is a target that is not there, not a
	 * malformed argument.
	 */
	public function test_a_parent_the_document_does_not_hold_is_a_target_refusal(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::TargetNotFound,
			$this->arguments( [ 'parentElementId' => 'nosuchid', 'elType' => 'container' ] )
		);
	}

	/**
	 * An identifier no stored element could carry is a malformed argument, and
	 * is refused without a tree walk that could never match it.
	 */
	public function test_a_malformed_parent_identifier_is_refused_as_invalid_input(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'parentElementId' => 'has a space', 'elType' => 'container' ] )
		);
	}

	/**
	 * Settings sent as a JSON list rather than as a map are refused: a list has no
	 * setting names, and storing one would give the element settings keyed 0, 1,
	 * 2 that Elementor reads as nothing at all.
	 *
	 * `container` IS THE CASE THAT MATTERS, because the widget-registry key check
	 * is correctly skipped for a layout element — so if the list were not refused
	 * here nothing further down would object to storing it.
	 */
	public function test_settings_sent_as_a_list_are_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'elType' => 'container', 'settings' => [ 'a', 'b' ] ] )
		);
	}

	/**
	 * Settings sent as something that is not a set of values at all — a bare
	 * string — are refused by the same guard's other arm.
	 */
	public function test_settings_sent_as_a_non_array_value_are_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ 'elType' => 'container', 'settings' => 'not-a-map' ] )
		);
	}

	/**
	 * The empty array stays accepted. It is how an empty settings map arrives once
	 * the shared validator has decoded `{}`, and refusing it would refuse every
	 * element that simply carries no settings of its own.
	 */
	public function test_an_empty_settings_map_is_accepted(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$planned = $this->plan( $this->arguments( [ 'elType' => 'container', 'settings' => [] ] ) );

		$this->assertSame( [], $planned->payload[ ElementorElementAddInput::INPUT_SETTINGS ] );
	}

	/**
	 * Asserts one set of arguments is refused with one error code.
	 *
	 * @param ErrorCode            $expected The expected code.
	 * @param array<string, mixed> $input    The arguments.
	 */
	private function assertRefusal( ErrorCode $expected, array $input ): void {
		try {
			$this->plan( $input );
			$this->fail( 'The request must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( $expected, $exception->errorCode );
		}
	}
}
