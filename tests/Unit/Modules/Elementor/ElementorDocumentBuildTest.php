<?php
/**
 * Tests for ElementorDocumentBuild: definition, gates, no-op, apply, rollback.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorDocumentBuild;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;
use SiteHelm\Tests\Doubles\PageLevelFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0104's build: the write that replaces everything a page holds with a
 * layout the caller sends.
 *
 * THE GATE CASES ARE THE POINT OF THIS FILE. A build is the widest opening in
 * the module — it accepts a whole tree from outside — so the cases below are
 * mostly about what it refuses: a layout with no elements, a widget this site
 * does not have, and a setting key the widget carrying it does not declare.
 * That last one is the one that would otherwise be stored quietly with the
 * unrecognised text already dropped by Elementor's own parser.
 *
 * ONLY `e-heading` IS REGISTERED in the shared fixture's widget manager, so
 * every layout sent through the gates below uses it. That is not a convenience:
 * it is what lets the missing-widget case say something, because a fixture
 * registering everything could never fail that gate.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process,
 * and the guard-order cases distinguish "Elementor is absent" from "you may not
 * edit this".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDocumentBuildTest extends TestCase {

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
	 * The registered shape. Destructive, because everything the page held before
	 * is gone afterwards, and therefore forced to preview, snapshot and roll back.
	 */
	public function test_the_definition_declares_a_destructive_write(): void {
		$definition = ElementorDocumentBuild::definition();

		$this->assertSame( 'elementor-document-build', $definition->id );
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
	 * The document and the layout, both required, and nothing else accepted.
	 */
	public function test_the_schema_takes_a_document_and_a_layout(): void {
		$schema = ElementorDocumentBuild::definition()->inputSchema;

		$this->assertSame(
			[ ElementorWriteFields::INPUT_DOCUMENT, ElementorDocumentBuild::INPUT_CONTENT ],
			array_keys( $schema['properties'] )
		);
		$this->assertSame(
			[ ElementorWriteFields::INPUT_DOCUMENT, ElementorDocumentBuild::INPUT_CONTENT ],
			$schema['required']
		);
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 1, $schema['properties'][ ElementorDocumentBuild::INPUT_CONTENT ]['minItems'] );
	}

	// ------------------------------------------------------- the guards

	/**
	 * A caller who may not edit the document is refused at resolve time, before
	 * the layout they sent is looked at by anything.
	 *
	 * THE REFUSAL IS `TargetNotFound`, DELIBERATELY. Answering "forbidden" would
	 * tell a caller who may not open a page that the page is there.
	 */
	public function test_a_caller_who_may_not_edit_is_refused_before_the_layout_is_read(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->mayEdit = false;

		try {
			$this->resolved( $this->documentBuild(), $this->request() );
			$this->fail( 'Expected the capability guard to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * A post Elementor does not control is refused at plan time rather than
	 * turned into an Elementor page by the write.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_at_plan_time(): void {
		$this->withElementor();

		$operation = $this->documentBuild();
		$target    = $this->resolved( $operation, $this->request() );

		$this->assertFalse( $target->exists );

		try {
			$operation->planChange( $target, $this->request(), $this->context() );
			$this->fail( 'Expected the absent document to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * AN EMPTY LAYOUT IS REFUSED, not accepted as a way to clear the page.
	 * Emptying a page is `elementor-document-clear`, which is destructive on its
	 * own terms and says so; a build that also emptied pages would let a caller
	 * clear one without ever naming the act.
	 */
	public function test_a_layout_with_no_elements_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$target    = $this->resolved( $operation, $this->request() );

		try {
			$operation->planChange( $target, $this->request( [] ), $this->context() );
			$this->fail( 'Expected the empty layout to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'holds no elements', $exception->getMessage() );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * A LAYOUT USING A WIDGET THIS SITE DOES NOT HAVE IS REFUSED. Its settings
	 * cannot be checked against a schema that is not there, and a page storing
	 * unvalidated props is the failure the key gate exists to prevent.
	 */
	public function test_a_layout_using_a_widget_this_site_lacks_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$layout = [
			[
				'id'         => 'w999999',
				'elType'     => 'widget',
				'widgetType' => 'not-installed-here',
				'elements'   => [],
			],
		];

		try {
			$this->plan( $this->documentBuild(), $this->request( $layout ) );
			$this->fail( 'Expected the missing widget type to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
			$this->assertStringContainsString( 'not-installed-here', $exception->getMessage() );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * A SETTING KEY THE WIDGET DOES NOT DECLARE IS REFUSED RATHER THAN STORED.
	 * Elementor's parser drops an unrecognised key without saying so, so a layout
	 * accepted here would be stored with the caller's text already gone and would
	 * still verify.
	 */
	public function test_a_setting_key_the_widget_does_not_declare_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$layout = [
			[
				'id'         => 'w999999',
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'content' => 'Text under a key this widget has never had' ],
				'elements'   => [],
			],
		];

		try {
			$this->plan( $this->documentBuild(), $this->request( $layout ) );
			$this->fail( 'Expected the undeclared setting key to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes );
	}

	// ------------------------------------------------------- the no-op

	/**
	 * A BUILD THAT WOULD STORE THE BYTES ALREADY THERE IS REFUSED. The writer
	 * cannot tell a save that changed nothing from a save Elementor dropped, so a
	 * caller told "written" for a page nothing happened to has learned something
	 * false.
	 */
	public function test_a_layout_the_page_already_holds_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$this->applied( $operation, $this->request() );

		try {
			$this->plan( $operation, $this->request() );
			$this->fail( 'Expected the unchanged layout to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'already holds exactly this layout', $exception->getMessage() );
		}
	}

	// ------------------------------------------------------- planning

	/**
	 * The promise measures the layout the caller sent, in every field the module
	 * reports a document in.
	 */
	public function test_the_plan_promises_the_layout_that_was_sent(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->documentBuild(), $this->request() );

		$this->assertSame( ElementorWriteFields::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( 3, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
		$this->assertSame( [ 'e-heading' => 2 ], $planned->afterFields[ ElementorWriteFields::FIELD_WIDGETS ] );
		$this->assertNotSame( '', $planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	/**
	 * The preview names what changes rather than only reporting that the page is
	 * replaced, so an operator can see the size of what they are approving.
	 */
	public function test_the_preview_detail_reports_the_difference(): void {
		$this->withElementor();
		$this->storePageFixture();

		$detail = $this->plan( $this->documentBuild(), $this->request() )->previewDetail;

		$this->assertNotSame( [], $detail );
	}

	/**
	 * PLANNING TWICE PRODUCES THE SAME PAYLOAD. The engine fingerprints the plan
	 * at preview and compares the fingerprint at apply, so a payload that minted
	 * an id or read a clock would refuse every change it ever planned.
	 */
	public function test_planning_twice_produces_the_same_payload(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();

		$this->assertSame(
			$this->plan( $operation, $this->request() )->payload,
			$this->plan( $operation, $this->request() )->payload
		);
	}

	// ------------------------------------------------------- applying

	/**
	 * The whole document is replaced: what the page held before is gone, and what
	 * the caller sent is what it now holds.
	 */
	public function test_the_write_replaces_the_whole_document(): void {
		$this->withElementor();
		$this->storePageFixture();

		$key = $this->applied( $this->documentBuild(), $this->request() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $key );

		$stored = $this->storedTree();

		$this->assertCount( 1, $stored );
		$this->assertSame( 'c999999', $stored[0]['id'] );
		$this->assertSame( 'builder', $this->meta[ self::DOCUMENT_ID . '|_elementor_edit_mode' ] );
	}

	/**
	 * THE PAGE'S OWN SETTINGS SURVIVE. They live in a different meta row, and a
	 * build that took them would silently reset the page's layout — a change
	 * nothing in the result reports.
	 */
	public function test_the_page_settings_row_is_left_alone(): void {
		$this->withElementor();
		$this->storePageFixture();
		$this->storePageSettings( [ 'template' => 'elementor_canvas' ] );

		$this->applied( $this->documentBuild(), $this->request() );

		$this->assertSame( [ 'template' => 'elementor_canvas' ], $this->storedPageSettings() );
	}

	/**
	 * A plan carrying no tree member is refused rather than treated as an empty
	 * one, which is the difference between "build this" and "this plan does not
	 * say what to write".
	 */
	public function test_a_plan_with_no_tree_member_is_refused(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$target    = $this->resolved( $operation, $this->request() );
		$planned   = $operation->planChange( $target, $this->request(), $this->context() );

		$stripped = new PlannedChange(
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
	 * THE SNAPSHOT IS THE ONLY RECORD OF THE REPLACED PAGE, so the case that
	 * matters is the whole round trip: build over it, then put it back and find
	 * the same bytes.
	 */
	public function test_a_rollback_puts_the_replaced_page_back(): void {
		$this->withElementor();
		$this->storePageFixture();
		$before = $this->storedRaw();

		$operation = $this->documentBuild();
		$target    = $this->resolved( $operation, $this->request() );
		$planned   = $operation->planChange( $target, $this->request(), $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$this->assertNotSame( $before, $this->storedRaw() );

		$operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( $before, $this->storedRaw() );
	}

	/**
	 * The read-back measures the stored page rather than repeating the promise,
	 * so the engine's comparison has two independently produced sides.
	 */
	public function test_the_read_back_reports_the_stored_layout(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$key       = $this->applied( $operation, $this->request() );

		$state = $operation->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 3, $state->fields[ ElementorWriteFields::FIELD_COUNT ] );
	}

	/**
	 * A target key naming no document cannot be verified, and says so rather than
	 * reporting a page that was never written.
	 */
	public function test_a_read_back_of_an_unrecognised_key_is_a_verification_failure(): void {
		try {
			$this->documentBuild()->readBack( 'not-a-document-key', $this->context() );
			$this->fail( 'Expected the unrecognised key to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- helpers

	/**
	 * The arguments a caller sends, with the fixture layout filled in.
	 *
	 * @param array[]|null $content The layout, or null for the fixture one.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function request( ?array $content = null ): array {
		return $this->arguments(
			[ ElementorDocumentBuild::INPUT_CONTENT => null === $content ? $this->buildTree() : $content ]
		);
	}

	/**
	 * The layout the cases build with: one container holding two headings.
	 *
	 * ITS IDS DIFFER FROM THE STORED FIXTURE'S, so "the whole document was
	 * replaced" is observable by name rather than only by count.
	 *
	 * @return array[] The layout.
	 */
	private function buildTree(): array {
		return [
			[
				'id'       => 'c999999',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w991111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => 'A new heading' ],
						'elements'   => [],
					],
					[
						'id'         => 'w992222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => 'And another' ],
						'elements'   => [],
					],
				],
			],
		];
	}
}
