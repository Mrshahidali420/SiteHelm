<?php
/**
 * Tests for ElementorDocumentCreate: definition, validation, apply, verification.
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
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentCreate;
use SiteHelm\Modules\Elementor\ElementorDocumentCreateTarget;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorPageSettings;
use SiteHelm\Modules\Elementor\ElementorPageSettingsTarget;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeInput;
use SiteHelm\Tests\Doubles\TemplateLibraryFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0104's create: the only Elementor write that makes a page rather than
 * changing one.
 *
 * THE CASES BELOW ARE MOSTLY ABOUT THE PAGE THAT COMES OUT. A create is easy to
 * get half right — the post lands, and the thing it produced is a page Elementor
 * does not control, or a published page nobody reviewed, or a page whose layout
 * request went nowhere. Each of those is asserted by name.
 *
 * `_elementor_data` IS WRITTEN EVEN FOR AN EMPTY PAGE, and the case for it is
 * here rather than left to the writer's own tests: without that row
 * `isElementorDocument()` answers false, and every other Elementor write in the
 * module would refuse the page this operation just reported creating.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDocumentCreateTest extends TestCase {

	use TemplateLibraryFixtures;

	/**
	 * The identifier the first created page is given.
	 */
	private const CREATED_ID = 41;

	/**
	 * The local style class the styled-layout cases define.
	 */
	private const STYLE_ID = 'e-w991111-abc1234';

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * The faked posts table, keyed by identifier.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = [];

	/**
	 * Every field map wp_insert_post() was called with.
	 *
	 * @var array[]
	 */
	private array $inserts = [];

	/**
	 * Whether the caller may act.
	 */
	private bool $mayEdit = true;

	/**
	 * Whether wp_insert_post() refuses.
	 */
	private bool $insertFails = false;

	/**
	 * The identifier the next created post is given.
	 */
	private int $nextPostId = self::CREATED_ID;

	protected function setUp(): void {
		parent::setUp();

		$this->meta        = [];
		$this->posts       = [];
		$this->inserts     = [];
		$this->mayEdit     = true;
		$this->insertFails = false;
		$this->nextPostId  = self::CREATED_ID;

		$this->stubTemplateWordPress( 'sitehelm-document-create' );
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape. NOT destructive and only `Risk::Medium`: nothing that
	 * exists is touched, and what this adds is an unpublished draft.
	 */
	public function test_the_definition_declares_a_non_destructive_creation(): void {
		$definition = ElementorDocumentCreate::definition();

		$this->assertSame( 'elementor-document-create', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * The title is the only required argument, and the post type is an enumeration
	 * rather than a free string: the library type is not on it, because a page
	 * created there is one nobody visits.
	 */
	public function test_only_the_title_is_required_and_the_post_type_is_enumerated(): void {
		$schema = ElementorDocumentCreate::definition()->inputSchema;

		$this->assertSame( [ ElementorDocumentCreate::INPUT_TITLE ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'page', 'post' ],
			$schema['properties'][ ElementorDocumentCreate::INPUT_POST_TYPE ]['enum']
		);
		$this->assertNotContains(
			'elementor_library',
			$schema['properties'][ ElementorDocumentCreate::INPUT_POST_TYPE ]['enum']
		);
	}

	/**
	 * The declared output names the five fields the read-back reports, so a client
	 * reading the catalog knows what an apply answers without applying one.
	 */
	public function test_the_output_schema_names_the_verified_fields(): void {
		$schema = ElementorDocumentCreate::definition()->outputSchema;
		$state  = null;

		foreach ( $schema['oneOf'] as $branch ) {
			if ( array_key_exists( 'state', $branch['properties'] ?? [] ) ) {
				$state = $branch['properties']['state'];
			}
		}

		$this->assertNotNull( $state );
		$this->assertSame( ElementorDocumentCreateTarget::FIELD_ORDER, $state['required'] );
	}

	// ------------------------------------------------------- the guards

	/**
	 * A site without Elementor is refused before any plan is built, so no preview
	 * ever shows a caller a page that could not be created.
	 */
	public function test_a_site_without_elementor_is_refused_at_resolve_time(): void {
		try {
			$this->create()->resolveTarget( $this->request(), $this->context() );
			$this->fail( 'Expected the presence gate to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * A title that is only whitespace is refused rather than trimmed to nothing
	 * and stored, which would create a page with no name in the editor's list.
	 */
	public function test_a_title_of_only_whitespace_is_refused(): void {
		$this->withElementor();

		try {
			$this->plan( $this->request( [ ElementorDocumentCreate::INPUT_TITLE => "  \t " ] ) );
			$this->fail( 'Expected the blank title to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * A post type this operation does not create is refused by name.
	 */
	public function test_a_post_type_this_operation_will_not_create_is_refused(): void {
		$this->withElementor();

		try {
			$this->plan( $this->request( [ ElementorDocumentCreate::INPUT_POST_TYPE => 'elementor_library' ] ) );
			$this->fail( 'Expected the post type to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * A STARTING LAYOUT GOES THROUGH THE SAME GATES A BUILD'S DOES. A create that
	 * skipped them would be the way to get an unvalidated tree into the site: make
	 * the page with it, rather than writing it to a page that exists.
	 */
	public function test_a_starting_layout_using_an_undeclared_setting_key_is_refused(): void {
		$this->withElementor();

		$layout = [
			[
				'id'         => 'w991111',
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'content' => 'Text under a key this widget has never had' ],
				'elements'   => [],
			],
		];

		try {
			$this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $layout ] ) );
			$this->fail( 'Expected the undeclared setting key to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * A LOCAL STYLE CLASS NOTHING WEARS RENDERS NOTHING — issue #97 from the
	 * writing side. Elementor stores a `styles` entry happily and generates its
	 * CSS under a selector no element carries, so the write reports success, the
	 * read verifies green, and the page looks exactly as it did before. The
	 * refusal names the style id so an operator can see which half is missing.
	 */
	public function test_a_style_class_no_element_wears_is_refused_by_name(): void {
		$this->withElementor();

		try {
			$this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->styledTree( false ) ] ) );
			$this->fail( 'Expected the unreferenced local style class to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( self::STYLE_ID, $exception->getMessage() );
		}

		$this->assertSame( [], $this->inserts );
	}

	/**
	 * BOTH HALVES PRESENT IS THE SHAPE THE GATE EXISTS TO PROTECT, and it has to
	 * pass untouched: a refusal here would make local styling unwritable.
	 */
	public function test_a_style_class_the_element_wears_is_accepted(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->styledTree( true ) ] ) );

		$this->assertInstanceOf( PlannedChange::class, $planned );
	}

	/**
	 * A TREE WITH NO LOCAL STYLES AT ALL HAS NOTHING TO JUDGE. Most layouts are
	 * this one, and the gate must not cost them a refusal.
	 */
	public function test_a_layout_carrying_no_local_styles_passes_the_gate(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->startingTree() ] ) );

		$this->assertInstanceOf( PlannedChange::class, $planned );
	}

	// ------------------------------------------------------- planning

	/**
	 * THE PROMISE SAYS DRAFT, ALWAYS. The status is a constant rather than an
	 * argument, so nothing an agent sends can publish a page nobody reviewed.
	 */
	public function test_the_plan_promises_a_draft_page(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request() );

		$this->assertSame( ElementorDocumentCreateTarget::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( 'draft', $planned->afterFields[ ElementorDocumentCreateTarget::FIELD_STATUS ] );
		$this->assertSame( 'page', $planned->afterFields[ ElementorDocumentCreateTarget::FIELD_TYPE ] );
		$this->assertSame( 'Spring services', $planned->afterFields[ ElementorDocumentCreateTarget::FIELD_TITLE ] );
		$this->assertSame( 0, $planned->afterFields[ ElementorDocumentCreateTarget::FIELD_COUNT ] );
	}

	/**
	 * A layout the caller sends is counted in the promise, so a preview reports
	 * the page's size before anything is created.
	 */
	public function test_a_starting_layout_is_counted_in_the_promise(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->startingTree() ] ) );

		$this->assertSame( 2, $planned->afterFields[ ElementorDocumentCreateTarget::FIELD_COUNT ] );
		$this->assertSame( [ 'e-heading' ], $planned->previewDetail['widgetTypes'] );
	}

	/**
	 * A STARTING LAYOUT SENT WITHOUT IDS IS NAMED. Elementor keys its per-element
	 * CSS on the id, so a draft created with unnamed nodes generates every rule
	 * under `.elementor-element-` and therefore applies all of them to all of its
	 * elements at once — a page that reads back correctly and renders as one
	 * collapsed block.
	 */
	public function test_a_starting_layout_sent_with_no_ids_is_named(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->unnamedTree() ] ) );

		$tree = $planned->payload[ ElementorDocumentCreate::PAYLOAD_TREE ];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $tree[0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $tree[0]['elements'][0]['id'] );
		$this->assertNotSame( $tree[0]['id'], $tree[0]['elements'][0]['id'] );
	}

	/**
	 * THE SAME DEFECT ONE LEVEL DOWN. Elementor generates each REPEATER ROW's CSS
	 * under `.elementor-repeater-item-<_id>`, so a draft created with rows that
	 * carry no `_id` holds content that renders but can never be styled per row,
	 * and gives the editor no stable handle on which row is which.
	 */
	public function test_a_starting_layout_s_repeater_rows_are_named(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->iconListTree() ] ) );

		$rows = $planned->payload[ ElementorDocumentCreate::PAYLOAD_TREE ][0]['elements'][0]['settings']['icon_list'];

		$this->assertCount( 2, $rows );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[0]['_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', (string) $rows[1]['_id'] );
		$this->assertNotSame( $rows[0]['_id'], $rows[1]['_id'] );
	}

	/**
	 * An id the caller did send is stored exactly as it was sent.
	 */
	public function test_a_starting_layout_keeps_the_ids_the_caller_sent(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->startingTree() ] ) );

		$tree = $planned->payload[ ElementorDocumentCreate::PAYLOAD_TREE ];

		$this->assertSame( 'c999999', $tree[0]['id'] );
		$this->assertSame( 'w991111', $tree[0]['elements'][0]['id'] );
	}

	/**
	 * Naming does not cost the determinism the plan token depends on.
	 */
	public function test_planning_an_unnamed_layout_twice_produces_the_same_payload(): void {
		$this->withElementor();

		$input = $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->unnamedTree() ] );

		$this->assertSame( $this->plan( $input )->payload, $this->plan( $input )->payload );
	}

	/**
	 * PLANNING TWICE PRODUCES THE SAME PAYLOAD. The engine fingerprints the plan
	 * at preview and compares the fingerprint at apply, so a payload that minted
	 * an id or read a clock would refuse every creation it ever planned.
	 */
	public function test_planning_twice_produces_the_same_payload(): void {
		$this->withElementor();

		$this->assertSame(
			$this->plan( $this->request() )->payload,
			$this->plan( $this->request() )->payload
		);
	}

	// ------------------------------------------------------- applying

	/**
	 * The page lands as a draft of the requested type.
	 */
	public function test_the_page_is_created_as_a_draft(): void {
		$this->withElementor();

		$key = $this->applied( $this->request() );

		$this->assertSame( ElementorDocumentCreateTarget::targetKey( self::CREATED_ID ), $key );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'draft', $this->inserts[0]['post_status'] );
		$this->assertSame( 'page', $this->inserts[0]['post_type'] );
	}

	/**
	 * THE CREATED PAGE IS AN ELEMENTOR DOCUMENT EVEN WITH NO CONTENT. Both meta
	 * rows are written unconditionally; without them the page reports as one
	 * Elementor does not control, and every other write in the module refuses it.
	 */
	public function test_an_empty_page_is_still_an_elementor_document(): void {
		$this->withElementor();

		$this->applied( $this->request() );

		$this->assertSame( '[]', $this->meta[ self::CREATED_ID . '|' . ElementorDocument::META_DATA ] );
		$this->assertSame( 'builder', $this->meta[ self::CREATED_ID . '|' . ElementorDocument::META_EDIT_MODE ] );
	}

	/**
	 * A starting layout is stored on the page that was just created.
	 */
	public function test_a_starting_layout_is_stored_on_the_created_page(): void {
		$this->withElementor();

		$this->applied( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->startingTree() ] ) );

		$stored = ( new ElementorDocument() )->elements( self::CREATED_ID );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'c999999', $stored[0]['id'] );
	}

	/**
	 * A REQUESTED LAYOUT REACHES THE PAGE-SETTINGS ROW IN ELEMENTOR'S OWN
	 * VOCABULARY, through the same allowlist `elementor-page-settings-set` uses.
	 * A create that stored the caller's word verbatim would write a row Elementor
	 * reads as no layout at all.
	 */
	public function test_a_requested_layout_reaches_the_page_settings_row(): void {
		$this->withElementor();

		$this->applied( $this->request( [ ElementorPageSettings::FIELD_LAYOUT => 'canvas' ] ) );

		$this->assertSame(
			[ 'template' => 'elementor_canvas' ],
			$this->meta[ self::CREATED_ID . '|' . ElementorPageSettings::META_KEY ]
		);
	}

	/**
	 * A page created without a layout gets NO settings row at all. Elementor reads
	 * an absent row as the theme's own layout, which is the default this operation
	 * documents; writing one would be a decision the caller did not make.
	 */
	public function test_no_settings_row_is_written_when_no_layout_is_requested(): void {
		$this->withElementor();

		$this->applied( $this->request() );

		$this->assertArrayNotHasKey(
			self::CREATED_ID . '|' . ElementorPageSettings::META_KEY,
			$this->meta
		);
	}

	/**
	 * WordPress refusing the insert is reported as an execution failure rather
	 * than verified against a page that is not there.
	 */
	public function test_a_refused_insert_is_reported(): void {
		$this->withElementor();
		$this->insertFails = true;

		try {
			$this->applied( $this->request() );
			$this->fail( 'Expected the refused insert to be reported.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	/**
	 * A plan carrying no post to create is refused rather than filled in from the
	 * operation's own defaults, which is the difference between "create this page"
	 * and "this plan does not say what to create".
	 */
	public function test_a_plan_with_no_post_member_is_refused(): void {
		$this->withElementor();

		$operation = $this->create();
		$target    = $operation->resolveTarget( $this->request(), $this->context() );
		$planned   = $operation->planChange( $target, $this->request(), $this->context() );

		$stripped = new PlannedChange( [ 'unrelated' => true ], $planned->afterFields, $planned->fieldOrder );

		try {
			$operation->applyChange( $target, $stripped, $this->context() );
			$this->fail( 'Expected the incomplete plan to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->inserts );
	}

	// ------------------------------------------------------- verification

	/**
	 * The read-back measures the created page rather than repeating the promise,
	 * so the engine's comparison has two independently produced sides.
	 */
	public function test_the_read_back_measures_the_created_page(): void {
		$this->withElementor();

		$operation = $this->create();
		$key       = $this->applied( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->startingTree() ] ) );

		$state = $operation->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 'draft', $state->fields[ ElementorDocumentCreateTarget::FIELD_STATUS ] );
		$this->assertSame( 2, $state->fields[ ElementorDocumentCreateTarget::FIELD_COUNT ] );
		$this->assertNotSame( '', $state->fields[ ElementorDocumentCreateTarget::FIELD_DIGEST ] );
	}

	/**
	 * A key naming no page cannot be verified, and says so rather than reporting a
	 * page that was never created.
	 */
	public function test_a_read_back_of_an_unrecognised_key_is_a_verification_failure(): void {
		try {
			$this->create()->readBack( 'not-a-created-page', $this->context() );
			$this->fail( 'Expected the unrecognised key to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}

	/**
	 * A CREATION CANNOT BE ROLLED BACK, and says so instead of deleting the page
	 * it made: a delete on a failure path is a second destructive write, taken
	 * without a snapshot and without a preview. The draft is unpublished, so
	 * leaving it costs a row in the editor's list.
	 */
	public function test_a_rollback_is_unavailable_and_says_so(): void {
		try {
			$this->create()->restore( [], $this->context() );
			$this->fail( 'Expected the rollback to be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- helpers

	/**
	 * The operation, wired exactly as the module wires it.
	 *
	 * @return ElementorDocumentCreate The subject.
	 */
	private function create(): ElementorDocumentCreate {
		$presence = new ElementorPresence();
		$document = new ElementorDocument();
		$tree     = new ElementorTree();
		$coercion = $this->propCoercion();

		return new ElementorDocumentCreate(
			new ElementorDocumentCreateTarget( $document, $tree, $presence ),
			new ElementorTreeInput( $tree, $coercion, $presence ),
			$coercion,
			new ElementorPageSettingsTarget( $document, $presence ),
			$this->documentWriter(),
			new ElementorIdMint()
		);
	}

	/**
	 * A layout whose one widget points its image at a URL with no attachment id.
	 *
	 * @return array[] The layout.
	 */
	private function bareMediaTree(): array {
		return [
			[
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'elType'     => 'widget',
						'widgetType' => 'icon-list',
						'settings'   => [ 'image' => [ 'url' => 'https://elsewhere.example/hero.jpg' ] ],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * One request.
	 *
	 * @param array<string, mixed> $overrides Argument members to add or replace.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function request( array $overrides = [] ): array {
		return array_merge( [ ElementorDocumentCreate::INPUT_TITLE => 'Spring services' ], $overrides );
	}

	// ------------------------------------------------------- media advisory

	/**
	 * A DOCUMENT CREATED FROM A SUPPLIED LAYOUT is the same bulk path a build
	 * takes, and it carries the same defect: a media value holding a `url` and
	 * no attachment id stores cleanly, reads back verbatim, and puts an
	 * unresponsive full-size image on a brand-new page.
	 */
	public function test_a_created_layout_with_a_bare_media_url_warns_on_the_plan(): void {
		$this->withElementor();

		$planned = $this->plan( $this->request( [ ElementorDocumentCreate::INPUT_CONTENT => $this->bareMediaTree() ] ) );

		$this->assertCount( 1, $planned->warnings, 'One bare media value earns one advisory.' );
		$this->assertStringContainsString( '"image"', $planned->warnings[0], 'The operator has to learn which setting to fix.' );
	}

	/**
	 * A document created with no layout at all cannot warn about one, and the
	 * empty-content branch is the one that skips the gates entirely.
	 */
	public function test_a_created_document_with_no_layout_warns_about_nothing(): void {
		$this->withElementor();

		$this->assertSame( [], $this->plan( $this->request() )->warnings, 'There is nothing to judge and nothing to say.' );
	}

	/**
	 * Runs resolve-then-plan, the pair the change engine always runs together.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $input ): PlannedChange {
		$operation = $this->create();

		return $operation->planChange( $operation->resolveTarget( $input, $this->context() ), $input, $this->context() );
	}

	/**
	 * Runs the whole engine sequence: resolve, plan, snapshot, apply.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return string The created target's key.
	 */
	private function applied( array $input ): string {
		$operation = $this->create();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$this->assertNull( $operation->captureSnapshot( $target, $this->context() ) );

		return $operation->applyChange( $target, $planned, $this->context() );
	}

	/**
	 * The same starting layout with every id left out, which is what a caller who
	 * composed a page from scratch sends.
	 *
	 * @return array[] The layout.
	 */
	private function unnamedTree(): array {
		$tree = $this->startingTree();

		unset( $tree[0]['id'], $tree[0]['elements'][0]['id'] );

		return $tree;
	}

	/**
	 * A starting layout holding one REPEATER-BACKED widget, with no `_id` on any
	 * row, which is what a caller composing an icon list from scratch sends.
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
	 * One heading defining a local style class, wearing it or not.
	 *
	 * @param bool $referenced Whether the element's settings wear the class.
	 *
	 * @return array[] The layout.
	 */
	private function styledTree( bool $referenced ): array {
		$settings = [ 'title' => 'A new heading' ];

		if ( $referenced ) {
			$settings['classes'] = [ 'value' => [ self::STYLE_ID ] ];
		}

		return [
			[
				'id'       => 'c999999',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w991111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => $settings,
						'styles'     => [
							self::STYLE_ID => [
								'id'       => self::STYLE_ID,
								'type'     => 'class',
								'variants' => [],
							],
						],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * The starting layout the content cases send: one container, one heading.
	 *
	 * @return array[] The layout.
	 */
	private function startingTree(): array {
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
				],
			],
		];
	}
}
