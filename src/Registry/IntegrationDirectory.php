<?php
/**
 * The canonical list of integration modules the plugin boots.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Modules\Metabox\MetaboxModule;
use Throwable;

/**
 * The boot table and the modules built from it.
 *
 * The table used to live on the bootstrap's Plugin class, which meant that a
 * module wanting to know what the plugin boots had to reach up into
 * `SiteHelm\Bootstrap` and invert the layering. It lives here instead, beside
 * the capability registry, because "which integrations exist" is registry
 * knowledge that both the bootstrap and a reporting module may read.
 *
 * @package SiteHelm
 */
final class IntegrationDirectory {

	/**
	 * Every module the plugin boots, in boot order.
	 *
	 * Later phases append module class names here. Class names rather than
	 * instances so that each construction sits inside the isolation boundary:
	 * a throwing constructor must not be able to take down the gateway.
	 *
	 * PUBLIC BECAUSE TWO READERS OUTSIDE THIS CLASS DEPEND ON IT. `Plugin::MODULE_CLASSES`
	 * is an alias of this constant, so the catalog-wide invariant tests — chief
	 * among them the REQ-0063 absence test, which asserts that no non-Elementor
	 * page builder appears in any V1 dispatcher catalog — keep enumerating this
	 * real boot table through the name they were written against. And
	 * `system-integrations` reports one entry per module by walking `describe()`,
	 * which is built from this list. Both readings are of the whole catalog, and a
	 * requirement about the whole catalog has to read it from the single place that
	 * defines it rather than from a hand-written copy that a module added here and
	 * nowhere else would silently leave behind.
	 *
	 * EDIT THIS CONSTANT, NOT THE ALIAS. A module appended to `Plugin` instead
	 * would never boot, because the loader walks this one.
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	public const MODULE_CLASSES = [
		DiagnosticsModule::class,
		CoreModule::class,
		MediaModule::class,
		MenusModule::class,
		ElementorModule::class,
		AcfModule::class,
		MetaboxModule::class,
	];

	/**
	 * The classes this directory answers for.
	 *
	 * Injectable rather than hard-wired to the constant so that the isolation
	 * boundary below can be exercised with a module that actually fails, which
	 * no shipped module does.
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	private readonly array $module_classes;

	/**
	 * Builds a directory over the plugin's table, or over a given one.
	 *
	 * The parameter defaults to null rather than to the constant so that
	 * `new IntegrationDirectory()` — the only form production code uses — reads
	 * as "the plugin's directory" and cannot be handed a partial table by
	 * accident.
	 *
	 * @param class-string<IntegrationModule>[]|null $module_classes Classes, or null for the plugin's own table.
	 */
	public function __construct( ?array $module_classes = null ) {
		$this->module_classes = $module_classes ?? self::MODULE_CLASSES;
	}

	/**
	 * Constructs each module inside its own isolation boundary. A module whose
	 * constructor throws is logged server-side and skipped; every other module,
	 * and the gateway itself, continues to load.
	 *
	 * @return IntegrationModule[] Successfully constructed modules.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	public function modules(): array {
		$modules = [];

		foreach ( $this->module_classes as $module_class ) {
			try {
				$modules[] = new $module_class();
			} catch ( Throwable $e ) {
				error_log(
					sprintf(
						'SiteHelm module %s could not be constructed: %s',
						$module_class,
						$e->getMessage()
					)
				);
			}
		}

		return $modules;
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * What each module says about itself, beyond its health.
	 *
	 * The health map the gateway builds carries version and health only, which
	 * is all the dispatcher needs to gate a call. An operator being told a
	 * module is unavailable needs two more facts to act on it: the name of the
	 * plugin to install, and the version range it must satisfy. Those live on
	 * the module, so they are read from the module.
	 *
	 * @return array<string, array{displayName: string, dependency: array<string, string>}> Descriptors keyed by module id.
	 */
	public function describe(): array {
		$described = [];

		foreach ( $this->modules() as $module ) {
			$described[ $module->id()->value ] = [
				'displayName' => $module->displayName(),
				'dependency'  => $module->dependency(),
			];
		}

		return $described;
	}
}
