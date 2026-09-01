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

	// ------------------------------------------------------- element naming

	/**
	 * THE DEFECT THIS BLOCK EXISTS FOR. A caller who composed a layout from
	 * scratch has no ids to send, and a document stored with unnamed nodes makes
	 * Elementor generate every per-element rule under the selector
	 * `.elementor-element-` — the empty id suffix — which matches every element
	 * on the page at once. A 175-element page built this way rendered with
	 * `data-id=""` throughout and one stylesheet selector carrying 27 merged
	 * rules, so every padding, colour and width the caller wrote landed on
	 * everything. The write verified green the whole time, because the tree that
	 * was stored was exactly the tree that had been promised.
	 */
	public function test_a_layout_sent_with_no_ids_is_planned_with_an_id_on_every_node(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->documentBuild(), $this->request( $this->unnamedTree() ) );

		$ids = $this->plannedIds( $planned );

		$this->assertCount( 3, $ids );

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $id );
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	/**
	 * THE SAME DEFECT ONE LEVEL DOWN. Elementor gives every REPEATER ROW its own
	 * `_id` and generates that row's CSS under `.elementor-repeater-item-<_id>`.
	 * A live page built through this operation rendered 93 icon-list rows with
	 * correct content, zero occurrences of `elementor-repeater-item` in its HTML
	 * and zero `.elementor-repeater-item-*` selectors in its stylesheet, so every
	 * row was permanently beyond the reach of any per-row rule and Elementor's
	 * editor had no stable handle on which row was which.
	 */
	public function test_a_repeater_sent_with_no_row_ids_is_planned_with_an_id_on_every_row(): void {
		$this->withElementor();
		$this->storePageFixture();

		$planned = $this->plan( $this->documentBuild(), $this->request( $this->iconListTree() ) );

		$rows = $planned->payload[ ElementorDocumentBuild::PAYLOAD_TREE ][0]['elements'][0]['settings']['icon_list'];

		$this->assertCount( 2, $rows );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $rows[0]['_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $rows[1]['_id'] );
		$this->assertNotSame( $rows[0]['_id'], $rows[1]['_id'] );
	}

	/**
	 * The row names reach the STORED bytes, not only the plan: it is the stored
	 * `_id` Elementor's CSS generator reads.
	 */
	public function test_the_written_document_holds_the_row_names_that_were_planned(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->documentBuild(), $this->request( $this->iconListTree() ) );

		$stored = json_decode( $this->storedRaw(), true );

		foreach ( $stored[0]['elements'][0]['settings']['icon_list'] as $row ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $row['_id'] );
		}
	}

	/**
	 * An id the caller DID send survives untouched, so a caller writing back a
	 * tree an `elementor-document-get` reported keeps the correspondence between
	 * what they hold and what the page stores.
	 */
	public function test_the_ids_the_caller_supplied_are_stored_exactly_as_sent(): void {
		$this->withElementor();
		$this->storePageFixture();

		$layout = $this->unnamedTree();
		// The container is named by the caller; both headings under it are not.
		$layout[0]['id'] = 'c999999';

		$planned = $this->plan( $this->documentBuild(), $this->request( $layout ) );

		$ids = $this->plannedIds( $planned );

		$this->assertSame( 'c999999', $ids[0] );
		$this->assertNotSame( 'c999999', $ids[1] );
		$this->assertNotSame( 'c999999', $ids[2] );
	}

	/**
	 * NAMING MUST NOT COST THE DETERMINISM THE PLAN TOKEN DEPENDS ON. This is the
	 * regression that would break preview and apply: the ids are minted from a
	 * seed the request already pins, so both runs derive the same ones and the
	 * payload digest does not move.
	 */
	public function test_planning_an_unnamed_layout_twice_produces_the_same_payload(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$layout    = $this->unnamedTree();

		$this->assertSame(
			$this->plan( $operation, $this->request( $layout ) )->payload,
			$this->plan( $operation, $this->request( $layout ) )->payload
		);
	}

	/**
	 * The names reach the STORED bytes, not only the plan: it is the stored id
	 * that Elementor's CSS generator reads.
	 */
	public function test_the_written_document_holds_the_names_that_were_planned(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->documentBuild(), $this->request( $this->unnamedTree() ) );

		$stored = json_decode( $this->storedRaw(), true );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $stored[0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $stored[0]['elements'][0]['id'] );
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

	// ------------------------------------------------------- media advisory

	/**
	 * A BULK BUILD IS THE PATH THAT PRODUCED THE DEFECT, so it is the path that
	 * has to carry the advisory. A page cloned from another site arrives here as
	 * one tree of forty widgets whose media values hold a `url` and no
	 * attachment id: it stores cleanly, reads back verbatim, verifies green, and
	 * serves full-size unresponsive images to every visitor. The plan is the only
	 * place an operator sees it before it lands.
	 */
	public function test_a_build_carrying_a_bare_media_url_warns_on_the_plan(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$request   = $this->request( $this->treeWithBareMedia() );
		$planned   = $operation->planChange( $this->resolved( $operation, $request ), $request, $this->context() );

		$this->assertCount( 1, $planned->warnings, 'One bare media value earns one advisory.' );
		$this->assertStringContainsString( '"background_image"', $planned->warnings[0], 'The operator has to learn which setting to fix.' );
		$this->assertStringContainsString( 'srcset', $planned->warnings[0], 'Naming the consequence is what makes it worth reading.' );
	}

	/**
	 * The advisory is a warning, not a gate: the build still happens, because a
	 * url-only image renders — badly, but visibly — and pointing a widget at an
	 * image outside this library is a legitimate thing to ask for.
	 */
	public function test_a_bare_media_url_does_not_stop_the_build(): void {
		$this->withElementor();
		$this->storePageFixture();

		$this->applied( $this->documentBuild(), $this->request( $this->treeWithBareMedia() ) );

		$this->assertSame(
			[ 'url' => 'https://elsewhere.example/hero.jpg' ],
			json_decode( $this->storedRaw(), true )[0]['settings']['background_image'],
			'The write the operator asked for still lands.'
		);
	}

	/**
	 * A build whose media carries its attachment says nothing at all, which is
	 * the ordinary case and the one an advisory must not be noisy about.
	 */
	public function test_a_build_carrying_an_attachment_id_warns_about_nothing(): void {
		$this->withElementor();
		$this->storePageFixture();

		$tree = $this->treeWithBareMedia();
		$tree[0]['settings']['background_image']['id'] = 4242;

		$operation = $this->documentBuild();
		$request   = $this->request( $tree );
		$planned   = $operation->planChange( $this->resolved( $operation, $request ), $request, $this->context() );

		$this->assertSame( [], $planned->warnings, 'This is the write the advisory exists to ask for.' );
	}

	/**
	 * `planChange()` runs at preview and again at apply, and a plan whose
	 * warnings moved between the two would not be the plan that was approved.
	 */
	public function test_the_advisory_is_the_same_on_both_evaluations(): void {
		$this->withElementor();
		$this->storePageFixture();

		$operation = $this->documentBuild();
		$request   = $this->request( $this->treeWithBareMedia() );
		$target    = $this->resolved( $operation, $request );

		$this->assertSame(
			$operation->planChange( $target, $request, $this->context() )->warnings,
			$operation->planChange( $target, $request, $this->context() )->warnings,
			'Two evaluations of one request have to agree.'
		);
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
	 * The same layout with EVERY id left out, which is what a caller who composed
	 * a page from scratch sends.
	 *
	 * @return array[] The layout.
	 */
	private function unnamedTree(): array {
		$tree = $this->buildTree();

		unset( $tree[0]['id'], $tree[0]['elements'][0]['id'], $tree[0]['elements'][1]['id'] );

		return $tree;
	}

	/**
	 * A layout holding one REPEATER-BACKED widget, with no `_id` on any row,
	 * which is what a caller composing an icon list from scratch sends.
	 *
	 * @return array[] The layout.
	 */
	private function iconListTree(): array {
		return [
			[
				'id'       => 'c999999',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w993333',
						'elType'     => 'widget',
						'widgetType' => 'icon-list',
						'settings'   => [
							'icon_list' => [
								[ 'text' => 'Fast setup' ],
								[ 'text' => 'No lock-in' ],
							],
						],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * Every id the planned payload's tree carries, in document order.
	 *
	 * @param PlannedChange $planned The plan.
	 *
	 * @return string[] The ids.
	 */
	private function plannedIds( PlannedChange $planned ): array {
		return $this->treeIds( $planned->payload[ ElementorDocumentBuild::PAYLOAD_TREE ] );
	}

	/**
	 * Every id a raw element list carries, at any depth, in document order.
	 *
	 * @param array[] $elements The element list.
	 *
	 * @return string[] The ids.
	 */
	private function treeIds( array $elements ): array {
		$ids = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) ) {
				$ids[] = (string) $element['id'];
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, $this->treeIds( $element['elements'] ) );
			}
		}

		return $ids;
	}

	/**
	 * The same layout with the container's background pointed at an image URL
	 * carrying no attachment id, which is what a cloned page arrives as.
	 *
	 * @return array[] The layout.
	 */
	private function treeWithBareMedia(): array {
		$tree = $this->buildTree();

		$tree[0]['settings'] = [
			'background_background' => 'classic',
			'background_image'      => [ 'url' => 'https://elsewhere.example/hero.jpg' ],
		];

		return $tree;
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
