<?php
/**
 * Tests for ElementorThemeTemplateCreate (REQ-0102).
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
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorTemplateTarget;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorThemeTemplateCreate;
use SiteHelm\Tests\Doubles\TemplateLibraryFixtures;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * REQ-0102: an empty header, footer, archive or single template.
 *
 * WHAT THESE TESTS ARE ABOUT is inertness. The operation's whole claim is that
 * creating a theme document changes not one page on the live site, and that
 * claim rests on two facts a test can check: no display conditions are written,
 * and the document is created empty. Everything else here — the capability, the
 * stored type, the warning — protects the second act, `theme-conditions-set`,
 * from being folded into this one.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The insert double reproduces two
 * upstream facts only: that `wp_insert_post()` answers the new identifier, and
 * that the row is then readable. It models no slug generation, no revisions and
 * no `save_post` hooks, so no assertion here is about what WordPress made of the
 * fields. The writer is the REAL one: "the empty document really is a document
 * Elementor controls" is the assertion this operation most needs, and a stubbed
 * writer would make it a claim about the stub.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorThemeTemplateCreateTest extends TestCase {

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
	private int $nextPostId = 700;

	protected function setUp(): void {
		parent::setUp();

		$this->meta        = [];
		$this->posts       = [];
		$this->inserts     = [];
		$this->mayEdit     = true;
		$this->insertFails = false;
		$this->nextPostId  = 700;

		$this->stubTemplateWordPress( 'sitehelm-theme-template-create' );
	}

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape.
	 */
	public function test_the_definition_declares_the_create_shape(): void {
		$definition = ElementorThemeTemplateCreate::definition();

		$this->assertSame( 'elementor-theme-template-create', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * The capability is `edit_theme_options`, not `edit_posts`. A theme document
	 * is a theme decision even before it applies anywhere, and an editor entitled
	 * to write pages is not thereby entitled to add one to the site's chrome —
	 * particularly since the operation that gives it somewhere to display is gated
	 * on the same capability.
	 */
	public function test_the_capability_is_the_theme_one(): void {
		$this->assertSame(
			[ ElementorKit::CAPABILITY ],
			ElementorThemeTemplateCreate::definition()->requiredCapabilities
		);
		$this->assertSame( 'edit_theme_options', ElementorKit::CAPABILITY );
	}

	/**
	 * Only the theme types this module recognises are offered.
	 */
	public function test_only_theme_types_are_offered(): void {
		$schema = ElementorThemeTemplateCreate::definition()->inputSchema;

		$this->assertSame( ElementorThemeConditions::THEME_TYPES, $schema['properties']['type']['enum'] );
		$this->assertSame( [ 'title', 'type' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	// ------------------------------------------------------- the target

	/**
	 * The document does not exist yet, so the target is the pending key.
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
		$handler = new ElementorThemeTemplateCreate( $this->templateTarget(), $this->documentWriter() );

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
	 * The promise: the type, the title, a published status, and no elements.
	 */
	public function test_the_plan_promises_an_empty_published_document(): void {
		$planned = $this->plan( $this->arguments() );

		$this->assertSame(
			[
				ElementorTemplateTarget::FIELD_TYPE   => 'header',
				ElementorTemplateTarget::FIELD_TITLE  => 'Campaign header',
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => 0,
			],
			$planned->afterFields
		);
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * The warning is not a caveat about this write, which is inert. It is the
	 * thing an operator most needs to know next, said before they discover it by
	 * looking at a site that has not changed.
	 */
	public function test_the_plan_warns_that_the_document_displays_nowhere(): void {
		$warnings = $this->plan( $this->arguments() )->warnings;

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'no display conditions', $warnings[0] );
		$this->assertStringContainsString( 'elementor-theme-conditions-set', $warnings[0] );
	}

	/**
	 * Every recognised theme type is accepted, so the schema's enum and the
	 * guard's list cannot drift apart unnoticed.
	 */
	public function test_every_recognised_theme_type_is_accepted(): void {
		foreach ( ElementorThemeConditions::THEME_TYPES as $type ) {
			$planned = $this->plan( $this->arguments( [ 'type' => $type ] ) );

			$this->assertSame( $type, $planned->afterFields[ ElementorTemplateTarget::FIELD_TYPE ] );
		}
	}

	/**
	 * The type is re-checked in the handler even though the schema declares the
	 * same enum. The schema is validation and this is the guard: an operation that
	 * would store an arbitrary string into `_elementor_template_type` on the
	 * strength of its own schema alone is one schema edit away from creating
	 * documents Elementor cannot classify.
	 */
	public function test_an_unrecognised_type_is_refused_by_the_handler_itself(): void {
		$this->assertRefusal( $this->arguments( [ 'type' => 'landing-page' ] ) );
		$this->assertRefusal( $this->arguments( [ 'type' => '' ] ) );
	}

	/**
	 * A title of only whitespace is not a title.
	 */
	public function test_a_whitespace_only_title_is_refused(): void {
		$this->assertRefusal( $this->arguments( [ 'title' => "\n  " ] ) );
	}

	/**
	 * A usable title is trimmed rather than refused.
	 */
	public function test_a_padded_title_is_trimmed_into_the_promise(): void {
		$planned = $this->plan( $this->arguments( [ 'title' => '  Campaign header ' ] ) );

		$this->assertSame( 'Campaign header', $planned->afterFields[ ElementorTemplateTarget::FIELD_TITLE ] );
	}

	/**
	 * Nothing is created when the plan refuses.
	 */
	public function test_a_refused_plan_creates_no_post(): void {
		$this->assertRefusal( $this->arguments( [ 'type' => 'not-a-type' ] ) );

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
				'post_title'  => 'Campaign header',
			],
			$this->inserts[0]
		);
	}

	/**
	 * THE DOCUMENT IS INERT. No display conditions are written, so it appears
	 * nowhere until `elementor-theme-conditions-set` says where.
	 */
	public function test_no_display_conditions_are_written(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertArrayNotHasKey( $id . '|' . ElementorThemeConditions::META_CONDITIONS, $this->meta );
	}

	/**
	 * The stored type is what makes the post a header rather than an unclassified
	 * library entry.
	 */
	public function test_the_stored_type_and_edit_mode_are_written(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertSame( 'header', $this->meta[ $id . '|' . ElementorThemeConditions::META_TYPE ] );
		$this->assertSame(
			ElementorDocumentWriter::EDIT_MODE,
			$this->meta[ $id . '|' . ElementorDocument::META_EDIT_MODE ]
		);
	}

	/**
	 * The empty tree is WRITTEN rather than left unset: a library post with no
	 * `_elementor_data` row at all is not a document Elementor controls, the read
	 * operations would not find it, and the create would have produced something
	 * only wp-admin can see.
	 */
	public function test_the_empty_document_is_stored_rather_than_left_unset(): void {
		$id = $this->createdIdFrom( $this->applied() );

		$this->assertArrayHasKey( $id . '|' . ElementorDocument::META_DATA, $this->meta );
		$this->assertTrue( ( new ElementorDocument() )->isElementorDocument( $id ) );
		$this->assertSame( [], $this->storedTreeFor( $id ) );
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
	 * The read-back measures the created document, and reports it empty.
	 */
	public function test_the_read_back_reports_an_empty_theme_document(): void {
		$handler = $this->operation();
		$key     = $this->applied( $handler );

		$state = $handler->readBack( $key, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( ElementorTemplateTarget::FIELD_ORDER, array_keys( $state->fields ) );
		$this->assertSame( 'header', $state->fields[ ElementorTemplateTarget::FIELD_TYPE ] );
		$this->assertSame( 'Campaign header', $state->fields[ ElementorTemplateTarget::FIELD_TITLE ] );
		$this->assertSame( 0, $state->fields[ ElementorTemplateTarget::FIELD_COUNT ] );
	}

	/**
	 * The honest refusal, not a delete dressed as a rollback.
	 */
	public function test_restore_refuses_rather_than_deleting_the_document(): void {
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
	 * @return ElementorThemeTemplateCreate The operation.
	 */
	private function operation(): ElementorThemeTemplateCreate {
		$this->withElementor();

		return new ElementorThemeTemplateCreate( $this->templateTarget(), $this->documentWriter() );
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
				'title' => 'Campaign header',
				'type'  => 'header',
			],
			$overrides
		);
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
	 * Plans and applies one create.
	 *
	 * @param ElementorThemeTemplateCreate|null $handler The operation, when the
	 *                                                   caller needs the same
	 *                                                   instance again.
	 *
	 * @return string The created target key.
	 */
	private function applied( ?ElementorThemeTemplateCreate $handler = null ): string {
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
	 * Asserts that planning one request refuses as invalid input.
	 *
	 * @param array<string, mixed> $input The arguments.
	 */
	private function assertRefusal( array $input ): void {
		try {
			$this->plan( $input );
			$this->fail( 'Expected the plan to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}
}
