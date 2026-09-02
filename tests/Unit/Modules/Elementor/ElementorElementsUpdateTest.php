<?php
/**
 * Tests for ElementorElementsUpdate: the batch's all-or-nothing contract.
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
use SiteHelm\Modules\Elementor\ElementorElementsUpdate;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\SettingsUpdateFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0068: many element changes as one reviewed change.
 *
 * WHAT THIS FILE HAS TO PROVE that the single-element file does not: that a
 * batch is genuinely one thing. Every case below turns on a property a client
 * loop over `elementor-element-update` would not have — a refusal that leaves
 * NOTHING written, entries that all land in ONE save, and one snapshot that
 * undoes all of them.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, for the reason the single-element file
 * records: `ELEMENTOR_VERSION` and the `\Elementor\` class aliases are permanent
 * for the life of a process, so without isolation the guard-order cases would be
 * decided by which test file ran first.
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The writer especially is real: "nothing was written" is
 * only a claim worth making against something that could have written.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementsUpdateTest extends TestCase {

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
	 * The registered shape REQ-0068 requires.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorElementsUpdate::definition();

		$this->assertSame( 'elementor-elements-update', $definition->id );
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
	 * The batch schema is CLOSED at both levels and BOUNDED at the list.
	 *
	 * The entry schema being closed is the half that is easy to forget: a caller
	 * that sent `elementID` for one of six entries would otherwise have five
	 * changes applied and the sixth silently absent, which is exactly the
	 * half-changed page this operation exists to make impossible.
	 */
	public function test_the_input_schema_is_closed_at_both_levels_and_bounds_the_batch(): void {
		$schema = ElementorElementsUpdate::definition()->inputSchema;
		$entry  = $schema['properties']['changes']['items'];

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'changes' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'changes' ], $schema['required'] );

		$this->assertSame( 1, $schema['properties']['changes']['minItems'] );
		$this->assertSame( ElementorElementsUpdate::MAX_CHANGES, $schema['properties']['changes']['maxItems'] );

		$this->assertFalse( $entry['additionalProperties'] );
		$this->assertSame( [ 'elementId', 'settings' ], array_keys( $entry['properties'] ) );
		$this->assertSame( [ 'elementId', 'settings' ], $entry['required'] );
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it, so the writes cannot drift apart on what an id may be.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorElementsUpdate::definition()->inputSchema['properties']['changes']['items']['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	/**
	 * ONLY the digest is promised, for the single-element operation's reason:
	 * a settings change moves no element's existence and no element's kind, so a
	 * promised `elementCount` could not move however many entries the batch has.
	 */
	public function test_the_plan_promises_the_digest_and_nothing_that_cannot_move(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan( $this->elementsUpdate(), $this->batch( $this->twoHeadings() ) );

		$this->assertSame( [ ElementorWriteFields::FIELD_DIGEST ], array_keys( $planned->afterFields ) );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check — and before the batch is even
	 * looked at. A caller with no rights over the document learns nothing about
	 * whether the site runs Elementor.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeFixture();

		try {
			$this->resolved( $this->elementsUpdate(), $this->batch( $this->twoHeadings() ) );
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
		$this->storeFixture();

		try {
			$this->resolved( $this->elementsUpdate(), $this->batch( $this->twoHeadings() ) );
			$this->fail( 'A site without Elementor must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * TARGET THIRD, before any entry is judged.
	 *
	 * The batch below is wrong in two ways at once: the page is not an Elementor
	 * document, and the first entry names an id no element can carry. Telling an
	 * operator to fix their entries for a page that was never an Elementor
	 * document sends them to fix the wrong thing.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_entries_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch( [ $this->change( 'not a valid id', [ 'title' => 'x' ] ) ] )
			);
			$this->fail( 'A page Elementor does not control must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- determinism

	/**
	 * Two plans for the same batch against the same state are byte-identical.
	 *
	 * `planChange()` runs once for the preview and again immediately before the
	 * write, and the engine compares the two payloads. A clock or a `wp_unique_id()`
	 * anywhere in here would make every plan un-appliable, intermittently.
	 */
	public function test_planning_the_same_batch_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementsUpdate();
		$input     = $this->batch( $this->twoHeadings() );

		$this->assertSame(
			json_encode( $this->plan( $operation, $input )->payload ),
			json_encode( $this->plan( $operation, $input )->payload ),
			'Two plans for the same batch against the same state must be byte-identical.'
		);
	}

	/**
	 * THE PAYLOAD CARRIES THE DELTAS IN REQUEST ORDER, not a merged tree.
	 *
	 * Request order is part of the payload because the entries are applied in it:
	 * a payload that reordered them would describe a different write from the one
	 * the operator approved. The entries here are deliberately sent with the
	 * later-sorting element first, so a payload sorted by element id would fail.
	 */
	public function test_the_payload_carries_the_requested_changes_in_the_order_they_were_sent(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->elementsUpdate(),
			$this->batch(
				[
					$this->change( 'w222222', [ 'title' => 'What it costs' ] ),
					$this->change( 'w111111', [ 'title' => 'Our services' ] ),
				]
			)
		);

		$this->assertSame( [ 'changes', 'document' ], array_keys( $planned->payload ) );
		$this->assertSame(
			[ 'w222222', 'w111111' ],
			array_column( $planned->payload['changes'], 'elementId' ),
			'The approved payload must preserve the order the entries were sent in.'
		);
		$this->assertSame( [ 'title' => 'What it costs' ], $planned->payload['changes'][0]['settings'] );
	}

	// ------------------------------------------------------- one bad entry

	/**
	 * THE REQUIREMENT ITSELF: one bad entry refuses the whole batch, and NOTHING
	 * is written.
	 *
	 * The first entry is perfectly good. The second names a setting the widget
	 * does not declare. A client looping over `elementor-element-update` would
	 * have landed the first change and left the page half-done; here the page is
	 * untouched and the write recorder is empty.
	 */
	public function test_one_undeclared_setting_refuses_the_whole_batch_and_writes_nothing(): void {
		$this->withElementor();
		$this->storeFixture();
		$this->writes = [];

		try {
			$this->applied(
				$this->elementsUpdate(),
				$this->batch(
					[
						$this->change( 'w111111', [ 'title' => 'Our services' ] ),
						$this->change( 'w222222', [ 'not_a_prop' => 'x' ] ),
					]
				)
			);
			$this->fail( 'A batch carrying an undeclared setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'A refused batch must write nothing at all.' );
		$this->assertSame(
			'Original heading',
			$this->settingValue( 'w111111', 'title' ),
			'The entry before the bad one must not have landed.'
		);
	}

	/**
	 * A REFUSAL NAMES WHICH ENTRY, counted from one.
	 *
	 * The message is asserted rather than only the code, because the code alone
	 * would leave this case green with the whole position-reporting layer
	 * removed — and an operator handed "the widget does not declare that setting"
	 * for a batch of six has to bisect their own request to find it.
	 *
	 * The failing entry is deliberately the SECOND, so a message that hardcoded
	 * the first entry, or that reported the zero-based index, fails here.
	 */
	public function test_the_refusal_names_the_entry_that_caused_it(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch(
					[
						$this->change( 'w111111', [ 'title' => 'Our services' ] ),
						$this->change( 'w222222', [ 'not_a_prop' => 'x' ] ),
					]
				)
			);
			$this->fail( 'A batch carrying an undeclared setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertStringContainsString( 'Change 2 in this request', $exception->getMessage() );
		}
	}

	/**
	 * The underlying refusal survives being re-reported.
	 *
	 * Naming the entry must not cost the operator the reason. This entry names a
	 * container and sends `title`, which is `e-heading`'s control and not a
	 * container's, so the wrong-vocabulary refusal beneath it has to still be
	 * readable through the position prefix — including the key it names.
	 */
	public function test_the_refusal_keeps_the_reason_it_was_reported_for(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch( [ $this->change( 'c111111', [ 'title' => 'Our services' ] ) ] )
			);
			$this->fail( 'A widget control must not be writable on a container.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'Change 1 in this request', $exception->getMessage() );
			$this->assertStringContainsString( 'a setting named "title"', $exception->getMessage() );
		}
	}

	/**
	 * A batch may change a container's own settings alongside a widget's, which
	 * is the shape a full-bleed page needs: zero the container's padding and set
	 * the heading in one approved change.
	 */
	public function test_a_batch_may_change_a_container_and_a_widget_together(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->elementsUpdate(),
			$this->batch(
				[
					$this->change( 'c111111', [ 'padding' => [ 'top' => '0' ] ] ),
					$this->change( 'w111111', [ 'title' => 'Our services' ] ),
				]
			)
		);

		$this->assertSame( [ 'top' => '0' ], $this->settingValue( 'c111111', 'padding' ) );
		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
	}

	/**
	 * An element the document does not hold is TargetNotFound, and the code
	 * survives the re-report too — a batch that flattened every entry refusal to
	 * InvalidInput would tell an operator to correct arguments that were correct.
	 */
	public function test_an_element_the_document_does_not_hold_keeps_its_missing_target_code(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch(
					[
						$this->change( 'w111111', [ 'title' => 'Our services' ] ),
						$this->change( 'w999999', [ 'title' => 'x' ] ),
					]
				)
			);
			$this->fail( 'An element the page does not hold must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringContainsString( 'Change 2 in this request', $exception->getMessage() );
		}
	}

	/**
	 * TWO ENTRIES FOR ONE ELEMENT ARE REFUSED rather than silently letting the
	 * later one win.
	 *
	 * It is almost always a mistake in the caller's own loop, and resolving it by
	 * order would make the outcome depend on a request ordering the schema never
	 * said was significant.
	 */
	public function test_two_entries_naming_the_same_element_are_refused(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch(
					[
						$this->change( 'w111111', [ 'title' => 'Our services' ] ),
						$this->change( 'w111111', [ 'title' => 'What it costs' ] ),
					]
				)
			);
			$this->fail( 'A repeated element must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'Change 2 in this request', $exception->getMessage() );
		}
	}

	/**
	 * An entry naming NO setting is refused, for the reason the single-element
	 * operation refuses an empty map: it plans a change whose promised digest
	 * equals the one it started from.
	 */
	public function test_an_entry_naming_no_setting_is_refused(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->elementsUpdate(),
				$this->batch(
					[
						$this->change( 'w111111', [ 'title' => 'Our services' ] ),
						$this->change( 'w222222', [] ),
					]
				)
			);
			$this->fail( 'An entry naming no setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'Change 2 in this request', $exception->getMessage() );
		}
	}

	/**
	 * An empty batch is refused IN THE CODE, not only by the schema.
	 *
	 * A transport that validated loosely would otherwise hand this an empty list
	 * and get a plan promising the digest it started from. The schema's `minItems`
	 * is asserted separately above; this is the belt beneath it.
	 */
	public function test_a_batch_naming_no_changes_is_refused_by_the_operation_itself(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan( $this->elementsUpdate(), $this->batch( [] ) );
			$this->fail( 'A batch naming no changes must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * The count bound is enforced IN THE CODE too, for the same reason.
	 *
	 * Every entry is well-formed and names a real element; the only thing wrong
	 * with this request is how many of them there are.
	 */
	public function test_a_batch_longer_than_the_bound_is_refused_by_the_operation_itself(): void {
		$this->withElementor();
		$this->storeFixture();

		$changes = [];

		for ( $index = 0; $index <= ElementorElementsUpdate::MAX_CHANGES; $index++ ) {
			$changes[] = $this->change( 'w111111', [ 'title' => 'Our services' ] );
		}

		try {
			$this->plan( $this->elementsUpdate(), $this->batch( $changes ) );
			$this->fail( 'A batch over the bound must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString(
				(string) ElementorElementsUpdate::MAX_CHANGES,
				$exception->getMessage(),
				'The refusal must state the bound, so a caller can split the batch without guessing.'
			);
		}
	}

	// ------------------------------------------------------- apply

	/**
	 * EVERY ENTRY LANDS, IN ONE SAVE.
	 *
	 * The single-save half is the point: `$writes` counts the mutating calls, and
	 * a batch that saved once per entry would be the client loop this operation
	 * replaces, with the same half-changed page waiting behind the next failure.
	 *
	 * BOTH values are asserted, which is what pins the merge as accumulating
	 * rather than each entry being applied to its own copy of the stored tree.
	 * Under that mistake the last entry would win and the first would vanish.
	 */
	public function test_every_entry_lands_in_a_single_save(): void {
		$this->withElementor();
		$this->storeFixture();
		$this->writes = [];

		$this->applied( $this->elementsUpdate(), $this->batch( $this->twoHeadings() ) );

		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame( 'What it costs', $this->settingValue( 'w222222', 'title' ) );

		$this->assertCount(
			1,
			array_filter( $this->writes, static fn( array $write ): bool => '_elementor_data' === ( $write[1] ?? '' ) ),
			'A batch must store the document once, however many entries it carries.'
		);
	}

	/**
	 * A setting no entry named survives the batch.
	 *
	 * The fixture heading carries a `title_tablet` nothing here mentions. A batch
	 * that replaced each element's settings map instead of merging into it would
	 * lose it — and lose it for every element at once.
	 */
	public function test_settings_no_entry_named_survive_the_batch(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied( $this->elementsUpdate(), $this->batch( $this->twoHeadings() ) );

		$this->assertSame( 'Original tablet heading', $this->settingValue( 'w111111', 'title_tablet' ) );
	}

	/**
	 * THE MERGE BASE IS READ AT APPLY, NOT AT PREVIEW — for every entry.
	 *
	 * Between the plan and the write somebody else changes a setting the batch
	 * never mentions. Their value must survive. A payload carrying the merged
	 * tree built at preview would silently revert a colleague's edit across every
	 * element the batch touched, and report success.
	 */
	public function test_a_setting_changed_between_preview_and_apply_by_somebody_else_survives(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementsUpdate();
		$input     = $this->batch( $this->twoHeadings() );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$tree = $this->settingsTree();
		$tree[0]['elements'][0]['settings']['title_tablet'] = 'Edited by somebody else';
		$this->storeRaw( (string) json_encode( $tree ) );

		$operation->captureSnapshot( $target, $this->context() );
		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame( 'Edited by somebody else', $this->settingValue( 'w111111', 'title_tablet' ) );
	}

	/**
	 * An element that left the page between preview and apply is a CONFLICT, and
	 * it refuses the entries beside it too.
	 *
	 * The batch's FIRST entry is still perfectly applicable; the second element is
	 * the one that vanished. Nothing may be written for either.
	 */
	public function test_an_element_removed_between_preview_and_apply_refuses_the_whole_batch(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementsUpdate();
		$input     = $this->batch( $this->twoHeadings() );

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$tree = $this->settingsTree();
		unset( $tree[0]['elements'][1] );
		$tree[0]['elements'] = array_values( $tree[0]['elements'] );
		$this->storeRaw( (string) json_encode( $tree ) );

		$this->writes = [];

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A vanished element must refuse the batch at apply.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'Nothing may be written when one approved element has gone.' );
		$this->assertSame(
			'Original heading',
			$this->settingValue( 'w111111', 'title' ),
			'The entry whose element is still there must not have landed alone.'
		);
	}

	/**
	 * The written document verifies against the promise, through the same
	 * `fieldsFor()` measurement the promise was built from.
	 */
	public function test_the_document_read_back_after_the_write_carries_the_promised_digest(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementsUpdate();
		$input     = $this->batch( $this->twoHeadings() );

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
	 * ONE RESTORE UNDOES EVERY ENTRY.
	 *
	 * This is the property a client-side loop cannot offer at all, and the reason
	 * the batch is one operation rather than a convenience wrapper: what the
	 * operator gets back is a single thing to put back.
	 */
	public function test_one_restore_puts_back_every_element_the_batch_changed(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->elementsUpdate();
		$input     = $this->batch( $this->twoHeadings() );

		$target   = $operation->resolveTarget( $input, $this->context() );
		$planned  = $operation->planChange( $target, $input, $this->context() );
		$snapshot = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$this->assertSame( 'Our services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame( 'What it costs', $this->settingValue( 'w222222', 'title' ) );

		$this->assertIsArray( $snapshot );
		$operation->restore( $snapshot, $this->context() );

		$this->assertSame( 'Original heading', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame( 'Second heading', $this->settingValue( 'w222222', 'title' ) );
	}

	// ------------------------------------------------------- request builders

	/**
	 * The arguments for one batch.
	 *
	 * @param array[] $changes The entries.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function batch( array $changes ): array {
		return $this->arguments( [ 'changes' => $changes ] );
	}

	/**
	 * One entry.
	 *
	 * @param string               $element_id The element to change.
	 * @param array<string, mixed> $settings   The settings it should take.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function change( string $element_id, array $settings ): array {
		return [
			'elementId' => $element_id,
			'settings'  => $settings,
		];
	}

	/**
	 * The two-heading batch most cases send.
	 *
	 * @return array[] The entries.
	 */
	private function twoHeadings(): array {
		return [
			$this->change( 'w111111', [ 'title' => 'Our services' ] ),
			$this->change( 'w222222', [ 'title' => 'What it costs' ] ),
		];
	}

	// ------------------------------------------------------- media advisory

	/**
	 * A BATCH IS WHERE THIS DEFECT ARRIVES AT SCALE. A media value with no
	 * attachment id stores, reads back and verifies green, and still puts an
	 * unresponsive full-size image on the page, because WordPress builds
	 * `srcset`, the `wp-image` class and lazy-loading from the attachment record
	 * rather than the URL. The plan is where an operator sees it in time.
	 */
	public function test_a_bare_media_url_warns_on_the_plan(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->elementsUpdate(),
			$this->batch(
				[
					$this->change(
						self::containerId(),
						[
							'background_background' => 'classic',
							'background_image'      => [ 'url' => 'https://elsewhere.example/hero.jpg' ],
						]
					),
				]
			)
		);

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

		$planned = $this->plan(
			$this->elementsUpdate(),
			$this->batch(
				[
					$this->change(
						self::containerId(),
						[
							'background_background' => 'classic',
							'background_image'      => [
								'id'  => 4242,
								'url' => 'https://example.test/hero.jpg',
							],
						]
					),
				]
			)
		);

		$this->assertSame( [], $planned->warnings, 'This is the write the advisory exists to ask for.' );
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

		$planned = $this->plan(
			$this->elementsUpdate(),
			$this->batch(
				[
					$this->change(
						'w555555',
						[
							'icon_list' => [
								[ 'text' => 'One' ],
								[ 'text' => 'Two' ],
							],
						]
					),
				]
			)
		);

		$rows = $planned->payload['changes'][0]['settings']['icon_list'];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[0]['_id'], 'A stored row carries a minted identifier.' );
		$this->assertNotSame( $rows[0]['_id'], $rows[1]['_id'], 'Two rows styled alike is the defect, not the fix.' );
	}

	/**
	 * A row the caller named keeps the name it was given.
	 *
	 * IDEMPOTENCE IS LOAD-BEARING HERE ABOVE ALL, because this operation names
	 * its rows inside the same walk `applyChange()` runs a second time over the
	 * approved entries. A pass that renamed anything would write a document the
	 * operator was never shown.
	 */
	public function test_a_row_that_already_carries_an_identifier_keeps_it(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->repeaterWidgetTree() ) );

		$planned = $this->plan(
			$this->elementsUpdate(),
			$this->batch( [ $this->change( 'w555555', [ 'icon_list' => [ [ '_id' => 'abc1234', 'text' => 'One' ] ] ] ) ] )
		);

		$this->assertSame( 'abc1234', $planned->payload['changes'][0]['settings']['icon_list'][0]['_id'], 'A named row is left exactly as it arrived.' );
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
