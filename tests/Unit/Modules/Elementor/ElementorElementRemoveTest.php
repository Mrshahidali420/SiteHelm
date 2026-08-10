<?php
/**
 * Tests for ElementorElementRemove: absence after the call, position after undo.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorElementRemove;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\RelocationFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0040, the sixth of the six Elementor writes and the only destructive one.
 *
 * THE FIXTURE IS THE FIVE-SIBLING CONTAINER `RelocationFixtures::moveTree()`
 * builds, and it is the right one for the same reason the move cases use it: the
 * acceptance is about a POSITION among siblings, and a container with two
 * children cannot tell a preserved order from a reversed one. The element these
 * cases remove is the MIDDLE of the five, so a restore that put it back at the
 * front or at the back would be visibly wrong rather than accidentally right.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases distinguish "Elementor is absent" from "you may not
 * edit this", and without isolation which of those a case sees would depend on
 * the alphabetical position of some other test file.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. `update_post_meta()` in particular unslashes what it is
 * handed, exactly as WordPress does, because the writer hands it
 * `wp_slash( wp_json_encode( $tree ) )` and a double that stored the value
 * verbatim would make every digest here disagree with every read.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementRemoveTest extends TestCase {

	use RelocationFixtures;

	/**
	 * The document every case operates on.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * The five siblings the fixture container holds, in stored order.
	 *
	 * @var string[]
	 */
	private const SIBLINGS_BEFORE = [ 'w111111', 'w222222', 'c222222', 'w333333', 'w444444' ];

	/**
	 * The four that remain once the middle sibling is removed.
	 *
	 * @var string[]
	 */
	private const SIBLINGS_AFTER = [ 'w111111', 'w222222', 'w333333', 'w444444' ];

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
	 * The registered shape the matrix pins for REQ-0040.
	 *
	 * `isDestructive: true` is the row's distinguishing member, and it FORCES all
	 * three policies to Required in the OperationDefinition constructor. Both the
	 * flag and the three policies are asserted, because the constructor's coupling
	 * runs the other way: it refuses a mismatch, so a definition that quietly
	 * dropped the flag would declare three merely-supported policies and construct
	 * perfectly well.
	 */
	public function test_the_definition_declares_the_destructive_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementRemove::definition();

		$this->assertSame( 'elementor-element-remove', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( 'elementor-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertTrue( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
		$this->assertArrayHasKey( 'elementor', $definition->supportedVersions );
	}

	/**
	 * The input schema is CLOSED and declares exactly the two documented members.
	 */
	public function test_the_input_schema_is_closed_and_names_only_the_document_and_the_element(): void {
		$schema = ElementorElementRemove::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'elementId' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'elementId' ], $schema['required'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than restating
	 * it, so the six writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementRemove::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	// ------------------------------------------------------- the promise

	/**
	 * The three fields a removal moves are promised, and `maxDepth` is not.
	 *
	 * The fixture holds eight elements and the removed subtree holds three — the
	 * middle container, the container inside it and that one's paragraph — so five
	 * remain and one of the two paragraphs is gone. Both numbers are asserted as
	 * literals rather than recomputed from the tree, because a test that recounts
	 * the thing it is checking cannot detect drift in it.
	 */
	public function test_the_plan_promises_the_counts_a_removal_moves(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$planned = $this->plan( $this->elementRemove(), $this->removeArguments() );

		$this->assertSame(
			[
				ElementorWriteFields::FIELD_DIGEST,
				ElementorWriteFields::FIELD_COUNT,
				ElementorWriteFields::FIELD_WIDGETS,
			],
			array_keys( $planned->afterFields )
		);
		$this->assertSame( 5, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
		$this->assertSame(
			[
				'e-heading'   => 3,
				'e-paragraph' => 1,
			],
			$planned->afterFields[ ElementorWriteFields::FIELD_WIDGETS ]
		);
	}

	/**
	 * The payload is closed, names the element the request asked to remove, and
	 * carries the whole tree the apply writes.
	 *
	 * THIS CASE PINS MEMBERSHIP AND CONTENT, NOT SORTING, and its name and
	 * assertions now say only that. `planChange()` builds the payload from three
	 * literal members — `document`, `elementId`, `tree` — and `SORT_STRING` order
	 * for those three (`d` < `e` < `t`) IS their insertion order, so the
	 * `ksort( $payload, SORT_STRING )` in `ElementorElementRemove::planChange()`
	 * is a no-op. No input can make it observable: the key set is fixed by the
	 * source rather than derived from the request, so there is no reachable call
	 * that produces the members out of order. Deleting that `ksort` leaves this
	 * case green, and MEASURED IT DOES — verified by mutation, not reasoned. A
	 * fixture built only to make the sort observable would have to fabricate a
	 * payload shape `planChange()` cannot produce.
	 *
	 * What would make the sort observable, and what should bring a real assertion
	 * with it: a fourth payload member whose key sorts before an existing one
	 * (anything below `document`), or a member added to the literal out of order.
	 * The sort stays in the source regardless — the payload is fingerprinted at
	 * preview and digest-compared at apply, and cheap insurance against a future
	 * member landing out of order is worth more than the line costs. Determinism
	 * itself is independently pinned by
	 * `test_planning_the_same_removal_twice_produces_a_byte_identical_payload`.
	 */
	public function test_the_payload_carries_the_remaining_tree_and_names_the_removed_element(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$planned = $this->plan( $this->elementRemove(), $this->removeArguments() );

		$this->assertSame(
			[ 'document', 'elementId', 'tree' ],
			array_keys( $planned->payload ),
			'The payload must carry exactly these three members and nothing else.'
		);
		$this->assertSame( 'c222222', $planned->payload['elementId'] );

		// The `tree` member is what the apply writes, so this case asserts it
		// really is the remaining tree rather than leaving the name unbacked:
		// the removed container and its two children are gone, the rest is there.
		$this->assertSame(
			[ 'c111111' ],
			array_column( $planned->payload['tree'], 'id' )
		);
		$this->assertSame(
			[ 'w111111', 'w222222', 'w333333', 'w444444' ],
			array_column( $planned->payload['tree'][0]['elements'], 'id' )
		);
	}

	/**
	 * The payload is fingerprinted at preview and digest-compared at apply, so a
	 * plan that is not byte-reproducible is a plan the engine will refuse —
	 * intermittently, which is the worst way to find it.
	 */
	public function test_planning_the_same_removal_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementRemove();

		$this->assertSame(
			json_encode( $this->plan( $operation, $this->removeArguments() )->payload ),
			json_encode( $this->plan( $operation, $this->removeArguments() )->payload ),
			'Two plans for the same removal against the same state must be byte-identical.'
		);
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeMoveFixture();

		try {
			$this->resolved( $this->elementRemove(), $this->removeArguments() );
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
		$this->storeMoveFixture();

		try {
			$this->resolved( $this->elementRemove(), $this->removeArguments() );
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
			$this->plan( $this->elementRemove(), $this->removeArguments( [ 'elementId' => 'not a valid id' ] ) );
			$this->fail( 'A page Elementor does not control must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * An element the document does not hold is a target that is not there, and
	 * nothing is written for it.
	 */
	public function test_an_element_the_document_does_not_hold_is_refused_without_writing(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		try {
			$this->plan( $this->elementRemove(), $this->removeArguments( [ 'elementId' => 'w999999' ] ) );
			$this->fail( 'An element that is not on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'A refused removal must write nothing.' );
	}

	// ------------------------------------------------- half one: it is absent

	/**
	 * THE FIRST HALF OF THE ACCEPTANCE: the element is not in the tree afterwards,
	 * and neither is anything that was inside it.
	 *
	 * The descendants are asserted separately from the element itself, because a
	 * removal that detached the container but left its children behind at the root
	 * would satisfy a check on the container alone.
	 */
	public function test_the_element_and_everything_inside_it_are_gone_from_the_saved_document(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$this->applied( $this->elementRemove(), $this->removeArguments() );

		$edit = new ElementorTreeEdit();
		$tree = $this->storedTree();

		$this->assertNull( $edit->find( $tree, 'c222222' ), 'The removed element must not be in the saved page.' );
		$this->assertNull( $edit->find( $tree, 'c333333' ), 'A child of the removed element must not survive it.' );
		$this->assertNull( $edit->find( $tree, 'w555555' ), 'A grandchild of the removed element must not survive it.' );
		$this->assertSame( self::SIBLINGS_AFTER, $this->childIds( 'c111111' ) );
	}

	/**
	 * THE RE-READ INSIDE `applyChange()` IS WHAT PROVES THE ABSENCE, and this is
	 * the case that proves the re-read exists.
	 *
	 * The engine's field comparison cannot make this claim on its own: a removal's
	 * acceptance is an ABSENCE, and no promised field has a key whose value is the
	 * non-existence of an id. So the plan handed in below is a well-formed one
	 * whose tree still holds the element the payload names — the document with one
	 * unrelated setting changed, which is a real save the writer accepts because
	 * the stored bytes really do move.
	 *
	 * ARMED ON THE MESSAGE, not only on the code. `ElementorDocumentWriter` raises
	 * `ExecutionFailed` too, for a save that did not change the row, so a test
	 * asserting the code alone would still pass with this operation's re-read
	 * deleted. Mutation-proved: making `assert_gone()` return unconditionally
	 * leaves the apply reporting success and this case fails on the message.
	 */
	public function test_an_apply_that_leaves_the_element_in_the_page_is_refused_by_the_re_read(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementRemove();
		$input     = $this->removeArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		try {
			$operation->applyChange( $target, $this->planKeepingTheElement( $planned ), $this->context() );
			$this->fail( 'A save that left the element in the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertStringContainsString( 'is still in it when the page is read back', $exception->getMessage() );
		}
	}

	/**
	 * The promised digest and the digest a read-back measures are one formula, so
	 * the engine's verification can actually disagree with the promise.
	 */
	public function test_the_promised_digest_matches_the_document_that_is_read_back(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementRemove();
		$input     = $this->removeArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		$read = $operation->readBack( $operation->applyChange( $target, $planned, $this->context() ), $this->context() );

		$this->assertSame(
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ],
			$read->fields[ ElementorWriteFields::FIELD_DIGEST ]
		);
	}

	// --------------------------------------- half two: it comes back in place

	/**
	 * THE SECOND HALF OF THE ACCEPTANCE: the element comes back at its PRIOR
	 * POSITION, with every sibling in its original order.
	 *
	 * The removed element is the MIDDLE of five, so an index restored as 0 or as 4
	 * is a different list from the one asserted here — which is what makes this a
	 * claim about a position rather than about membership. The expected orders are
	 * literal lists, never a recomputation of what the operation produced.
	 *
	 * Mutation-proved: reinserting the element at index 0 during the restore, in
	 * place of replaying the recorded document, leaves the container reading
	 * `c222222, w111111, w222222, w333333, w444444` and this case fails.
	 */
	public function test_a_rollback_returns_the_element_to_its_prior_position_among_its_siblings(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementRemove();
		$input     = $this->removeArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$this->assertSame( self::SIBLINGS_BEFORE, $this->childIds( 'c111111' ) );

		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( self::SIBLINGS_AFTER, $this->childIds( 'c111111' ), 'The apply must have removed the element.' );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( self::SIBLINGS_BEFORE, $this->childIds( 'c111111' ) );
	}

	/**
	 * The subtree that was inside the removed element comes back with it, in its
	 * own original shape — a restore that returned the container empty would put
	 * the element back at the right index and still have lost the content.
	 */
	public function test_a_rollback_returns_the_whole_removed_subtree(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementRemove();
		$input     = $this->removeArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( [ 'c333333' ], $this->childIds( 'c222222' ) );
		$this->assertSame( [ 'w555555' ], $this->childIds( 'c333333' ) );
	}

	/**
	 * The restored row is the recorded row, byte for byte. This is the property
	 * the position claim above rests on, asserted directly so a restore that
	 * happened to reproduce the ordering while changing the encoding is still
	 * caught.
	 */
	public function test_a_rollback_restores_the_document_that_was_recorded(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$before    = $this->storedRaw();
		$operation = $this->elementRemove();
		$input     = $this->removeArguments();
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
		$this->storeMoveFixture();

		$operation = $this->elementRemove();
		$planned   = $this->plan( $operation, $this->removeArguments() );

		$this->writes = [];

		try {
			$operation->applyChange( new TargetState( 'not-a-key', true, [] ), $planned, $this->context() );
			$this->fail( 'A plan naming no document must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'Nothing may be written for a plan with no document.' );
	}

	// ------------------------------------------------------- fixture helpers

	/**
	 * The remove operation, wired exactly as `ElementorModule` wires it.
	 *
	 * REAL COLLABORATORS THROUGHOUT, from the shared trait: the real tree edit,
	 * the real coercion, the real writer, the real target. A stubbed tree edit
	 * would make the "everything inside it went too" claim a claim about the stub,
	 * and a stubbed target would make the restore a claim about nothing at all.
	 *
	 * @return ElementorElementRemove The subject.
	 */
	private function elementRemove(): ElementorElementRemove {
		$parts = $this->collaborators();

		return new ElementorElementRemove(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer'],
			$parts['diff']
		);
	}

	/**
	 * The arguments every case sends: remove the middle of the five siblings.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function removeArguments( array $overrides = [] ): array {
		return $this->arguments( array_merge( [ 'elementId' => 'c222222' ], $overrides ) );
	}

	/**
	 * A plan that names the element but whose tree still contains it.
	 *
	 * This is the state the re-read exists to catch: the write lands, the stored
	 * bytes really move — so `ElementorDocumentWriter`'s own unchanged-row refusal
	 * does not fire — and the element is nonetheless still on the page.
	 *
	 * @param PlannedChange $planned The genuine plan.
	 *
	 * @return PlannedChange The tampered plan.
	 */
	private function planKeepingTheElement( PlannedChange $planned ): PlannedChange {
		$tree = $this->storedTree();

		$tree[0]['settings']['content_width'] = 'full';

		$payload         = $planned->payload;
		$payload['tree'] = $tree;

		return new PlannedChange(
			$payload,
			$planned->afterFields,
			ElementorWriteFields::FIELD_ORDER,
			[],
			$planned->previewDetail
		);
	}
}
