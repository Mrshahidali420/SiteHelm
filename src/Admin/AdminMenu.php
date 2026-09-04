<?php
/**
 * Admin console menu, screens and assets.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Registers the SiteHelm top-level menu and its screens.
 *
 * The whole menu is gated on `manage_options`, which is the capability the
 * `system-*` and `audit-list` operations already require. A user who could see
 * these screens but not call the operations behind them would be looking at a
 * console that answers nothing, so the gate matches the data rather than the
 * convention of gating admin pages on whatever the author had to hand.
 *
 * Assets load on SiteHelm screens only. The stylesheet is scoped to
 * `.sitehelm-app`, but an unscoped enqueue would still cost every other admin
 * page a request, and a plugin that slows down wp-admin generally is a plugin
 * people remove.
 *
 * @package SiteHelm
 */
final class AdminMenu {

	/**
	 * The capability required for every screen.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The top-level page slug, which is also the Home screen.
	 */
	public const PAGE_HOME = 'sitehelm';

	/**
	 * The SiteHelm community group. One address, said in three places — the
	 * menu, the help menu and nowhere else — so a moved group is one edit.
	 */
	public const COMMUNITY_URL = 'https://www.facebook.com/groups/2838081573231405';

	/**
	 * The Connect screen's page slug.
	 */
	public const PAGE_CONNECT = 'sitehelm-connect';

	/**
	 * The Activity screen's page slug.
	 */
	public const PAGE_ACTIVITY = 'sitehelm-activity';

	/**
	 * The Status screen's page slug.
	 */
	public const PAGE_STATUS = 'sitehelm-status';

	/**
	 * The Operations screen's page slug.
	 */
	public const PAGE_OPERATIONS = 'sitehelm-operations';

	/**
	 * The Modules screen's page slug.
	 */
	public const PAGE_MODULES = 'sitehelm-modules';

	/**
	 * The Upgrade screen's page slug.
	 *
	 * Not in {@see self::tabs()}: the screen exists only while there is something
	 * to buy or activate, and a tab bar that gains and loses a tab depending on a
	 * licence would move every other tab under the reader. It is registered as a
	 * submenu entry of its own, below the console's screens.
	 */
	public const PAGE_UPGRADE = 'sitehelm-upgrade';

	/**
	 * The console's screens, in the order they appear.
	 *
	 * One list, read by both the WordPress submenu and the console's own tab bar,
	 * so a screen can never exist in one and not the other. The order runs from
	 * what a person does first to what they consult afterwards: see how things
	 * are, connect an app, decide what it may do, then read back what happened
	 * and whether the site is healthy. The labels are a site owner's words; the
	 * slugs and the screen classes keep their original names, so bookmarks and
	 * tests survive the renaming. The tab bar scrolls when more screens arrive.
	 *
	 * @return array<string, array{label: string, icon: string}> Page slug to label and dashicon class.
	 */
	public static function tabs(): array {
		return [
			self::PAGE_HOME       => [
				'label' => __( 'Home', 'sitehelm' ),
				'icon'  => 'dashicons-admin-home',
			],
			self::PAGE_CONNECT    => [
				'label' => __( 'Connect an app', 'sitehelm' ),
				'icon'  => 'dashicons-admin-links',
			],
			self::PAGE_MODULES    => [
				'label' => __( 'Permissions', 'sitehelm' ),
				'icon'  => 'dashicons-lock',
			],
			self::PAGE_OPERATIONS => [
				'label' => __( 'Tools', 'sitehelm' ),
				'icon'  => 'dashicons-admin-tools',
			],
			self::PAGE_ACTIVITY   => [
				'label' => __( 'History', 'sitehelm' ),
				'icon'  => 'dashicons-backup',
			],
			self::PAGE_STATUS     => [
				'label' => __( 'Health', 'sitehelm' ),
				'icon'  => 'dashicons-heart',
			],
		];
	}

	/**
	 * The registry the Operations screen reads.
	 *
	 * Passed in rather than rebuilt, so the screen lists what this site actually
	 * booted. A second registry constructed for the console could disagree with
	 * the one serving requests, and a catalogue that disagrees with the server
	 * is worse than no catalogue.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * Module health, as the loader recorded it while booting this request.
	 *
	 * @var array<string, array{version: ?string, health: string}>
	 */
	private array $health;

