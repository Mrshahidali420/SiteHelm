<?php
/**
 * Tests for ElementorApi::controlSchema().
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0067's registry read, split out of ElementorApiTest for the 800-line
 * ceiling rather than for any difference in subject.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is the one `controlSchema()`'s
 * docblock states: `null` means NOTHING WAS READ and `[]` means the type was
 * read and declares no controls. The operation refuses the first with the
 * retryable `ExecutionFailed` and answers the second normally, so a collapse of
 * either into the other tells an operator their widget accepts no settings at
 * all on a site where the registry was never reached.
 *
 * THE SECOND DISTINCTION is that `null` here does NOT mean "no such type". The
 * caller establishes existence first from `ElementorPresence::widgetTypes()` or
 * `elementTypes()`; that is why the unknown-type cases below assert only that
 * nothing was read, and why no test in this file claims a refusal code.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): the doubles below reproduce
 * exactly the four upstream behaviours this accessor reads:
 *
 *   1. `\Elementor\Plugin` has a public static `$instance` holding the plugin
 *      singleton, exposing public `widgets_manager` and `elements_manager`
 *      properties.
 *   2. `Widgets_Manager::get_widget_types( $name )` answers one `Widget_Base`,
 *      or null for a name it does not register.
 *   3. `Elements_Manager::get_element_types( $name )` answers one
 *      `Element_Base`, or null for a name it does not register.
 *   4. `Controls_Stack::get_controls()`, which both bases inherit, answers an
 *      array keyed by control id whose values are control-definition arrays
 *      carrying at least `name`, `type` and `tab` — the three
 *      `Controls_Manager::add_control()` merges in before storing.
 *
 * They deliberately reproduce NOTHING else: not `get_controls( $id )`'s
 * single-control form, not Elementor's control-registration order, not
 * `Controls_Stack`'s section stack, and not the frontend control optimisation
 * that splits style controls off — which is disabled whenever `REST_REQUEST` is
 * defined, and every SiteHelm operation arrives over the REST route.
 *
 * THE SHARED TEST PROCESS DEFINES NO `\Elementor\` SYMBOL, and the two absence
 * cases below run against that real absence. Every other case installs a class
 * alias and a constant, both permanent for the life of a PHP process, so each
 * runs `@runInSeparateProcess` — installing either in the shared process would
 * make every later test in the suite run against a site that has Elementor.
 */
final class ElementorApiControlSchemaTest extends TestCase {

	private ElementorApi $api;

