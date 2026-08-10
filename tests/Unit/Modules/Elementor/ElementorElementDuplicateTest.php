<?php
/**
 * Tests for ElementorElementDuplicate: identifiers, style remap, placement.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorElementDuplicate;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\RelocationFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0039, the fifth of the six Elementor writes.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases distinguish "Elementor is absent" from "you may not
 * edit this", and without isolation which of those a case sees would depend on
 * the alphabetical position of some other test file.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The id minter and the style remapper in particular are
 * real, because the two invariants this operation exists to keep — every id in
 * the copy is new, and no class in the copy still belongs to the source — are
 * properties of what they actually produce.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementDuplicateTest extends TestCase {

	use RelocationFixtures;

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
	 * The registered shape the matrix pins for REQ-0039.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementDuplicate::definition();

		$this->assertSame( 'elementor-element-duplicate', $definition->id );
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
	 * The input schema is CLOSED and declares exactly the two documented members.
	 *
	 * NO DESTINATION AND NO POSITION: a duplicate goes beside its source, and
	 * offering a placement here would be a second, half-specified move operation
	 * living inside this one.
	 */
	public function test_the_input_schema_is_closed_and_names_only_the_document_and_the_element(): void {
		$schema = ElementorElementDuplicate::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'elementId' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'elementId' ], $schema['required'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it, so the writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementDuplicate::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	/**
	 * The three fields that CAN move are promised, and `maxDepth`, which cannot,
	 * is not.
	 *
	 * A copy placed beside its source is at the source's own depth, so the
	 * deepest point of the page is exactly where it was. `elementCount` moves by
	 * the size of the copied subtree — three nodes here — and that number is
	 * asserted literally rather than recomputed.
	 */
	public function test_the_plan_promises_the_counts_a_duplication_moves(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$planned = $this->plan( $this->elementDuplicate(), $this->duplicateArguments() );

		$this->assertSame(
			[
				ElementorWriteFields::FIELD_DIGEST,
				ElementorWriteFields::FIELD_COUNT,
				ElementorWriteFields::FIELD_WIDGETS,
			],
			array_keys( $planned->afterFields )
		);
		$this->assertSame( 7, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeDuplicateFixture();

		try {
			$this->resolved( $this->elementDuplicate(), $this->duplicateArguments() );
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
		$this->storeDuplicateFixture();

		try {
			$this->resolved( $this->elementDuplicate(), $this->duplicateArguments() );
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
	 * Elementor document AND the element id is not one an element can carry.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan( $this->elementDuplicate(), $this->duplicateArguments( [ 'elementId' => 'not a valid id' ] ) );
			$this->fail( 'A page Elementor does not control must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * An element the document does not hold is a target that is not there.
	 */
	public function test_an_element_the_document_does_not_hold_is_refused(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		try {
			$this->plan( $this->elementDuplicate(), $this->duplicateArguments( [ 'elementId' => 'w999999' ] ) );
			$this->fail( 'An element that is not on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'A refused duplication must write nothing.' );
	}

	/**
	 * An element inside a container that stores no id of its own has nowhere a
	 * sibling could be placed, and that is a refusal rather than a quiet move to
	 * the top level.
	 */
	public function test_an_element_whose_container_has_no_identifier_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->orphanTree() ) );

		try {
			$this->plan( $this->elementDuplicate(), $this->duplicateArguments( [ 'elementId' => 'w111111' ] ) );
			$this->fail( 'An element with no addressable parent must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'stores no identifier', $exception->getMessage() );
		}
	}

	// ------------------------------------------------------- determinism

	/**
	 * THE TEST THAT PROTECTS THE WHOLE PHASE.
	 *
	 * The copy's identifiers are MINTED, and a minter fed a clock or a counter
	 * would make every plan this operation issues un-appliable — intermittently,
	 * which is the worst way to find it. The payload carries the whole copied
	 * tree, so this compares the minted ids too.
	 */
	public function test_planning_the_same_duplication_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$operation = $this->elementDuplicate();

		$this->assertSame(
			json_encode( $this->plan( $operation, $this->duplicateArguments() )->payload ),
			json_encode( $this->plan( $operation, $this->duplicateArguments() )->payload ),
			'Two plans for the same duplication against the same state must be byte-identical.'
		);
	}

	/**
	 * The payload is closed and sorted, and names the copy it will create.
	 */
	public function test_the_payload_carries_the_minted_identifier_in_sorted_keys(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$planned = $this->plan( $this->elementDuplicate(), $this->duplicateArguments() );

		$this->assertSame( [ 'document', 'elementId', 'newElementId', 'tree' ], array_keys( $planned->payload ) );
		$this->assertNotSame( 'c111111', $planned->payload['newElementId'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $planned->payload['newElementId'] );
	}

	// ------------------------------------------------------- the placement

	/**
	 * THE COPY LANDS IMMEDIATELY AFTER THE SOURCE, not at the end.
	 *
	 * The fixture has a second root container precisely so that "second" and
	 * "last" are different places; with one root element this claim would be
	 * unfalsifiable.
	 */
	public function test_the_copy_is_placed_immediately_after_the_element_it_was_made_from(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$new_id = $this->duplicateAndReturnNewId();

		$this->assertSame( [ 'c111111', $new_id, 'c222222' ], $this->childIds( null ) );
	}

	/**
	 * EVERY ID IN THE DOCUMENT IS STILL UNIQUE, which is the first of the two
	 * invariants REQ-0039 names.
	 *
	 * The fixture holds four elements and the copied subtree holds three, so a
	 * correct duplication leaves seven ids and seven DISTINCT ids. Mutation-
	 * proved: re-iding only the subtree's root instead of recursing leaves the
	 * copy's two children answering to their source's names, the distinct count
	 * drops to five, and this fails.
	 */
	public function test_no_identifier_in_the_copy_appears_anywhere_else_in_the_document(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$this->duplicateAndReturnNewId();

		$ids = ( new ElementorTreeEdit() )->collectIds( $this->storedTree() );

		$this->assertCount( 7, $ids );
		$this->assertCount( 7, array_unique( $ids ), 'Every element identifier in the document must be unique.' );
	}

	/**
	 * ISSUE #97: NO LOCAL STYLE CLASS IN THE COPY STILL BELONGS TO THE SOURCE.
	 *
	 * A local class is named `e-<elementId>-<hash>`, stored under the owning
	 * node's `styles` key and referenced from `settings.classes.value`. A copy
	 * that kept the source's names would make a later restyle of either element
	 * silently restyle both — invisible until a customer notices.
	 *
	 * The assertion is on the SOURCE ids as literal strings, so it cannot be
	 * satisfied by a remap that renamed things into some other wrong shape.
	 * Mutation-proved: skipping the `ElementorStyleRemap::remap()` call leaves
	 * `e-c111111-aaa111` in the copy and this fails.
	 */
	public function test_the_copy_references_no_style_class_belonging_to_the_source(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$new_id = $this->duplicateAndReturnNewId();
		$names  = $this->styleNamesIn( $this->storedNode( $new_id ) );

		$this->assertNotSame( [], $names, 'The copy must carry the style names the source carried.' );

		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( 'c111111', $name );
			$this->assertStringNotContainsString( 'w111111', $name );
		}
	}

	/**
	 * The copy's style names are bound to the copy's OWN identifiers, and the
	 * hash segment Elementor generated survives the rename.
	 *
	 * The `styles` key and the definition's inner `id` are asserted together,
	 * because Elementor writes the name in both places and a copy whose key and
	 * inner id disagreed would be a document the editor reads one way and the CSS
	 * generator another.
	 */
	public function test_the_copy_owns_its_style_definitions_under_its_own_identifier(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$new_id = $this->duplicateAndReturnNewId();
		$copy   = $this->storedNode( $new_id );

		$this->assertSame( [ 'e-' . $new_id . '-aaa111' ], array_keys( $copy['styles'] ) );
		$this->assertSame( 'e-' . $new_id . '-aaa111', $copy['styles'][ 'e-' . $new_id . '-aaa111' ]['id'] );
		$this->assertSame(
			[ 'e-' . $new_id . '-aaa111', 'g-brand' ],
			$copy['settings']['classes']['value']
		);
	}

	/**
	 * GLOBAL CLASSES ARE NOT TOUCHED. `g-` classes live in Elementor's own
	 * repository rather than in `_elementor_data`, so both elements go on
	 * referencing the one global class — which is what a global class is for —
	 * and the source's own reference is left exactly as it was.
	 */
	public function test_the_global_class_reference_survives_on_both_elements(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$new_id = $this->duplicateAndReturnNewId();

		$this->assertContains( 'g-brand', $this->storedNode( 'c111111' )['settings']['classes']['value'] );
		$this->assertContains( 'g-brand', $this->storedNode( $new_id )['settings']['classes']['value'] );
	}

	/**
	 * IDENTICAL SETTINGS MEANS IDENTICAL AFTER THE REMAP.
	 *
	 * Every setting the source carries is on the copy, byte for byte, except the
	 * class references — those necessarily name the copy's own classes. The
	 * comparison runs on both the container and the widget inside it, because the
	 * widget's settings are the ones the prop coercion envelopes and a copy that
	 * lost the envelope would render differently.
	 */
	public function test_every_setting_except_the_class_references_matches_the_source(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$new_id = $this->duplicateAndReturnNewId();
		$copy   = $this->storedNode( $new_id );

		$this->assertSame(
			$this->settingsWithoutClasses( $this->storedNode( 'c111111' ) ),
			$this->settingsWithoutClasses( $copy )
		);
		$this->assertSame(
			$this->settingsWithoutClasses( $this->storedNode( 'w111111' ) ),
			$this->settingsWithoutClasses( $copy['elements'][0] )
		);
	}

	// ------------------------------------------------------- apply and undo

	/**
	 * The promised digest and the digest a read-back measures are one formula,
	 * so the engine's verification can actually disagree with the promise.
	 */
	public function test_the_promised_digest_matches_the_document_that_is_read_back(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$operation = $this->elementDuplicate();
		$input     = $this->duplicateArguments();
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
	 * A rollback puts the recorded document back, byte for byte — which is what
	 * takes the copy away without having to trust that the minted id still names
	 * the thing this operation created.
	 */
	public function test_a_rollback_restores_the_document_that_was_recorded(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$before    = $this->storedRaw();
		$operation = $this->elementDuplicate();
		$input     = $this->duplicateArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertNotSame( $before, $this->storedRaw(), 'The apply must have changed the document.' );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( $before, $this->storedRaw() );
	}

	/**
	 * A plan whose target names no document writes nothing.
	 */
	public function test_a_plan_naming_no_document_is_refused_without_writing(): void {
		$this->withElementor();
		$this->storeDuplicateFixture();

		$operation = $this->elementDuplicate();
		$planned   = $this->plan( $operation, $this->duplicateArguments() );

		$this->writes = [];

		try {
			$operation->applyChange( new TargetState( 'not-a-key', true, [] ), $planned, $this->context() );
			$this->fail( 'A plan naming no document must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'Nothing may be written for a plan with no document.' );
	}

	/**
	 * The arguments every case sends: duplicate the styled root container.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function duplicateArguments( array $overrides = [] ): array {
		return $this->arguments( array_merge( [ 'elementId' => 'c111111' ], $overrides ) );
	}

	/**
	 * Runs the whole engine sequence and answers the copy's minted identifier.
	 *
	 * The id is read out of the PLAN rather than found in the written document,
	 * so the placement and uniqueness assertions are about a value the preview
	 * showed the operator rather than about whatever happens to be there.
	 *
	 * @return string The copy's identifier.
	 */
	private function duplicateAndReturnNewId(): string {
		$operation = $this->elementDuplicate();
		$input     = $this->duplicateArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );
		$operation->applyChange( $target, $planned, $this->context() );

		return (string) $planned->payload['newElementId'];
	}

	/**
	 * Every style definition name and class reference in one subtree.
	 *
	 * @param array<string, mixed> $node One raw element.
	 *
	 * @return string[] The names, in the order read.
	 */
	private function styleNamesIn( array $node ): array {
		$names = array_keys( is_array( $node['styles'] ?? null ) ? $node['styles'] : [] );

		foreach ( (array) ( $node['settings']['classes']['value'] ?? [] ) as $reference ) {
			$names[] = $reference;
		}

		foreach ( (array) ( $node['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) {
				$names = array_merge( $names, $this->styleNamesIn( $child ) );
			}
		}

		return array_map( 'strval', $names );
	}

	/**
	 * One element's settings with the class references taken out.
	 *
	 * @param array<string, mixed> $node One raw element.
	 *
	 * @return array<string, mixed> The remaining settings.
	 */
	private function settingsWithoutClasses( array $node ): array {
		$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];

		unset( $settings['classes'] );

		return $settings;
	}

	/**
	 * A document whose outer container carries no identifier of its own.
	 *
	 * @return array[] The raw tree.
	 */
	private function orphanTree(): array {
		return [
			[
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w111111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => 'Orphaned heading' ],
						'elements'   => [],
					],
				],
			],
		];
	}
}
