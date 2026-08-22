<?php
/**
 * Admin console menu, screens and assets.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

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
	 * The top-level page slug, which is also the Connect screen.
	 */
	public const PAGE_CONNECT = 'sitehelm';

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
	 * The console's screens, in the order they appear.
	 *
	 * One list, read by both the WordPress submenu and the console's own tab bar,
	 * so a screen can never exist in one and not the other. The order runs from
	 * what a person does first to what they consult afterwards: connect the
	 * client, see what it can reach, look up an operation, then read back what
	 * happened and why.
	 *
	 * @return array<string, array{label: string, icon: string}> Page slug to label and dashicon class.
	 */
	public static function tabs(): array {
		return [
			self::PAGE_CONNECT    => [
				'label' => __( 'Connect', 'sitehelm' ),
				'icon'  => 'dashicons-admin-links',
			],
			self::PAGE_MODULES    => [
				'label' => __( 'Modules', 'sitehelm' ),
				'icon'  => 'dashicons-screenoptions',
			],
			self::PAGE_OPERATIONS => [
				'label' => __( 'Operations', 'sitehelm' ),
				'icon'  => 'dashicons-list-view',
			],
			self::PAGE_ACTIVITY   => [
				'label' => __( 'Activity', 'sitehelm' ),
				'icon'  => 'dashicons-backup',
			],
			self::PAGE_STATUS     => [
				'label' => __( 'Status', 'sitehelm' ),
				'icon'  => 'dashicons-shield-alt',
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
	 * Constructs the console.
	 *
	 * @param CapabilityRegistry                                     $registry The registry the gateway is serving from.
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's health map.
	 */
	public function __construct( CapabilityRegistry $registry, array $health = [] ) {
		$this->registry = $registry;
		$this->health   = $health;
	}

	/**
	 * Hook the console into wp-admin.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_' . ConnectScreen::ACTION_CREATE_PASSWORD, [ new ConnectScreen(), 'handle_create_password' ] );

		// Other plugins' banners are pruned from our screens only. See the class
		// for the rule and for why it fails open.
		( new ForeignNotices() )->register();
	}

	/**
	 * Register the top-level menu and its subpages.
	 */
	public function add_pages(): void {
		$screens = [
			self::PAGE_CONNECT    => new ConnectScreen(),
			self::PAGE_MODULES    => new ModulesScreen( $this->registry, $this->health ),
			self::PAGE_OPERATIONS => new OperationsScreen( $this->registry, $this->health ),
			self::PAGE_ACTIVITY   => new ActivityScreen(),
			self::PAGE_STATUS     => new StatusScreen( $this->health ),
		];

		add_menu_page(
			__( 'SiteHelm', 'sitehelm' ),
			__( 'SiteHelm', 'sitehelm' ),
			self::CAPABILITY,
			self::PAGE_CONNECT,
			[ $screens[ self::PAGE_CONNECT ], 'render' ],
			self::menu_icon(),
			58
		);

		foreach ( self::tabs() as $slug => $tab ) {
			add_submenu_page(
				self::PAGE_CONNECT,
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
	}

	/**
	 * Load the console's stylesheet and script on SiteHelm screens only.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! self::is_console_screen( $hook_suffix ) ) {
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
		return str_contains( $hook_suffix, '_page_' . self::PAGE_CONNECT )
			|| 'toplevel_page_' . self::PAGE_CONNECT === $hook_suffix;
	}

	/**
	 * The menu icon: a ship's wheel, drawn rather than fetched.
	 *
	 * Inline so the icon costs no request and inherits the admin menu's own
	 * colour through `currentColor`, which is how WordPress expects a menu icon
	 * to behave across the admin colour schemes.
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
			. ' stroke-width="1.5"><circle cx="10" cy="10" r="7.2"/><circle cx="10" cy="10" r="2.6"/>'
			. '<path d="M10 1.4v5.9M10 12.7v5.9M1.4 10h5.9M12.7 10h5.9"/></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI is defined as base64; WordPress documents this exact form for menu icons.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
