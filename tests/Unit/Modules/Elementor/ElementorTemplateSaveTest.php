<?php
/**
 * Tests for ElementorTemplateSave (REQ-0102).
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
use SiteHelm\Change\PlannedChange;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorTemplateLibrary;
use SiteHelm\Modules\Elementor\ElementorTemplateSave;
use SiteHelm\Modules\Elementor\ElementorTemplateTarget;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Tests\Doubles\TemplateLibraryFixtures;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * REQ-0102: a document, or one element of it, saved into the library.
 *
 * WHAT THESE TESTS ARE ABOUT is the boundary between the two posts. The source
 * document is read and must come back byte-identical afterwards; the template is
 * created and must carry the tree, the stored type and the edit mode, because a
 * template missing any one of those is a post that exists and never appears
 * where the author looked for it.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The insert double reproduces two
 * upstream facts and no others — that `wp_insert_post()` answers the new
 * identifier, and that the row is then readable through `get_post()`. It models
 * no slug generation, no revisions, no `save_post` hooks and no status
 * transitions, so no assertion below is about what WordPress made of the fields:
 * only about which fields the operation handed it. The document writer is the
 * REAL one, because a stubbed writer would turn the "the tree really was stored"
 * assertions into claims about the stub.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process,
 * and the refusal-when-Elementor-is-absent case needs a process in which neither
 * was ever installed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTemplateSaveTest extends TestCase {

	use TemplateLibraryFixtures;

	/**
	 * The document every ordinary case saves from.
	 */
	private const SOURCE_ID = 128;

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * The post rows `get_post()` serves, keyed by identifier.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $posts = [];

	/**
	 * Every field set `wp_insert_post()` was called with, in order.
	 *
	 * @var array[]
	 */
	private array $inserts = [];

	/**
	 * Whether the caller may edit the source document.
	 */
	private bool $mayEdit = true;

	/**
	 * Whether `wp_insert_post()` refuses.
	 */
	private bool $insertFails = false;

	/**
	 * The identifier the next insert mints.
	 */
	private int $nextPostId = 900;

	protected function setUp(): void {
		parent::setUp();

		$this->meta        = [];
		$this->posts       = [];
		$this->inserts     = [];
		$this->mayEdit     = true;
		$this->insertFails = false;
		$this->nextPostId  = 900;

		$this->stubTemplateWordPress( 'sitehelm-template-save' );
		$this->storeDocument( self::SOURCE_ID, (string) wp_json_encode( $this->sourceTree() ) );
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape, including the two policies that separate a create
	 * from every other Elementor write.
	 */
	public function test_the_definition_declares_the_create_shape(): void {
		$definition = ElementorTemplateSave::definition();

		$this->assertSame( 'elementor-template-save', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * A create declares `supported`, not `required`, and the reason is mechanical:
	 * `SnapshotLifecycle` refuses a change outright when a required snapshot comes
	 * back null, and a post that does not exist has no prior state to capture. A
	 * create declaring `required` would refuse every time it ran.
	 */
	public function test_the_snapshot_policy_is_supported_because_a_create_has_nothing_to_capture(): void {
		$this->assertSame( SnapshotPolicy::Supported, ElementorTemplateSave::definition()->snapshotPolicy );
		$this->assertNull( $this->operation()->captureSnapshot( $this->pendingState(), $this->context() ) );
	}

	/**
	 * Only the three types Elementor itself lets an author save are offered. A
	 * theme document is created empty by elementor-theme-template-create, and a
	 * popup carries display conditions this operation never sets.
	 */
	public function test_only_the_saveable_types_are_offered(): void {
		$schema = ElementorTemplateSave::definition()->inputSchema;

		$this->assertSame( ElementorTemplateLibrary::SAVEABLE_TYPES, $schema['properties']['type']['enum'] );
		$this->assertSame( [ 'postId', 'title', 'type' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	// ------------------------------------------------------- the target

	/**
	 * The template does not exist yet, so the target is the pending key and the
	 * state reports it absent.
	 */
	public function test_the_target_is_the_pending_key_and_reports_the_template_absent(): void {
		$state = $this->operation()->resolveTarget( $this->arguments(), $this->context() );

		$this->assertSame( ElementorTemplateTarget::pendingTargetKey(), $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	/**
	 * The presence gate is in the target, so a site without Elementor refuses
	 * before any plan is built — and therefore before a preview could show a
	 * caller a change that could never be applied.
	 */
	public function test_a_site_without_elementor_refuses_before_the_plan(): void {
		$handler = new ElementorTemplateSave(
			$this->templateTarget(),
			new ElementorDocument(),
			new ElementorTreeEdit(),
			new ElementorTree(),
			$this->documentWriter()
		);

		try {
			$handler->resolveTarget( $this->arguments(), $this->context() );
			$this->fail( 'Expected the absent integration to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	// ------------------------------------------------------- planning

	/**
	 * The whole document, saved as one template.
	 */
	public function test_the_whole_document_is_promised_with_its_element_count(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame(
			[
				ElementorTemplateTarget::FIELD_TYPE   => 'section',
				ElementorTemplateTarget::FIELD_TITLE  => 'Pricing table',
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => 3,
			],
			$planned->afterFields
		);
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( $this->sourceTree(), $planned->payload[ ElementorTemplateSave::PAYLOAD_TREE ] );
	}

	/**
	 * One element and everything inside it, wrapped in a list — because a
	 * document's stored value is a LIST of top-level elements, and one bare
	 * element where a list belongs decodes without error and renders as nothing.
	 */
	public function test_an_element_id_saves_that_subtree_wrapped_as_a_list(): void {
		$planned = $this->plan( $this->arguments( [ 'elementId' => 'aaa111' ] ) );
		$tree    = $planned->payload[ ElementorTemplateSave::PAYLOAD_TREE ];

		$this->assertCount( 1, $tree );
		$this->assertSame( 'aaa111', $tree[0]['id'] );
		$this->assertSame( 2, $planned->afterFields[ ElementorTemplateTarget::FIELD_COUNT ] );
	}

	/**
	 * IDS ARE KEPT EXACTLY AS THE SOURCE STORES THEM. They are re-minted on
	 * apply, not here: minting at save time would fix one set of ids into the
	 * template and guarantee a collision the second time it was applied to the
	 * same page.
	 */
	public function test_the_source_element_ids_are_stored_unchanged(): void {
		$tree = $this->plan( $this->arguments() )->payload[ ElementorTemplateSave::PAYLOAD_TREE ];

		$this->assertSame( 'aaa111', $tree[0]['id'] );
		$this->assertSame( 'bbb222', $tree[0]['elements'][0]['id'] );
		$this->assertSame( 'ccc333', $tree[1]['id'] );
	}

	/**
	 * The settings ride along with the tree. A template that kept the structure
	 * and dropped the settings would apply as an empty skeleton.
	 */
	public function test_the_widget_settings_are_carried_into_the_template(): void {
		$tree = $this->plan( $this->arguments() )->payload[ ElementorTemplateSave::PAYLOAD_TREE ];

		$this->assertSame( [ 'title' => 'Plans' ], $tree[0]['elements'][0]['settings'] );
	}

	/**
	 * The preview names the source, so an operator approving a plan can see which
	 * page the layout is being taken from and what is in it.
	 */
	public function test_the_preview_detail_names_the_source_and_its_widgets(): void {
		$detail = $this->plan( $this->arguments() )->previewDetail;

		$this->assertSame( self::SOURCE_ID, $detail['sourcePostId'] );
		$this->assertSame( [ 'e-heading' => 1 ], $detail['widgetTypes'] );
		$this->assertGreaterThan( 0, $detail['sourceDepth'] );
	}

	/**
	 * `planChange()` runs at preview and again at apply, so two runs against an
	 * unchanged source must promise the same template.
	 */
	public function test_planning_the_same_request_twice_promises_the_same_template(): void {
		$handler = $this->operation();
		$state   = $this->pendingState();

		$first  = $handler->planChange( $state, $this->arguments(), $this->context() );
		$second = $handler->planChange( $state, $this->arguments(), $this->context() );

		$this->assertSame( $first->payload, $second->payload );
		$this->assertSame( $first->afterFields, $second->afterFields );
	}

	// ------------------------------------------------------- planning refusals

	/**
	 * The declared `edit_posts` is the floor the policy engine enforces;
	 * `edit_post` on the document being copied FROM is the question that matters.
	 * A caller who may not read a page must not obtain its whole layout by saving
	 * it into the library.
	 */
	public function test_a_caller_who_may_not_edit_the_source_cannot_copy_it(): void {
		$this->mayEdit = false;

		$this->assertRefusal( ErrorCode::TargetNotFound, $this->arguments() );
	}

	/**
	 * A post Elementor does not control is refused with the same message as one
	 * that does not exist, so the refusal cannot be used to probe which posts are
	 * on the site.
	 */
	public function test_a_post_elementor_does_not_control_is_refused_as_not_found(): void {
		$this->assertRefusal( ErrorCode::TargetNotFound, $this->arguments( [ 'postId' => 4242 ] ) );
	}

	/**
	 * An element the document does not hold.
	 */
	public function test_an_unknown_element_is_refused(): void {
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'elementId' => 'no-such' ] ) );
	}

	/**
	 * A template with no elements is a library entry that applies nothing, and an
	 * author would find out only by applying it and watching nothing happen.
	 */
	public function test_a_document_with_no_elements_is_refused(): void {
		$this->storeDocument( self::SOURCE_ID, '[]' );

		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments() );
	}

	/**
	 * A title of only whitespace is not a title.
	 */
	public function test_a_whitespace_only_title_is_refused(): void {
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'title' => "  \t " ] ) );
	}

	/**
	 * A usable title is trimmed rather than refused, and the trimmed form is what
	 * both the promise and the insert carry.
	 */
	public function test_a_padded_title_is_trimmed_into_the_promise(): void {
		$planned = $this->plan( $this->arguments( [ 'title' => '  Pricing table  ' ] ) );

		$this->assertSame( 'Pricing table', $planned->afterFields[ ElementorTemplateTarget::FIELD_TITLE ] );
		$this->assertSame( 'Pricing table', $planned->payload[ ElementorTemplateSave::PAYLOAD_POST ]['post_title'] );
	}

	/**
	 * Nothing is created when the plan refuses.
	 */
	public function test_a_refused_plan_creates_no_post(): void {
		$this->mayEdit = false;

		$this->assertRefusal( ErrorCode::TargetNotFound, $this->arguments() );
		$this->assertSame( [], $this->inserts );
		$this->assertSame( [], $this->posts );
	}

	// ------------------------------------------------------- applying

	/**
	 * The insert carries the library post type, the published status and the
	 * title — and nothing else, because everything that makes the post a template
	 * is meta written straight after.
	 */
	public function test_the_insert_creates_a_published_library_post(): void {
		$this->applied();

		$this->assertCount( 1, $this->inserts );
		$this->assertSame(
			[
				'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Pricing table',
			],
			$this->inserts[0]
		);
	}

	/**
	 * THE TYPE IS PART OF THE PROMISE, not a side effect of the insert: a
	 * template saved with the right tree and the wrong stored type is a post that
	 * exists, has content, and never appears in the library section the author
	 * looked in.
	 */
	public function test_the_stored_type_and_edit_mode_are_written(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertSame( 'section', $this->meta[ $id . '|' . ElementorThemeConditions::META_TYPE ] );
		$this->assertSame(
			ElementorDocumentWriter::EDIT_MODE,
			$this->meta[ $id . '|' . ElementorDocument::META_EDIT_MODE ]
		);
	}

	/**
	 * REQ-0114: the type is recorded as a TERM as well as as meta.
	 *
	 * The docblock above is right that the wrong stored type hides a template
	 * from the section the author looked in — and so does the RIGHT stored type
	 * with no term beside it, because the library screen queries the taxonomy
	 * rather than the meta.
	 */
	public function test_the_type_is_also_recorded_in_the_taxonomy_elementors_screens_query(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertSame(
			'section',
			$this->terms[ $id . '|' . ElementorTemplateLibrary::TAXONOMY_TYPE ] ?? null
		);
	}

	/**
	 * The tree really is in the created post, through the real writer — a save
	 * that reported success and stored nothing is the failure this plugin exists
	 * to catch.
	 */
	public function test_the_tree_is_stored_in_the_created_template(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertSame( $this->sourceTree(), $this->storedTreeFor( $id ) );
	}

	/**
	 * NOTHING ABOUT THE SOURCE DOCUMENT CHANGES. That is the whole reason this
	 * write can be offered without a snapshot of the page an operator is looking
	 * at.
	 */
	public function test_the_source_document_is_left_byte_identical(): void {
		$before = $this->meta[ self::SOURCE_ID . '|' . ElementorDocument::META_DATA ];

		$this->applied();

		$this->assertSame( $before, $this->meta[ self::SOURCE_ID . '|' . ElementorDocument::META_DATA ] );
	}

	/**
	 * The returned key names the created template, so the engine's read-back asks
	 * about the post the apply made rather than the pending key it planned
	 * against.
	 */
	public function test_the_returned_key_names_the_created_template(): void {
		$key = $this->applied();

		$this->assertSame( ElementorTemplateTarget::targetKey( $this->createdIdFrom( $key ) ), $key );
		$this->assertNotSame( ElementorTemplateTarget::pendingTargetKey(), $key );
	}

	/**
	 * A refused insert is reported as a failure to execute, and nothing is left
	 * behind to explain.
	 */
	public function test_a_refused_insert_fails_the_change(): void {
		$this->insertFails = true;
		$handler           = $this->operation();
		$state             = $this->pendingState();
		$planned           = $handler->planChange( $state, $this->arguments(), $this->context() );

		try {
			$handler->applyChange( $state, $planned, $this->context() );
			$this->fail( 'Expected the refused insert to fail the change.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->posts );
	}

	// ------------------------------------------------------- read-back, restore

	/**
	 * The read-back measures the created template in the five verification
	 * fields, including the digest of what was actually stored.
	 */
	public function test_the_read_back_reports_the_created_template(): void {
		$handler = $this->operation();
		$key     = $this->applied( $handler );

		$state = $handler->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, array_keys( $state->fields ) );
		$this->assertSame( 'section', $state->fields[ ElementorTemplateTarget::FIELD_TYPE ] );
		$this->assertSame( 'Pricing table', $state->fields[ ElementorTemplateTarget::FIELD_TITLE ] );
		$this->assertSame( 'publish', $state->fields[ ElementorTemplateTarget::FIELD_STATUS ] );
		$this->assertSame( 3, $state->fields[ ElementorTemplateTarget::FIELD_COUNT ] );
	}

	/**
	 * The honest refusal, not a delete dressed as a rollback. Trashing the created
	 * post would be a NEW write performed under the name of an undo, and wrong the
	 * moment somebody had edited the template in between.
	 */
	public function test_restore_refuses_rather_than_deleting_the_template(): void {
		$handler = $this->operation();
		$key     = $this->applied( $handler );
		$id      = $this->createdIdFrom( $key );

		try {
			$handler->restore( [], $this->context() );
			$this->fail( 'Expected the create to refuse a rollback.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}

		$this->assertArrayHasKey( $id, $this->posts );
		$this->assertSame( $this->sourceTree(), $this->storedTreeFor( $id ) );
	}

	// ------------------------------------------------------- helpers

	/**
	 * The operation, wired as the module wires it, on a site running Elementor.
	 *
	 * @return ElementorTemplateSave The operation.
	 */
	private function operation(): ElementorTemplateSave {
		$this->withElementor();

		return new ElementorTemplateSave(
			$this->templateTarget(),
			new ElementorDocument(),
			new ElementorTreeEdit(),
			new ElementorTree(),
			$this->documentWriter()
		);
	}

	/**
	 * The pending state every plan is built against.
	 *
	 * @return TargetState The pending state.
	 */
	private function pendingState(): TargetState {
		return new TargetState( ElementorTemplateTarget::pendingTargetKey(), false, [] );
	}

	/**
	 * One valid argument set.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge(
			[
				'postId' => self::SOURCE_ID,
				'title'  => 'Pricing table',
				'type'   => 'section',
			],
			$overrides
		);
	}

	/**
	 * The document every ordinary case saves from: two top-level containers, one
	 * of which holds a widget.
	 *
	 * @return array[] The tree.
	 */
	private function sourceTree(): array {
		return [
			[
				'id'       => 'aaa111',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'         => 'bbb222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => 'Plans' ],
						'elements'   => [],
					],
				],
			],
			[
				'id'       => 'ccc333',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [],
			],
		];
	}

	/**
	 * One plan.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $input ): PlannedChange {
		return $this->operation()->planChange( $this->pendingState(), $input, $this->context() );
	}

	/**
	 * Plans and applies one save.
	 *
	 * @param ElementorTemplateSave|null $handler The operation, when the caller
	 *                                            needs the same instance again.
	 *
	 * @return string The created target key.
	 */
	private function applied( ?ElementorTemplateSave $handler = null ): string {
		$handler ??= $this->operation();
		$state     = $this->pendingState();

		return $handler->applyChange(
			$state,
			$handler->planChange( $state, $this->arguments(), $this->context() ),
			$this->context()
		);
	}

	/**
	 * The created identifier a target key names.
	 *
	 * @param string $key The target key.
	 *
	 * @return int The identifier.
	 */
	private function createdIdFrom( string $key ): int {
		return ElementorTemplateTarget::postIdFromTargetKey( $key );
	}

	/**
	 * Asserts that planning one request refuses with a given code.
	 *
	 * @param ErrorCode            $expected The expected code.
	 * @param array<string, mixed> $input    The arguments.
	 */
	private function assertRefusal( ErrorCode $expected, array $input ): void {
		try {
			$this->plan( $input );
			$this->fail( 'Expected the plan to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( $expected, $exception->errorCode );
		}
	}
}
