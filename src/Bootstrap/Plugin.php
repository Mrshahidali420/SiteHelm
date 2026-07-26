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
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use Throwable;

/**
 * Core bootstrap: builds the service graph, loads modules in isolation,
 * and exposes the MCP gateway route.
 *
 * @package SiteHelm
 */
final class Plugin {

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

		// Later phases append module class names here. Class names rather than
		// instances so that each construction sits inside the isolation boundary:
		// a throwing constructor must not be able to take down the gateway.
		$module_classes = [ DiagnosticsModule::class, CoreModule::class ];
		$module_health  = ( new ModuleLoader() )->load( $this->constructModules( $module_classes ), $registry );

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
	}

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
