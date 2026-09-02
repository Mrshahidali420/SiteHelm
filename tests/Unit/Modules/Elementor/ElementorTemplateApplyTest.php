<?php
/**
 * Tests for ElementorTemplateApply (REQ-0102).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
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
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorSettingsMerge;
use SiteHelm\Modules\Elementor\ElementorStyleRemap;
use SiteHelm\Modules\Elementor\ElementorTemplateApply;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;
use SiteHelm\Tests\Doubles\ElementorWordPressStubs;
use SiteHelm\Tests\Doubles\WriteTargetFixtures;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * REQ-0102: a saved template inserted into a page.
 *
 * TWO POSTS, ONE TARGET. The destination document is the target and the template
 * is read-only input, so the cases below check the two posts' permissions
 * separately and check that a rollback puts the DESTINATION back.
 *
 * THE ID INVARIANT IS THE POINT OF THE FILE. Every element the template
 * contributes has to arrive with an identifier no other element in the
 * destination holds, and every style definition and every reference to it has to
 * be rebound to those new identifiers. Both halves are pinned, separately,
 * because a rebind that renamed the definitions and left the references behind
 * renders an unstyled page that every check in the pipeline reports as a success:
 * the tree is well formed, the element count is right, and nothing downstream can
 * tell. The minter and the style remapper are therefore the REAL classes, since
 * both invariants are properties of what they actually produce.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTemplateApplyTest extends TestCase {

	use WriteTargetFixtures;
	use ElementorWordPressStubs;

	/**
	 * The destination document.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * The saved template every case applies.
	 */
	private const TEMPLATE_ID = 412;

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair `get_post_meta()` was asked for.
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
	 * Whether the caller may edit anything at all.
	 */
	private bool $mayEdit = true;

	/**
	 * The posts the caller may NOT edit, whatever `$mayEdit` says.
	 *
	 * @var int[]
	 */
	private array $denied = [];

	/**
	 * The post rows `get_post()` serves, keyed by identifier.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = [];
		$this->reads   = [];
		$this->writes  = [];
		$this->mayEdit = true;
		$this->denied  = [];
		$this->posts   = [];

		$this->stubElementorWordPress( 'sitehelm-template-apply' );
		$this->stubPosts();

		$this->storeRaw( (string) json_encode( $this->destinationTree() ) );
		$this->storeTemplate( $this->templateTree() );
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape.
	 */
	public function test_the_definition_declares_the_apply_shape(): void {
		$definition = ElementorTemplateApply::definition();

		$this->assertSame( 'elementor-template-apply', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * A SNAPSHOT IS REQUIRED, not merely supported: this write replaces a whole
	 * document, and a replacement that could not be recorded first would be one
	 * nothing could undo.
	 */
	public function test_a_snapshot_is_required(): void {
		$this->assertSame( SnapshotPolicy::Required, ElementorTemplateApply::definition()->snapshotPolicy );
	}

	/**
	 * The applied template is NOT idempotent, and saying so is the difference
	 * between a retry that repairs a timed-out call and one that inserts the
	 * template a second time.
	 */
	public function test_the_operation_is_not_idempotent(): void {
		$this->assertFalse( ElementorTemplateApply::definition()->isIdempotent );
	}

	/**
	 * The closed schema: the two required members and the two optional placement
	 * members, and nothing else.
	 */
	public function test_the_input_schema_is_closed_and_names_the_four_members(): void {
		$schema = ElementorTemplateApply::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[
				ElementorTemplateApply::INPUT_TEMPLATE_ID,
				ElementorWriteFields::INPUT_DOCUMENT,
				ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID,
				ElementorElementAddInput::INPUT_INDEX,
			],
			array_keys( $schema['properties'] )
		);
		$this->assertSame(
			[ ElementorTemplateApply::INPUT_TEMPLATE_ID, ElementorWriteFields::INPUT_DOCUMENT ],
			$schema['required']
		);
	}

	/**
	 * The position is bounded in the schema as well as in the handler, so a caller
	 * reading the schema learns the bound before sending anything.
	 */
	public function test_the_position_is_bounded_in_the_schema(): void {
		$declared = ElementorTemplateApply::definition()->inputSchema['properties'][ ElementorElementAddInput::INPUT_INDEX ];

		$this->assertSame( 0, $declared['minimum'] );
		$this->assertSame( ElementorTemplateApply::MAX_INDEX, $declared['maximum'] );
	}

	// ------------------------------------------------------- the target

	/**
	 * THE DESTINATION IS THE TARGET, not the template. A target key naming the
	 * template would produce a rollback reference pointing at a post this
	 * operation never wrote to.
	 */
	public function test_the_target_is_the_destination_document(): void {
		$state = $this->operation()->resolveTarget( $this->arguments(), $this->context() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $state->targetKey );
		$this->assertTrue( $state->exists );
	}

	// ------------------------------------------------------- planning

	/**
	 * The three fields that can move are promised, and `maxDepth` is not: the
	 * writer may reshape settings, never nesting.
	 */
	public function test_the_plan_promises_the_three_moving_fields(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame(
			[
				ElementorWriteFields::FIELD_DIGEST,
				ElementorWriteFields::FIELD_COUNT,
				ElementorWriteFields::FIELD_WIDGETS,
			],
			array_keys( $planned->afterFields )
		);
		$this->assertSame( ElementorWriteFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * The promise counts the destination's elements plus the template's.
	 */
	public function test_the_promised_count_is_both_documents_together(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame( 6, $planned->afterFields[ ElementorWriteFields::FIELD_COUNT ] );
	}

	/**
	 * The preview shows what the page will gain.
	 */
	public function test_the_preview_detail_is_a_diff_of_the_two_documents(): void {
		$detail = $this->plan( $this->arguments() )->previewDetail;

		$this->assertNotSame( [], $detail );
	}

	/**
	 * DETERMINISTIC, because the engine runs `planChange()` at preview and again
	 * at apply and a plan whose ids changed between the two would fail its own
	 * verification.
	 */
	public function test_planning_the_same_request_twice_plans_the_same_ids(): void {
		$operation = $this->operation();
		$state     = $operation->resolveTarget( $this->arguments(), $this->context() );

		$this->assertSame(
			$operation->planChange( $state, $this->arguments(), $this->context() )->payload,
			$operation->planChange( $state, $this->arguments(), $this->context() )->payload
		);
	}

	// ------------------------------------------------------- the id invariant

	/**
	 * NO IDENTIFIER THE TEMPLATE STORES SURVIVES INTO THE DOCUMENT. A template is
	 * applied to page after page, and reusing its stored ids would put the same
	 * identifier in two places the first time one page took the template twice.
	 */
	public function test_no_stored_template_identifier_reaches_the_document(): void {
		$ids = $this->idsIn( $this->plannedTree( $this->arguments() ) );

		foreach ( $this->idsIn( $this->templateTree() ) as $stored ) {
			$this->assertNotContains( $stored, $ids );
		}
	}

	/**
	 * And every identifier in the finished document is distinct.
	 */
	public function test_every_identifier_in_the_planned_document_is_distinct(): void {
		$ids = $this->idsIn( $this->plannedTree( $this->arguments() ) );

		$this->assertSame( array_values( array_unique( $ids ) ), $ids );
	}

	/**
	 * APPLYING THE SAME TEMPLATE TWICE INTO THE SAME PLACE MUST NOT COLLIDE WITH
	 * ITSELF. The mint is seeded from the destination's current ids, so the second
	 * application sees the first's and mints around them.
	 */
	public function test_applying_the_same_template_twice_mints_two_distinct_sets(): void {
		$this->applied( $this->arguments() );
		$this->applied( $this->arguments() );

		$ids = $this->idsIn( $this->storedTree() );

		$this->assertCount( 8, $ids );
		$this->assertSame( array_values( array_unique( $ids ) ), $ids );
	}

	/**
	 * A TEMPLATE WHOSE TWO TOP-LEVEL ELEMENTS SHARE A STORED ID — which a
	 * hand-edited or imported template can hold — still lands with two distinct
	 * ids, because each element is minted against the tree being built rather than
	 * against the tree as it was found.
	 */
	public function test_two_template_elements_sharing_one_stored_id_land_distinct(): void {
		$this->storeTemplate(
			[
				[ 'id' => 'dupe111', 'elType' => 'container', 'elements' => [] ],
				[ 'id' => 'dupe111', 'elType' => 'container', 'elements' => [] ],
			]
		);

		$ids = $this->plan( $this->arguments() )->payload[ ElementorTemplateApply::PAYLOAD_ELEMENT_IDS ];

		$this->assertCount( 2, $ids );
		$this->assertNotSame( $ids[0], $ids[1] );
	}

	/**
	 * The ids the applied top-level elements were given are recorded in the
	 * payload, which is what makes the applied content findable afterwards.
	 */
	public function test_the_applied_top_level_identifiers_are_recorded(): void {
		$planned = $this->plan( $this->arguments() );
		$ids     = $planned->payload[ ElementorTemplateApply::PAYLOAD_ELEMENT_IDS ];

		$this->assertCount( 1, $ids );
		$this->assertContains( $ids[0], $this->idsIn( $planned->payload[ ElementorTemplateApply::PAYLOAD_TREE ] ) );
	}

	// ------------------------------------------------------- the style invariant

	/**
	 * THE STYLE DEFINITIONS ARE REBOUND. A local class is named after the element
	 * that owns it, so a copy that kept the template's class keys would leave two
	 * elements sharing one definition across two documents.
	 */
	public function test_the_local_style_definitions_are_rebound_to_the_new_identifiers(): void {
		$node = $this->appliedNode();

		$this->assertArrayNotHasKey( 'e-tpl1111-aaa111', $node['styles'] );
		$this->assertCount( 1, $node['styles'] );

		$key = (string) array_key_first( $node['styles'] );

		$this->assertSame( $key, $node['styles'][ $key ]['id'] );
	}

	/**
	 * AND SO ARE THE REFERENCES TO THEM. This is the half that fails silently: a
	 * remap that renamed the definitions and left `settings.classes.value` naming
	 * the template's old keys produces a page that renders unstyled and reports
	 * every check as a success.
	 */
	public function test_the_style_references_are_rebound_to_the_same_new_identifiers(): void {
		$node = $this->appliedNode();

		$this->assertSame(
			[ (string) array_key_first( $node['styles'] ), 'g-brand' ],
			$node['settings']['classes']['value']
		);
	}

	/**
	 * A GLOBAL CLASS IS NOT A LOCAL ONE AND SURVIVES UNTOUCHED. Rebinding it would
	 * detach the applied elements from the site's own design system, which is the
	 * one thing a template is applied in order to inherit.
	 */
	public function test_a_global_class_reference_survives_the_apply(): void {
		$this->assertContains( 'g-brand', $this->appliedNode()['settings']['classes']['value'] );
	}

	// ------------------------------------------------------- placement

	/**
	 * An omitted position appends.
	 */
	public function test_an_omitted_position_appends_to_the_document(): void {
		$this->applied( $this->arguments() );

		$ids = $this->rootIds();

		$this->assertCount( 3, $ids );
		$this->assertSame( $ids[2], $this->lastAppliedId() );
	}

	/**
	 * A named position places the template there.
	 */
	public function test_a_named_position_places_the_template_there(): void {
		$this->applied( $this->arguments( [ ElementorElementAddInput::INPUT_INDEX => 0 ] ) );

		$this->assertSame( $this->rootIds()[0], $this->lastAppliedId() );
	}

	/**
	 * A named parent inserts inside it rather than at the document root.
	 */
	public function test_a_named_parent_inserts_inside_that_element(): void {
		$input = $this->arguments(
			[
				ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => 'c111111',
				ElementorElementAddInput::INPUT_INDEX             => 0,
			]
		);

		$operation = $this->operation();
		$state     = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $state, $input, $this->context() );

		$operation->captureSnapshot( $state, $this->context() );
		$operation->applyChange( $state, $planned, $this->context() );

		$this->assertSame( [ 'c111111', 'c222222' ], $this->rootIds() );

		$children = ( new ElementorTreeEdit() )->find( $this->storedTree(), 'c111111' );

		$this->assertSame(
			(string) $planned->payload[ ElementorTemplateApply::PAYLOAD_ELEMENT_IDS ][0],
			(string) $children['node']['elements'][0]['id']
		);
	}

	/**
	 * A parent the document does not hold is refused rather than falling back to
	 * the root, which would put the template somewhere the caller did not ask for.
	 */
	public function test_a_parent_the_document_does_not_hold_is_refused(): void {
		$this->assertRefusal(
			ErrorCode::TargetNotFound,
			$this->arguments( [ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => 'nosuch1' ] )
		);
	}

	/**
	 * A position outside the accepted range is a caller's mistake and is reported
	 * rather than clamped.
	 *
	 * @dataProvider unacceptablePositions
	 *
	 * @param mixed $index The position sent.
	 */
	public function test_a_position_outside_the_range_is_refused( mixed $index ): void {
		$this->assertRefusal(
			ErrorCode::InvalidInput,
			$this->arguments( [ ElementorElementAddInput::INPUT_INDEX => $index ] )
		);
	}

	/**
	 * The positions the handler refuses on its own, whatever the schema let past.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public function unacceptablePositions(): array {
		return [
			'negative'      => [ -1 ],
			'past the bound' => [ ElementorTemplateApply::MAX_INDEX + 1 ],
			'not a number'  => [ '2' ],
		];
	}

	// ------------------------------------------------------- refusals

	/**
	 * ONE REFUSAL FOR FOUR CONDITIONS, so a caller cannot learn that a template
	 * exists from the difference between two refusals.
	 *
	 * @dataProvider unavailableTemplates
	 *
	 * @param string $condition The fixture state to install.
	 */
	public function test_an_unavailable_template_is_refused_the_same_way( string $condition ): void {
		switch ( $condition ) {
			case 'no such post':
				unset( $this->posts[ self::TEMPLATE_ID ] );
				break;
			case 'not a library template':
				$this->posts[ self::TEMPLATE_ID ]->post_type = 'page';
				break;
			case 'not an elementor document':
				unset( $this->meta[ self::TEMPLATE_ID . '|' . ElementorDocument::META_EDIT_MODE ] );
				break;
			case 'may not edit it':
				$this->denied = [ self::TEMPLATE_ID ];
				break;
		}

		$this->assertRefusal( ErrorCode::TargetNotFound, $this->arguments() );
	}

	/**
	 * The four conditions that produce the one refusal.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public function unavailableTemplates(): array {
		return [
			'no such post'             => [ 'no such post' ],
			'not a library template'   => [ 'not a library template' ],
			'not an elementor document' => [ 'not an elementor document' ],
			'may not edit it'          => [ 'may not edit it' ],
		];
	}

	/**
	 * THE TEMPLATE'S PERMISSION IS CHECKED SEPARATELY FROM THE DESTINATION'S. They
	 * are two posts; treating the destination's permission as covering both would
	 * let a caller copy a layout out of a page they may not read.
	 */
	public function test_the_destinations_permission_does_not_cover_the_template(): void {
		$this->denied = [ self::TEMPLATE_ID ];

		$this->assertTrue(
			$this->operation()->resolveTarget( $this->arguments(), $this->context() )->exists
		);
		$this->assertRefusal( ErrorCode::TargetNotFound, $this->arguments() );
	}

	/**
	 * A template holding nothing would change nothing, and is refused rather than
	 * written as a no-op the caller has to notice for themselves.
	 */
	public function test_an_empty_template_is_refused(): void {
		$this->storeTemplate( [] );

		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments() );
	}

	/**
	 * A TEMPLATE NAMING WIDGETS THIS SITE DOES NOT HAVE IS REFUSED BY NAME, at plan
	 * time. The coercion sweep would refuse the write anyway, three steps later and
	 * without naming anything, because it may not quote the stored tree it sweeps.
	 * Checking the template the caller chose turns that into a list of plugins to
	 * install.
	 */
	public function test_a_template_naming_widgets_this_site_lacks_is_refused_by_name(): void {
		$this->storeTemplate(
			[
				[
					'id'       => 'tpl1111',
					'elType'   => 'container',
					'elements' => [
						[ 'id' => 'w900001', 'elType' => 'widget', 'widgetType' => 'zeta-widget', 'elements' => [] ],
						[ 'id' => 'w900002', 'elType' => 'widget', 'widgetType' => 'alpha-widget', 'elements' => [] ],
					],
				],
			]
		);

		try {
			$this->plan( $this->arguments() );
			$this->fail( 'Expected the missing widgets to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
			$this->assertStringContainsString( 'alpha-widget, zeta-widget', $exception->getMessage() );
		}
	}

	/**
	 * Nothing is written by any refused plan.
	 */
	public function test_a_refused_plan_leaves_the_document_byte_identical(): void {
		$before = $this->storedRaw();

		$this->storeTemplate( [] );
		$this->assertRefusal( ErrorCode::InvalidInput, $this->arguments() );

		$this->assertSame( $before, $this->storedRaw() );
	}

	// ------------------------------------------------------- applying

	/**
	 * The snapshot records the DESTINATION, which is what a rollback puts back.
	 */
	public function test_the_snapshot_records_the_destination_document(): void {
		$operation = $this->operation();
		$state     = $operation->resolveTarget( $this->arguments(), $this->context() );

		$snapshot = $operation->captureSnapshot( $state, $this->context() );

		$this->assertIsArray( $snapshot );
		$this->assertNotSame( [], $snapshot );
	}

	/**
	 * The template's elements really are in the stored document afterwards.
	 */
	public function test_the_template_is_stored_in_the_destination(): void {
		$key = $this->applied( $this->arguments() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $key );
		$this->assertCount( 6, $this->idsIn( $this->storedTree() ) );
	}

	/**
	 * THE TEMPLATE ITSELF IS LEFT BYTE-IDENTICAL. It is read-only input to this
	 * write, and a template that drifted every time it was applied would stop
	 * being the thing the library says it is.
	 */
	public function test_the_template_is_left_byte_identical(): void {
		$before = $this->meta[ self::TEMPLATE_ID . '|' . ElementorDocument::META_DATA ];

		$this->applied( $this->arguments() );

		$this->assertSame( $before, $this->meta[ self::TEMPLATE_ID . '|' . ElementorDocument::META_DATA ] );
	}

	/**
	 * An approved plan carrying no tree is REFUSED, never substituted with an
	 * empty document — which would replace the destination page's whole content
	 * with nothing and report it as a success.
	 */
	public function test_an_approved_plan_with_no_tree_is_refused(): void {
		$operation = $this->operation();
		$state     = $operation->resolveTarget( $this->arguments(), $this->context() );
		$before    = $this->storedRaw();

		try {
			$operation->applyChange(
				$state,
				new PlannedChange( [], [ ElementorWriteFields::FIELD_COUNT => 6 ] ),
				$this->context()
			);
			$this->fail( 'Expected the empty plan to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( $before, $this->storedRaw() );
	}

	// ------------------------------------------------------- read-back, restore

	/**
	 * The read-back re-reads the destination, so the engine's verification is
	 * against the same fields the plan promised.
	 */
	public function test_the_read_back_reports_the_destination(): void {
		$operation = $this->operation();
		$key       = $this->applied( $this->arguments(), $operation );

		$state = $operation->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 6, $state->fields[ ElementorWriteFields::FIELD_COUNT ] );
	}

	/**
	 * The read-back's promise and the plan's agree, which they must: they are
	 * computed by one formula rather than two.
	 */
	public function test_the_read_back_agrees_with_what_the_plan_promised(): void {
		$operation = $this->operation();
		$state     = $operation->resolveTarget( $this->arguments(), $this->context() );
		$planned   = $operation->planChange( $state, $this->arguments(), $this->context() );

		$operation->captureSnapshot( $state, $this->context() );
		$key = $operation->applyChange( $state, $planned, $this->context() );

		$after = $operation->readBack( $key, $this->context() )->fields;

		foreach ( $planned->afterFields as $field => $value ) {
			$this->assertSame( $value, $after[ $field ] );
		}
	}

	/**
	 * A rollback puts the destination back as it was, byte for byte — the whole
	 * document, not a delete of the elements the apply added, which would have to
	 * trust a recorded id list against a document somebody may since have edited.
	 */
	public function test_a_rollback_restores_the_destination_byte_for_byte(): void {
		$operation = $this->operation();
		$state     = $operation->resolveTarget( $this->arguments(), $this->context() );
		$planned   = $operation->planChange( $state, $this->arguments(), $this->context() );
		$before    = $this->storedRaw();

		$snapshot = $operation->captureSnapshot( $state, $this->context() );
		$operation->applyChange( $state, $planned, $this->context() );

		$this->assertNotSame( $before, $this->storedRaw() );

		$restored = $operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $restored );
		$this->assertSame( $before, $this->storedRaw() );
	}

	// ------------------------------------------------------- helpers

	/**
	 * The operation, wired exactly as `ElementorModule` wires it, on a site
	 * running Elementor. Every collaborator is real; only WordPress and the
	 * `\Elementor\` symbols are doubled.
	 *
	 * @return ElementorTemplateApply The subject.
	 */
	private function operation(): ElementorTemplateApply {
		$this->withElementor();

		$presence = new ElementorPresence();
		$api      = new ElementorApi( $presence );
		$document = new ElementorDocument();
		$tree     = new ElementorTree();
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) );
		$edit     = new ElementorTreeEdit();

		return new ElementorTemplateApply(
			new ElementorWriteTarget( $document, $tree, $presence, $coercion, $writer ),
			$document,
			$edit,
			new ElementorIdMint(),
			new ElementorStyleRemap(),
			$coercion,
			new ElementorSettingsMerge( $edit, $coercion, new ElementorIdMint() ),
			new ElementorTreeDiff( $tree ),
			$tree,
			$presence,
			$writer
		);
	}

	/**
	 * The arguments a caller sends, with both posts filled in.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge(
			[
				ElementorWriteFields::INPUT_DOCUMENT      => self::DOCUMENT_ID,
				ElementorTemplateApply::INPUT_TEMPLATE_ID => self::TEMPLATE_ID,
			],
			$overrides
		);
	}

	/**
	 * Resolve-then-plan, the pair the engine always runs together.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $input ): PlannedChange {
		$operation = $this->operation();

		return $operation->planChange( $operation->resolveTarget( $input, $this->context() ), $input, $this->context() );
	}

	/**
	 * The whole engine sequence: resolve, plan, snapshot, apply.
	 *
	 * @param array<string, mixed>        $input     The arguments.
	 * @param ElementorTemplateApply|null $operation The subject, when the caller
	 *                                               needs the same instance again.
	 *
	 * @return string The written document's target key.
	 */
	private function applied( array $input, ?ElementorTemplateApply $operation = null ): string {
		$operation ??= $this->operation();
		$state       = $operation->resolveTarget( $input, $this->context() );
		$planned     = $operation->planChange( $state, $input, $this->context() );

		$operation->captureSnapshot( $state, $this->context() );

		return $operation->applyChange( $state, $planned, $this->context() );
	}

	/**
	 * The tree one request plans.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array[] The planned tree.
	 */
	private function plannedTree( array $input ): array {
		return $this->plan( $input )->payload[ ElementorTemplateApply::PAYLOAD_TREE ];
	}

	/**
	 * The applied top-level element, as the plan built it.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function appliedNode(): array {
		$planned = $this->plan( $this->arguments() );
		$id      = (string) $planned->payload[ ElementorTemplateApply::PAYLOAD_ELEMENT_IDS ][0];
		$found   = ( new ElementorTreeEdit() )->find(
			$planned->payload[ ElementorTemplateApply::PAYLOAD_TREE ],
			$id
		);

		return null === $found ? [] : $found['node'];
	}

	/**
	 * The id the most recent apply gave the template's top-level element.
	 *
	 * @return string The id.
	 */
	private function lastAppliedId(): string {
		foreach ( $this->rootIds() as $id ) {
			if ( 'c111111' !== $id && 'c222222' !== $id ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * The stored document's root element ids, in order.
	 *
	 * @return string[] The ids.
	 */
	private function rootIds(): array {
		$ids = [];

		foreach ( $this->storedTree() as $node ) {
			$ids[] = (string) ( $node['id'] ?? '' );
		}

		return $ids;
	}

	/**
	 * Every element id in one tree, depth first.
	 *
	 * @param array[] $tree The tree.
	 *
	 * @return string[] The ids.
	 */
	private function idsIn( array $tree ): array {
		$ids = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['id'] ) ) {
				$ids[] = (string) $node['id'];
			}

			if ( is_array( $node['elements'] ?? null ) ) {
				$ids = array_merge( $ids, $this->idsIn( $node['elements'] ) );
			}
		}

		return $ids;
	}

	/**
	 * The destination document as it now reads.
	 *
	 * @return array[] The decoded tree.
	 */
	private function storedTree(): array {
		return ( new ElementorDocument() )->elements( self::DOCUMENT_ID );
	}

	/**
	 * The destination's stored bytes, exactly as they sit in the row.
	 *
	 * @return string The stored value.
	 */
	private function storedRaw(): string {
		$raw = $this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ] ?? '';

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Stores one tree as the saved template, and the library post row that makes
	 * it one.
	 *
	 * @param array[] $tree The template's tree.
	 */
	private function storeTemplate( array $tree ): void {
		$this->meta[ self::TEMPLATE_ID . '|' . ElementorDocument::META_DATA ]      = (string) json_encode( $tree );
		$this->meta[ self::TEMPLATE_ID . '|' . ElementorDocument::META_EDIT_MODE ] = 'builder';

		$row              = new WP_Post();
		$row->ID          = self::TEMPLATE_ID;
		$row->post_type   = ElementorFields::LIBRARY_POST_TYPE;
		$row->post_title  = 'Pricing table';
		$row->post_status = 'publish';

		$this->posts[ self::TEMPLATE_ID ] = $row;
	}

	/**
	 * Installs `get_post()` and the per-post capability check.
	 *
	 * `user_can` is re-declared over the shared stub's version because this
	 * operation asks about TWO posts and the cases have to be able to answer
	 * differently for each.
	 */
	private function stubPosts(): void {
		require_once __DIR__ . '/../../../Doubles/wordpress-value-objects.php';

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool =>
				$this->mayEdit && ! in_array( (int) ( $args[0] ?? 0 ), $this->denied, true )
		);

		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);
	}

	/**
	 * The destination: two root containers, the first holding two headings.
	 *
	 * TWO ROOTS, so "the template landed at position 0" is a claim about a
	 * POSITION rather than about an append.
	 *
	 * @return array[] The tree.
	 */
	private function destinationTree(): array {
		return [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					[ 'id' => 'w111111', 'elType' => 'widget', 'widgetType' => 'e-heading', 'elements' => [] ],
					[ 'id' => 'w222222', 'elType' => 'widget', 'widgetType' => 'e-heading', 'elements' => [] ],
				],
			],
			[
				'id'       => 'c222222',
				'elType'   => 'container',
				'elements' => [],
			],
		];
	}

	/**
	 * The template: a styled container holding one heading.
	 *
	 * The container carries a LOCAL style class and references it from
	 * `settings.classes.value` alongside a GLOBAL one, because the rebind is about
	 * the reference as much as the definition, and because the two kinds of class
	 * must be treated differently.
	 *
	 * @return array[] The tree.
	 */
	private function templateTree(): array {
		return [
			[
				'id'       => 'tpl1111',
				'elType'   => 'container',
				'styles'   => [
					'e-tpl1111-aaa111' => [
						'id'       => 'e-tpl1111-aaa111',
						'label'    => 'local',
						'type'     => 'class',
						'variants' => [],
					],
				],
				'settings' => [
					'classes' => [ 'value' => [ 'e-tpl1111-aaa111', 'g-brand' ] ],
				],
				'elements' => [
					[
						'id'         => 'tpl2222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [
							'title' => [
								'$$type' => 'string',
								'value'  => 'Plans',
							],
						],
						'elements'   => [],
					],
				],
			],
		];
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
