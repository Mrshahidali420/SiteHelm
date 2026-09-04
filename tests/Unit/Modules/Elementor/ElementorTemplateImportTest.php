<?php
/**
 * Tests for ElementorTemplateImport (REQ-0102).
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
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTemplateImport;
use SiteHelm\Modules\Elementor\ElementorTemplateLibrary;
use SiteHelm\Modules\Elementor\ElementorTemplateTarget;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeInput;
use SiteHelm\Tests\Doubles\TemplateLibraryFixtures;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * REQ-0102: a template built somewhere else, checked before it is stored.
 *
 * THIS IS THE ONLY OPERATION IN THE MODULE THAT TAKES AN ELEMENT TREE FROM THE
 * CALLER, so the tests below are mostly about refusals. Every other Elementor
 * write is entitled to assume its tree came out of `_elementor_data` on this
 * site; this one is not, and the five gates — shape, size, bounds, widget
 * availability, declared keys — are the whole of the module's input validation.
 * Their ORDER is asserted, not just their existence: each gate exists so the one
 * below it can run at all, and a gate that ran second would be reading input the
 * gate above it had not yet vouched for.
 *
 * ALL FIVE RUN AT PLAN TIME, before a preview is shown. A caller must not be
 * able to approve a plan the apply would then refuse.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The widget registry answers both
 * of upstream's forms — the whole map, and one widget's prop schema — because
 * this operation reads both. It registers exactly one widget, `e-heading`, with
 * two declared props, which is enough to exercise "this site does not have that
 * widget" and "that widget does not declare that key" and nothing more. The
 * insert double reproduces only that `wp_insert_post()` answers an identifier
 * and that the row is then readable; the writer is REAL.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTemplateImportTest extends TestCase {

	use TemplateLibraryFixtures;

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
	 * Unused by this operation, which names no source post, but part of the
	 * fixture contract.
	 */
	private bool $mayEdit = true;

	/**
	 * Whether `wp_insert_post()` refuses.
	 */
	private bool $insertFails = false;

	/**
	 * The identifier the next insert mints.
	 */
	private int $nextPostId = 500;

	protected function setUp(): void {
		parent::setUp();

		$this->meta        = [];
		$this->posts       = [];
		$this->inserts     = [];
		$this->mayEdit     = true;
		$this->insertFails = false;
		$this->nextPostId  = 500;

		$this->stubTemplateWordPress( 'sitehelm-template-import' );
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape. The risk is High rather than Medium: this is the one
	 * operation whose content comes from outside the site.
	 */
	public function test_the_definition_declares_the_import_shape(): void {
		$definition = ElementorTemplateImport::definition();

		$this->assertSame( 'elementor-template-import', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * The tree is required and the page settings are not, and only the three
	 * saveable types can be imported: a theme document is created empty and a
	 * popup carries display conditions this operation never sets.
	 */
	public function test_the_schema_requires_a_tree_and_offers_only_saveable_types(): void {
		$schema = ElementorTemplateImport::definition()->inputSchema;

		$this->assertSame(
			[ 'title', 'type', ElementorTemplateImport::INPUT_CONTENT ],
			$schema['required']
		);
		$this->assertSame(
			ElementorTemplateLibrary::SAVEABLE_TYPES,
			$schema['properties']['type']['enum']
		);
		$this->assertSame( 1, $schema['properties'][ ElementorTemplateImport::INPUT_CONTENT ]['minItems'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	/**
	 * The size bound is `ElementorWriteTarget`'s snapshot ceiling, reused rather
	 * than chosen again: a template accepted above that bound would be one a later
	 * write could not snapshot, and the first honest report of that would arrive
	 * when somebody tried to undo something.
	 */
	public function test_the_size_bound_is_the_snapshot_ceiling(): void {
		$this->assertSame(
			\SiteHelm\Modules\Elementor\ElementorWriteTarget::MAX_SNAPSHOT_BYTES,
			ElementorTemplateImport::MAX_CONTENT_BYTES
		);
	}

	// ------------------------------------------------------- the target

	/**
	 * The template does not exist yet, so the target is the pending key.
	 */
	public function test_the_target_is_the_pending_key(): void {
		$state = $this->operation()->resolveTarget( $this->arguments(), $this->context() );

		$this->assertSame( ElementorTemplateTarget::pendingTargetKey(), $state->targetKey );
		$this->assertFalse( $state->exists );
	}

	/**
	 * A site without Elementor refuses before any plan is built.
	 */
	public function test_a_site_without_elementor_refuses_before_the_plan(): void {
		$handler = new ElementorTemplateImport(
			$this->templateTarget(),
			new ElementorTreeInput( new ElementorTree(), $this->propCoercion(), new ElementorPresence() ),
			$this->propCoercion(),
			$this->documentWriter(),
			new ElementorIdMint()
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
	 * The promise a caller approves.
	 */
	public function test_the_plan_promises_the_template_and_its_element_count(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame(
			[
				ElementorTemplateTarget::FIELD_TYPE   => 'section',
				ElementorTemplateTarget::FIELD_TITLE  => 'Imported pricing table',
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => 2,
			],
			$planned->afterFields
		);
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * The preview names what is coming in, so an operator approving content built
	 * elsewhere can see how deep it nests and which widgets it uses.
	 */
	public function test_the_preview_detail_reports_the_depth_and_the_widget_types(): void {
		$detail = $this->plan( $this->arguments() )->previewDetail;

		$this->assertSame( [ 'e-heading' ], $detail['widgetTypes'] );
		$this->assertGreaterThan( 0, $detail['maxDepth'] );
	}

	/**
	 * AN ID THE CALLER SENT IS STORED AS IT WAS SENT, which keeps the
	 * correspondence between an imported template and the export it came from —
	 * the only thing that makes two sites' templates diffable.
	 */
	public function test_the_element_ids_are_stored_as_the_caller_sent_them(): void {
		$tree = $this->plan( $this->arguments() )->payload[ ElementorTemplateImport::PAYLOAD_TREE ];

		$this->assertSame( 'imported1', $tree[0]['id'] );
		$this->assertSame( 'imported2', $tree[0]['elements'][0]['id'] );
	}

	/**
	 * A NODE THAT ARRIVED WITHOUT AN ID IS NAMED HERE, AND NOWHERE ELSE WILL DO.
	 * `elementor-template-apply` re-mints through `ElementorIdMint::reassign()`,
	 * which by design leaves an unnamed node unnamed, so a template imported with
	 * unnamed nodes stays unnamed for the rest of its life. Elementor keys its
	 * per-element CSS on the id, so every rule such a document generates lands
	 * under `.elementor-element-` and therefore on every element on the page at
	 * once.
	 */
	public function test_a_node_imported_without_an_id_is_named(): void {
		$tree = $this->plan( $this->arguments( $this->unnamedContent() ) )
			->payload[ ElementorTemplateImport::PAYLOAD_TREE ];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $tree[0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $tree[0]['elements'][0]['id'] );
		$this->assertNotSame( $tree[0]['id'], $tree[0]['elements'][0]['id'] );
	}

	/**
	 * THE SAME DEFECT ONE LEVEL DOWN, and it outlives the import for the same
	 * reason the element one did: `elementor-template-apply` re-mints through
	 * `ElementorIdMint::reassign()`, which by design invents nothing, so rows
	 * stored here without an `_id` stay without one for the life of every page
	 * the template is ever applied to. Elementor generates each row's CSS under
	 * `.elementor-repeater-item-<_id>`, so those rows can never be styled
	 * individually and the editor has no stable handle on which row is which.
	 */
	public function test_a_repeater_row_imported_without_an_id_is_named(): void {
		$tree = $this->plan( $this->arguments( $this->iconListContent() ) )
			->payload[ ElementorTemplateImport::PAYLOAD_TREE ];

		$rows = $tree[0]['elements'][0]['settings']['icon_list'];

		$this->assertCount( 2, $rows );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[0]['_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[1]['_id'] );
		$this->assertNotSame( $rows[0]['_id'], $rows[1]['_id'] );
	}

	/**
	 * Naming fills only the gaps: an id the caller did send is untouched even when
	 * a sibling or a child of it had none.
	 */
	public function test_naming_leaves_the_ids_the_caller_did_send_alone(): void {
		$content                                = $this->unnamedContent();
		$content[ ElementorTemplateImport::INPUT_CONTENT ][0]['id'] = 'imported1';

		$tree = $this->plan( $this->arguments( $content ) )
			->payload[ ElementorTemplateImport::PAYLOAD_TREE ];

		$this->assertSame( 'imported1', $tree[0]['id'] );
		$this->assertNotSame( 'imported1', $tree[0]['elements'][0]['id'] );
	}

	/**
	 * NAMING MUST NOT COST DETERMINISM. `planChange()` runs at preview and again
	 * at apply, and the two payloads are digest-compared, so a naming pass that
	 * moved between the runs would refuse every import it ever planned.
	 */
	public function test_planning_an_unnamed_import_twice_produces_the_same_payload(): void {
		$arguments = $this->arguments( $this->unnamedContent() );
		$operation = $this->operation();

		$this->assertSame(
			$operation->planChange( $this->pendingState(), $arguments, $this->context() )->payload,
			$operation->planChange( $this->pendingState(), $arguments, $this->context() )->payload
		);
	}

	/**
	 * Page settings ride along when they are sent.
	 */
	public function test_page_settings_are_carried_into_the_plan(): void {
		$planned = $this->plan(
			$this->arguments( [ ElementorTemplateImport::INPUT_PAGE_SETTINGS => [ 'content_width' => '1140' ] ] )
		);

		$this->assertSame(
			[ 'content_width' => '1140' ],
			$planned->payload[ ElementorTemplateImport::PAYLOAD_PAGE_SETTINGS ]
		);
	}

	/**
	 * A page-settings value that is not a map is stored as none rather than
	 * refused. Page settings are optional by design — a section template carries
	 * none — and the failure mode of a wrong one is a layout that takes the
	 * destination's settings, which is what a template with none does anyway.
	 */
	public function test_page_settings_that_are_not_a_map_are_stored_as_none(): void {
		$planned = $this->plan(
			$this->arguments( [ ElementorTemplateImport::INPUT_PAGE_SETTINGS => 'wide' ] )
		);

		$this->assertSame( [], $planned->payload[ ElementorTemplateImport::PAYLOAD_PAGE_SETTINGS ] );
	}

	// ------------------------------------------------------- gate 1, shape

	/**
	 * Every node is checked for the members Elementor's parser reads, and for
	 * their types. A node whose `elType` is an array is not a template Elementor
	 * can render; it is a payload, and the parser's answer to it is undefined.
	 *
	 * @dataProvider malformedTrees
	 *
	 * @param array $content The malformed tree.
	 */
	public function test_a_tree_that_is_not_shaped_like_elements_is_refused( array $content ): void {
		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] )
		);
	}

	/**
	 * One malformed tree per member the shape gate reads.
	 *
	 * @return array<string, array{0: array}> The cases.
	 */
	public function malformedTrees(): array {
		return [
			'a node that is not an object'   => [ [ 'not-a-node' ] ],
			'a node with no elType'          => [ [ [ 'settings' => [] ] ] ],
			'an elType that is not a name'   => [ [ [ 'elType' => [ 'container' ] ] ] ],
			'a widgetType that is not a name' => [
				[ [ 'elType' => 'widget', 'widgetType' => [ 'e-heading' ] ] ],
			],
			'settings that are not an object' => [
				[ [ 'elType' => 'container', 'settings' => 'wide' ] ],
			],
			'children that are not a list'   => [
				[ [ 'elType' => 'container', 'elements' => 'none' ] ],
			],
			'a malformed node one level down' => [
				[ [ 'elType' => 'container', 'elements' => [ 'not-a-node' ] ] ],
			],
		];
	}

	/**
	 * No refusal quotes the caller's tree: it is arbitrary text of arbitrary
	 * length that will be read by whoever opens the activity log. The nesting
	 * level and the member name are enough to find the node in a payload the
	 * caller sent and still has.
	 */
	public function test_a_shape_refusal_names_the_level_and_quotes_nothing(): void {
		$content = [
			[
				'elType'   => 'container',
				'elements' => [ [ 'elType' => 'widget', 'settings' => 'secret-value-from-the-caller' ] ],
			],
		];

		try {
			$this->plan( $this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] ) );
			$this->fail( 'Expected the malformed tree to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertStringContainsString( 'nesting level 2', $exception->getMessage() );
			$this->assertStringNotContainsString( 'secret-value-from-the-caller', $exception->getMessage() );
		}
	}

	/**
	 * The shape walk carries the depth bound itself, because it runs BEFORE the
	 * normalizer and a hand-built tree can be nested arbitrarily deep — deeply
	 * enough to exhaust the stack of whatever walks it first.
	 */
	public function test_a_tree_nested_past_the_bound_is_refused(): void {
		$node = [ 'elType' => 'container', 'elements' => [] ];

		for ( $i = 0; $i < ElementorTree::MAX_DEPTH + 1; $i++ ) {
			$node = [ 'elType' => 'container', 'elements' => [ $node ] ];
		}

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => [ $node ] ] )
		);
	}

	// ------------------------------------------------------- gate 2, size

	/**
	 * Measured in bytes, before anything walks the tree twice.
	 */
	public function test_a_tree_larger_than_the_bound_is_refused(): void {
		$content = [
			[
				'elType'   => 'container',
				'settings' => [ 'note' => str_repeat( 'a', ElementorTemplateImport::MAX_CONTENT_BYTES ) ],
				'elements' => [],
			],
		];

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] )
		);
	}

	// ------------------------------------------------------- gate 4, widgets

	/**
	 * A widget this site does not register is refused BY NAME, because the gate
	 * below this one needs a live prop schema per widget and a widget that is not
	 * installed has none.
	 */
	public function test_a_widget_this_site_does_not_have_is_refused_by_name(): void {
		$content = [
			[
				'elType'     => 'widget',
				'widgetType' => 'acme-slider',
				'settings'   => [],
			],
		];

		try {
			$this->plan( $this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] ) );
			$this->fail( 'Expected the missing widget to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
			$this->assertStringContainsString( 'acme-slider', $exception->getMessage() );
		}
	}

	/**
	 * Several missing widgets are named in a stable order, so two runs of the same
	 * import produce the same refusal.
	 */
	public function test_several_missing_widgets_are_named_in_a_stable_order(): void {
		$content = [
			[ 'elType' => 'widget', 'widgetType' => 'zeta-widget', 'settings' => [] ],
			[ 'elType' => 'widget', 'widgetType' => 'alpha-widget', 'settings' => [] ],
		];

		try {
			$this->plan( $this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] ) );
			$this->fail( 'Expected the missing widgets to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertStringContainsString( 'alpha-widget, zeta-widget', $exception->getMessage() );
		}
	}

	// ------------------------------------------------------- gate 5, keys

	/**
	 * UPSTREAM DEFECT #102: Elementor DISCARDS a setting key it does not
	 * recognise instead of rejecting it, so a `content` where the widget declares
	 * `title` is content deleted and reported as a success — in a template built
	 * to be applied to page after page.
	 */
	public function test_a_setting_the_widget_does_not_declare_is_refused(): void {
		$content = [
			[
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'content' => 'Plans' ],
			],
		];

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] )
		);
	}

	/**
	 * A declared key is accepted, so the gate is refusing on the schema rather than
	 * on the presence of settings at all.
	 *
	 * AND IT IS STORED IN ELEMENTOR'S OWN ATOMIC ENVELOPE, not as the bare scalar
	 * the caller sent. An imported template is written straight into
	 * `_elementor_data` without the editor ever normalising it, so a bare value
	 * would sit there rendering correctly until the day somebody opened the
	 * template in the editor and it read the prop as untyped.
	 */
	public function test_a_declared_setting_is_stored_in_elementors_own_envelope(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame(
			[
				'$$type' => 'string',
				'value'  => 'Plans',
			],
			$planned->payload[ ElementorTemplateImport::PAYLOAD_TREE ][0]['elements'][0]['settings']['title']
		);
	}

	// ------------------------------------------------------- the gate order

	/**
	 * THE ORDER IS THE DESIGN. A tree that is both malformed and full of widgets
	 * this site does not have is refused for its shape, because the widget gate
	 * reads members the shape gate has not yet vouched for.
	 */
	public function test_the_shape_gate_runs_before_the_widget_gate(): void {
		$content = [
			[ 'elType' => 'widget', 'widgetType' => 'acme-slider' ],
			[ 'elType' => [ 'widget' ] ],
		];

		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] )
		);
	}

	/**
	 * And a tree naming a widget this site does not have is refused for the
	 * widget, not for the settings on it, because the key gate cannot read a
	 * schema that is not installed.
	 */
	public function test_the_widget_gate_runs_before_the_key_gate(): void {
		$content = [
			[
				'elType'     => 'widget',
				'widgetType' => 'acme-slider',
				'settings'   => [ 'anything-at-all' => 'x' ],
			],
		];

		$this->assertRefusal(
			ErrorCode::IntegrationUnavailable,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $content ] )
		);
	}

	// ------------------------------------------------------- other refusals

	/**
	 * A type this operation will not import.
	 */
	public function test_an_unimportable_type_is_refused(): void {
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'type' => 'header' ] ) );
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'type' => 'popup' ] ) );
	}

	/**
	 * A title of only whitespace is not a title.
	 */
	public function test_a_whitespace_only_title_is_refused(): void {
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'title' => '   ' ] ) );
	}

	/**
	 * An empty tree would store nothing.
	 */
	public function test_an_empty_tree_is_refused(): void {
		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => [] ] )
		);
	}

	/**
	 * Nothing is created when any gate refuses.
	 */
	public function test_a_refused_plan_creates_no_post(): void {
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments( [ 'type' => 'header' ] ) );

		$this->assertSame( [], $this->inserts );
		$this->assertSame( [], $this->posts );
	}

	/**
	 * A create has no prior state to capture.
	 */
	public function test_there_is_nothing_to_snapshot(): void {
		$this->assertNull( $this->operation()->captureSnapshot( $this->pendingState(), $this->context() ) );
	}

	// ------------------------------------------------------- applying

	/**
	 * The insert carries the library post type, the published status and the
	 * title.
	 */
	public function test_the_insert_creates_a_published_library_post(): void {
		$this->applied();

		$this->assertCount( 1, $this->inserts );
		$this->assertSame(
			[
				'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Imported pricing table',
			],
			$this->inserts[0]
		);
	}

	/**
	 * The stored type and edit mode, without which the post is a library entry
	 * that appears in no library section and belongs to no builder.
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
	 * An import is the one create where the operator is most likely to go
	 * looking: someone else's template, brought in to be found again. The meta
	 * satisfies every read this plugin makes; the term is what the library
	 * screen they will open queries by.
	 */
	public function test_the_type_is_also_recorded_in_the_taxonomy_elementors_screens_query(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertSame(
			'section',
			$this->terms[ $id . '|' . ElementorTemplateLibrary::TAXONOMY_TYPE ] ?? null
		);
	}

	/**
	 * The validated tree really is in the created post, through the real writer.
	 */
	public function test_the_tree_is_stored_in_the_created_template(): void {
		$id   = $this->createdIdFrom( $this->applied() );
		$tree = $this->storedTreeFor( $id );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'imported1', $tree[0]['id'] );
		$this->assertSame( 'e-heading', $tree[0]['elements'][0]['widgetType'] );
	}

	/**
	 * Page settings are stored when there are any.
	 */
	public function test_page_settings_are_stored_when_they_were_sent(): void {
		$id = $this->createdIdFrom(
			$this->applied( null, [ ElementorTemplateImport::INPUT_PAGE_SETTINGS => [ 'content_width' => '1140' ] ] )
		);

		$this->assertSame(
			[ 'content_width' => '1140' ],
			$this->meta[ $id . '|' . ElementorTemplateLibrary::META_PAGE_SETTINGS ]
		);
	}

	/**
	 * And no empty settings row is written when there are none, so a template
	 * with no page settings is indistinguishable from one Elementor saved itself.
	 */
	public function test_no_page_settings_row_is_written_when_there_are_none(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertArrayNotHasKey(
			$id . '|' . ElementorTemplateLibrary::META_PAGE_SETTINGS,
			$this->meta
		);
	}

	/**
	 * NOTHING IS WRITTEN TO A DOCUMENT. An import creates a library post and
	 * touches no page on the site; the template shows nowhere until it is applied.
	 */
	public function test_only_the_created_post_is_touched(): void {
		$id = $this->createdIdFrom( $this->applied() );

		foreach ( array_keys( $this->meta ) as $key ) {
			$this->assertStringStartsWith( $id . '|', (string) $key );
		}
	}

	/**
	 * An approved plan that carries no tree is refused rather than creating an
	 * empty template out of it.
	 */
	public function test_an_approved_plan_with_no_tree_is_refused(): void {
		$handler = $this->operation();
		$planned = new PlannedChange(
			[
				ElementorTemplateImport::PAYLOAD_POST => [
					'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'Imported pricing table',
				],
			],
			[ ElementorTemplateTarget::FIELD_TITLE => 'Imported pricing table' ]
		);

		try {
			$handler->applyChange( $this->pendingState(), $planned, $this->context() );
			$this->fail( 'Expected the empty plan to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * A refused insert is reported as a failure to execute.
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
	 * The read-back measures the created template.
	 */
	public function test_the_read_back_reports_the_created_template(): void {
		$handler = $this->operation();
		$key     = $this->applied( $handler );

		$state = $handler->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, array_keys( $state->fields ) );
		$this->assertSame( 'section', $state->fields[ ElementorTemplateTarget::FIELD_TYPE ] );
		$this->assertSame( 2, $state->fields[ ElementorTemplateTarget::FIELD_COUNT ] );
	}

	/**
	 * The honest refusal, not a delete dressed as a rollback.
	 */
	public function test_restore_refuses_rather_than_deleting_the_template(): void {
		$handler = $this->operation();
		$id      = $this->createdIdFrom( $this->applied( $handler ) );

		try {
			$handler->restore( [], $this->context() );
			$this->fail( 'Expected the create to refuse a rollback.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}

		$this->assertArrayHasKey( $id, $this->posts );
	}

	// ------------------------------------------------------- helpers

	/**
	 * The operation, wired as the module wires it, on a site running Elementor.
	 *
	 * @return ElementorTemplateImport The operation.
	 */
	private function operation(): ElementorTemplateImport {
		$this->withElementor();

		return new ElementorTemplateImport(
			$this->templateTarget(),
			new ElementorTreeInput( new ElementorTree(), $this->propCoercion(), new ElementorPresence() ),
			$this->propCoercion(),
			$this->documentWriter(),
			new ElementorIdMint()
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
	 * One valid argument set: a container holding one heading.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge(
			[
				'title'                              => 'Imported pricing table',
				'type'                               => 'section',
				ElementorTemplateImport::INPUT_CONTENT => [
					[
						'id'       => 'imported1',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'imported2',
								'elType'     => 'widget',
								'widgetType' => 'e-heading',
								'settings'   => [ 'title' => 'Plans' ],
								'elements'   => [],
							],
						],
					],
				],
			],
			$overrides
		);
	}

	/**
	 * Content holding one REPEATER-BACKED widget whose rows carry no `_id`,
	 * which is what a caller composing an icon list from scratch sends.
	 *
	 * @return array<string, mixed> The override.
	 */
	private function iconListContent(): array {
		return [
			ElementorTemplateImport::INPUT_CONTENT => [
				[
					'id'       => 'imported1',
					'elType'   => 'container',
					'settings' => [],
					'elements' => [
						[
							'id'         => 'imported3',
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
			],
		];
	}

	/**
	 * The same content with EVERY id left out, as an `arguments()` override.
	 *
	 * @return array<string, mixed> The override.
	 */
	private function unnamedContent(): array {
		$content = $this->arguments()[ ElementorTemplateImport::INPUT_CONTENT ];

		unset( $content[0]['id'], $content[0]['elements'][0]['id'] );

		return [ ElementorTemplateImport::INPUT_CONTENT => $content ];
	}

	/**
	 * A layout of widgets whose images all point at bare URLs.
	 *
	 * @param int $count How many widgets to build.
	 *
	 * @return array[] The layout.
	 */
	private function bareMediaTree( int $count ): array {
		$widgets = [];

		for ( $index = 0; $index < $count; $index++ ) {
			$widgets[] = [
				'elType'     => 'widget',
				'widgetType' => 'icon-list',
				'settings'   => [ 'image' => [ 'url' => 'https://elsewhere.example/' . $index . '.jpg' ] ],
				'elements'   => [],
			];
		}

		return [
			[
				'elType'   => 'container',
				'settings' => [],
				'elements' => $widgets,
			],
		];
	}

	// ------------------------------------------------------- media advisory

	/**
	 * AN IMPORT IS SOMEONE ELSE'S LAYOUT, which is precisely where media values
	 * arrive holding a `url` and no attachment id: the export was written
	 * against another site's library, and every image in it will render without
	 * srcset, without the wp-image class and without lazy-loading while the
	 * write verifies green.
	 */
	public function test_an_imported_bare_media_url_warns_on_the_plan(): void {
		$planned = $this->plan( $this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $this->bareMediaTree( 1 ) ] ) );

		$this->assertCount( 1, $planned->warnings, 'One bare media value earns one advisory.' );
		$this->assertStringContainsString( '"image"', $planned->warnings[0], 'A small import is fixed one key at a time.' );
	}

	/**
	 * Past the cap the report becomes one sentence, because a whole imported
	 * template is not fixed one key at a time and forty sentences is a wall an
	 * operator scrolls past — which would hide the finding as surely as silence.
	 */
	public function test_a_bulk_import_is_summarised_rather_than_listed(): void {
		$planned = $this->plan( $this->arguments( [ ElementorTemplateImport::INPUT_CONTENT => $this->bareMediaTree( 9 ) ] ) );

		$this->assertCount( 1, $planned->warnings, 'Past the cap the report is one sentence.' );
		$this->assertStringContainsString( '9 image settings across 9 elements', $planned->warnings[0], 'Both counts are said, because their ratio is the diagnosis.' );
	}

	/**
	 * An import whose media carries its attachment says nothing at all.
	 */
	public function test_a_clean_import_warns_about_nothing(): void {
		$this->assertSame( [], $this->plan( $this->arguments() )->warnings, 'An advisory that fires on ordinary writes is one nobody reads.' );
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
	 * Plans and applies one import.
	 *
	 * @param ElementorTemplateImport|null $handler   The operation, when the
	 *                                                caller needs the same
	 *                                                instance again.
	 * @param array<string, mixed>         $overrides Argument members to replace.
	 *
	 * @return string The created target key.
	 */
	private function applied( ?ElementorTemplateImport $handler = null, array $overrides = [] ): string {
		$handler ??= $this->operation();
		$state     = $this->pendingState();

		return $handler->applyChange(
			$state,
			$handler->planChange( $state, $this->arguments( $overrides ), $this->context() ),
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
