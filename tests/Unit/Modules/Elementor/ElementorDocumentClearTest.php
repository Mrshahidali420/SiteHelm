<?php
/**
 * Tests for ElementorDocumentClear: definition, guard order, no-op, apply, rollback.
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
use SiteHelm\Modules\Elementor\ElementorDocumentClear;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;
use SiteHelm\Tests\Doubles\PageLevelFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0104's clear: the one Elementor write whose whole purpose is that the page
 * ends up holding nothing.
 *
 * THE CASES BELOW ARE MOSTLY ABOUT WHAT SURVIVES IT. A clear that also took the
 * page's settings, its title or its Elementor flag with it would pass any
 * assertion about the element tree and would still be wrong, so those are
 * asserted by name.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process,
 * and the guard-order cases distinguish "Elementor is absent" from "you may not
 * edit this".
 *
 * TEST DOUBLE FIDELITY. Every collaborator is the real class, wired as
 * `ElementorModule` wires it; only WordPress functions and the `\Elementor\`
 * symbols are doubled. The writer in particular is real, because "the stored
 * bytes moved" is the claim this operation leans on.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDocumentClearTest extends TestCase {

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
	 * The registered shape. Destructive, and therefore forced to preview,
	 * snapshot and roll back: the content this removes exists nowhere else
	 * afterwards.
	 */
	public function test_the_definition_declares_a_destructive_write(): void {
		$definition = ElementorDocumentClear::definition();

		$this->assertSame( 'elementor-document-clear', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertTrue( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
	}

	/**
	 * The document is the only argument, and nothing else is accepted. A clear
	 * that quietly ignored an extra member would let a caller believe they had
	 * narrowed it to one branch of the page.
	 */
	public function test_the_schema_accepts_only_the_document(): void {
		$schema = ElementorDocumentClear::definition()->inputSchema;

		$this->assertSame( [ ElementorWriteFields::INPUT_DOCUMENT ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ ElementorWriteFields::INPUT_DOCUMENT ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	// ------------------------------------------------------- the guards

	/**
	 * A caller who may not edit the document is refused at resolve time, before
	 * the document is read at all.
	 *
	 * THE REFUSAL IS `TargetNotFound`, DELIBERATELY. Answering "forbidden" would
	 * tell a caller who may not open a page that the page is there, which is a
	 * fact about the site they are not entitled to.
	 */
	public function test_a_caller_who_may_not_edit_is_refused_before_any_read(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->mayEdit = false;

		try {
			$this->resolved( $this->documentClear(), $this->arguments() );
			$this->fail( 'Expected the capability guard to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * A post Elementor does not control resolves as absent rather than as a
	 * refusal, and the plan is what says so.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_at_plan_time(): void {
		$this->withElementor();

		$operation = $this->documentClear();
		$target    = $this->resolved( $operation, $this->arguments() );

		$this->assertFalse( $target->exists );

		try {
			$operation->planChange( $target, $this->arguments(), $this->context() );
			$this->fail( 'Expected the absent document to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- the no-op

	/**
	 * A PAGE THAT ALREADY HOLDS NOTHING IS REFUSED, not reported as cleared. An
	 * empty save reaches the writer as bytes that did not move, which it cannot
	 * tell apart from a save Elementor dropped — and a caller told "cleared" for
	 * a page nothing happened to has learned something false.
	 */
	public function test_a_page_that_already_holds_nothing_is_refused(): void {
		$this->withElementor();
		$this->storeRaw( '[]' );

		$operation = $this->documentClear();
		$target    = $this->resolved( $operation, $this->arguments() );

		try {
			$operation->planChange( $target, $this->arguments(), $this->context() );
			$this->fail( 'Expected the empty page to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'already holds no Elementor content', $exception->getMessage() );
		}

		$this->assertSame( [], $this->writes );
	}

	// ------------------------------------------------------- planning

	/**
	 * The promise is an empty document in all four fields. `maxDepth` is promised
	 * here where `elementor-element-remove` leaves it out, because an empty tree
	 * has exactly one encoding: the result does not depend on what the page held.
	 */
	public function test_the_plan_promises_an_empty_document_in_every_field(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->documentClear(), $this->arguments() );

		$this->assertSame( ElementorWriteFields::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( 0, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
		$this->assertSame( 0, $planned->afterFields[ ElementorWriteFields::FIELD_DEPTH ] );
		$this->assertSame( [], $planned->afterFields[ ElementorWriteFields::FIELD_WIDGETS ] );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	/**
	 * The preview names what is going away, element by element, so an operator
	 * approving a clearing can see the size of what they are approving rather
	 * than only the word "clear".
	 */
	public function test_the_preview_detail_reports_what_is_removed(): void {
		$this->withElementor();
		$this->storePageFixture();

		$detail = $this->plan( $this->documentClear(), $this->arguments() )->previewDetail;

		$this->assertNotSame( [], $detail );
	}

	/**
	 * PLANNING TWICE PRODUCES THE SAME PAYLOAD. The engine fingerprints the plan
	 * at preview and compares the fingerprint at apply, so a payload carrying a
	 * clock or a counter would refuse every change it ever planned.
	 */
	public function test_planning_twice_produces_the_same_payload(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentClear();

		$this->assertSame(
			$this->plan( $operation, $this->arguments() )->payload,
			$this->plan( $operation, $this->arguments() )->payload
		);
	}

	// ------------------------------------------------------- applying

	/**
	 * The page ends up holding nothing.
	 */
	public function test_the_write_empties_the_document(): void {
		$this->withElementor();
		$this->storePageFixture();

		$key = $this->applied( $this->documentClear(), $this->arguments() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $key );
		$this->assertSame( [], $this->storedTree() );
	}

	/**
	 * THE PAGE IS STILL AN ELEMENTOR PAGE AFTERWARDS. A clear that dropped the
	 * edit-mode flag would leave a page every other Elementor write refuses —
	 * emptied and, from the module's point of view, no longer a document at all.
	 */
	public function test_the_page_is_still_an_elementor_document_afterwards(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->documentClear(), $this->arguments() );

		$this->assertNotSame( '', $this->storedRaw() );
		$this->assertSame(
			'builder',
			$this->meta[ self::DOCUMENT_ID . '|' . \SiteHelm\Modules\Elementor\ElementorDocument::META_EDIT_MODE ]
		);
	}

	/**
	 * THE PAGE'S OWN SETTINGS SURVIVE. They live in a different meta row, and a
	 * clearing that took them would silently reset the page's layout — a change
	 * nothing in the result reports and nothing in the snapshot would restore.
	 */
	public function test_the_page_settings_row_is_left_alone(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings( [ 'template' => 'elementor_canvas' ] );

		$this->applied( $this->documentClear(), $this->arguments() );

		$this->assertSame( [ 'template' => 'elementor_canvas' ], $this->storedPageSettings() );
	}

	/**
	 * A plan carrying no tree member is refused rather than treated as an empty
	 * one, which is the difference between "clear this page" and "this plan does
	 * not say what to write".
	 */
	public function test_a_plan_with_no_tree_member_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentClear();
		$target    = $this->resolved( $operation, $this->arguments() );
		$planned   = $operation->planChange( $target, $this->arguments(), $this->context() );

		$stripped = new \SiteHelm\Change\PlannedChange(
			[ ElementorWriteFields::INPUT_DOCUMENT => self::DOCUMENT_ID ],
			$planned->afterFields,
			$planned->fieldOrder
		);

		try {
			$operation->applyChange( $target, $stripped, $this->context() );
			$this->fail( 'Expected the incomplete plan to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- rollback

	/**
	 * THE SNAPSHOT IS THE ONLY RECORD OF THE CLEARED PAGE, so the case that
	 * matters is the whole round trip: clear it, then put it back and find the
	 * same bytes.
	 */
	public function test_a_rollback_puts_the_whole_page_back(): void {
		$this->withElementor();
		$this->storePageFixture();
		$before = $this->storedRaw();

		$operation = $this->documentClear();
		$target    = $this->resolved( $operation, $this->arguments() );
		$planned   = $operation->planChange( $target, $this->arguments(), $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$this->assertSame( [], $this->storedTree() );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( $before, $this->storedRaw() );
	}

	/**
	 * The read-back reports the emptied page rather than the plan's promise, so
	 * the engine's comparison has two independently produced sides.
	 */
	public function test_the_read_back_reports_the_emptied_page(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentClear();
		$key       = $this->applied( $operation, $this->arguments() );

		$state = $operation->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 0, $state->fields[ ElementorWriteFields::FIELD_COUNT ] );
	}

	/**
	 * A target key naming no document cannot be verified, and says so rather than
	 * reporting a page that was never written.
	 */
	public function test_a_read_back_of_an_unrecognised_key_is_a_verification_failure(): void {
		try {
			$this->documentClear()->readBack( 'not-a-document-key', $this->context() );
			$this->fail( 'Expected the unrecognised key to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}
}
