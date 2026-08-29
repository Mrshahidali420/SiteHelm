<?php
/**
 * Tests for ElementorElementLabelSet: definition, guard order, naming, clearing.
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
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorElementLabelSet;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\PageLevelFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0103's navigator name.
 *
 * WHAT THIS OPERATION WRITES IS `settings._title`, the custom name Elementor's
 * navigator shows. It is NOT `ElementorTree::label()`, which this module derives
 * for display and never stores. The two are easy to conflate and the cases below
 * are written to keep them apart: every claim here is about the STORED settings.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases distinguish "Elementor is absent" from "you may not
 * edit this", and without isolation which of those a case sees would depend on
 * the alphabetical position of some other test file.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementLabelSetTest extends TestCase {

	use PageLevelFixtures;

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
	 * The registered shape the matrix pins for REQ-0103's navigator name.
	 *
	 * RISK IS LOW, unlike the other two page-level writes. Naming an element
	 * changes what the editor shows and nothing a visitor sees, and grading it
	 * alongside a rearrangement would teach an operator to skim the risk column.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementLabelSet::definition();

		$this->assertSame( 'elementor-element-label-set', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Low, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertArrayHasKey( 'elementor', $definition->supportedVersions );
	}

	/**
	 * The input schema is CLOSED and requires all three members.
	 *
	 * `label` IS REQUIRED even though an empty one is meaningful. Defaulting it
	 * would turn a caller's omission into a silent clearing of a name somebody
	 * put there on purpose.
	 */
	public function test_the_input_schema_is_closed_and_requires_the_name(): void {
		$schema = ElementorElementLabelSet::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'elementId', 'label' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'elementId', 'label' ], $schema['required'] );
		$this->assertSame( 'string', $schema['properties']['label']['type'] );
		$this->assertSame( ElementorElementLabelSet::LABEL_MAX_LENGTH, $schema['properties']['label']['maxLength'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it, so the writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementLabelSet::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	/**
	 * ONLY the digest is promised: naming an element creates and destroys
	 * nothing, so every count the write target measures is the same number
	 * before and after by construction.
	 */
	public function test_the_plan_promises_the_digest_and_nothing_that_cannot_move(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->elementLabelSet(), $this->labelArguments() );

		$this->assertSame( [ ElementorWriteFields::FIELD_DIGEST ], array_keys( $planned->afterFields ) );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 *
	 * That refusal would otherwise tell a caller with no rights over the document
	 * whether the site runs Elementor at all, which is site configuration they
	 * are not entitled to.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storePageFixture();

		try {
			$this->resolved( $this->elementLabelSet(), $this->labelArguments() );
			$this->fail( 'An unauthorized caller must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the document lookup.
	 */
	public function test_an_absent_elementor_is_reported_before_any_document_lookup(): void {
		$this->storePageFixture();

		try {
			$this->resolved( $this->elementLabelSet(), $this->labelArguments() );
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
	 * Elementor document AND the name is not text. The target refusal is the one
	 * that must surface, because telling an operator to correct their name for a
	 * page that was never an Elementor document sends them to fix the wrong
	 * thing.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'label' => 12 ] ) );
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
	 * the write, and the engine compares the two payloads by digest. A timestamp
	 * or a `wp_unique_id()` anywhere in this operation would make every plan it
	 * issues un-appliable — intermittently, which is the worst way to find it.
	 */
	public function test_planning_the_same_name_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementLabelSet();

		$this->assertSame(
			json_encode( $this->plan( $operation, $this->labelArguments() )->payload ),
			json_encode( $this->plan( $operation, $this->labelArguments() )->payload ),
			'Two plans for the same name against the same state must be byte-identical.'
		);
	}

	/**
	 * The payload is closed, sorted, and carries the TRIMMED name — the one that
	 * will be stored, not the one that was sent, so an operator approves what
	 * actually lands.
	 */
	public function test_the_payload_carries_the_trimmed_name_in_sorted_keys(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'label' => "  Hero heading \n" ] ) );

		$this->assertSame( [ 'document', 'elementId', 'label' ], array_keys( $planned->payload ) );
		$this->assertSame( self::DOCUMENT_ID, $planned->payload['document'] );
		$this->assertSame( 'w111111', $planned->payload['elementId'] );
		$this->assertSame( 'Hero heading', $planned->payload['label'] );
	}

	// ------------------------------------------------------- the naming

	/**
	 * A name lands in `settings._title`, which is the key Elementor's navigator
	 * reads. The assertion names that key literally rather than going through the
	 * operation's own constant, because a rename of the constant that pointed it
	 * at a different key would otherwise stay green while the navigator went
	 * blank.
	 */
	public function test_a_name_lands_in_the_navigator_setting(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementLabelSet(), $this->labelArguments() );

		$this->assertSame( 'Hero heading', $this->storedSettings( 'w111111' )['_title'] ?? null );
	}

	/**
	 * NAMING MERGES, it does not replace. The widget's own props are still there
	 * afterwards, which is the invariant a `withSettings()` call that passed only
	 * the new key would break.
	 */
	public function test_naming_an_element_leaves_its_other_settings_alone(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementLabelSet(), $this->labelArguments() );

		$this->assertSame(
			$this->enveloped( 'First heading' ),
			$this->storedSettings( 'w111111' )['title'] ?? null
		);
	}

	/**
	 * An element that stores no settings at all can still be named, and a
	 * CONTAINER can be named as well as a widget — which is the case a navigator
	 * is mostly used for, since a page's sections are what an editor scrolls
	 * past looking for the one they want.
	 */
	public function test_a_container_that_stores_no_settings_can_be_named(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementLabelSet(), $this->labelArguments( [ 'elementId' => 'c222222' ] ) );

		$this->assertSame( 'Hero heading', $this->storedSettings( 'c222222' )['_title'] ?? null );
	}

	/**
	 * CLEARING REMOVES THE KEY, rather than storing an empty string.
	 *
	 * The distinction is the whole point of the empty name: an ABSENT `_title`
	 * makes Elementor fall back to the element's type, and a `_title` of `""`
	 * makes it show a blank row. `array_key_exists` is asserted rather than the
	 * value, because a stored `""` reads as "no name" through every `?? null`
	 * and would pass a value comparison.
	 */
	public function test_clearing_a_name_removes_the_setting_rather_than_emptying_it(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied(
			$this->elementLabelSet(),
			$this->labelArguments(
				[
					'elementId' => 'w222222',
					'label'     => '',
				]
			)
		);

		$this->assertArrayNotHasKey( '_title', $this->storedSettings( 'w222222' ) );
	}

	/**
	 * Clearing a name leaves the element's other settings where they were: it
	 * removes one key, not the settings map.
	 */
	public function test_clearing_a_name_leaves_the_other_settings_alone(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied(
			$this->elementLabelSet(),
			$this->labelArguments(
				[
					'elementId' => 'w222222',
					'label'     => '',
				]
			)
		);

		$this->assertSame(
			$this->enveloped( 'Second heading' ),
			$this->storedSettings( 'w222222' )['title'] ?? null
		);
	}

	/**
	 * A name of nothing but whitespace CLEARS, because it trims to empty. Storing
	 * it would put an invisible name in the navigator that an operator could not
	 * see to remove.
	 */
	public function test_a_name_of_only_whitespace_clears_the_name(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied(
			$this->elementLabelSet(),
			$this->labelArguments(
				[
					'elementId' => 'w222222',
					'label'     => '   ',
				]
			)
		);

		$this->assertArrayNotHasKey( '_title', $this->storedSettings( 'w222222' ) );
	}

	/**
	 * Naming one element names ONLY that element.
	 */
	public function test_naming_one_element_does_not_name_its_siblings(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementLabelSet(), $this->labelArguments() );

		$this->assertSame( 'The old name', $this->storedSettings( 'w222222' )['_title'] ?? null );
		$this->assertArrayNotHasKey( '_title', $this->storedSettings( 'w333333' ) );
	}

	// ------------------------------------------------------- the refusals

	/**
	 * An element that is not on the page is refused AT PLAN.
	 */
	public function test_an_element_that_is_not_on_the_page_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'elementId' => 'w999999' ] ) );
			$this->fail( 'An element that is not on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * A name that is not text is refused rather than cast, so a number does not
	 * become the string it happens to print as.
	 */
	public function test_a_name_that_is_not_text_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'label' => 12 ] ) );
			$this->fail( 'A name that is not text must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'is not text', $exception->getMessage() );
		}
	}

	/**
	 * A name past the bound is refused rather than truncated.
	 *
	 * THE BOUND IS COUNTED IN CHARACTERS, NOT BYTES, and the case is built from a
	 * multi-byte character to say so: a `strlen()` reading would refuse this name
	 * at a third of its declared length and would refuse plenty of legitimate
	 * ones with it.
	 */
	public function test_a_name_past_the_bound_is_refused_by_characters_rather_than_bytes(): void {
		$this->withElementor();
		$this->storePageFixture();

		$within = str_repeat( 'é', ElementorElementLabelSet::LABEL_MAX_LENGTH );

		$this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'label' => $within ] ) );

		try {
			$this->plan( $this->elementLabelSet(), $this->labelArguments( [ 'label' => $within . 'é' ] ) );
			$this->fail( 'A name past the bound must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'longer than', $exception->getMessage() );
		}
	}

	// ------------------------------------------------------- apply-time state

	/**
	 * An element that left the page between preview and apply is a `Conflict`,
	 * not a target that does not exist: the caller's request was correct when it
	 * was approved, and something else changed the page.
	 *
	 * NO PARTIAL STATE IS PRODUCIBLE, and this is the case that proves it.
	 * `applyChange()` is the only path that reaches `writer->write()`, and it
	 * checks the element against the document as it reads NOW before it asks for
	 * a relabelled tree. The claim is asserted on the STORED BYTES rather than on
	 * a decoded tree, because a byte comparison cannot be satisfied by a document
	 * that was rewritten into an equivalent shape.
	 */
	public function test_an_element_that_vanished_between_preview_and_apply_is_a_conflict(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementLabelSet();
		$input     = $this->labelArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		// Somebody else deletes the widget between the approval and the write.
		$shrunk = $this->pageTree();
		array_shift( $shrunk[0]['elements'] );
		$this->storeRaw( (string) json_encode( $shrunk ) );

		// Taken AFTER the third-party edit, so the comparison is against the
		// document the apply actually met, and reset for the same reason: the
		// fixture helpers store verbatim and record nothing, so anything in
		// `$this->writes` from here on was written by the operation.
		$before       = $this->storedRaw();
		$this->writes = [];

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'An element that is no longer on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'no longer on the page', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->storedRaw(), 'A refused apply must leave the stored document untouched.' );
		$this->assertSame( [], $this->writes, 'A refused apply must write nothing.' );
	}

	/**
	 * The promised digest and the digest a read-back measures are one formula,
	 * so the engine's verification can actually disagree with the promise.
	 */
	public function test_the_promised_digest_matches_the_document_that_is_read_back(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementLabelSet();
		$input     = $this->labelArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		$read = $operation->readBack( $operation->applyChange( $target, $planned, $this->context() ), $this->context() );

		$this->assertSame(
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ],
			$read->fields[ ElementorWriteFields::FIELD_DIGEST ]
		);
	}

	/**
	 * A rollback puts the recorded document back, byte for byte — including the
	 * name that was there before, which is the half a clearing could lose.
	 */
	public function test_a_rollback_restores_the_name_that_was_recorded(): void {
		$this->withElementor();
		$this->storePageFixture();

		$before    = $this->storedRaw();
		$operation = $this->elementLabelSet();
		$input     = $this->labelArguments(
			[
				'elementId' => 'w222222',
				'label'     => '',
			]
		);
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertArrayNotHasKey( '_title', $this->storedSettings( 'w222222' ) );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( $before, $this->storedRaw() );
		$this->assertSame( 'The old name', $this->storedSettings( 'w222222' )['_title'] ?? null );
	}

	/**
	 * The arguments one naming is described by.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function labelArguments( array $overrides = [] ): array {
		return $this->arguments(
			array_merge(
				[
					'elementId' => 'w111111',
					'label'     => 'Hero heading',
				],
				$overrides
			)
		);
	}
}
