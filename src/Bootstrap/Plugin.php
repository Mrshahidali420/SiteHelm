<?php
/**
 * Plugin bootstrap and service graph initialization.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Change\ChangeEngine;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Modules\Metabox\MetaboxModule;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\Retention;
use SiteHelm\Storage\SnapshotStore;
use Throwable;

/**
 * Core bootstrap: builds the service graph, loads modules in isolation,
 * and exposes the MCP gateway route.
 *
 * @package SiteHelm
 */
final class Plugin {

	/**
	 * Every module the plugin boots, in boot order.
	 *
	 * Later phases append module class names here. Class names rather than
	 * instances so that each construction sits inside the isolation boundary:
	 * a throwing constructor must not be able to take down the gateway.
	 *
	 * PUBLIC BECAUSE THE CATALOG-WIDE TESTS MUST ENUMERATE THE REAL TABLE. When
	 * this list lived as a local inside `register()`, the REQ-0063 absence test
	 * — no non-Elementor page builder may appear in any V1 dispatcher catalog —
	 * had to keep a hand-written copy of it, so a module added here and nowhere
	 * else shipped a foreign builder into the catalog with the suite still
	 * green. A requirement about the whole catalog has to read the whole
	 * catalog from the single place that defines it.
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
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Retrieve the singleton plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Initialize the plugin (private constructor for singleton).
	 */
	private function __construct() {
	}

	/**
	 * Register the MCP gateway and load modules.
	 */
	public function register(): void {
		$registry = new CapabilityRegistry();

		$module_health = ( new ModuleLoader() )->load( $this->constructModules( self::MODULE_CLASSES ), $registry );

		$server = new McpServer(
			new Dispatcher(
				$registry,
				new CatalogBuilder( $registry ),
				new PolicyEngine(),
				new SchemaValidator(),
				ChangeEngine::create()
			),
			new ContextFactory(),
			$module_health
		);

		$transport = new RestTransport( $server );
		add_action( 'rest_api_init', [ $transport, 'registerRoute' ] );

		$this->registerMaintenance();
	}

	/**
	 * Hooks the schema upgrade check and the retention pruning event.
	 *
	 * The upgrade check runs on `admin_init` rather than on every request. It is
	 * a cheap option read on a healthy site, but `maybeUpgrade()` has no backoff:
	 * while storage is unavailable every call re-runs three `dbDelta` statements
	 * and three `SHOW TABLES` queries. Binding it to anonymous front-end traffic
	 * would turn a broken install into a load problem, and an administrator visit
	 * is guaranteed after an update anyway.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function registerMaintenance(): void {
		add_action( 'admin_init', [ new Installer(), 'maybeUpgrade' ] );
		add_action(
			Retention::CRON_HOOK,
			static function (): void {
				( new Retention( new PlanStore(), new AuditStore(), new SnapshotStore() ) )->prune( time() );
			}
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Constructs each module inside its own isolation boundary. A module whose
	 * constructor throws is logged server-side and skipped; every other module,
	 * and the gateway itself, continues to load.
	 *
	 * @param class-string<IntegrationModule>[] $module_classes Module classes to construct.
	 *
	 * @return IntegrationModule[] Successfully constructed modules.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function constructModules( array $module_classes ): array {
		$modules = [];

		foreach ( $module_classes as $module_class ) {
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