	protected function setUp(): void {
		parent::setUp();
		$this->api = new ElementorApi( new ElementorPresence() );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` and `ELEMENTOR_VERSION`.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 *
	 * @param object|null $instance The value `\Elementor\Plugin::$instance` holds.
	 */
	private function installElementor( ?object $instance ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( SchemaFakePlugin::class, 'Elementor\Plugin' );
		}

		SchemaFakePlugin::$instance = $instance;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * A singleton whose WIDGET registry holds the given types.
	 *
	 * The element manager is left unreachable on purpose, so a widget assertion
	 * made through this helper cannot pass by reading the wrong manager.
	 *
	 * @param array<string, object> $widgets The widget registry.
	 *
	 * @return SchemaFakePlugin The singleton.
	 */
	private function pluginWithWidgets( array $widgets ): SchemaFakePlugin {
		$plugin                  = new SchemaFakePlugin();
		$plugin->widgets_manager = new SchemaFakeWidgets( $widgets );

		return $plugin;
	}

	/**
	 * A singleton whose ELEMENT registry holds the given types.
	 *
	 * The widget manager is left unreachable on purpose; see pluginWithWidgets().
	 *
	 * @param array<string, object> $elements The element registry.
	 *
	 * @return SchemaFakePlugin The singleton.
	 */
	private function pluginWithElements( array $elements ): SchemaFakePlugin {
		$plugin                   = new SchemaFakePlugin();
		$plugin->elements_manager = new SchemaFakeElements( $elements );

		return $plugin;
	}

	public function test_a_site_without_elementor_cannot_read_a_widget_control_schema_and_says_so(): void {
		$answer = $this->api->controlSchema( 'heading', true );

		// BOTH assertions. assertNull alone would still pass were the method
		// changed to answer [], and [] is the answer that means "this widget was
		// read and accepts no settings" — which a client acts on by writing none.
		$this->assertNull( $answer, 'An unreachable widget registry must answer null.' );
		$this->assertNotSame( [], $answer, '[] would claim the widget was read and declares no controls.' );
	}

	public function test_a_site_without_elementor_cannot_read_a_container_control_schema_and_says_so(): void {
		$answer = $this->api->controlSchema( 'container', false );

		$this->assertNull( $answer, 'An unreachable element registry must answer null.' );
		$this->assertNotSame( [], $answer, '[] would claim the element was read and declares no controls.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_three_guaranteed_keys_are_projected_for_every_control(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'type' => 'text',
								'tab'  => 'content',
							],
							'align' => [
								'name' => 'align',
								'type' => 'choose',
								'tab'  => 'style',
							],
						]
					),
				]
			)
		);

		$this->assertSame(
			[
				'title' => [
					'name' => 'title',
					'type' => 'text',
					'tab'  => 'content',
				],
				'align' => [
					'name' => 'align',
					'type' => 'choose',
					'tab'  => 'style',
				],
			],
			$this->api->controlSchema( 'heading', true )
		);
	}

	/**
	 * The name is taken from the REGISTRY KEY, not from the stored `name`.
	 *
	 * Upstream stamps the two identically, so a projection that read the stored
	 * member would look correct on every real site — and would carry a third
	 * party's mismatched value straight into the response the client addresses
	 * its writes by. The key is the address; it is what the projection uses.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_control_name_is_the_registry_key(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'something-else',
								'type' => 'text',
								'tab'  => 'content',
							],
						]
					),
				]
			)
		);

		$schema = $this->api->controlSchema( 'heading', true );

		$this->assertSame( 'title', $schema['title']['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_optional_key_is_projected_when_the_control_declares_it(): void {
		$control = [
			'name'        => 'title',
			'type'        => 'text',
			'tab'         => 'content',
			'label'       => 'Title',
			'default'     => 'Add Your Heading Text Here',
			'options'     => [ 'left' => 'Left' ],
			'section'     => 'section_title',
			'description' => 'The text this heading renders.',
		];

		$this->installElementor( $this->pluginWithWidgets( [ 'heading' => new SchemaFakeStack( [ 'title' => $control ] ) ] ) );

		$this->assertSame( $control, $this->api->controlSchema( 'heading', true )['title'] );
	}

	/**
	 * The mirror, and the reason the optional list is a list rather than a
	 * projection of everything.
	 *
	 * A control declaring none of the five carries none of the five, so a client
	 * can tell "this control declares no default" from "this control defaults to
	 * an empty string" — two answers a projection that stamped `null` in would
	 * have merged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_optional_key_the_control_does_not_declare_is_absent_rather_than_null(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'type' => 'text',
								'tab'  => 'content',
							],
						]
					),
				]
			)
		);

		$descriptor = $this->api->controlSchema( 'heading', true )['title'];

		foreach ( ElementorApi::OPTIONAL_CONTROL_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $descriptor, "An undeclared optional key must not be invented: {$key}." );
		}
	}

	/**
	 * A control's declared `default` may legitimately be null.
	 *
	 * Which is why the projection tests `array_key_exists()` rather than `isset()`
	 * or `??` — the standing restore-trap rule, in a read.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_declared_null_default_is_projected_rather_than_dropped(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name'    => 'title',
								'type'    => 'text',
								'tab'     => 'content',
								'default' => null,
							],
						]
					),
				]
			)
		);

		$descriptor = $this->api->controlSchema( 'heading', true )['title'];

		$this->assertArrayHasKey( 'default', $descriptor );
		$this->assertNull( $descriptor['default'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rendering_members_are_not_projected(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name'       => 'title',
								'type'       => 'text',
								'tab'        => 'content',
								'selectors'  => [ '{{WRAPPER}} .elementor-heading-title' => 'color: {{VALUE}};' ],
								'condition'  => [ 'title_link[url]!' => '' ],
								'dynamic'    => [ 'active' => true ],
								'responsive' => true,
							],
						]
					),
				]
			)
		);

		$descriptor = $this->api->controlSchema( 'heading', true )['title'];

		$this->assertSame( [ 'name', 'type', 'tab' ], array_keys( $descriptor ) );
	}

	/**
	 * A container resolves through the ELEMENT manager.
	 *
	 * The double leaves the widget manager unreachable, so a change that routed
	 * both kinds through one manager would answer null here.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_container_is_read_from_the_element_registry(): void {
		$this->installElementor(
			$this->pluginWithElements(
				[
					'container' => new SchemaFakeStack(
						[
							'flex_direction' => [
								'name' => 'flex_direction',
								'type' => 'choose',
								'tab'  => 'layout',
							],
						]
					),
				]
			)
		);

		$this->assertNull( $this->api->controlSchema( 'container', true ), 'A container is not in the widget registry.' );
		$this->assertSame(
			[
				'flex_direction' => [
					'name' => 'flex_direction',
					'type' => 'choose',
					'tab'  => 'layout',
				],
			],
			$this->api->controlSchema( 'container', false )
		);
	}

	/**
	 * The mirror of the container case: a widget is not in the element registry.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_is_read_from_the_widget_registry(): void {
		$this->installElementor( $this->pluginWithWidgets( [ 'heading' => new SchemaFakeStack( [] ) ] ) );

		$this->assertNull( $this->api->controlSchema( 'heading', false ), 'A widget is not in the element registry.' );
		$this->assertSame( [], $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * The `[]` half of the pair this file exists to pin.
	 *
	 * A type that really was read and really declares no controls answers `[]`,
	 * so `[]` and null are two distinct, reachable answers rather than one value
	 * with two spellings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_type_that_declares_no_controls_answers_an_empty_map_not_null(): void {
		$this->installElementor( $this->pluginWithWidgets( [ 'spacer' => new SchemaFakeStack( [] ) ] ) );

		$this->assertSame( [], $this->api->controlSchema( 'spacer', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_type_the_registry_does_not_register_cannot_be_read(): void {
		$this->installElementor( $this->pluginWithWidgets( [] ) );

		$this->assertNull( $this->api->controlSchema( 'not-a-widget', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_element_type_the_registry_does_not_register_cannot_be_read(): void {
		$this->installElementor( $this->pluginWithElements( [] ) );

		$this->assertNull( $this->api->controlSchema( 'not-an-element', false ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_singleton_without_a_widget_manager_cannot_be_read(): void {
		$this->installElementor( new SchemaFakePlugin() );

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registered_type_that_is_not_a_controls_stack_cannot_be_read(): void {
		$this->installElementor( $this->pluginWithWidgets( [ 'heading' => new SchemaFakeStranger() ] ) );

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_control_stack_that_does_not_answer_an_array_cannot_be_read(): void {
		$this->installElementor( $this->pluginWithWidgets( [ 'heading' => new SchemaFakeStack( 'title' ) ] ) );

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * ONE unreadable control takes the whole schema with it.
	 *
	 * The readable control here would make a short map perfectly plausible, and a
	 * short map is worse than none: a client trusts it and omits the key it never
	 * saw, so a legitimate write comes back refused naming a setting the widget
	 * really accepts. Both assertions, because a short map is not null and
	 * assertNull alone would not distinguish them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_control_that_is_not_an_array_makes_the_whole_schema_unreadable(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'type' => 'text',
								'tab'  => 'content',
							],
							'align' => 'choose',
						]
					),
				]
			)
		);

		$schema = $this->api->controlSchema( 'heading', true );

		$this->assertNull( $schema, 'An unreadable control must take the whole schema with it.' );
		$this->assertArrayNotHasKey( 'title', (array) $schema, 'A shortened schema is the outcome this guard exists to prevent.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_control_declaring_no_type_makes_the_schema_unreadable(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'tab'  => 'content',
							],
						]
					),
				]
			)
		);

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_control_declaring_no_tab_makes_the_schema_unreadable(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'type' => 'text',
							],
						]
					),
				]
			)
		);

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * A control whose guaranteed key holds an array rather than a scalar.
	 *
	 * `(string)` on an array is a fatal, so this is the guard that keeps a
	 * third party's malformed control from taking the request down.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_control_whose_type_is_not_scalar_makes_the_schema_unreadable(): void {
		$this->installElementor(
			$this->pluginWithWidgets(
				[
					'heading' => new SchemaFakeStack(
						[
							'title' => [
								'name' => 'title',
								'type' => [ 'text' ],
								'tab'  => 'content',
							],
						]
					),
				]
			)
		);

		$this->assertNull( $this->api->controlSchema( 'heading', true ) );
	}

	/**
	 * The projected optional set is a published contract.
	 *
	 * REQ-0067's output schema is generated from it and pinned as a fixture, so a
	 * change here changes what every client is told a control declares.
	 */
	public function test_the_projected_optional_control_keys_are_pinned(): void {
		$this->assertSame(
			[ 'label', 'default', 'options', 'section', 'description' ],
			ElementorApi::OPTIONAL_CONTROL_KEYS
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours this reproduces and which it does not.
 *
 * phpcs:disable
 */
final class SchemaFakePlugin {

	/**
	 * The plugin singleton, null before Elementor has booted.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The widget registry, or whatever a third party has substituted.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;

	/**
	 * The element registry, or whatever a third party has substituted.
	 *
	 * @var mixed
	 */
	public mixed $elements_manager = null;
}

/**
 * Stands in for `Widgets_Manager`, in its single-widget form.
 */
final class SchemaFakeWidgets {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $widgets The registry.
	 */
	public function __construct( private array $widgets ) {
	}

	/**
	 * One registered widget, or null for a type this site does not register.
	 *
	 * @param string $name The widget type name.
	 *
	 * @return object|null The widget.
	 */
	public function get_widget_types( string $name ): ?object {
		return $this->widgets[ $name ] ?? null;
	}
}

/**
 * Stands in for `Elements_Manager`, in its single-element form.
 *
 * A separate class from SchemaFakeWidgets rather than a shared one, because the
 * production code reads two separate managers and a shared double would let a
 * change that collapsed them keep passing.
 */
final class SchemaFakeElements {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $elements The registry.
	 */
	public function __construct( private array $elements ) {
	}

	/**
	 * One registered element, or null for a type this site does not register.
	 *
	 * @param string|null $name The element type name.
	 *
	 * @return object|null The element.
	 */
	public function get_element_types( ?string $name = null ): ?object {
		return null === $name ? null : ( $this->elements[ $name ] ?? null );
	}
}

/**
 * Stands in for `Controls_Stack`, which both `Widget_Base` and `Element_Base`
 * inherit — which is why one double serves widgets and containers alike here,
 * exactly as one accessor serves both in production.
 */
final class SchemaFakeStack {

	/**
	 * Constructs the double.
	 *
	 * @param mixed $controls What get_controls() answers.
	 */
	public function __construct( private mixed $controls ) {
	}

	/**
	 * The declared controls, keyed by control id.
	 *
	 * @return mixed The controls.
	 */
	public function get_controls(): mixed {
		return $this->controls;
	}
}

/**
 * A registry value that is not a controls stack at all, standing in for a third
 * party's substitute registration.
 */
final class SchemaFakeStranger {
}
