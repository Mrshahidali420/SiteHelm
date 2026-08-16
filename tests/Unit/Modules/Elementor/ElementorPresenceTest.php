<?php
/**
 * Tests for ElementorPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * The one gate that is allowed to name an `\Elementor\` symbol.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is `widgetTypes()` and
 * `elementTypes()` answering NULL rather than `[]` when their registry cannot
 * be reached. REQ-0034 refuses on null; on an empty array it would report every
 * proposed widget type as missing from the installed Elementor. "This widget
 * does not exist" and "I could not check whether this widget exists" are
 * different answers, and returning the first when the second is true is a lie
 * an operator acts on.
 *
 * THE SECOND DISTINCTION is that the two accessors read two SEPARATE managers.
 * A site whose widget manager has been replaced by a third party still has an
 * intact element manager, so one failing must not make the other answer null;
 * there is a test below that installs exactly that site.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): FakeElementorPlugin,
 * FakeWidgetsManager and FakeElementsManager below reproduce exactly four
 * upstream behaviours, because they are the four this gate reads:
 *
 *   1. `\Elementor\Plugin` has a public static `$instance` holding the plugin
 *      singleton, which is null before Elementor has booted.
 *   2. That singleton exposes public `widgets_manager` and `elements_manager`
 *      properties.
 *   3. `Widgets_Manager::get_widget_types()` called with no argument returns an
 *      associative array KEYED BY WIDGET TYPE NAME whose values are
 *      `Widget_Base` instances.
 *   4. `Elements_Manager::get_element_types()` called with no argument returns
 *      an associative array KEYED BY ELEMENT NAME whose values are
 *      `Element_Base` instances.
 *
 * They deliberately reproduce NOTHING else. In particular they do not model
 * either manager's single-type form — `get_widget_types( $name )` and
 * `get_element_types( $name )`, which this gate never calls and
 * ElementorApiControlSchemaTest doubles separately — registration order,
 * `Widget_Base` or `Element_Base` at all (the values here are placeholders,
 * because this gate reads only the keys), or Elementor's own autoloader. Any
 * future assertion that depends on one of those must not be written against
 * these doubles.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, not decoration. `ELEMENTOR_VERSION` is a
 * constant and `\Elementor\Plugin` is a class alias; both are permanent for the
 * life of a PHP process. Defining either in the shared process would make every
 * later test in the suite — including the absent-Elementor cases in this very
 * file — run against a site that has Elementor installed, and the absent cases
 * would then pass or fail for reasons unrelated to what they assert.
 */
final class ElementorPresenceTest extends TestCase {

	private ElementorPresence $presence;

	protected function setUp(): void {
		parent::setUp();
		$this->presence = new ElementorPresence();
	}

	/**
	 * Installs a fake `\Elementor\Plugin` and `ELEMENTOR_VERSION`.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; see the class
	 * docblock for why.
	 *
	 * @param object|null $instance The value `\Elementor\Plugin::$instance` holds.
	 * @param string      $version  The value `ELEMENTOR_VERSION` holds.
	 */
	private function installElementor( ?object $instance, string $version = '3.25.0' ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( FakeElementorPlugin::class, 'Elementor\Plugin' );
		}

