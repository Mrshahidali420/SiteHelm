<?php
/**
 * Tests for ElementorPageSettingsSet: the merge, the promise, the rollback.
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
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorPageSettings;
use SiteHelm\Modules\Elementor\ElementorPageSettingsSet;
use SiteHelm\Modules\Elementor\ElementorPageSettingsTarget;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\PageLevelFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0103's page-settings write.
 *
 * THE ONE CLAIM THIS FILE EXISTS FOR IS THAT THE WRITE MERGES. Every case that
 * stores a row stores a stranger key in it — a custom CSS block, a background —
 * and asserts it is still there afterwards. A write that replaced the row
 * instead of merging into it lands both allowlisted values correctly and
 * silently discards the page's background, its padding and every responsive
 * variant of both, which is the single most destructive mistake available in
 * this operation and the one an operator would not notice until a client did.
 *
 * THE TARGET IS THE SETTINGS ROW, NOT THE DOCUMENT, so the rollback cases assert
 * against `_elementor_page_settings` directly. A write built on the document
 * target would snapshot `_elementor_data`, restore `_elementor_data`, and leave
 * the settings row exactly as this write left it — a rollback reporting success
 * and reversing nothing.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process,
 * and the guard-ordering cases distinguish "Elementor is absent" from "you may
 * not edit this".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorPageSettingsSetTest extends TestCase {

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
	 * The registered shape the matrix pins for REQ-0103's page settings.
	 *
	 * RISK IS MEDIUM rather than Low: this changes what a visitor sees on a
	 * published page, which naming an element does not.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorPageSettingsSet::definition();

		$this->assertSame( 'elementor-page-settings-set', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * The input schema is CLOSED, and only the page is required.
	 *
	 * Both settings optional is deliberate — changing the layout without touching
	 * the title flag is the ordinary request — and it is why an empty request has
	 * to be refused in code rather than by the schema.
	 */
	public function test_the_input_schema_is_closed_and_requires_only_the_page(): void {
		$schema = ElementorPageSettingsSet::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'layout', 'hideTitle' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document' ], $schema['required'] );
	}

	/**
	 * The layout enum is the write's, not a second list, so the read and the
	 * write cannot advertise different vocabularies.
	 */
	public function test_the_layout_enum_is_the_shared_vocabulary(): void {
		$schema = ElementorPageSettingsSet::definition()->inputSchema;

		$this->assertSame( array_keys( ElementorPageSettings::LAYOUTS ), $schema['properties']['layout']['enum'] );
		$this->assertSame( 'boolean', $schema['properties']['hideTitle']['type'] );
	}

	/**
	 * THE KEY COUNT IS PROMISED, not diagnostic.
	 *
	 * It is the measurement that catches a write which replaced the row instead
	 * of merging into it, and it only catches it if the engine verifies it — so
	 * it has to be in `required`, and the schema has to be closed around it.
	 */
	public function test_the_output_schema_promises_the_key_count(): void {
		$schema = ElementorPageSettingsSet::definition()->outputSchema;

		$this->assertSame( [ 'layout', 'hideTitle', 'settingsKeyCount' ], array_keys( $schema['properties'] ) );
		$this->assertSame( ElementorPageSettingsTarget::FIELD_ORDER, $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 0, $schema['properties']['settingsKeyCount']['minimum'] );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before Elementor is asked whether it is here and before
	 * anything is read.
	 *
	 * The refusal alone is thrown either way, so the load-bearing assertion is
	 * the empty read log: a caller with no rights over the page must not learn
	 * from the refusal whether the page exists.
	 */
	public function test_an_unauthorized_caller_is_refused_before_anything_is_read(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->reads   = [];
		$this->mayEdit = false;

		$refusal = $this->refusal( $this->arguments( [ 'layout' => 'canvas' ] ) );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the page is looked up.
	 */
	public function test_an_absent_elementor_is_reported_before_the_page_is_looked_up(): void {
		$refusal = $this->refusal( $this->arguments( [ 'layout' => 'canvas' ] ) );

		$this->assertSame( ErrorCode::IntegrationUnavailable, $refusal->errorCode );
		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * A post Elementor does not control resolves as a target that DOES NOT
	 * EXIST, rather than as a refusal. Page settings on a page Elementor has
	 * never touched are not a permission problem.
	 */
	public function test_a_post_elementor_does_not_control_resolves_as_absent(): void {
		$this->withElementor();

		$target = $this->resolved( $this->pageSettingsSet(), $this->arguments( [ 'layout' => 'canvas' ] ) );

		$this->assertFalse( $target->exists );
		$this->assertSame( [], $target->fields );
	}

	/**
	 * THE TARGET IS TESTED BEFORE THE INPUT.
	 *
	 * The arguments below are wrong twice over — the request names no setting at
	 * all — and the answer is still that the page is not an Elementor document.
	 * Answering "your arguments are wrong" would send an operator to correct a
	 * request that was never the problem.
	 */
	public function test_a_page_elementor_does_not_control_is_reported_before_the_arguments_are_read(): void {
		$this->withElementor();

		$refusal = $this->refusal( $this->arguments() );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	// ------------------------------------------------------- determinism

	/**
	 * The engine plans twice and compares the two payloads by digest, so the
	 * second plan has to be byte-identical to the first.
	 */
	public function test_planning_the_same_request_twice_yields_identical_payloads(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments(
			[
				'layout'    => 'canvas',
				'hideTitle' => true,
			]
		);

		$this->assertSame(
			json_encode( $this->plan( $operation, $input )->payload ),
			json_encode( $this->plan( $operation, $input )->payload )
		);
	}

	/**
	 * The payload is sorted and carries the CHANGED VALUES ONLY, never a finished
	 * row. That is what lets the apply re-read and merge into whatever the page
	 * holds by then.
	 */
	public function test_the_payload_carries_the_requested_values_in_sorted_order(): void {
		$this->withElementor();
		$this->storePageFixture();

		$payload = $this->plan(
			$this->pageSettingsSet(),
			$this->arguments(
				[
					'layout'    => 'canvas',
					'hideTitle' => true,
				]
			)
		)->payload;

		$this->assertSame( [ 'document', 'hideTitle', 'layout' ], array_keys( $payload ) );
		$this->assertSame( self::DOCUMENT_ID, $payload['document'] );
		$this->assertSame( 'canvas', $payload['layout'] );
		$this->assertTrue( $payload['hideTitle'] );
	}

	/**
	 * A request naming one setting carries only that one, so the other is left
	 * alone rather than being re-asserted at its current value.
	 */
	public function test_a_request_naming_one_setting_plans_only_that_setting(): void {
		$this->withElementor();
		$this->storePageFixture();

		$payload = $this->plan( $this->pageSettingsSet(), $this->arguments( [ 'hideTitle' => true ] ) )->payload;

		$this->assertSame( [ 'document', 'hideTitle' ], array_keys( $payload ) );
	}

	/**
	 * The promise is the three verification fields, in the target's order.
	 */
	public function test_the_promise_reports_the_three_verification_fields(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings( [ 'custom_css' => '.hero{}' ] );

		$planned = $this->plan( $this->pageSettingsSet(), $this->arguments( [ 'layout' => 'canvas' ] ) );

		$this->assertSame( ElementorPageSettingsTarget::FIELD_ORDER, array_keys( $planned->afterFields ) );
		$this->assertSame( 'canvas', $planned->afterFields['layout'] );
		$this->assertFalse( $planned->afterFields['hideTitle'] );
		$this->assertSame( 2, $planned->afterFields['settingsKeyCount'] );
	}

	// ------------------------------------------------------- the merge

	/**
	 * The values land under ELEMENTOR'S OWN KEY NAMES, asserted as literals.
	 *
	 * Through the constants they would still pass if `SETTING_MAP` were renamed
	 * to point at a row Elementor never reads, which is a change that breaks
	 * every page and no test.
	 */
	public function test_the_change_lands_under_elementors_own_key_names(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied(
			$this->pageSettingsSet(),
			$this->arguments(
				[
					'layout'    => 'canvas',
					'hideTitle' => true,
				]
			)
		);

		$this->assertSame(
			[
				'hide_title' => 'yes',
				'template'   => 'elementor_canvas',
			],
			$this->storedPageSettings()
		);
	}

	/**
	 * EVERY KEY THE REQUEST DID NOT NAME SURVIVES.
	 *
	 * This is the case the operation exists to keep true. The stranger keys below
	 * are a page's background and its custom CSS, neither of which this operation
	 * will ever write and neither of which is its to discard.
	 */
	public function test_the_settings_the_request_did_not_name_survive_the_write(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings(
			[
				'background_background' => 'classic',
				'custom_css'            => '.hero{color:red}',
				'template'              => 'default',
			]
		);

		$this->applied( $this->pageSettingsSet(), $this->arguments( [ 'layout' => 'canvas' ] ) );

		$stored = $this->storedPageSettings();

		$this->assertSame( 'classic', $stored['background_background'] );
		$this->assertSame( '.hero{color:red}', $stored['custom_css'] );
		$this->assertSame( 'elementor_canvas', $stored['template'] );
	}

	/**
	 * The promised key count reports the MERGED row, so a write that replaced it
	 * would be caught by the engine's own verification rather than by a reader
	 * of this file.
	 */
	public function test_the_promised_key_count_measures_the_merged_row(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings(
			[
				'background_background' => 'classic',
				'custom_css'            => '.hero{}',
			]
		);

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$planned   = $this->plan( $operation, $input );

		$this->applied( $operation, $input );

		$this->assertSame( 3, $planned->afterFields['settingsKeyCount'] );
		$this->assertCount( 3, $this->storedPageSettings() );
	}

	/**
	 * THE ROW IS RE-READ AT APPLY, not carried in the payload.
	 *
	 * A background somebody else set between the preview and the apply survives,
	 * because the payload describes the two values being CHANGED rather than a
	 * finished row.
	 */
	public function test_a_setting_added_between_preview_and_apply_survives(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );
		$this->storePageSettings( [ 'background_background' => 'classic' ] );
		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( 'classic', $this->storedPageSettings()['background_background'] );
		$this->assertSame( 'elementor_canvas', $this->storedPageSettings()['template'] );
	}

	/**
	 * Turning the flag off stores the EMPTY STRING Elementor's own editor stores,
	 * rather than removing the key or storing a literal `no`.
	 */
	public function test_hiding_the_title_and_showing_it_again_store_elementors_own_values(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings( [ 'hide_title' => 'yes' ] );

		$this->applied( $this->pageSettingsSet(), $this->arguments( [ 'hideTitle' => false ] ) );

		$this->assertSame( '', $this->storedPageSettings()['hide_title'] );
	}

	/**
	 * The write's target key names the SETTINGS ROW rather than the document, so
	 * the read-back and the rollback address the row this operation changed.
	 */
	public function test_the_written_target_key_names_the_settings_row(): void {
		$this->withElementor();
		$this->storePageFixture();

		$key = $this->applied( $this->pageSettingsSet(), $this->arguments( [ 'layout' => 'canvas' ] ) );

		$this->assertSame( ElementorPageSettings::targetKey( self::DOCUMENT_ID ), $key );
		$this->assertStringStartsWith( ElementorPageSettings::TARGET_PREFIX, $key );
	}

	/**
	 * The read-back measures the persisted row with the same formula the promise
	 * was built from, so the two can be compared at all.
	 */
	public function test_the_read_back_reports_what_the_page_now_holds(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments(
			[
				'layout'    => 'canvas',
				'hideTitle' => true,
			]
		);
		$planned   = $this->plan( $operation, $input );
		$key       = $this->applied( $operation, $input );

		$this->assertSame( $planned->afterFields, $operation->readBack( $key, $this->context() )->fields );
	}

	// ------------------------------------------------------- the refusals

	/**
	 * A request naming NEITHER setting is refused.
	 *
	 * It would otherwise be a write that changes nothing while burning a plan
	 * token, writing an audit record and reporting success.
	 */
	public function test_a_request_naming_no_setting_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$refusal = $this->refusal( $this->arguments() );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->writes, 'A refused request must not have written anything.' );
	}

	/**
	 * A layout outside the four is refused, and the remediation names the four.
	 */
	public function test_a_layout_outside_the_vocabulary_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$refusal = $this->refusal( $this->arguments( [ 'layout' => 'elementor_canvas' ] ) );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'canvas', (string) $refusal->remediation );
	}

	/**
	 * THE STRING "false" IS REFUSED rather than coerced.
	 *
	 * Coercing it would read as a request to show the title that hides it
	 * instead, which is the one wrong answer worse than a refusal.
	 */
	public function test_a_string_flag_is_refused_rather_than_coerced(): void {
		$this->withElementor();
		$this->storePageFixture();

		$refusal = $this->refusal( $this->arguments( [ 'hideTitle' => 'false' ] ) );

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame( [], $this->writes, 'A refused request must not have written anything.' );
	}

	/**
	 * An approved plan whose target key names no page is refused at apply rather
	 * than writing to post zero.
	 */
	public function test_a_plan_naming_no_page_is_refused_at_apply(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$this->writes = [];

		try {
			$operation->applyChange(
				new TargetState( 'elementor-page-settings:not-a-number', true, [] ),
				$planned,
				$this->context()
			);
			$this->fail( 'The operation was expected to refuse and did not.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
			$this->assertSame( [], $this->writes );
		}
	}

	/**
	 * A read-back for a key that names no page is a VERIFICATION failure, not an
	 * execution one: the write already happened.
	 */
	public function test_a_read_back_for_a_key_naming_no_page_fails_verification(): void {
		$this->withElementor();

		try {
			$this->pageSettingsSet()->readBack( 'elementor-page-settings:not-a-number', $this->context() );
			$this->fail( 'The operation was expected to refuse and did not.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode );
		}
	}

	// ------------------------------------------------------- the rollback

	/**
	 * The snapshot records the row, the page it belongs to, and WHETHER THERE WAS
	 * A ROW AT ALL — the third separately, because an absent row and an empty one
	 * are different states.
	 */
	public function test_the_snapshot_records_the_row_and_whether_there_was_one(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings( [ 'custom_css' => '.hero{}' ] );

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$snapshot  = $operation->captureSnapshot( $this->resolved( $operation, $input ), $this->context() );

		$this->assertSame( [ 'existed', 'post_id', 'settings' ], array_keys( (array) $snapshot ) );
		$this->assertTrue( $snapshot['existed'] );
		$this->assertSame( self::DOCUMENT_ID, $snapshot['post_id'] );
		$this->assertSame( [ 'custom_css' => '.hero{}' ], $snapshot['settings'] );
	}

	/**
	 * Snapshotting is SIDE-EFFECT FREE, because the engine takes one at preview
	 * for eligibility and a preview that wrote anything would be a write.
	 */
	public function test_capturing_a_snapshot_writes_nothing(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->writes = [];

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );

		$operation->captureSnapshot( $this->resolved( $operation, $input ), $this->context() );

		$this->assertSame( [], $this->writes );
	}

	/**
	 * A rollback puts the recorded row back verbatim, INCLUDING the keys this
	 * operation never writes.
	 */
	public function test_a_rollback_restores_the_recorded_row_verbatim(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings(
			[
				'custom_css' => '.hero{}',
				'template'   => 'default',
			]
		);

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame(
			[
				'custom_css' => '.hero{}',
				'template'   => 'default',
			],
			$this->storedPageSettings()
		);
	}

	/**
	 * A PAGE THAT HAD NO SETTINGS ROW COMES OUT OF A ROLLBACK WITH NONE.
	 *
	 * Restoring an empty map here would leave a row behind on every page that had
	 * none, which is a write performed in the name of undoing one. The assertion
	 * is that the meta key is ABSENT, not that it reads empty — `get_post_meta()`
	 * answers `''` for both and cannot tell them apart.
	 */
	public function test_a_rollback_deletes_a_row_the_write_created(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->pageSettingsSet();
		$input     = $this->arguments( [ 'layout' => 'canvas' ] );
		$target    = $this->resolved( $operation, $input );
		$planned   = $operation->planChange( $target, $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$this->assertFalse( $snapshot['existed'] );

		$operation->applyChange( $target, $planned, $this->context() );
		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertArrayNotHasKey(
			self::DOCUMENT_ID . '|' . ElementorPageSettings::META_KEY,
			$this->meta,
			'A page that had no settings row must not come out of a rollback holding one.'
		);
	}

	/**
	 * A restore state that names no page is refused as a rollback that cannot be
	 * performed, rather than being attempted against post zero.
	 */
	public function test_a_restore_state_naming_no_page_is_refused(): void {
		$this->withElementor();

		try {
			$this->pageSettingsSet()->restore( [ 'settings' => [] ], $this->context() );
			$this->fail( 'The rollback was expected to refuse and did not.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		}
	}

	// ------------------------------------------------------- the scaffolding

	/**
	 * Runs the operation expecting a refusal, and answers it.
	 *
	 * Returned rather than asserted here, so each caller asserts the specific
	 * ErrorCode. A bare expectException( OperationException::class ) passes for
	 * any of the eleven codes and proves nothing about which one was raised.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $input ): OperationException {
		try {
			$this->plan( $this->pageSettingsSet(), $input );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}
}