	/**
	 * The dispatcher the gateway serves from, when the console may write through it.
	 *
	 * Null means the console is read-only: no rollback button is bound. The
	 * Activity screen still renders the button markup, but the POST would land
	 * on an unbound admin_post action, which WordPress ignores.
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher;

	/**
	 * The operator's per-operation switches, shared with the gateway.
	 *
	 * @var OperationSwitches
	 */
	private OperationSwitches $switches;

	/**
	 * The add-on's state, which decides whether the menu offers Pro at all.
	 *
	 * @var ProCatalogue
	 */
	private ProCatalogue $pro;

	/**
	 * Constructs the console.
	 *
	 * @param CapabilityRegistry                                     $registry   The registry the gateway is serving from.
	 * @param array<string, array{version: ?string, health: string}> $health     The loader's health map.
	 * @param Dispatcher|null                                        $dispatcher The gateway's dispatcher, for console rollback; null binds none.
	 * @param OperationSwitches|null                                 $switches   The gateway's per-operation switches; null reads the option afresh.
	 * @param ProCatalogue|null                                      $pro        The add-on's state; null probes the site.
	 */
	public function __construct( CapabilityRegistry $registry, array $health = [], ?Dispatcher $dispatcher = null, ?OperationSwitches $switches = null, ?ProCatalogue $pro = null ) {
		$this->registry   = $registry;
		$this->health     = $health;
		$this->dispatcher = $dispatcher;
		$this->switches   = $switches ?? new OperationSwitches();
		$this->pro        = $pro ?? new ProCatalogue();
	}

	/**
	 * Hook the console into wp-admin.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_' . ConnectScreen::ACTION_CREATE_PASSWORD, [ new ConnectScreen(), 'handle_create_password' ] );
		add_action( 'admin_footer', [ $this, 'print_connect_modal' ] );
		add_action( 'admin_post_' . ConnectModalAction::ACTION, [ new ConnectModalAction(), 'handle' ] );
		add_action( 'admin_post_' . WriteModeAction::ACTION, [ new WriteModeAction(), 'handle' ] );
		add_action( 'admin_post_' . RevokeAction::ACTION, [ new RevokeAction(), 'handle' ] );
		add_action( 'admin_post_' . RetentionAction::ACTION, [ new RetentionAction(), 'handle' ] );
		add_action( 'admin_post_' . ConnectedAppsAction::ACTION_SIGN_OUT, [ new ConnectedAppsAction(), 'handle_sign_out' ] );
		add_action( 'admin_post_' . ConnectedAppsAction::ACTION_REMOVE, [ new ConnectedAppsAction(), 'handle_remove' ] );
		add_action( 'admin_post_' . AuthSettingsAction::ACTION, [ new AuthSettingsAction(), 'handle' ] );
		add_action( 'admin_post_' . ExportAction::ACTION, [ new ExportAction(), 'handle' ] );
		add_action( 'admin_post_' . OperationsAction::ACTION, [ new OperationsAction( $this->registry ), 'handle' ] );
		add_action( 'admin_post_' . ModuleSwitchAction::ACTION, [ new ModuleSwitchAction( $this->registry, $this->switches ), 'handle' ] );
		add_action( 'wp_dashboard_setup', [ new DashboardWidget(), 'add_widget' ] );
		PluginLinks::register();
		( new SiteHealth() )->register();
		( new ActivationNotice() )->register();
		( new UnlicensedNotice() )->register();

		// Console rollback goes through the same dispatcher the gateway serves
		// from, so the console can restore nothing an agent could not, and every
		// restoration is recorded and verified the same way.
		if ( null !== $this->dispatcher ) {
			add_action(
				'admin_post_' . RollbackAction::ACTION,
				[ new RollbackAction( [ $this->dispatcher, 'dispatch' ], new ContextFactory(), $this->health ), 'handle' ]
			);
		}

		// Other plugins' banners are pruned from our screens only. See the class
		// for the rule and for why it fails open.
		( new ForeignNotices() )->register();
	}

	/**
	 * Register the top-level menu and its subpages.
	 */
	public function add_pages(): void {
		$screens = [
			self::PAGE_HOME       => new HomeScreen(),
			self::PAGE_CONNECT    => new ConnectScreen(),
			self::PAGE_MODULES    => new ModulesScreen( $this->registry, $this->health, $this->switches ),
			self::PAGE_OPERATIONS => new OperationsScreen( $this->registry, $this->health, $this->switches ),
			self::PAGE_ACTIVITY   => new ActivityScreen(),
			self::PAGE_STATUS     => new StatusScreen( $this->health ),
		];

		add_menu_page(
			__( 'SiteHelm', 'sitehelm' ),
			__( 'SiteHelm', 'sitehelm' ),
			self::CAPABILITY,
			self::PAGE_HOME,
			[ $screens[ self::PAGE_HOME ], 'render' ],
			self::menu_icon(),
			58
		);

		foreach ( self::tabs() as $slug => $tab ) {
			add_submenu_page(
				self::PAGE_HOME,
				sprintf(
					/* translators: %s: screen name, such as Activity. */
					__( 'SiteHelm %s', 'sitehelm' ),
					$tab['label']
				),
				$tab['label'],
				self::CAPABILITY,
				$slug,
				[ $screens[ $slug ], 'render' ]
			);
		}

		$this->add_outward_links();
	}

