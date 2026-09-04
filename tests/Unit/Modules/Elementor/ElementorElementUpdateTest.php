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
	 * THE elType DISPATCH, and the protection it must keep while permitting a
	 * container write.
	 *
	 * `title` is `e-heading`'s control and NOT a container's. Elementor renders a
	 * container from its own settings and ignores widget settings entirely, so a
	 * container validated against widget schema would accept this key, store it,
	 * verify green and change nothing an operator can see — the exact failure
	 * Elementor's own `update-atomic-widget` produces by reading `widgetType`
	 * without first reading `elType`.
	 *
	 * THIS IS THE TEST THAT SEPARATES THE FIX FROM ITS DISSOLUTION. The blanket
	 * refusal of every layout element that this operation used to carry is gone —
	 * a container's padding is writable now, and the case below proves it — so
	 * the only thing standing between a container and a widget's vocabulary is
	 * that the schema is resolved from the ELEMENT registry. Point
	 * `assertKnownKeys()` at the widget registry instead and this fails: the
	 * fixture container declares `padding`, `content_width` and `flex_gap`, and
	 * the fixture `e-heading` declares `title`, so exactly one of the two
	 * vocabularies accepts this request.
	 */
	public function test_a_control_a_widget_declares_but_a_container_does_not_is_refused_on_a_container(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'c111111', 'settings' => [ 'title' => 'Our services' ] ] )
			);
			$this->fail( 'A widget control must not be writable on a container.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString(
				'a setting named "title"',
				$exception->getMessage(),
				'The refusal must name the key the container does not declare.'
			);
		}

		$this->assertSame(
			[ 'content_width' => 'boxed' ],
			$this->storedSettings( 'c111111' ),
			'Nothing may have been written.'
		);
	}

	/**
	 * The defect this operation shipped with: a container's own settings were
	 * unwritable by any operation.
	 *
	 * Elementor's kit insets every container by 10px on all four sides, so a
	 * page built entirely through this plugin could never be made full-bleed —
	 * there was no operation that could set a container's padding to 0. The
	 * write has to reach the STORED TREE, not merely be planned, which is why
	 * this reads the settings back out of the fixture meta.
	 *
	 * `content_width` is asserted alongside because the merge is additive: a
	 * container write that replaced the settings map would be visible here as a
	 * lost value rather than only as a missing one.
	 */
	public function test_a_container_setting_is_written_to_the_stored_tree(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->elementUpdate(),
			$this->arguments(
				[
					'elementId' => 'c111111',
					'settings'  => [ 'padding' => [ 'top' => '0' ] ],
				]
			)
		);

		$this->assertSame( [ 'top' => '0' ], $this->settingValue( 'c111111', 'padding' ) );
		$this->assertSame( 'boxed', $this->settingValue( 'c111111', 'content_width' ) );
	}

	/**
	 * A node whose type the registry cannot read at all is still refused, and
	 * the refusal NAMES THE TYPE.
	 *
	 * The one refusal the dispatch keeps. `unknown-block` is registered in
	 * neither registry — the shape a page holds after the plugin that provided
	 * an element type is deactivated — and an operator who is told only that
	 * "an element" could not be read has to go and find it themselves.
	 */
	public function test_an_element_type_the_registry_cannot_read_is_refused_and_named(): void {
		$this->withElementor();
		$this->storeRaw(
			(string) json_encode(
				[
					[
						'id'       => 'c111111',
						'elType'   => 'unknown-block',
						'settings' => [],
						'elements' => [],
					],
				]
			)
		);

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'c111111', 'settings' => [ 'padding' => [] ] ] )
			);
			$this->fail( 'An unreadable element type must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertStringContainsString( '"unknown-block"', $exception->getMessage() );
		}
	}

	/**
	 * A node recording no `elType` at all has no vocabulary to be checked
	 * against, and is refused before any tree is edited.
	 */
	public function test_an_element_recording_no_kind_is_refused(): void {
		$this->withElementor();
		$this->storeRaw(
			(string) json_encode(
				[
					[
						'id'       => 'c111111',
						'settings' => [],
						'elements' => [],
					],
				]
			)
		);

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'c111111', 'settings' => [ 'padding' => [] ] ] )
			);
			$this->fail( 'An element recording no kind must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'does not record what kind of element it is', $exception->getMessage() );
		}
	}

	/**
	 * A setting the widget does not declare is refused AT PLAN TIME (#102).
	 *
	 * Elementor discards an unrecognised alias key rather than refusing it, so a
	 * check made after the save is made on content that is already gone.
	 *
	 * THE NAME SAYS "PLANNED" AND NOT "BEFORE ANYTHING IS WRITTEN" because this
	 * case only plans. `resolveTarget()` and `planChange()` cannot reach the
	 * writer under any mutation of this operation, so an assertion here that
	 * nothing was written would be true by construction and would tell a reader
	 * auditing #102 that a claim was covered when nothing had checked it. What
	 * makes the refusal fail closed on the APPLY call is that `ChangeEngine`
	 * re-runs `planChange()` there, so this same guard runs again before any
	 * write — a property of the engine, asserted where the engine is.
	 */
	public function test_a_setting_the_widget_does_not_declare_is_refused_when_the_change_is_planned(): void {
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
	}

	/**
	 * A setting Elementor would store and never render is refused HERE, not only
	 * in the coercion layer's own tests.
	 *
	 * The gate lives inside `assertKnownKeys()` precisely so that every write
	 * path inherits it without an edit; this asserts that the inheritance is
	 * real for the commonest write of all. The fixture container declares
	 * `background_color` gated on `background_background`, and the stored
	 * container holds neither.
	 */
	public function test_a_setting_whose_companion_switcher_is_unset_is_refused(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementUpdate(),
				$this->arguments( [ 'elementId' => 'c111111', 'settings' => [ 'background_color' => '#ff0000' ] ] )
			);
			$this->fail( 'A background colour with no background switcher stores and renders nothing.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString(
				'a setting named "background_background"',
				$exception->getMessage(),
				'The refusal must name the companion the caller has to send.'
			);
		}
	}

	/**
	 * The remediation the refusal asks for is accepted on this same path.
	 *
	 * A refusal naming a companion that the operation then also rejects would be
	 * worse than no gate at all, so the working direction is pinned beside the
	 * refusing one.
	 */
	public function test_the_same_write_carrying_its_companion_switcher_is_planned(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->elementUpdate(),
			$this->arguments(
				[
					'elementId' => 'c111111',
					'settings'  => [
						'background_background' => 'classic',
						'background_color'      => '#ff0000',
					],
				]
			)
		);

		$this->assertSame(
			[
				'background_background' => 'classic',
				'background_color'      => '#ff0000',
			],
			$planned->payload['settings'],
			'Sending the companion alongside is the whole remediation; it has to reach the payload intact.'
		);
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

	// ------------------------------------------------------- media advisory

	/**
	 * A MEDIA VALUE WITH NO ATTACHMENT ID STORES, READS BACK AND VERIFIES GREEN,
	 * and still puts an unresponsive full-size image on the page: WordPress
	 * builds `srcset`, the `wp-image` class and lazy-loading from the attachment
	 * record, not the URL. The plan is where an operator sees it in time.
	 */
	public function test_a_bare_media_url_warns_on_the_plan(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => self::containerId(),
				'settings'  => [
					'background_background' => 'classic',
					'background_image'      => [ 'url' => 'https://elsewhere.example/hero.jpg' ],
				],
			]
		);

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$this->assertCount( 1, $planned->warnings, 'One bare media value earns one advisory.' );
		$this->assertStringContainsString( '"background_image"', $planned->warnings[0], 'The operator has to learn which setting to fix.' );
	}

	/**
	 * A media value carrying its attachment is the correct write and says
	 * nothing, which is what keeps the advisory worth reading.
	 */
	public function test_a_media_value_with_an_attachment_warns_about_nothing(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => self::containerId(),
				'settings'  => [
					'background_background' => 'classic',
					'background_image'      => [ 'id' => 4242, 'url' => 'https://example.test/hero.jpg' ],
				],
			]
		);

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$this->assertSame( [], $planned->warnings, 'This is the write the advisory exists to ask for.' );
	}

	// ------------------------------------------------------------ rich text

	/**
	 * Rewording a paragraph keeps the editor's formatting tree.
	 *
	 * THE PAGE RENDERED EITHER WAY, which is why this went unnoticed: the words
	 * on screen were the new ones and the write reported success. What went
	 * missing was the link somebody had put inside the sentence, and the only
	 * place it showed up was in the editor, later, with nothing to say when.
	 */
	public function test_a_text_update_keeps_the_stored_editor_tree(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w333333',
				'settings'  => [ 'paragraph' => 'Call our team today' ],
			]
		);

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );
		$operation->applyChange( $target, $planned, $this->context() );

		$stored = $this->storedSettings( 'w333333' )['paragraph'];

		$this->assertSame(
			'Call our team today',
			$stored['value']['content']['value'],
			'The words are the ones the caller asked for, in the place the editor reads them.'
		);
		$this->assertSame(
			[
				[
					'id'       => 'a1',
					'type'     => 'link',
					'content'  => 'us',
					'children' => [],
				],
			],
			$stored['value']['children'],
			'Nobody asked for the link to be deleted.'
		);
	}

	/**
	 * The kept formatting is said out loud on the plan.
	 *
	 * Elementor anchors each run to a position in the text it was written
	 * against, so carrying it across a rewritten sentence can leave the emphasis
	 * on the wrong words. Keeping it is still the right default; this is what
	 * stops the choice being silent.
	 */
	public function test_kept_formatting_earns_an_advisory(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w333333',
				'settings'  => [ 'paragraph' => 'Call our team today' ],
			]
		);

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$this->assertCount( 1, $planned->warnings, 'One reworded rich-text key earns one sentence.' );
		$this->assertStringContainsString( '"paragraph"', $planned->warnings[0], 'The operator has to learn which setting to check.' );
	}

	/**
	 * A widget with no stored formatting says nothing.
	 *
	 * The false positive is the expensive failure for an advisory: one that
	 * fires on every heading is one nobody reads.
	 */
	public function test_a_plain_text_update_warns_about_nothing(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementUpdate();
		$input     = $this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Our services' ] ] );

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$this->assertSame( [], $planned->warnings, 'A widget carrying no inline formatting has nothing to report.' );
	}

	// ------------------------------------------------- repeater row identity

	/**
	 * A repeater row written here is stored with an `_id` of its own.
	 *
	 * A ROW WITHOUT ONE STORES, READS BACK AND RENDERS, and can never be styled:
	 * per-row rules are emitted as `.elementor-repeater-item-<_id>`, so a
	 * nameless row takes the control's defaults for the life of the page and
	 * cannot be told from its siblings in the editor either. The writes that
	 * ORIGINATE an element have always named their rows; this one reaches the
	 * document through the settings merge and went past the mint entirely.
	 */
	public function test_repeater_rows_are_written_with_an_identifier(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->repeaterWidgetTree() ) );

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w555555',
				'settings'  => [
					'icon_list' => [
						[ 'text' => 'One' ],
						[ 'text' => 'Two' ],
					],
				],
			]
		);

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$rows = $planned->payload['settings']['icon_list'];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[0]['_id'], 'A stored row carries a minted identifier.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[1]['_id'], 'Every row, not just the first.' );
		$this->assertNotSame( $rows[0]['_id'], $rows[1]['_id'], 'Two rows styled alike is the defect, not the fix.' );
	}

	/**
	 * A row the caller named keeps the name it was given.
	 *
	 * IDEMPOTENCE IS LOAD-BEARING, not a courtesy: `applyChange()` merges the
	 * approved settings out of the payload, which already carry the ids the
	 * plan promised, and a second naming pass that moved them would write a
	 * document the operator was never shown.
	 */
	public function test_a_row_that_already_carries_an_identifier_keeps_it(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->repeaterWidgetTree() ) );

		$operation = $this->elementUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w555555',
				'settings'  => [ 'icon_list' => [ [ '_id' => 'abc1234', 'text' => 'One' ] ] ],
			]
		);

		$planned = $operation->planChange( $this->resolved( $operation, $input ), $input, $this->context() );

		$this->assertSame( 'abc1234', $planned->payload['settings']['icon_list'][0]['_id'], 'A named row is left exactly as it arrived.' );
	}

	/**
	 * A document holding one classic widget that declares a repeater control.
	 *
	 * The shared fixture tree holds no repeater, and rippling one through it
	 * would change what every unrelated case in this file asserts about.
	 *
	 * @return array[] The raw tree.
	 */
	private function repeaterWidgetTree(): array {
		return [
			[
				'id'       => self::containerId(),
				'elType'   => 'container',
				'settings' => [ 'content_width' => 'boxed' ],
				'elements' => [
					[
						'id'         => 'w555555',
						'elType'     => 'widget',
						'widgetType' => 'icon-list',
						'settings'   => [ 'icon_list' => [] ],
						'elements'   => [],
					],
				],
			],
		];
	}
}
