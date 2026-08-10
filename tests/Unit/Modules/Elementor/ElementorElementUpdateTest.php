<?php
/**
 * Tests for ElementorElementUpdate: definition, guard order, merge, apply.
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
use SiteHelm\Modules\Elementor\ElementorElementUpdate;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\SettingsUpdateFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0037, the second of the six Elementor writes.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 * The guard-ordering cases below distinguish "Elementor is absent" from "you may
 * not edit this", and without isolation which of those a case sees would depend
 * on the alphabetical position of some other test file — making those assertions
 * true for the wrong reason, or true by accident.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The writer in particular is real, because the silent-save
 * defence and the merge-at-apply behaviour are both properties of what it
 * actually stores.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementUpdateTest extends TestCase {

	use SettingsUpdateFixtures;

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
	 * The registered shape the matrix pins for REQ-0037.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementUpdate::definition();

		$this->assertSame( 'elementor-element-update', $definition->id );
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
	 * The input schema is CLOSED and declares exactly the three documented
	 * members, all of them required.
	 *
	 * A write whose schema admitted an undeclared member would let a caller send
	 * something the payload never carries and read the silence as acceptance.
	 */
	public function test_the_input_schema_is_closed_and_requires_all_three_members(): void {
		$schema = ElementorElementUpdate::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'elementId', 'settings' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'elementId', 'settings' ], $schema['required'] );
		$this->assertSame( 'object', $schema['properties']['settings']['type'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it, so the writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementUpdate::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	/**
	 * ONLY the digest is promised.
	 *
	 * An update changes no element's existence and no element's kind, so a
	 * promised `elementCount` could not move whatever the change did. Promising
	 * a total that cannot change invites an operator to read "3 headings, still
	 * 3 headings" as evidence the change landed.
	 */
	public function test_the_plan_promises_the_digest_and_nothing_that_cannot_move(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->elementUpdate(),
			$this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] )
		);

		$this->assertSame( [ ElementorWriteFields::FIELD_DIGEST ], array_keys( $planned->afterFields ) );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 *
	 * Mutation-proved: moving the presence check above the capability check in
	 * `ElementorWriteTarget::resolve()` turns this into IntegrationUnavailable
	 * and this case fails. That refusal would tell a caller with no rights over
	 * the document whether the site runs Elementor at all, which is site
	 * configuration they are not entitled to.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeFixture();

		try {
			$this->resolved( $this->elementUpdate(), $this->arguments( [ 'elementId' => 'w111111' ] ) );
			$this->fail( 'An unauthorized caller must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the document lookup.
	 *
	 * Mutation-proved: moving the presence check below the document lookup makes
	 * this answer a target that does not exist instead of
	 * IntegrationUnavailable, and the read recorder stops being empty.
	 */
	public function test_an_absent_elementor_is_reported_before_any_document_lookup(): void {
		$this->storeFixture();

		try {
			$this->resolved( $this->elementUpdate(), $this->arguments( [ 'elementId' => 'w111111' ] ) );
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
	 * Elementor document AND the element id is not one an element can carry. The
	 * target refusal is the one that must surface, because telling an operator
	 * to correct their arguments for a page that was never an Elementor document
	 * sends them to fix the wrong thing.
	 *
	 * Mutation-proved: moving the `$current->exists` guard below
	 * `requestedElementId()` in `planChange()` turns this into InvalidInput.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'not a valid id', 'settings' => [ 'title' => 'x' ] ] )
			);
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
	public function test_planning_the_same_change_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Our services' ],
			]
		);

		$first  = $this->plan( $operation, $input );
		$second = $this->plan( $operation, $input );

		$this->assertSame(
			json_encode( $first->payload ),
			json_encode( $second->payload ),
			'Two plans for the same change against the same state must be byte-identical.'
		);
	}

	/**
	 * THE PAYLOAD CARRIES THE DELTA, not the merged result.
	 *
	 * This is what makes the merge base a read at apply rather than a snapshot
	 * taken at preview. A payload carrying the finished settings map would
	 * silently revert whatever somebody else changed in between.
	 *
	 * THE KEY ASSERTION PINS THE PAYLOAD'S MEMBERSHIP, NOT ITS SORTING, and the
	 * message below says only what it can prove. `planChange()` builds the payload
	 * from three literal members — `document`, `elementId`, `settings` — and
	 * `SORT_STRING` order for those three IS their insertion order, so the
	 * `ksort( $payload, SORT_STRING )` at `ElementorElementUpdate::planChange()`
	 * is a no-op that no input can make observable: the key set is fixed by the
	 * source, not derived from the request, so there is no reachable call that
	 * produces them out of order. Deleting that `ksort` leaves this case green,
	 * and MEASURED IT DOES — verified by mutation, not reasoned. A fixture built
	 * only to make the sort observable would have to fabricate a payload shape
	 * `planChange()` cannot produce, which is a different way of writing a test
	 * that proves nothing about the code.
	 *
	 * What would make the sort observable, and what should bring a real assertion
	 * with it: a fourth payload member whose key sorts before an existing one
	 * (anything below `document`), or a member added to the literal out of order.
	 * Determinism itself is independently pinned by
	 * `test_planning_the_same_change_twice_produces_a_byte_identical_payload`,
	 * which fails on any non-reproducible payload however it is ordered.
	 */
	public function test_the_payload_carries_only_the_settings_the_request_asked_for(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->elementUpdate(),
			$this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] )
		);

		$this->assertSame(
			[ 'document', 'elementId', 'settings' ],
			array_keys( $planned->payload ),
			'The payload must carry exactly these three members and nothing else.'
		);
		$this->assertSame( [ 'title' => 'Our services' ], $planned->payload['settings'] );
	}

	// ------------------------------------------------------- input refusals

	/**
	 * THE elType CHECK, and the reason this operation has one.
	 *
	 * Elementor's own `update-atomic-widget` reads a node's `widgetType` without
	 * first checking `elType`, so it "succeeds" against a container by writing
	 * settings no renderer reads — a write that verifies, reports done, and
	 * changes nothing an operator can see. This must be a refusal instead.
	 *
	 * THE MESSAGE IS ASSERTED, not just the code. A container carries no
	 * `widgetType`, so the unknown-key guard downstream refuses this request too
	 * and with the same InvalidInput — asserting the code alone would leave this
	 * test green with the elType check deleted, which is precisely the defect it
	 * exists to catch. Mutation-proved: replacing the elType condition with
	 * `false` leaves the code identical and fails on the message.
	 */
	public function test_a_container_named_with_widget_shaped_settings_is_refused_rather_than_silently_succeeding(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'c111111', 'settings' => [ 'title' => 'Our services' ] ] )
			);
			$this->fail( 'A layout element must not be treated as a widget.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString(
				'layout element',
				$exception->getMessage(),
				'The refusal must be the elType one, not the unknown-key one that would also fire here.'
			);
		}

		$this->assertSame( [], $this->writes, 'A refused plan must not have written anything.' );
	}

	/**
	 * A setting the widget does not declare is refused BEFORE the save (#102).
	 *
	 * Elementor discards an unrecognised alias key rather than refusing it, so a
	 * check made after the save is made on content that is already gone.
	 */
	public function test_a_setting_the_widget_does_not_declare_is_refused_before_anything_is_written(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'not_a_prop' => 'x' ] ] )
			);
			$this->fail( 'An undeclared setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'A refused plan must not have written anything.' );
	}

	/**
	 * An EMPTY settings map is refused.
	 *
	 * It passes the schema — there is no `minProperties` — and planning it would
	 * produce a change whose promised digest equals the digest it started from,
	 * which an operator would have no way to distinguish from a change that did
	 * nothing because it went wrong.
	 */
	public function test_a_request_naming_no_setting_is_refused(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'w111111', 'settings' => [] ] )
			);
			$this->fail( 'A change naming no setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * A well-formed identifier the document does not hold is TargetNotFound
	 * rather than InvalidInput: the argument was not the thing that was wrong.
	 */
	public function test_an_element_the_document_does_not_hold_is_reported_as_a_missing_target(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'w999999', 'settings' => [ 'title' => 'x' ] ] )
			);
			$this->fail( 'An element the page does not hold must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- apply

	/**
	 * The change lands, and every setting the request did not name survives.
	 *
	 * The fixture heading carries a `title_tablet` this request never mentions;
	 * a write that replaced the settings map instead of merging into it would
	 * lose it, and the loss would be invisible in a fixture that only carried
	 * the one key being changed.
	 */
	public function test_applying_the_change_merges_over_the_settings_the_element_already_holds(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->elementUpdate(),
			$this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] )
		);

		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame(
			'Original tablet heading',
			$this->settingValue( 'w111111', 'title_tablet' ),
			'A setting the request never named must survive the write.'
		);
	}

	/**
	 * THE MERGE BASE IS READ AT APPLY, NOT AT PREVIEW.
	 *
	 * Between the plan and the write, somebody else changes a setting this
	 * request never mentions. Their value must survive. If the payload carried
	 * the merged tree built at preview, this write would silently revert a
	 * colleague's edit and report success.
	 *
	 * Cannot-fail mutation: making `store()` merge over the settings captured in
	 * `planChange()` instead of re-reading turns the surviving value back into
	 * the fixture's original and this case fails.
	 */
	public function test_a_setting_changed_between_preview_and_apply_by_somebody_else_survives(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$this->storeRaw( (string) json_encode( $this->treeWithTabletTitle( 'Edited by somebody else' ) ) );

		$operation->captureSnapshot( $target, $this->context() );
		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame(
			'Edited by somebody else',
			$this->settingValue( 'w111111', 'title_tablet' ),
			'The merge base must be the document as it reads at apply.'
		);
	}

	/**
	 * An element that left the page between preview and apply is a CONFLICT, not
	 * a missing target: the caller's request was correct when it was approved,
	 * and something else changed the page.
	 */
	public function test_an_element_removed_between_preview_and_apply_is_reported_as_a_conflict(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$this->storeRaw( (string) json_encode( [] ) );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A vanished element must be refused at apply.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * The written document verifies against the promise, through the same
	 * `fieldsFor()` measurement the promise was built from.
	 */
	public function test_the_document_read_back_after_the_write_carries_the_promised_digest(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		$key       = $operation->applyChange( $target, $planned, $this->context() );
		$persisted = $operation->readBack( $key, $this->context() );

		$this->assertSame(
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ],
			$persisted->fields[ ElementorWriteFields::FIELD_DIGEST ],
			'The promised digest and the persisted digest are one formula.'
		);
	}

	/**
	 * Restoring puts the recorded document back, settings and all.
	 */
	public function test_restoring_the_snapshot_puts_the_original_settings_back(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$target   = $operation->resolveTarget( $input, $this->context() );
		$planned  = $operation->planChange( $target, $input, $this->context() );
		$snapshot = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );

		$this->assertIsArray( $snapshot );
		$operation->restore( $snapshot, $this->context() );

		$this->assertSame( 'Original heading', $this->settingValue( 'w111111', 'title' ) );
	}

	/**
	 * A plan whose target key names no document fails as an execution failure
	 * rather than writing anywhere.
	 */
	public function test_a_plan_naming_no_document_is_refused_at_apply(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

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
	 * The fixture tree with the heading's tablet override set to a given value.
	 *
	 * @param string $tablet_title The tablet title to store.
	 *
	 * @return array[] The raw tree.
	 */
	private function treeWithTabletTitle( string $tablet_title ): array {
		$tree = $this->settingsTree();

		$tree[0]['elements'][0]['settings']['title_tablet'] = $tablet_title;

		return $tree;
	}
}
