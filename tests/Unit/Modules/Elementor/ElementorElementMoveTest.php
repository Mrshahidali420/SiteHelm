<?php
/**
 * Tests for ElementorElementMove: definition, guard order, ordering, apply.
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
use SiteHelm\Modules\Elementor\ElementorElementMove;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\RelocationFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0038, the fourth of the six Elementor writes.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases below distinguish "Elementor is absent" from "you may
 * not edit this", and without isolation which of those a case sees would depend
 * on the alphabetical position of some other test file.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The tree edit in particular is real, because "no partial
 * state is producible" and "a move into a descendant is refused" are properties
 * of what it actually does.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementMoveTest extends TestCase {

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
	 * The registered shape the matrix pins for REQ-0038.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementMove::definition();

		$this->assertSame( 'elementor-element-move', $definition->id );
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
	 * The input schema is CLOSED, declares exactly the four documented members,
	 * and requires the three that a move cannot be described without.
	 *
	 * `index` IS REQUIRED. A move with no position does not say where the element
	 * should end up, and defaulting it would turn a caller's omission into a
	 * silent relocation to the front of the destination.
	 */
	public function test_the_input_schema_is_closed_and_requires_the_position(): void {
		$schema = ElementorElementMove::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'document', 'elementId', 'parentElementId', 'index' ],
			array_keys( $schema['properties'] )
		);
		$this->assertSame( [ 'document', 'elementId', 'index' ], $schema['required'] );
		$this->assertSame( 'integer', $schema['properties']['index']['type'] );
		$this->assertSame( 0, $schema['properties']['index']['minimum'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it, so the writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementMove::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	/**
	 * `parentElementId` accepts null as a VALUE — the document root — and is
	 * otherwise bound exactly as an element id is.
	 */
	public function test_the_destination_accepts_null_and_otherwise_keeps_the_shared_bounds(): void {
		$declared = ElementorElementMove::definition()->inputSchema['properties']['parentElementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( [ 'string', 'null' ], $declared['type'] );
		$this->assertSame( $shared['pattern'], $declared['pattern'] );
		$this->assertSame( $shared['maxLength'], $declared['maxLength'] );
		$this->assertNotSame( $shared['description'], $declared['description'] );
	}

	/**
	 * ONLY the digest is promised.
	 *
	 * A move creates and destroys nothing, so `elementCount` and
	 * `widgetTypeCounts` are the same numbers before and after by construction.
	 * Promising a total that cannot move invites an operator to read "5 widgets,
	 * still 5 widgets" as evidence the change landed.
	 */
	public function test_the_plan_promises_the_digest_and_nothing_that_cannot_move(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$planned = $this->plan( $this->elementMove(), $this->moveArguments() );

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
		$this->storeMoveFixture();

		try {
			$this->resolved( $this->elementMove(), $this->moveArguments() );
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
			$this->resolved( $this->elementMove(), $this->moveArguments() );
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
	 * Elementor document AND the position is not a whole number. The target
	 * refusal is the one that must surface, because telling an operator to
	 * correct their arguments for a page that was never an Elementor document
	 * sends them to fix the wrong thing.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan( $this->elementMove(), $this->moveArguments( [ 'index' => 'first' ] ) );
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
	public function test_planning_the_same_move_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementMove();

		$this->assertSame(
			json_encode( $this->plan( $operation, $this->moveArguments() )->payload ),
			json_encode( $this->plan( $operation, $this->moveArguments() )->payload ),
			'Two plans for the same move against the same state must be byte-identical.'
		);
	}

	/**
	 * The payload is closed, sorted, and describes the RELOCATION rather than a
	 * finished tree — which is what lets an edit somebody else made elsewhere on
	 * the page survive the apply.
	 */
	public function test_the_payload_carries_the_relocation_in_sorted_keys(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$planned = $this->plan( $this->elementMove(), $this->moveArguments() );

		$this->assertSame(
			[ 'document', 'elementId', 'index', 'parentElementId' ],
			array_keys( $planned->payload )
		);
		$this->assertSame( 'w444444', $planned->payload['elementId'] );
		$this->assertSame( 1, $planned->payload['index'] );
		$this->assertSame( 'c111111', $planned->payload['parentElementId'] );
	}

	// ------------------------------------------------------- the ordering

	/**
	 * SIBLING ORDER PRESERVED, which is REQ-0038's acceptance.
	 *
	 * Five siblings, not two: a two-child container cannot tell a preserved order
	 * from a reversed one. The moved element leaves position four and arrives at
	 * position one, and the other four keep their relative order exactly.
	 *
	 * The expectation is a LITERAL LIST rather than a recomputation of what the
	 * move should have produced, because a test that re-derives its expectation
	 * from the same rules as the code cannot detect the two drifting together.
	 */
	public function test_moving_one_sibling_leaves_every_other_sibling_in_its_relative_order(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$this->applied( $this->elementMove(), $this->moveArguments() );

		$this->assertSame(
			[ 'w111111', 'w444444', 'w222222', 'c222222', 'w333333' ],
			$this->childIds( 'c111111' )
		);
	}

	/**
	 * A move to the document root places the element beside the top-level
	 * container rather than inside it, and takes it out of its old parent.
	 */
	public function test_a_move_to_the_document_root_places_the_element_at_the_top_level(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$this->applied( $this->elementMove(), $this->moveArguments( [ 'parentElementId' => null ] ) );

		$this->assertSame( [ 'c111111', 'w444444' ], $this->childIds( null ) );
		$this->assertSame( [ 'w111111', 'w222222', 'c222222', 'w333333' ], $this->childIds( 'c111111' ) );
	}

	/**
	 * NO PARTIAL STATE IS PRODUCIBLE.
	 *
	 * `ElementorTreeEdit::move()` finds and validates before it removes, so a
	 * refused move returns the caller's tree untouched. The claim is asserted on
	 * the STORED BYTES rather than on a decoded tree, because a byte comparison
	 * cannot be satisfied by a document that was rewritten into an equivalent
	 * shape.
	 */
	public function test_a_move_into_a_destination_that_is_not_there_leaves_the_document_byte_identical(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$before = $this->storedRaw();

		try {
			$this->plan( $this->elementMove(), $this->moveArguments( [ 'parentElementId' => 'c999999' ] ) );
			$this->fail( 'A destination that is not in the document must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( $before, $this->storedRaw(), 'A refused move must leave the stored document untouched.' );
		$this->assertSame( [], $this->writes, 'A refused move must write nothing.' );
	}

	/**
	 * A MOVE INTO THE ELEMENT'S OWN DESCENDANT IS REFUSED.
	 *
	 * It is the ordinary way a caller gets a parent id wrong, and honouring it
	 * would detach the whole subtree from the document — a deletion wearing a
	 * relocation's clothes.
	 *
	 * THE MESSAGE IS ASSERTED, not just the code. With the descendant guard
	 * deleted the move removes the element first and then cannot find the
	 * destination, which refuses too — with a DIFFERENT code today, but a test
	 * that leaned on that would be resting on the accident that two guards
	 * happened to disagree. Mutation-proved: with the guard removed this case
	 * reports TargetNotFound and the message assertion is what pins which guard
	 * did the refusing.
	 */
	public function test_moving_an_element_inside_its_own_descendant_is_refused(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$before = $this->storedRaw();

		try {
			$this->plan(
				$this->elementMove(),
				$this->moveArguments( [ 'elementId' => 'c222222', 'parentElementId' => 'c333333', 'index' => 0 ] )
			);
			$this->fail( 'A move into the element\'s own descendant must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'inside itself', $exception->getMessage() );
		}

		$this->assertSame( $before, $this->storedRaw(), 'A refused move must leave the stored document untouched.' );
	}

	/**
	 * A position that is not a whole number is refused rather than cast.
	 */
	public function test_a_position_below_zero_is_refused(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$this->expectException( OperationException::class );

		$this->plan( $this->elementMove(), $this->moveArguments( [ 'index' => -1 ] ) );
	}

	/**
	 * A position past the end of the destination is CLAMPED, not refused: a plan
	 * built against one state and applied against a slightly smaller one must
	 * still land, and "past the last child" means "last".
	 */
	public function test_a_position_past_the_last_child_places_the_element_last(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$this->applied( $this->elementMove(), $this->moveArguments( [ 'elementId' => 'w111111', 'index' => 99 ] ) );

		$this->assertSame(
			[ 'w222222', 'c222222', 'w333333', 'w444444', 'w111111' ],
			$this->childIds( 'c111111' )
		);
	}

	// ------------------------------------------------------- apply-time state

	/**
	 * An element that left the page between preview and apply is a CONFLICT, not
	 * a target that does not exist: the caller's request was correct when it was
	 * approved, and something else changed the page.
	 */
	public function test_an_element_that_vanished_between_preview_and_apply_is_a_conflict(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementMove();
		$input     = $this->moveArguments();
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );
		$this->storeWithout( 'w444444' );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'An element that is no longer on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'element this change was approved for', $exception->getMessage() );
		}
	}

	/**
	 * A DESTINATION that left the page between preview and apply is a conflict
	 * too, and says so in its own words.
	 *
	 * THE MESSAGE IS ASSERTED because the element-gone refusal above carries the
	 * same code: an operator has to know WHICH end of the move went away, and a
	 * test that checked only the code would stay green if this refusal were
	 * deleted and the other one caught the case.
	 */
	public function test_a_destination_that_vanished_between_preview_and_apply_is_a_conflict(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementMove();
		$input     = $this->moveArguments( [ 'elementId' => 'w111111', 'parentElementId' => 'c222222' ] );
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );
		$this->storeWithout( 'c222222' );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A destination that is no longer on the page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'approved to move into', $exception->getMessage() );
		}
	}

	/**
	 * The promised digest and the digest a read-back measures are one formula,
	 * so the engine's verification can actually disagree with the promise.
	 */
	public function test_the_promised_digest_matches_the_document_that_is_read_back(): void {
		$this->withElementor();
		$this->storeMoveFixture();

		$operation = $this->elementMove();
		$input     = $this->moveArguments();
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
		$this->storeMoveFixture();

		$before    = $this->storedRaw();
		$operation = $this->elementMove();
		$input     = $this->moveArguments();
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

		$operation = $this->elementMove();
		$planned   = $this->plan( $operation, $this->moveArguments() );

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
	 * The arguments the ordering cases send: move the last of the five siblings
	 * to position one within its own container.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function moveArguments( array $overrides = [] ): array {
		return $this->arguments(
			array_merge(
				[
					'elementId'       => 'w444444',
					'parentElementId' => 'c111111',
					'index'           => 1,
				],
				$overrides
			)
		);
	}

	/**
	 * Stores the fixture document with one element taken out of it, standing in
	 * for somebody else editing the page between preview and apply.
	 *
	 * The removal runs through the real `ElementorTreeEdit`, which is a
	 * collaborator this operation also uses — but only to BUILD the fixture, not
	 * to compute anything the assertions then compare against.
	 *
	 * @param string $element_id The element to take out.
	 */
	private function storeWithout( string $element_id ): void {
		$this->storeRaw( (string) json_encode( ( new ElementorTreeEdit() )->remove( $this->storedTree(), $element_id ) ) );
	}
}
