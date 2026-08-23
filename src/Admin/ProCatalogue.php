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
	 * Every Pro operation, keyed by identifier: the dispatcher it lands on,
	 * the module it belongs to, whether it reads, and what it does.
	 *
	 * @var array<string, array{dispatcher: string, module: ModuleId, read: bool, description: string}>
	 */
	public const OPERATIONS = [
		'seo-settings-get'     => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "Read the SEO plugin's site-wide settings (title separator, knowledge graph, default social image, breadcrumbs) or one post type's settings (title and description templates, noindex, sitemap inclusion).",
		],
		'seo-settings-set'     => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => "Set the SEO plugin's site-wide settings or one post type's settings from a strict allowlist. Previewed, reversible.",
		],
		'content-seo-bulk-set' => [
			'dispatcher'  => 'content-write',
			'module'      => ModuleId::Seo,
			'read'        => false,
			'description' => 'Set the same search-engine metadata on up to fifty posts in one previewed, reversible change — title, description, canonical, focus keyword, noindex, nofollow and the social overrides.',
		],
		'seo-404-log-list'     => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the URLs visitors requested that returned 404, as Rank Math's 404 monitor recorded them, most recent first, with hit counts and referrers. Rank Math only.",
		],
		'seo-redirection-list' => [
			'dispatcher'  => 'system-read',
			'module'      => ModuleId::Seo,
			'read'        => true,
			'description' => "List the redirections Rank Math's redirections module holds — source patterns, destination, status code, hit count, active or inactive. Rank Math only.",
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
