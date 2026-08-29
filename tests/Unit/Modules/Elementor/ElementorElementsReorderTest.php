<?php
/**
 * Tests for ElementorElementsReorder: definition, guard order, permutation, apply.
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
use SiteHelm\Modules\Elementor\ElementorElementsReorder;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\PageLevelFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0103's sibling reorder.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases below distinguish "Elementor is absent" from "you may
 * not edit this", and without isolation which of those a case sees would depend
 * on the alphabetical position of some other test file.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The tree edit in particular is real, because "the order
 * has to name every child exactly once" is a property of what it actually does.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementsReorderTest extends TestCase {

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
	 * The registered shape the matrix pins for REQ-0103's reorder.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementsReorder::definition();

		$this->assertSame( 'elementor-elements-reorder', $definition->id );
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
	 * The input schema is CLOSED, declares exactly the three documented members,
	 * and requires the two a rearrangement cannot be described without.
	 *
	 * `parentElementId` IS NOT REQUIRED, because its absence has a meaning that
	 * is not a guess: the document's top level.
	 */
	public function test_the_input_schema_is_closed_and_requires_the_order(): void {
		$schema = ElementorElementsReorder::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'document', 'parentElementId', 'order' ],
			array_keys( $schema['properties'] )
		);
		$this->assertSame( [ 'document', 'order' ], $schema['required'] );
	}

	/**
	 * The order is a bounded list of unique identifiers, and its members inherit
	 * the shared element-id bounds rather than restating them.
	 *
	 * `uniqueItems` IS ASSERTED because the duplicate refusal in
	 * `ElementorTreeEdit::reorder()` is the second line of defence, not the
	 * first: a list naming one child twice never reaches the operation.
	 */
	public function test_the_order_is_a_bounded_list_of_unique_shared_identifiers(): void {
		$declared = ElementorElementsReorder::definition()->inputSchema['properties']['order'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( 'array', $declared['type'] );
		$this->assertTrue( $declared['uniqueItems'] );
		$this->assertSame( 1, $declared['minItems'] );
		$this->assertSame( ElementorElementsReorder::MAX_CHILDREN, $declared['maxItems'] );
		$this->assertSame( $shared['pattern'], $declared['items']['pattern'] );
		$this->assertSame( $shared['maxLength'], $declared['items']['maxLength'] );
	}

	/**
	 * `parentElementId` accepts null as a VALUE — the document root — and is
	 * otherwise bound exactly as an element id is.
	 */
	public function test_the_parent_accepts_null_and_otherwise_keeps_the_shared_bounds(): void {
		$declared = ElementorElementsReorder::definition()->inputSchema['properties']['parentElementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( [ 'string', 'null' ], $declared['type'] );
		$this->assertSame( $shared['pattern'], $declared['pattern'] );
		$this->assertNotSame( $shared['description'], $declared['description'] );
	}

	/**
	 * ONLY the digest is promised.
	 *
	 * A reorder creates and destroys nothing, so `elementCount` and
	 * `widgetTypeCounts` are the same numbers before and after by construction.
	 * Promising a total that cannot move invites an operator to read "3 widgets,
	 * still 3 widgets" as evidence the change landed.
	 */
	public function test_the_plan_promises_the_digest_and_nothing_that_cannot_move(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->elementsReorder(), $this->reorderArguments() );

		$this->assertSame( [ ElementorWriteFields::FIELD_DIGEST ], array_keys( $planned->afterFields ) );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	/**
	 * The preview carries the structural detail an operator reviews, so a
	 * rearrangement is not approved on a digest alone.
	 */
	public function test_the_plan_carries_structural_preview_detail(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->assertNotSame( [], $this->plan( $this->elementsReorder(), $this->reorderArguments() )->previewDetail );
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
			$this->resolved( $this->elementsReorder(), $this->reorderArguments() );
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
			$this->resolved( $this->elementsReorder(), $this->reorderArguments() );
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
	 * Elementor document AND the order is empty. The target refusal is the one
	 * that must surface, because telling an operator to correct their order for a
	 * page that was never an Elementor document sends them to fix the wrong
	 * thing.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan( $this->elementsReorder(), $this->reorderArguments( [ 'order' => [] ] ) );
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
	public function test_planning_the_same_rearrangement_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementsReorder();

		$this->assertSame(
			json_encode( $this->plan( $operation, $this->reorderArguments() )->payload ),
			json_encode( $this->plan( $operation, $this->reorderArguments() )->payload ),
			'Two plans for the same rearrangement against the same state must be byte-identical.'
		);
	}

	/**
	 * The payload is closed, sorted, and describes the ARRANGEMENT of one
	 * parent's children rather than a finished tree — which is what lets an edit
	 * somebody else made elsewhere on the page survive the apply.
	 */
	public function test_the_payload_carries_the_arrangement_in_sorted_keys(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->elementsReorder(), $this->reorderArguments() );

		$this->assertSame( [ 'document', 'order', 'parentElementId' ], array_keys( $planned->payload ) );
		$this->assertSame( self::DOCUMENT_ID, $planned->payload['document'] );
		$this->assertSame( 'c111111', $planned->payload['parentElementId'] );
		$this->assertSame( [ 'w333333', 'w111111', 'w222222' ], $planned->payload['order'] );
	}

	// ------------------------------------------------------- the permutation

	/**
	 * THE WHOLE PERMUTATION LANDS, which is REQ-0103's acceptance.
	 *
	 * Three siblings, not two: a two-child container cannot tell a permutation
	 * from a reversal. The expectation is a LITERAL LIST rather than a
	 * recomputation of what the reorder should have produced, because a test that
	 * re-derives its expectation from the same rules as the code cannot detect
	 * the two drifting together.
	 */
	public function test_a_rearrangement_puts_every_named_child_where_the_order_named_it(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementsReorder(), $this->reorderArguments() );

		$this->assertSame( [ 'w333333', 'w111111', 'w222222' ], $this->childIds( 'c111111' ) );
	}

	/**
	 * The rest of the document is untouched: the other container keeps its own
	 * child, and the top level keeps its order.
	 */
	public function test_a_rearrangement_leaves_the_rest_of_the_document_alone(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementsReorder(), $this->reorderArguments() );

		$this->assertSame( [ 'c111111', 'c222222' ], $this->childIds( null ) );
		$this->assertSame( [ 'w444444' ], $this->childIds( 'c222222' ) );
	}

	/**
	 * The document's TOP LEVEL is rearrangeable, which is the case a null parent
	 * exists for and the one a "parent is required" reading would lose.
	 */
	public function test_the_top_level_can_be_rearranged_with_a_null_parent(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied(
			$this->elementsReorder(),
			$this->reorderArguments(
				[
					'parentElementId' => null,
					'order'           => [ 'c222222', 'c111111' ],
				]
			)
		);

		$this->assertSame( [ 'c222222', 'c111111' ], $this->childIds( null ) );
	}

	/**
	 * The elements keep their contents. A reorder that rebuilt its nodes rather
	 * than moving them would pass every ordering assertion above while quietly
	 * emptying the widgets.
	 */
	public function test_a_rearranged_element_keeps_its_settings(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->elementsReorder(), $this->reorderArguments() );

		$this->assertSame( 'The old name', $this->storedSettings( 'w222222' )['_title'] ?? null );
	}

	// ------------------------------------------------------- the refusals

	/**
	 * A PARTIAL ORDER IS REFUSED rather than guessed at, which is the rule the
	 * whole operation is built on: honouring it would require inventing a policy
	 * for the children the caller did not mention, and a caller working from a
	 * stale read is far better served by a loud failure than by a silent rule.
	 *
	 * THE MESSAGE AND THE REMEDIATION ARE ASSERTED, not just the code, because
	 * several refusals here carry `InvalidInput` and an operator has to be sent
	 * to read the current children rather than to correct an identifier.
	 */
	public function test_an_order_that_misses_a_child_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan( $this->elementsReorder(), $this->reorderArguments( [ 'order' => [ 'w222222', 'w111111' ] ] ) );
			$this->fail( 'A partial order must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'exactly once', $exception->getMessage() );
			$this->assertStringContainsString( 'elementor-document-get', (string) $exception->remediation );
		}
	}

	/**
	 * An order naming an element that is not a child of the named parent is
	 * refused even when the COUNT matches, so the completeness rule cannot be
	 * satisfied by an id from somewhere else in the document.
	 */
	public function test_an_order_naming_a_stranger_is_refused_even_when_the_count_matches(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->expectException( OperationException::class );

		$this->plan(
			$this->elementsReorder(),
			$this->reorderArguments( [ 'order' => [ 'w111111', 'w222222', 'w444444' ] ] )
		);
	}

	/**
	 * A parent that is not in the document is refused AT PLAN.
	 *
	 * This case deliberately makes NO claim about the stored bytes: it only ever
	 * calls `resolveTarget()` and `planChange()`, neither of which can reach
	 * `writer->write()` under any mutation of the operation, so a byte-identity
	 * assertion here would be true by construction. The byte-identity claim lives
	 * where a write could actually land, in the conflict case below.
	 */
	public function test_a_parent_that_is_not_in_the_document_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan( $this->elementsReorder(), $this->reorderArguments( [ 'parentElementId' => 'c999999' ] ) );
			$this->fail( 'A parent that is not in the document must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * A CHILD THAT STORES NO IDENTIFIER REFUSES THE WHOLE CALL, with its own
	 * message.
	 *
	 * THIS GUARD IS NOT REDUNDANT, and `ElementorTreeEditReorderTest` records
	 * why: the primitive builds its completeness rule out of the children it can
	 * NAME, so a two-child parent holding one idless child accepts a one-element
	 * order and moves the unnameable child wherever the arithmetic puts it. The
	 * refusal has to come from here, before `reorder()` is asked for anything.
	 *
	 * THE MESSAGE IS ASSERTED because the completeness refusal carries the same
	 * code, and "your list is wrong" would send an operator to correct a list
	 * that was right.
	 */
	public function test_a_child_that_stores_no_identifier_refuses_the_whole_call(): void {
		$this->withElementor();
		$this->storeRaw(
			(string) json_encode(
				[
					[
						'id'       => 'c111111',
						'elType'   => 'container',
						'elements' => [
							[
								'id'       => 'w111111',
								'elType'   => 'widget',
								'elements' => [],
							],
							[
								'elType'   => 'widget',
								'elements' => [],
							],
						],
					],
				]
			)
		);

		try {
			$this->plan( $this->elementsReorder(), $this->reorderArguments( [ 'order' => [ 'w111111' ] ] ) );
			$this->fail( 'A parent holding an unnameable child must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'stores no identifier', $exception->getMessage() );
		}
	}

	/**
	 * An empty order is refused in the operation's own words, not only by the
	 * schema. The schema is the outer gate; a caller reaching `planChange()`
	 * directly — as the engine's replay does — meets this one.
	 */
	public function test_an_empty_order_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan( $this->elementsReorder(), $this->reorderArguments( [ 'order' => [] ] ) );
			$this->fail( 'An empty order must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'names no order', $exception->getMessage() );
		}
	}

	/**
	 * A member that is not an identifier is refused rather than cast, so a number
	 * in the list cannot become a string and then fail as a missing child.
	 */
	public function test_an_order_member_that_is_not_an_identifier_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		try {
			$this->plan(
				$this->elementsReorder(),
				$this->reorderArguments( [ 'order' => [ 'w333333', 12, 'w222222' ] ] )
			);
			$this->fail( 'A member that is not an identifier must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'not an element identifier', $exception->getMessage() );
		}
	}

	// ------------------------------------------------------- apply-time state

	/**
	 * A PARENT that left the page between preview and apply is a `Conflict`, not
	 * a target that does not exist: the caller's request was correct when it was
	 * approved, and something else changed the page.
	 *
	 * NO PARTIAL STATE IS PRODUCIBLE, and this is the case that proves it.
	 * `applyChange()` is the only path that reaches `writer->write()`, and it
	 * checks the parent against the document as it reads NOW before it asks
	 * `ElementorTreeEdit::reorder()` for anything. The claim is asserted on the
	 * STORED BYTES rather than on a decoded tree, because a byte comparison
	 * cannot be satisfied by a document that was rewritten into an equivalent
	 * shape.
	 */
	public function test_a_parent_that_vanished_between_preview_and_apply_is_a_conflict(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementsReorder();
		$input     = $this->reorderArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		// Somebody else deletes the container between the approval and the write.
		$tree = $this->pageTree();
		$this->storeRaw( (string) json_encode( [ $tree[1] ] ) );

		// Taken AFTER the third-party edit, so the comparison is against the
		// document the apply actually met, and reset for the same reason: the
		// fixture helpers store verbatim and record nothing, so anything in
		// `$this->writes` from here on was written by the operation.
		$before       = $this->storedRaw();
		$this->writes = [];

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A parent that is no longer on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'no longer on the page', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->storedRaw(), 'A refused apply must leave the stored document untouched.' );
		$this->assertSame( [], $this->writes, 'A refused apply must write nothing.' );
	}

	/**
	 * A CHILD that left the page between preview and apply is refused too, by the
	 * completeness rule itself: the approved order no longer names every child.
	 * Nothing is written, which is the half that matters.
	 */
	public function test_a_child_that_vanished_between_preview_and_apply_writes_nothing(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->elementsReorder();
		$input     = $this->reorderArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		$shrunk = $this->pageTree();
		array_pop( $shrunk[0]['elements'] );
		$this->storeRaw( (string) json_encode( $shrunk ) );

		$before       = $this->storedRaw();
		$this->writes = [];

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'An order that no longer names every child must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
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

		$operation = $this->elementsReorder();
		$input     = $this->reorderArguments();
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
	 * A rollback puts the recorded document back, byte for byte.
	 */
	public function test_a_rollback_restores_the_document_that_was_recorded(): void {
		$this->withElementor();
		$this->storePageFixture();

		$before    = $this->storedRaw();
		$operation = $this->elementsReorder();
		$input     = $this->reorderArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertNotSame( $before, $this->storedRaw(), 'The rearrangement must have changed the document.' );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( $before, $this->storedRaw() );
	}

	/**
	 * The arguments one rearrangement is described by: the fixture's first
	 * container, with its last child brought to the front.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function reorderArguments( array $overrides = [] ): array {
		return $this->arguments(
			array_merge(
				[
					'parentElementId' => 'c111111',
					'order'           => [ 'w333333', 'w111111', 'w222222' ],
				],
				$overrides
			)
		);
	}
}