	/**
	 * The last two submenu entries: the community group, which leaves wp-admin,
	 * and — only while the Pro add-on is not active — the Upgrade screen, which
	 * does not.
	 *
	 * A URL passed as the menu slug with no callback is rendered by WordPress
	 * as a plain link to that URL, which is the convention plugins use for an
	 * outward menu item, and is how the community entry works. Upgrade used to
	 * be one of those, pointing at the website's pricing page; it is now a page
	 * of our own, because somebody deciding between plans should not be sent out
	 * of their site to read them, and somebody holding a licence key needs a
	 * field, not a brochure.
	 *
	 * The entry disappears the moment a licence is active: a menu that keeps
	 * selling to someone who already paid reads as not knowing they paid.
	 */
	private function add_outward_links(): void {
		add_submenu_page(
			self::PAGE_HOME,
			'',
			__( 'Community', 'sitehelm' ),
			self::CAPABILITY,
			self::COMMUNITY_URL
		);

		$state = (string) $this->pro->probe()['state'];

		if ( ProCatalogue::STATE_ACTIVE !== $state ) {
			add_submenu_page(
				self::PAGE_HOME,
				__( 'SiteHelm Pro', 'sitehelm' ),
				'<span class="sitehelm-menu-upgrade">'
					. ( ProCatalogue::STATE_UNLICENSED === $state
						? esc_html__( 'Activate Pro', 'sitehelm' )
						: esc_html__( 'Upgrade to Pro', 'sitehelm' ) )
					. '</span>',
				self::CAPABILITY,
				self::PAGE_UPGRADE,
				[ new UpgradeScreen(), 'render' ]
			);
		}

		add_action( 'admin_head', [ self::class, 'print_menu_style' ] );
		add_action( 'admin_footer', [ self::class, 'print_outward_targets' ] );
	}

	/**
	 * Colour the upgrade entry. Printed on every admin page because the menu
	 * is on every admin page; it is one rule, not a stylesheet.
	 */
	public static function print_menu_style(): void {
		echo '<style>#adminmenu .sitehelm-menu-upgrade{color:#f6a33b;font-weight:600;}</style>';
	}

	/**
	 * Make the outward menu entry open in a new tab. WordPress renders a
	 * URL-slugged submenu item as a same-tab link; leaving wp-admin without
	 * warning is worse than one line of script.
	 *
	 * Community is the only such entry left. Upgrade is a page of ours now, and
	 * opening one of our own screens in a new tab would be a bug.
	 */
	public static function print_outward_targets(): void {
		printf(
			"<script>document.querySelectorAll('#adminmenu a[href^=\"%s\"]').forEach(function(a){a.target='_blank';a.rel='noopener noreferrer';});</script>",
			esc_url( self::COMMUNITY_URL )
		);
	}

