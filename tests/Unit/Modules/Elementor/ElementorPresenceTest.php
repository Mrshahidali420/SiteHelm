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
 * THE DISTINCTION THIS FILE EXISTS TO PIN is `widgetTypes()` answering NULL
 * rather than `[]` when the widget registry cannot be reached. REQ-0034 refuses
 * on null; on an empty array it would report every proposed widget type as
 * missing from the installed Elementor. "This widget does not exist" and "I
 * could not check whether this widget exists" are different answers, and
 * returning the first when the second is true is a lie an operator acts on.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): FakeElementorPlugin and
 * FakeWidgetsManager below reproduce exactly three upstream behaviours, because
 * they are the three this gate reads:
 *
 *   1. `\Elementor\Plugin` has a public static `$instance` holding the plugin
 *      singleton, which is null before Elementor has booted.
 *   2. That singleton exposes a public `widgets_manager` property.
 *   3. `Widgets_Manager::get_widget_types()` called with no argument returns an
 *      associative array KEYED BY WIDGET TYPE NAME whose values are
 *      `Widget_Base` instances.
 *
 * They deliberately reproduce NOTHING else. In particular they do not model
 * `get_widget_types( $name )`'s single-widget form, widget registration order,
 * `Widget_Base` at all (the values here are placeholders, because this gate
 * reads only the keys), or Elementor's own autoloader. Any future assertion
 * that depends on one of those must not be written against these doubles.
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
 * A widgets_manager replacement that does not implement the registry call.
 */
final class FakeStrangerManager {
}

/**
 * A placeholder for `Widget_Base`. Only the registry KEY is read, so the value
 * deliberately models nothing.
 */
final class FakeWidget {
}