		FakeElementorPlugin::$instance = $instance;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', $version );
		}
	}

	/**
	 * A plugin singleton whose widget registry answers the supplied names.
	 *
	 * @param array<string, mixed>|mixed $types What get_widget_types() returns.
	 *
	 * @return FakeElementorPlugin The singleton.
	 */
	private function pluginWithRegistry( mixed $types ): FakeElementorPlugin {
		$plugin                  = new FakeElementorPlugin();
		$plugin->widgets_manager = new FakeWidgetsManager( $types );

		return $plugin;
	}

	/**
	 * A plugin singleton whose ELEMENT registry answers the given value.
	 *
	 * Deliberately leaves `widgets_manager` null, so that every assertion made
	 * through this helper is made on a site whose widget manager is unreachable.
	 * A double that wired both managers would let elementTypes() pass while
	 * silently reading the wrong one.
	 *
	 * @param mixed $types What get_element_types() answers.
	 *
	 * @return FakeElementorPlugin The singleton.
	 */
	private function pluginWithElementRegistry( mixed $types ): FakeElementorPlugin {
		$plugin                   = new FakeElementorPlugin();
		$plugin->elements_manager = new FakeElementsManager( $types );

		return $plugin;
	}

	/**
	 * Installs a fake Elementor at a chosen version. Permanent in the process,
	 * so every caller runs in its own.
	 *
	 * The alias target is FakeElementorPlugin rather than stdClass because
	 * class_alias() refuses an internal class outright, and because the gate
	 * this helper feeds sits one method away from widgetTypes(), which reads
	 * `\Elementor\Plugin::$instance`. A double that has no such property would
	 * be faithful for isSupported() and a fatal for anything nearby.
	 *
	 * @param mixed $version The value ELEMENTOR_VERSION should hold.
	 */
	private function installElementorVersion( mixed $version ): void {
		if ( ! class_exists( ElementorPresence::PLUGIN_CLASS, false ) ) {
			class_alias( FakeElementorPlugin::class, ElementorPresence::PLUGIN_CLASS );
		}
		if ( ! defined( ElementorPresence::VERSION_CONSTANT ) ) {
			define( ElementorPresence::VERSION_CONSTANT, $version );
		}
	}

	public function test_a_site_without_elementor_reports_not_loaded_and_no_version(): void {
		// No constant is defined and no alias is installed in this process, which
		// is the ordinary state of most WordPress sites and therefore the state
		// the module has to survive without fataling.
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertNull( $this->presence->version() );
	}

	public function test_a_site_without_elementor_cannot_check_widget_types_and_says_so(): void {
		$types = $this->presence->widgetTypes();

		// BOTH assertions, not just the first. assertNull alone would still pass
		// if the method were changed to return [] and PHPUnit's null/empty
		// coercion were relied on anywhere downstream; assertNotSame pins the
		// distinction in the words the requirement uses.
		$this->assertNull( $types, 'An unreachable widget registry must answer null, never a list.' );
		$this->assertNotSame( [], $types, '[] would claim the registry was read and found empty.' );
	}

	public function test_a_site_without_elementor_cannot_check_element_types_and_says_so(): void {
		$types = $this->presence->elementTypes();

		$this->assertNull( $types, 'An unreachable element registry must answer null, never a list.' );
		$this->assertNotSame( [], $types, '[] would claim the registry was read and found empty.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_elementor_reports_loaded_and_the_installed_version(): void {
		$this->installElementor( $this->pluginWithRegistry( [] ), '3.25.0' );

		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertSame( '3.25.0', $this->presence->version() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_registered_widget_type_names_are_the_registry_keys(): void {
		$this->installElementor(
			$this->pluginWithRegistry(
				[
					'heading'      => new FakeWidget(),
					'image'        => new FakeWidget(),
					'text-editor'  => new FakeWidget(),
					'nested-tabs'  => new FakeWidget(),
				]
			)
		);

		$this->assertSame(
			[ 'heading', 'image', 'text-editor', 'nested-tabs' ],
			$this->presence->widgetTypes()
		);
	}

	/**
	 * The mirror of the null case, and the reason the null case has to exist.
	 *
	 * A registry that really was read and really is empty answers [] — so [] and
	 * null are two distinct, reachable answers rather than one value with two
	 * spellings.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registry_that_is_genuinely_empty_answers_an_empty_list_not_null(): void {
		$this->installElementor( $this->pluginWithRegistry( [] ) );

		$this->assertSame( [], $this->presence->widgetTypes() );
	}

	/**
	 * Elementor loaded, but its singleton has not been built yet.
	 *
	 * Reachable in practice: `\Elementor\Plugin::$instance` is null between the
	 * constant being defined by the plugin header and `Plugin::instance()`
	 * running on `plugins_loaded`. A request that lands in that window must be
	 * told the registry could not be checked, not that no widgets exist.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_null_plugin_singleton_cannot_be_checked(): void {
		$this->installElementor( null );

		$this->assertNull( $this->presence->widgetTypes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_singleton_without_a_widgets_manager_cannot_be_checked(): void {
		$plugin                  = new FakeElementorPlugin();
		$plugin->widgets_manager = null;

		$this->installElementor( $plugin );

		$this->assertNull( $this->presence->widgetTypes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widgets_manager_that_does_not_answer_the_registry_call_cannot_be_checked(): void {
		$plugin                  = new FakeElementorPlugin();
		$plugin->widgets_manager = new FakeStrangerManager();

		$this->installElementor( $plugin );

		$this->assertNull( $this->presence->widgetTypes() );
	}

	/**
	 * The registry answering something that is not an array at all.
	 *
	 * `elementor/widgets/widgets_registered` is a public action and
	 * `get_widget_types()` is overridable by any plugin that swaps the manager,
	 * so a non-array answer is a third-party outcome rather than a theoretical
	 * one. Casting it would invent widget types; the honest answer is null.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registry_answering_a_non_array_cannot_be_checked(): void {
		$this->installElementor( $this->pluginWithRegistry( 'heading' ) );

		$this->assertNull( $this->presence->widgetTypes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_registered_element_type_names_are_the_element_registry_keys(): void {
		$this->installElementor(
			$this->pluginWithElementRegistry(
				[
					'container' => new FakeWidget(),
					'section'   => new FakeWidget(),
					'column'    => new FakeWidget(),
				]
			)
		);

		$this->assertSame(
			[ 'container', 'section', 'column' ],
			$this->presence->elementTypes()
		);
	}

	/**
	 * The claim the class docblock makes, installed as a site.
	 *
	 * The widget manager here is missing entirely and the element manager is
	 * intact. Were the two accessors sharing one lookup, or were elementTypes()
	 * reading `widgets_manager`, this would answer null — which would tell an
	 * operator their site registers no containers when in fact every container
	 * type is registered and only an unrelated manager is broken.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_broken_widget_manager_does_not_make_the_element_registry_unreadable(): void {
		$this->installElementor( $this->pluginWithElementRegistry( [ 'container' => new FakeWidget() ] ) );

		$this->assertNull( $this->presence->widgetTypes(), 'The double leaves the widget manager unreachable.' );
		$this->assertSame( [ 'container' ], $this->presence->elementTypes() );
	}

	/**
	 * The mirror of the element null case, and the reason it has to exist.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_element_registry_that_is_genuinely_empty_answers_an_empty_list_not_null(): void {
		$this->installElementor( $this->pluginWithElementRegistry( [] ) );

		$this->assertSame( [], $this->presence->elementTypes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_singleton_without_an_elements_manager_cannot_be_checked(): void {
		$plugin                   = new FakeElementorPlugin();
		$plugin->elements_manager = null;

		$this->installElementor( $plugin );

		$this->assertNull( $this->presence->elementTypes() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elements_manager_that_does_not_answer_the_registry_call_cannot_be_checked(): void {
		$plugin                   = new FakeElementorPlugin();
		$plugin->elements_manager = new FakeStrangerManager();

		$this->installElementor( $plugin );

		$this->assertNull( $this->presence->elementTypes() );
	}

	/**
	 * `elementor/elements/elements_registered` is a public action and the
	 * manager is swappable, so a non-array answer is a third-party outcome
	 * rather than a theoretical one. Casting it would invent element types.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_element_registry_that_is_not_an_array_cannot_be_checked(): void {
		$this->installElementor( $this->pluginWithElementRegistry( 'container' ) );

		$this->assertNull( $this->presence->elementTypes() );
	}

	/**
	 * Absent is not the same answer as too old, and neither is supported.
	 */
	public function test_an_absent_elementor_is_not_supported(): void {
		$this->assertFalse( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_below_the_floor_is_not_supported(): void {
		$this->installElementorVersion( '2.9.14' );

		$this->assertFalse( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_at_or_above_the_floor_is_supported(): void {
		$this->installElementorVersion( ElementorPresence::MIN_VERSION );

		$this->assertTrue( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * AN UNREADABLE VERSION IS NOT TREATED AS AN OLD ONE. A constant another
	 * plugin mangled is a claim this gate cannot substantiate in either
	 * direction, and refusing on it would block a working site over someone
	 * else's bug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_version_is_still_supported(): void {
		$this->installElementorVersion( [ '2.9.14' ] );

		$this->assertTrue( ( new ElementorPresence() )->isSupported() );
	}

	public function test_the_minimum_supported_version_is_frozen_at_three(): void {
		// Pinned because every Elementor definition's supportedVersions range is
		// built from this constant, and the golden definition fixture in Task 2
		// would absorb a change to it silently.
		$this->assertSame( '3.0.0', ElementorPresence::MIN_VERSION );
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours this reproduces and which it does not.
 *
 * phpcs:disable
 */
final class FakeElementorPlugin {

	/**
	 * The plugin singleton, null before Elementor has booted.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The widget registry, or whatever a third party has substituted for it.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;

	/**
	 * The element registry, or whatever a third party has substituted for it.
	 *
	 * @var mixed
	 */
	public mixed $elements_manager = null;
}

/**
 * Stands in for `\Elementor\Core\Common\Modules\...\Widgets_Manager`.
 */
final class FakeWidgetsManager {

	/**
	 * Constructs the double.
	 *
	 * @param mixed $types What get_widget_types() answers.
	 */
	public function __construct( private mixed $types ) {
	}

	/**
	 * The registered widget types, keyed by type name.
	 *
	 * @return mixed The registry.
	 */
	public function get_widget_types(): mixed {
		return $this->types;
	}
}

/**
 * Stands in for `\Elementor\Elements_Manager`, in its whole-registry form.
 *
 * A separate class from FakeWidgetsManager rather than a shared one, because
 * the production code reads two separate managers and a shared double would let
 * a change that collapsed them keep passing.
 */
final class FakeElementsManager {

	/**
	 * Constructs the double.
	 *
	 * @param mixed $types What get_element_types() answers.
	 */
	public function __construct( private mixed $types ) {
	}

	/**
	 * The registered element types, keyed by element name.
	 *
	 * @return mixed The registry.
	 */
	public function get_element_types(): mixed {
		return $this->types;
	}
}

/**
 * A manager replacement that implements neither registry call, standing in for
 * a third party's substitute for either manager.
 */
final class FakeStrangerManager {
}

/**
 * A placeholder for `Widget_Base`. Only the registry KEY is read, so the value
 * deliberately models nothing.
 */
final class FakeWidget {
}
