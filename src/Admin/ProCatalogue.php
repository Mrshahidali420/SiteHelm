<?php
/**
 * What SiteHelm Pro adds, as the free console knows it.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use Closure;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The free plugin's knowledge of the Pro add-on: which operation identifiers
 * are Pro, what each does, and whether the add-on is absent, installed
 * without a licence, or active.
 *
 * The console lists Pro operations in the groups they belong to so an owner
 * reading the Tools tab sees the whole surface in one place — what this site
 * can do today and what the add-on would add — without a banner anywhere
 * else. The catalogue is static text: the free plugin carries no Pro code,
 * and a site without the add-on never loads any.
 *
 * @package SiteHelm
 */
final class ProCatalogue {

	public const STATE_ABSENT     = 'absent';
	public const STATE_UNLICENSED = 'unlicensed';
	public const STATE_ACTIVE     = 'active';

	/**
	 * The add-on's slug on the Add-Ons page.
	 */
	public const ADDON_SLUG = 'sitehelm-pro';

	/**
	 * Where the add-on is bought. Named here because more than the console
	 * says it: the gateway's refusal for a Pro operation points an agent at
	 * the same address the Tools tab points an owner at.
	 */
	public const PRICING_URL = 'https://wpsitehelm.com/pricing';

	/**
	 * The WooCommerce version the add-on's commerce operations require.
	 *
	 * Declared here rather than in the add-on because the free console has to
	 * name the floor on a site that has no add-on installed. The add-on reads
	 * this constant as its own floor when the free plugin is new enough to
	 * carry it, so the number advertised and the number enforced are one.
	 */
	public const WOOCOMMERCE_MIN_VERSION = '8.0';

	/**
	 * Modules whose operations live entirely in the add-on.
	 *
	 * The console shows such a module like any other — an owner can set its
	 * permission level before buying — but what it is waiting on is the add-on,
	 * not a plugin they can activate from the Plugins screen, so the card says
	 * so.
	 *
	 * The two entries wait on different things. Commerce waits on the add-on and
	 * on WooCommerce; code waits on the add-on alone, because its host is the
	 * add-on's own runner and there is no third-party plugin behind it. The card
	 * text branches on that rather than printing a version floor that does not
	 * exist.
	 *
	 * @var ModuleId[]
	 */
	public const ADDON_ONLY_MODULES = [ ModuleId::Woocommerce, ModuleId::Code ];

