<?php
/**
 * Tests for ElementorControlSchema (REQ-0067).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorControlSchema;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0067: the controls one type declares, read from the running plugin.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is the three-way split between "no
 * such type", "nothing could be read" and "found, declares nothing". They are
 * `TargetNotFound`, `ExecutionFailed` and a normal answer with an empty map, and
 * an implementation that collapsed any two of them would still pass a test
 * suite that only asserted `OperationException`. Every refusal case below
 * therefore asserts the specific ErrorCode, and the empty case asserts a
 * successful response rather than the absence of a throw.
 *
 * THE SECOND DISTINCTION is that the widget registry and the element registry
 * are separate. A name registered as a widget is not registered as a container,
 * and there is a test that asks for one under the other kind and requires a
 * refusal — because a handler that resolved both through one manager would pass
 * every other test in this file.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The Elementor stand-in below
 * reproduces exactly four upstream behaviours:
 *
 * 1. `Plugin::instance()` answers a singleton carrying `widgets_manager` and
 *    `elements_manager` as public members.
 * 2. `Widgets_Manager::get_widget_types()` with no argument answers an
 *    associative array keyed by widget name; with a name it answers that one
 *    widget or null.
 * 3. `Elements_Manager::get_element_types()` behaves the same way against its
 *    own separate registry.
 * 4. `Controls_Stack::get_controls()` answers the stored control array, in which
 *    `name`, `type` and `tab` are guaranteed on every control because
 *    `Controls_Manager::add_control()` merges its defaults and stamps the name.
 *
 * It reproduces NOTHING else: no control sections, no responsive duplication, no
 * conditions, and in particular not the frontend optimisation that splits style
 * controls off — that is disabled whenever `REST_REQUEST` is defined, which is
 * every request this plugin serves.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of a process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorControlSchemaTest extends TestCase {

	private ElementorControlSchema $handler;

	/**
	 * Whether user_can( 'edit_posts' ) approves the caller.
	 */
	private bool $mayEditPosts = true;

	protected function setUp(): void {
		parent::setUp();

		// One presence object shared by the operation and the accessor beneath
		// it, which is how the module wires them: a request must not be able to
		// answer "is Elementor installed" two different ways.
		$presence = new ElementorPresence();

		$this->handler      = new ElementorControlSchema( new ElementorApi( $presence ), $presence );
		$this->mayEditPosts = true;

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool => 'edit_posts' === $capability && $this->mayEditPosts
		);
	}

	/**
	 * Installs Elementor with the given widget and element registries.
	 *
	 * @param mixed $widgets  The widget registry, or a non-array to make it unreadable.
	 * @param mixed $elements The element registry, or a non-array to make it unreadable.
	 */
	private function withElementor( mixed $widgets = null, mixed $elements = null ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ControlSchemaPlugin::class, 'Elementor\Plugin' );
		}

		$plugin                   = new ControlSchemaPlugin();
		$plugin->widgets_manager  = new ControlSchemaWidgets( $widgets ?? $this->widgetRegistry() );
		$plugin->elements_manager = new ControlSchemaElements( $elements ?? $this->elementRegistry() );

		ControlSchemaPlugin::$instance = $plugin;
	}

	/**
	 * A heading widget declaring one control of each interesting shape.
	 *
	 * @return array<string, mixed> The registry.
	 */
	private function widgetRegistry(): array {
		return [
			'heading' => new ControlSchemaStack(
				[
					'title'        => [
						'name'        => 'title',
						'type'        => 'text',
						'tab'         => 'content',
						'label'       => 'Title',
						'default'     => 'Add Your Heading Text Here',
						'section'     => 'section_title',
						'description' => 'The text the heading shows.',
						'selectors'   => [ '{{WRAPPER}} .elementor-heading-title' => 'color: {{VALUE}}' ],
						'condition'   => [ 'title!' => '' ],
					],
					'header_size' => [
						'name'    => 'header_size',
						'type'    => 'select',
						'tab'     => 'content',
						'options' => [
							'h1' => 'H1',
							'h2' => 'H2',
						],
					],
				]
			),
			'spacer'  => new ControlSchemaStack( [] ),
			'broken'  => new ControlSchemaStack( 'not an array' ),
		];
	}

	/**
	 * A container element type declaring one control.
	 *
	 * @return array<string, mixed> The registry.
	 */
	private function elementRegistry(): array {
		return [
			'container' => new ControlSchemaStack(
				[
					'flex_direction' => [
						'name' => 'flex_direction',
						'type' => 'choose',
						'tab'  => 'layout',
					],
				]
			),
		];
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'elementor' => [
					'version' => '3.25.0',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function describe( array $input ): array {
		return $this->handler->handle( $input, $this->makeContext() );
	}

	/**
	 * Runs the operation expecting a refusal, and answers it.
	 *
	 * Returned rather than asserted here, so each caller asserts the specific
	 * ErrorCode. A bare expectException( OperationException::class ) passes for
	 * any of the eleven codes and proves nothing about which one was raised —
	 * which is exactly what this file exists to tell apart.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $input ): OperationException {
		try {
			$this->describe( $input );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}

	// ---------------------------------------------------------------- payload

	public function test_the_response_carries_the_type_the_version_and_the_controls(): void {
		$this->withElementor();

		$result = $this->describe( [ 'type' => 'heading' ] );

		$this->assertSame(
			[ 'type', 'kind', 'elementorVersion', 'controlCount', 'controls' ],
			array_keys( $result )
		);
		$this->assertSame( 'heading', $result['type'] );
		$this->assertSame( 'widget', $result['kind'] );
		$this->assertSame( '3.25.0', $result['elementorVersion'] );
		$this->assertSame( 2, $result['controlCount'] );
	}

	public function test_the_controls_are_keyed_by_control_name(): void {
		$this->withElementor();

		$result = $this->describe( [ 'type' => 'heading' ] );

		$this->assertSame( [ 'title', 'header_size' ], array_keys( $result['controls'] ) );
	}

	public function test_the_three_guaranteed_keys_are_projected_for_every_control(): void {
		$this->withElementor();

		foreach ( $this->describe( [ 'type' => 'heading' ] )['controls'] as $name => $descriptor ) {
			$this->assertSame( $name, $descriptor['name'] );
			$this->assertArrayHasKey( 'type', $descriptor );
			$this->assertArrayHasKey( 'tab', $descriptor );
		}
	}

	/**
	 * A key the control does not declare is absent rather than null.
	 *
	 * `header_size` declares no `default`. A descriptor carrying `'default' =>
	 * null` would tell a client the control's default IS null, which is a
	 * different and wrong claim.
	 */
	public function test_an_optional_key_the_control_does_not_declare_is_absent(): void {
		$this->withElementor();

		$controls = $this->describe( [ 'type' => 'heading' ] )['controls'];

		$this->assertSame( 'Add Your Heading Text Here', $controls['title']['default'] );
		$this->assertArrayNotHasKey( 'default', $controls['header_size'] );
		$this->assertArrayNotHasKey( 'label', $controls['header_size'] );
	}

	/**
	 * Rendering members are not projected.
	 *
	 * Selectors and conditions describe how Elementor RENDERS a control, not
	 * what a client may write, and they carry CSS this response has no business
	 * shipping.
	 */
	public function test_rendering_members_are_not_projected(): void {
		$this->withElementor();

		$title = $this->describe( [ 'type' => 'heading' ] )['controls']['title'];

		$this->assertArrayNotHasKey( 'selectors', $title );
		$this->assertArrayNotHasKey( 'condition', $title );
		$this->assertSame(
			[ 'name', 'type', 'tab', 'label', 'default', 'section', 'description' ],
			array_keys( $title )
		);
	}

	/**
	 * A type declaring no controls is a normal answer, not a refusal.
	 */
	public function test_a_type_declaring_no_controls_answers_an_empty_map(): void {
		$this->withElementor();

		$result = $this->describe( [ 'type' => 'spacer' ] );

		$this->assertSame( [], $result['controls'] );
		$this->assertSame( 0, $result['controlCount'] );
	}

	// ------------------------------------------------------------------ kinds

	public function test_the_kind_defaults_to_widget(): void {
		$this->withElementor();

		$this->assertSame( 'widget', $this->describe( [ 'type' => 'heading' ] )['kind'] );
	}

	public function test_a_container_is_read_from_the_element_registry(): void {
		$this->withElementor();

		$result = $this->describe(
			[
				'type' => 'container',
				'kind' => 'container',
			]
		);

		$this->assertSame( 'container', $result['kind'] );
		$this->assertSame( [ 'flex_direction' ], array_keys( $result['controls'] ) );
	}

	/**
	 * The two registries are separate.
	 *
	 * `heading` is a widget and is not an element type. A handler resolving both
	 * kinds through one manager would answer this happily and pass every other
	 * test in the file.
	 */
	public function test_a_widget_name_is_not_a_container_name(): void {
		$this->withElementor();

		$refusal = $this->refusal(
			[
				'type' => 'heading',
				'kind' => 'container',
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	public function test_a_container_name_is_not_a_widget_name(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ 'type' => 'container' ] );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	// --------------------------------------------------------------- refusals

	public function test_an_unknown_type_is_a_target_refusal(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ 'type' => 'no-such-widget' ] );

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
	}

	/**
	 * The refusal does not list the registry.
	 *
	 * A refusal naming every installed widget would turn a typo into a plugin
	 * inventory for any caller who can reach the endpoint.
	 */
	public function test_an_unknown_type_refusal_does_not_list_the_registry(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ 'type' => 'no-such-widget' ] );
		$text    = $refusal->getMessage() . ' ' . (string) $refusal->remediation;

		$this->assertStringNotContainsString( 'heading', $text );
		$this->assertStringNotContainsString( 'spacer', $text );
		$this->assertStringNotContainsString( 'no-such-widget', $text );
	}

	/**
	 * A registry that cannot be read is retryable, not a missing type.
	 *
	 * THE CENTRAL REFUSAL DISTINCTION. Told `TargetNotFound`, an operator whose
	 * widget manager was momentarily unbuilt goes looking for a plugin that was
	 * installed the whole time.
	 */
	public function test_an_unreadable_registry_is_an_execution_refusal(): void {
		$this->withElementor( 'not an array' );

		$refusal = $this->refusal( [ 'type' => 'heading' ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_an_unreadable_element_registry_is_an_execution_refusal(): void {
		$this->withElementor( null, 'not an array' );

		$refusal = $this->refusal(
			[
				'type' => 'container',
				'kind' => 'container',
			]
		);

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	/**
	 * A registered type whose controls cannot be read is retryable.
	 *
	 * `broken` IS in the registry, so this reaches the schema read rather than
	 * the existence check — which is what makes it a different path from the
	 * test above.
	 */
	public function test_a_registered_type_with_an_unreadable_schema_is_an_execution_refusal(): void {
		$this->withElementor();

		$refusal = $this->refusal( [ 'type' => 'broken' ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
	}

	public function test_a_caller_who_may_not_edit_posts_is_refused(): void {
		$this->withElementor();
		$this->mayEditPosts = false;

		$refusal = $this->refusal( [ 'type' => 'heading' ] );

		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
	}

	/**
	 * The capability is checked before Elementor's presence.
	 *
	 * Elementor is deliberately NOT installed here, so a presence check placed
	 * above the capability check would answer IntegrationUnavailable.
	 */
	public function test_the_capability_is_checked_before_elementor_presence(): void {
		$this->mayEditPosts = false;

		$this->assertSame( ErrorCode::Forbidden, $this->refusal( [ 'type' => 'heading' ] )->errorCode );
	}

	public function test_a_site_without_elementor_cannot_be_asked(): void {
		$refusal = $this->refusal( [ 'type' => 'heading' ] );

		$this->assertSame( ErrorCode::IntegrationUnavailable, $refusal->errorCode );
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_a_read_needing_the_general_capability(): void {
		$definition = ElementorControlSchema::definition();

		$this->assertSame( 'elementor-control-schema', $definition->id );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
	}

	public function test_only_the_type_is_required_and_the_kind_is_bounded(): void {
		$schema = ElementorControlSchema::definition()->inputSchema;

		$this->assertSame( [ 'type' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'widget', 'container' ], $schema['properties']['kind']['enum'] );
		$this->assertSame( 100, $schema['properties']['type']['maxLength'] );
	}

	/**
	 * The output schema states the two things a client cannot infer.
	 */
	public function test_the_output_schema_states_the_absent_key_and_empty_map_rules(): void {
		$schema = ElementorControlSchema::definition()->outputSchema;

		$this->assertStringContainsString( 'ABSENT rather than null', $schema['properties']['controls']['description'] );
		$this->assertStringContainsString( 'ZERO IS A VALID ANSWER', $schema['properties']['controlCount']['description'] );
		$this->assertSame(
			[ 'type', 'kind', 'elementorVersion', 'controlCount', 'controls' ],
			$schema['required']
		);
	}
}

/**
 * The Elementor singleton, carrying two separate managers.
 *
 * THE SINGLETON IS THE STATIC `$instance` PROPERTY, not an `instance()` method,
 * because that is what both `ElementorPresence::manager()` and
 * `ElementorApi::plugin_member()` actually read. A double offering a method
 * instead would be faithful to Elementor's public surface and unfaithful to the
 * one line under test, which is the failure mode this codebase has hit before.
 */
final class ControlSchemaPlugin {

	/**
	 * The singleton both accessors read.
	 *
	 * @var mixed
	 */
	public static mixed $instance = null;

	/**
	 * The widget manager, as a public member.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;

	/**
	 * The element manager, as a public member.
	 *
	 * @var mixed
	 */
	public mixed $elements_manager = null;
}

/**
 * Elementor's widget manager, in both of the forms this codebase calls.
 */
final class ControlSchemaWidgets {

	/**
	 * Builds the manager over one registry.
	 *
	 * @param mixed $types The registry.
	 */
	public function __construct( private mixed $types ) {}

	/**
	 * The whole registry with no argument, one widget with a name.
	 *
	 * @param string|null $name The widget name.
	 *
	 * @return mixed The registry, one widget, or null.
	 */
	public function get_widget_types( ?string $name = null ): mixed {
		if ( null === $name ) {
			return $this->types;
		}

		return is_array( $this->types ) ? ( $this->types[ $name ] ?? null ) : null;
	}
}

/**
 * Elementor's element manager, over its own separate registry.
 *
 * A separate class from ControlSchemaWidgets rather than a shared one, because
 * the production code reads two separate managers and a shared double would let
 * a change that collapsed them keep passing.
 */
final class ControlSchemaElements {

	/**
	 * Builds the manager over one registry.
	 *
	 * @param mixed $types The registry.
	 */
	public function __construct( private mixed $types ) {}

	/**
	 * The whole registry with no argument, one element type with a name.
	 *
	 * @param string|null $name The element name.
	 *
	 * @return mixed The registry, one element type, or null.
	 */
	public function get_element_types( ?string $name = null ): mixed {
		if ( null === $name ) {
			return $this->types;
		}

		return is_array( $this->types ) ? ( $this->types[ $name ] ?? null ) : null;
	}
}

/**
 * A Controls_Stack answering a fixed control array.
 */
final class ControlSchemaStack {

	/**
	 * Builds the stack over one control array.
	 *
	 * @param mixed $controls The controls.
	 */
	public function __construct( private mixed $controls ) {}

	/**
	 * The stored controls.
	 *
	 * @return mixed The controls.
	 */
	public function get_controls(): mixed {
		return $this->controls;
	}
}
