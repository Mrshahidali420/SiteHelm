<?php
/**
 * Plugin bootstrap and service graph initialization.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Modules\Core\RedirectRouter;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Registry\IntegrationDirectory;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\Retention;
use SiteHelm\Storage\SnapshotStore;

/**
 * Core bootstrap: builds the service graph, loads modules in isolation,
 * and exposes the MCP gateway route.
 *
 * @package SiteHelm
 */
final class Plugin {

	/**
	 * The plugin's boot table, defined once in {@see IntegrationDirectory}.
	 *
	 * It stays reachable under this name because the catalog-wide invariant
	 * tests name it here, and because "the classes the plugin boots" is a fact
	 * about the plugin. The definition moved so that a module can read the
	 * table without depending on the bootstrap layer.
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	public const MODULE_CLASSES = IntegrationDirectory::MODULE_CLASSES;

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

		$module_health = ( new ModuleLoader() )->load( ( new IntegrationDirectory() )->modules(), $registry );

		$dispatcher = new Dispatcher(
			$registry,
			new CatalogBuilder( $registry ),
			new PolicyEngine(),
			new SchemaValidator(),
			ChangeEngine::create()
		);

		$server = new McpServer( $dispatcher, new ContextFactory(), $module_health );

		$transport = new RestTransport( $server );
		add_action( 'rest_api_init', [ $transport, 'registerRoute' ] );

		// The console is handed the same registry and the same health map the
		// gateway is serving from. A second registry built for the admin could
		// disagree with the one answering requests, and a catalogue that
		// disagrees with the server is worse than no catalogue at all. The
		// dispatcher goes too, so a console rollback runs the same write path.
		if ( is_admin() ) {
			( new AdminMenu( $registry, $module_health, $dispatcher ) )->register();
		}

		// The redirect router is the only part of this plugin that serves ordinary
		// front-end traffic, and it is bound OUTSIDE the is_admin() branch above
		// for exactly that reason. It is bound unconditionally rather than behind
		// a "are there any redirects" test, because answering that question needs
		// the same autoloaded option the router already reads.
		add_action( 'template_redirect', [ new RedirectRouter( new RedirectStore() ), 'handle' ], 1 );

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
}