	/**
	 * Print the first-run connect dialog under the console's screens.
	 *
	 * Hung on the footer rather than written into `Ui::app_open()`, which every
	 * screen calls: the dialog belongs to the console as a whole, not to any one
	 * screen, and the shell that opens a page is the wrong place to ask the site
	 * whether anything can reach it. Gated on the same screens the stylesheet is,
	 * because a dialog with no stylesheet is a wall of unstyled text.
	 */
	public function print_connect_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! self::is_console_screen( (string) $screen->id ) ) {
			return;
		}

		ConnectModal::render_if_needed();
	}

	/**
	 * Load the console's stylesheet and script on SiteHelm screens only.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! self::is_console_screen( $hook_suffix ) && DashboardWidget::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$base = plugin_dir_url( SITEHELM_PLUGIN_FILE ) . 'assets/admin/';

		wp_enqueue_style( 'sitehelm-admin', $base . 'sitehelm-admin.css', [], SITEHELM_VERSION );
		wp_enqueue_script( 'sitehelm-admin', $base . 'sitehelm-admin.js', [], SITEHELM_VERSION, true );

		wp_localize_script(
			'sitehelm-admin',
			'siteHelmAdmin',
			[
				'copied'          => __( 'Copied', 'sitehelm' ),
				'copyFailed'      => __( 'Could not copy', 'sitehelm' ),
				'copyUnavailable' => __( 'Select and copy manually', 'sitehelm' ),
				/* translators: 1: number of matching operations, 2: total number of operations. */
				'filtered'        => __( '%1$s of %2$s operations shown', 'sitehelm' ),
				'testRunning'     => __( 'Testing the endpoint…', 'sitehelm' ),
				'testReachable'   => __(
					'The endpoint answered and asked for a credential, so the address is right and authentication is being checked. If a client still fails here, the password is wrong or the Authorization header is being dropped.',
					'sitehelm'
				),
				'testNotFound'    => __(
					'Nothing answered at that address. Check that permalinks are working and that the REST API has not been disabled on this site.',
					'sitehelm'
				),
				/* translators: %s: the HTTP status code the endpoint returned. */
				'testUnexpected'  => __( 'The endpoint answered with status %s, which SiteHelm did not expect.', 'sitehelm' ),
				'testFailed'      => __( 'The request could not be sent from this browser. Something between it and the site is blocking the call.', 'sitehelm' ),
			]
		);
	}

	/**
	 * Whether the given hook suffix belongs to a SiteHelm screen.
	 *
	 * Matched on the suffix WordPress builds for our own pages rather than on a
	 * `$_GET['page']` read, because the suffix is the value WordPress itself
	 * decided this request resolves to.
	 *
	 * Public because it is the definition of "a SiteHelm screen" and more than
	 * one thing needs it: the asset enqueue here, and the notice pruning that
	 * must not touch any other page in wp-admin. Two copies of that test could
	 * disagree, and the copy that drifted wider would silently start hiding
	 * notices on pages this plugin does not own.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public static function is_console_screen( string $hook_suffix ): bool {
		return str_contains( $hook_suffix, '_page_' . self::PAGE_HOME )
			|| 'toplevel_page_' . self::PAGE_HOME === $hook_suffix;
	}

	/**
	 * The menu icon: a ship's wheel inside a shield, drawn rather than fetched.
	 *
	 * Inline so the icon costs no request and inherits the admin menu's own
	 * colour through `currentColor`, which is how WordPress expects a menu icon
	 * to behave across the admin colour schemes.
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
			. ' stroke-width="1.5" stroke-linecap="round">'
			. '<path d="M10 1.6 2.6 3.2v7c0 1 .3 1.9.9 2.5L10 18.6l6.5-5.9c.6-.6.9-1.5.9-2.5v-7Z"/>'
			. '<circle cx="10" cy="10" r="3"/><circle cx="10" cy="10" r="1"/>'
			. '<path d="M10 4.8v2.2M10 13v2.2M4.8 10H7M13 10h2.2M6.3 6.3l1.6 1.6M12.1 12.1l1.6 1.6'
			. 'M13.7 6.3l-1.6 1.6M7.9 12.1l-1.6 1.6"/></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI is defined as base64; WordPress documents this exact form for menu icons.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