	/**
	 * Every Pro operation, keyed by identifier: the dispatcher it lands on,
	 * the module it belongs to, whether it reads, and what it does.
	 *
	 * @var array<string, array{dispatcher: string, module: ModuleId, read: bool, description: string}>
	 */
	public const OPERATIONS = [
		'product-list'                 => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => "List this shop's products with their price, sale price, stock and categories, filtered by search term, status, category or stock state.",
		],
		'product-get'                  => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'Read one product in full — name, description, SKU, prices, stock, categories, tags, images and type, including whether its price lives on its variations.',
		],
		'product-category-list'        => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => "List the shop's product categories with their parent, slug and product count.",
		],
		'order-list'                   => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'List orders newest first with status, total, currency, item count and date, filtered by status, customer or date range. Read only — SiteHelm never changes an order.',
		],
		'order-get'                    => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'Read one order — its line items, totals, tax, shipping, payment method and status history. Read only.',
		],
		'customer-list'                => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'List shop customers with order count, lifetime spend and last order date. Read only, and gated on the WooCommerce management capability because the answer carries personal data.',
		],
		'product-create'               => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Woocommerce,
			'read'        => false,
			'description' => 'Create one simple product — name, description, SKU, price, sale price, stock and categories. Previewed before it is made.',
		],
		'product-update'               => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Woocommerce,
			'read'        => false,
			'description' => "Change one product's name, description, SKU, price, sale price, stock or categories. Previewed, snapshotted and reversible.",
		],
		'seo-settings-get'             => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "Read the SEO plugin's site-wide settings (title separator, knowledge graph, default social image, breadcrumbs) or one post type's settings (title and description templates, noindex, sitemap inclusion).",
		],
		'seo-settings-set'             => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => "Set the SEO plugin's site-wide settings or one post type's settings from a strict allowlist. Previewed, reversible.",
		],
		'seo-404-log-list'             => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the URLs visitors requested that returned 404, as Rank Math's 404 monitor recorded them, most recent first, with hit counts and referrers. Rank Math only.",
		],
		'seo-redirection-list'         => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the redirections Rank Math's redirections module holds — source patterns, destination, status code, hit count, active or inactive. Rank Math only.",
		],
		'content-seo-schema-get'       => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "Read one post's structured-data schema — its primary type and the plugin's stored fields — and the type names the plugin accepts.",
		],
		'content-seo-schema-set'       => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => "Set one post's schema type and fields, or clear it back to the plugin's default. Previewed, reversible.",
		],
		'code-host-list'               => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => "List the places a snippet can live on this site — SiteHelm's own runner, which is always there, and any snippet plugin that is installed — and say which of the safety guarantees each one can keep.",
		],
		'code-snippet-list'            => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'List the snippets stored on this site with their language, the hook each one runs on, whether it is live, and whether it has been quarantined. Bodies are not returned.',
		],
		'code-snippet-get'             => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Read one snippet in full, including its body.',
		],
		'code-safe-mode-token'         => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Issue the one-off web address that loads this site with every snippet skipped, so an owner can reach the admin screens even while a snippet is breaking the front end.',
		],
		'code-quarantine-list'         => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'List the snippets that were taken out of circulation because a request died while they were running, and the error that did it.',
		],
		'code-health-check'            => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Ask this site for its home page and its admin screen and report whether they render, break, or could not be reached at all. The same check activation runs, callable on its own.',
		],
		'code-scaffold-widget'         => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Generate the source for an Elementor widget class from a description of its controls, and return it as text. Nothing is stored and nothing runs.',
		],
		'code-scaffold-block'          => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Generate the source for a WordPress block — registration, attributes and render callback — and return it as text. Nothing is stored and nothing runs.',
		],
		'code-scaffold-theme-template' => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Code,
			'read'        => true,
			'description' => 'Generate the source for a theme template file for a given post type or archive, and return it as text. Nothing is stored and nothing runs.',
		],
		'code-snippet-write'           => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Store one PHP snippet. It is always stored switched off — there is no argument that makes it live — and it is refused outright if it does not parse. Previewed and reversible. If WPCode or Code Snippets is on the site, name it as the host and the snippet is stored there instead; if neither is, installing one is usually the better answer.',
		],
		'code-snippet-activate'        => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Switch one stored snippet on, with a time limit. The site is checked immediately afterwards, and unless a confirmation follows inside the window the snippet switches itself back off.',
		],
		'code-snippet-confirm'         => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Confirm that a snippet activated a moment ago should stay live. Without this, staying on is not the default — switching back off is.',
		],
		'code-snippet-deactivate'      => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Switch one snippet off. It only ever reduces what runs on the site.',
		],
		'code-snippet-delete'          => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Delete one snippet. Snapshotted first, so it can be put back.',
		],
		'code-css-write'               => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Store custom CSS printed on the front end. It cannot run anything, but it can still make a site unusable to look at, so it is previewed and reversible like any other change. If WPCode or Code Snippets is on the site, name it as the host and the CSS is stored there instead.',
		],
		'code-js-write'                => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => "Store custom JavaScript printed on the front end. It runs in every visitor's browser rather than on the server, which is a wider reach than PHP, and it goes through the same guarded activation. If WPCode or Code Snippets is on the site, name it as the host and the JavaScript is stored there instead.",
		],
		'code-safe-mode-set'           => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Turn every snippet on this site off at once, or back on, without needing to know which one broke. The switch is read before any snippet is considered, so a broken snippet cannot defeat it.',
		],
		'code-quarantine-clear'        => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Code,
			'read'        => false,
			'description' => 'Put a quarantined snippet back into circulation. It goes through the whole activation guard again rather than simply being trusted.',
		],
		'elementor-dynamic-tag-list'   => [
			'dispatcher'  => 'elementor-read',
			'module'      => ModuleId::Elementor,
			'read'        => true,
			'description' => 'List the dynamic tags this site registers, so a binding names a tag the site actually has. Requires Elementor Pro.',
		],
		'elementor-brand-kit-list'     => [
			'dispatcher'  => 'elementor-read',
			'module'      => ModuleId::Elementor,
			'read'        => true,
			'description' => "List the site's brand kits with their global colour and typography counts, and say which one is live. Needs only Elementor.",
		],
		'elementor-popup-create'       => [
			'dispatcher'  => 'elementor-write',
			'module'      => ModuleId::Elementor,
			'read'        => false,
			'description' => 'Create a popup with no trigger armed, so it shows to nobody until you give it one. Requires Elementor Pro.',
		],
		'elementor-popup-settings-set' => [
			'dispatcher'  => 'elementor-write',
			'module'      => ModuleId::Elementor,
			'read'        => false,
			'description' => 'Set when a popup opens and how a visitor can close it, from an allowlist of five settings written in whole groups. Requires Elementor Pro.',
		],
		'elementor-dynamic-tag-set'    => [
			'dispatcher'  => 'elementor-write',
			'module'      => ModuleId::Elementor,
			'read'        => false,
			'description' => "Bind one widget setting to a dynamic tag, so the page shows what the site holds rather than typed text. The tag is checked against the site's own registry first. Requires Elementor Pro.",
		],
		'elementor-brand-kit-apply'    => [
			'dispatcher'  => 'elementor-write',
			'module'      => ModuleId::Elementor,
			'read'        => false,
			'description' => 'Switch the active brand kit. Every page using global colours or global typography changes appearance, so the change is high risk and always reversible.',
		],
		'plugin-activate'              => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Switch one installed plugin on. Previewed first, and the state it was in is recorded so it can be put back.',
		],
		'plugin-deactivate'            => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Switch one plugin off. Previewed and reversible, and it refuses a plugin the network activated rather than changing something a single site does not own.',
		],
		'plugin-update'                => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Update one plugin to the version WordPress says is waiting. Previewed, and the version it came from is recorded — but WordPress has no clean way back to an old version, so this one cannot be rolled back.',
		],
		'theme-switch'                 => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Make a different installed theme the live one. Previewed, and the theme it replaced is recorded so it can be put back.',
		],
		'theme-update'                 => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Update one theme to the version WordPress says is waiting. Previewed, and not reversible, for the same reason a plugin update is not.',
		],
		'plugin-install'               => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Install a plugin from WordPress.org by its slug. There is no argument that accepts a web address, a zip or a file path, and what is installed is always stored switched off — switching it on is a separate, deliberate step.',
		],
		'theme-install'                => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Install a theme from WordPress.org by its slug, on the same terms as a plugin install: slug only, never a web address or a zip, and the theme is not made live.',
		],

		'plugin-delete'                => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Remove one deactivated plugin from this site for good. Previewed, and there is no way back: SiteHelm keeps no copy of what it deleted. An active plugin, a network-activated one, and SiteHelm itself are all refused.',
		],
		'theme-delete'                 => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Extensions,
			'read'        => false,
			'description' => 'Remove one theme from this site for good, on the same terms as a plugin delete. The live theme is refused, and so is a theme another installed theme is built on.',
		],
	];

	/**
	 * The test seam: answers probe() in place of the add-on and the SDK.
	 *
	 * @var Closure|null
	 */
	private ?Closure $resolver;

	/**
	 * Constructs the catalogue.
	 *
	 * @param callable|null $resolver Returns array{state: string, url: string}; null asks the add-on.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = null === $resolver ? null : Closure::fromCallable( $resolver );
	}

	/**
	 * Whether an operation identifier is one of Pro's.
	 *
	 * @param string $id The operation identifier.
	 */
	public function is_pro( string $id ): bool {
		return isset( self::OPERATIONS[ $id ] );
	}

	/**
	 * The console's own Upgrade screen, which answers both states.
	 *
	 * Every link the console offers about Pro points here, and it is a page this
	 * plugin registers itself. The two addresses the SDK can hand us do not
	 * survive contact with real sites: `addon_url()` lands on an Add-Ons list
	 * that fails to load when a host blocks outbound calls to Freemius, and
	 * `get_account_url()` on an add-on builds `sitehelm-pro-account`, a slug the
	 * SDK never registers for an add-on, so it answers "you are not allowed to
	 * access this page". Both were being handed to owners as the way to buy or
	 * activate.
	 */
	public static function upgrade_url(): string {
		return admin_url( 'admin.php?page=' . AdminMenu::PAGE_UPGRADE );
	}

	/**
	 * The add-on's state on this site and the one link the console may offer.
	 *
	 * Absent and unlicensed both point at the Upgrade screen: it shows the plans
	 * in the first case and the licence field in the second, and it decides which
	 * by probing the same state, so the caller does not have to. Active carries no
	 * link — there is nothing left to sell or enter.
	 *
	 * Every lookup is guarded, so a site without the SDK answers "absent" rather
	 * than failing.
	 *
	 * @return array{state: string, url: string}
	 */
	public function probe(): array {
		if ( null !== $this->resolver ) {
			return ( $this->resolver )();
		}

		if ( ! function_exists( 'sitehelm_pro_fs' ) ) {
			return [
				'state' => self::STATE_ABSENT,
				'url'   => self::upgrade_url(),
			];
		}

		$fs = sitehelm_pro_fs();

		if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code() ) {
			return [
				'state' => self::STATE_ACTIVE,
				'url'   => '',
			];
		}

		return [
			'state' => self::STATE_UNLICENSED,
			'url'   => self::upgrade_url(),
		];
	}

	/**
	 * The Pro operations the registry does not hold, keyed by dispatcher in
	 * catalogue order.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway is serving from.
	 *
	 * @return array<string, list<string>>
	 */
	public function missing( CapabilityRegistry $registry ): array {
		$missing = [];

		foreach ( self::OPERATIONS as $id => $entry ) {
			if ( ! $registry->has( $id ) ) {
				$missing[ $entry['dispatcher'] ][] = $id;
			}
		}

		return $missing;
	}

	/**
	 * How many of the registry's operations are Pro's.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway is serving from.
	 */
	public function registered_count( CapabilityRegistry $registry ): int {
		$count = 0;

		foreach ( array_keys( self::OPERATIONS ) as $id ) {
			if ( $registry->has( $id ) ) {
				++$count;
			}
		}

		return $count;
	}
}
