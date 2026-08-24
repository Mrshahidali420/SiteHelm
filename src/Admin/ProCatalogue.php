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
	 * @var ModuleId[]
	 */
	public const ADDON_ONLY_MODULES = [ ModuleId::Woocommerce ];

	/**
	 * Every Pro operation, keyed by identifier: the dispatcher it lands on,
	 * the module it belongs to, whether it reads, and what it does.
	 *
	 * @var array<string, array{dispatcher: string, module: ModuleId, read: bool, description: string}>
	 */
	public const OPERATIONS = [
		'product-list'           => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => "List this shop's products with their price, sale price, stock and categories, filtered by search term, status, category or stock state.",
		],
		'product-get'            => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'Read one product in full — name, description, SKU, prices, stock, categories, tags, images and type, including whether its price lives on its variations.',
		],
		'product-category-list'  => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => "List the shop's product categories with their parent, slug and product count.",
		],
		'order-list'             => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'List orders newest first with status, total, currency, item count and date, filtered by status, customer or date range. Read only — SiteHelm never changes an order.',
		],
		'order-get'              => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'Read one order — its line items, totals, tax, shipping, payment method and status history. Read only.',
		],
		'customer-list'          => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Woocommerce,
			'read'        => true,
			'description' => 'List shop customers with order count, lifetime spend and last order date. Read only, and gated on the WooCommerce management capability because the answer carries personal data.',
		],
		'product-create'         => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Woocommerce,
			'read'        => false,
			'description' => 'Create one simple product — name, description, SKU, price, sale price, stock and categories. Previewed before it is made.',
		],
		'product-update'         => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Woocommerce,
			'read'        => false,
			'description' => "Change one product's name, description, SKU, price, sale price, stock or categories. Previewed, snapshotted and reversible.",
		],
		'seo-settings-get'       => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "Read the SEO plugin's site-wide settings (title separator, knowledge graph, default social image, breadcrumbs) or one post type's settings (title and description templates, noindex, sitemap inclusion).",
		],
		'seo-settings-set'       => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => "Set the SEO plugin's site-wide settings or one post type's settings from a strict allowlist. Previewed, reversible.",
		],
		'content-seo-bulk-set'   => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => 'Set the same search-engine metadata on up to fifty posts in one previewed, reversible change — title, description, canonical, focus keyword, noindex, nofollow and the social overrides.',
		],
		'seo-404-log-list'       => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the URLs visitors requested that returned 404, as Rank Math's 404 monitor recorded them, most recent first, with hit counts and referrers. Rank Math only.",
		],
		'seo-redirection-list'   => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the redirections Rank Math's redirections module holds — source patterns, destination, status code, hit count, active or inactive. Rank Math only.",
		],
		'content-seo-schema-get' => [
			'dispatcher'  => 'content-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "Read one post's structured-data schema — its primary type and the plugin's stored fields — and the type names the plugin accepts.",
		],
		'content-seo-schema-set' => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => "Set one post's schema type and fields, or clear it back to the plugin's default. Previewed, reversible.",
		],
		'content-seo-audit-fix'  => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => 'Fix a page of SEO audit findings as one previewed, reversible change — missing descriptions from the post\'s own text, over-long titles and descriptions trimmed, published posts taken off noindex.',
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
	 * The add-on's state on this site and the one link the console may offer.
	 *
	 * Absent: the add-on is not installed; the link is the free plugin's own
	 * Add-Ons page for it. Unlicensed: installed but the licence is not active;
	 * the link is its Account page. Active: no link — there is nothing to sell.
	 * Every lookup is guarded, so a site without the SDK answers "absent" with
	 * no link rather than failing.
	 *
	 * @return array{state: string, url: string}
	 */
	public function probe(): array {
		if ( null !== $this->resolver ) {
			return ( $this->resolver )();
		}

		if ( ! function_exists( 'sitehelm_pro_fs' ) ) {
			$url = '';
			if ( function_exists( 'sitehelm_fs' ) ) {
				$fs = sitehelm_fs();
				if ( is_object( $fs ) && method_exists( $fs, 'addon_url' ) ) {
					$url = (string) $fs->addon_url( self::ADDON_SLUG );
				}
			}

			return [
				'state' => self::STATE_ABSENT,
				'url'   => $url,
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
			'url'   => is_object( $fs ) && method_exists( $fs, 'get_account_url' ) ? (string) $fs->get_account_url() : '',
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
