<?php
/**
 * What SiteHelm Pro costs, as the console states it.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use Closure;

/**
 * The plan list behind the Upgrade screen.
 *
 * Prices move. A plugin that compiles them in is wrong the day after a sale
 * starts and stays wrong until every install has updated, so the list is
 * fetched from the same data the pricing page is built from and cached for
 * half a day. The compiled copy is still here, and is what a site with no
 * outbound connection shows: the screen has to be able to name a price without
 * the network, because a buyer who sees an empty table does not come back.
 *
 * The fetched copy is trusted only as far as it validates. Anything missing, of
 * the wrong shape, or written against a contract version this plugin does not
 * know falls back whole — a half-read feed must never produce a screen that
 * advertises one plan at another plan's price. A failed read is remembered for
 * an hour so a site behind a firewall is not made slower by asking again on
 * every page load.
 *
 * Prices are never used to gate anything. They are display text; what a licence
 * actually unlocks is decided by Freemius at checkout and by the add-on at run
 * time, so a stale number here can cost a sale but cannot let anyone in.
 *
 * @package SiteHelm
 */
final class Pricing {

	/**
	 * The plugin's home on the web — what "By SiteHelm" links to, and where
	 * every other public address here hangs off.
	 */
	public const SITE_URL = 'https://wpsitehelm.com/';

	/**
	 * Where the live list is published.
	 */
	public const FEED_URL = 'https://wpsitehelm.com/pricing.json';

	/**
	 * The contract version this plugin was written against. A feed announcing
	 * anything else is ignored rather than guessed at.
	 */
	public const FEED_VERSION = 1;

	/**
	 * Where a person reads the whole comparison, coupons included.
	 */
	public const PRICING_PAGE = 'https://wpsitehelm.com/pricing/';

	/**
	 * The cached list.
	 */
	public const TRANSIENT = 'sitehelm_pricing';

	/**
	 * How long a good read is kept.
	 */
	public const CACHE_SECONDS = 43200;

	/**
	 * How long a bad read is remembered before trying again.
	 */
	public const FAILURE_SECONDS = 3600;

	/**
	 * The Freemius add-on and plan the checkout opens.
	 */
	public const PLUGIN_ID = '37704';
	public const PLAN_ID   = '62673';

	/**
	 * The add-on's public key. Public by definition — it is what identifies the
	 * product to the checkout, and it authorises nothing.
	 */
	public const PUBLIC_KEY = 'pk_a63d723ca3b55c38438129e108cb8';

	/**
	 * The compiled list: what the screen shows when the feed cannot be read.
	 *
	 * Kept in step with the site's own pricing data by hand, and deliberately
	 * so — the two are checked against Freemius together whenever a price
	 * changes, which is the moment the numbers are known to be right.
	 *
	 * @var list<array{id: string, name: string, sites: string, who: string, featured: bool, pricingId: string, annual: array{list: float, now: float}, lifetime: array{list: float, now: float}|null}>
	 */
	public const FALLBACK_PLANS = [
		[
			'id'        => 'single',
			'name'      => 'Single site',
			'sites'     => '1 site',
			'who'       => 'Your own site, or one you look after.',
			'featured'  => false,
			'pricingId' => '83841',
			'annual'    => [
				'list' => 39.0,
				'now'  => 24.99,
			],
			'lifetime'  => [
				'list' => 129.0,
				'now'  => 79.0,
			],
		],
		[
			'id'        => 'five',
			'name'      => '5 sites',
			'sites'     => 'Up to 5 sites',
			'who'       => 'A small book of client work.',
			'featured'  => false,
			'pricingId' => '84936',
			'annual'    => [
				'list' => 79.99,
				'now'  => 49.99,
			],
			'lifetime'  => [
				'list' => 249.0,
				'now'  => 149.0,
			],
		],
		[
			'id'        => 'unlimited',
			'name'      => 'Unlimited',
			'sites'     => 'Every site you run',
			'who'       => 'An agency, or a freelancer with a full roster.',
			'featured'  => true,
			'pricingId' => '83843',
			'annual'    => [
				'list' => 149.99,
				'now'  => 89.99,
			],
			'lifetime'  => [
				'list' => 499.0,
				'now'  => 299.0,
			],
		],
	];

	/**
	 * What a licence carries whichever plan it is.
	 *
	 * @var list<string>
	 */
	public const FALLBACK_INCLUDES = [
		'Every Pro operation, on every plan',
		'Updates and support for as long as the licence runs',
		'Deactivate a site and move the licence to another',
		'Cancel a renewal any time; the paid term runs to its end',
	];

	/**
	 * What a pay-once licence is, said once rather than on every row.
	 */
	public const FALLBACK_NOTE = 'A lifetime licence is the same licence, bought once: no renewal, and updates and support for as long as SiteHelm is sold.';

	/**
	 * The HTTP read, injectable so the tests never touch the network.
	 *
	 * @var Closure|null
	 */
	private ?Closure $fetcher;

	/**
	 * Constructs the list.
	 *
	 * @param Closure|null $fetcher Returns the raw feed body, or null; null uses WordPress's HTTP API.
	 */
	public function __construct( ?Closure $fetcher = null ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * The plans, cheapest first, as the screen renders them.
	 *
	 * @return list<array{id: string, name: string, sites: string, who: string, featured: bool, pricingId: string, annual: array{list: float, now: float}, lifetime: array{list: float, now: float}|null}>
	 */
	public function plans(): array {
		$feed = $this->feed();

		return $feed['plans'] ?? self::FALLBACK_PLANS;
	}

	/**
	 * What every licence carries.
	 *
	 * @return list<string>
	 */
	public function includes(): array {
		$feed = $this->feed();

		return $feed['includes'] ?? self::FALLBACK_INCLUDES;
	}

	/**
	 * The sentence explaining a pay-once licence.
	 */
	public function note(): string {
		$feed = $this->feed();

		return $feed['note'] ?? self::FALLBACK_NOTE;
	}

	/**
	 * Whether these prices came off the wire or out of the plugin.
	 *
	 * The screen does not say which, but the tests do: a fallback that silently
	 * became the only path would be invisible otherwise.
	 */
	public function is_live(): bool {
		return null !== $this->feed();
	}

	/**
	 * A checkout link for one plan and one billing cycle.
	 *
	 * Plain, with no coupon parameter — the codes are copied in at checkout, so
	 * a buyer who ignores every offer still pays exactly what the screen said.
	 *
	 * @param string $pricing_id    The Freemius pricing row.
	 * @param string $billing_cycle 'annual' or 'lifetime'.
	 */
	public static function checkout_url( string $pricing_id, string $billing_cycle ): string {
		return add_query_arg(
			[
				'pricing_id'    => rawurlencode( $pricing_id ),
				'billing_cycle' => rawurlencode( $billing_cycle ),
			],
			sprintf( 'https://checkout.freemius.com/plugin/%s/plan/%s/', self::PLUGIN_ID, self::PLAN_ID )
		);
	}

	/**
	 * Drop the cached list, so the next read goes out to the feed.
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The validated feed, or null if there isn't one to be had.
	 *
	 * @return array{plans: list<array<string, mixed>>, includes: list<string>, note: string}|null
	 */
	private function feed(): ?array {
		$cached = get_transient( self::TRANSIENT );

		// A cached failure is stored as the empty string, which is not an array
		// and so is not mistaken for a list of no plans.
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( '' === $cached ) {
			return null;
		}

		$parsed = self::parse( $this->read() );

		if ( null === $parsed ) {
			set_transient( self::TRANSIENT, '', self::FAILURE_SECONDS );

			return null;
		}

		set_transient( self::TRANSIENT, $parsed, self::CACHE_SECONDS );

		return $parsed;
	}

	/**
	 * The raw feed body, or null if it could not be read.
	 */
	private function read(): ?string {
		if ( null !== $this->fetcher ) {
			$body = ( $this->fetcher )();

			return is_string( $body ) ? $body : null;
		}

		$response = wp_remote_get(
			self::FEED_URL,
			[
				'timeout'    => 5,
				'user-agent' => 'SiteHelm/' . ( defined( 'SITEHELM_VERSION' ) ? SITEHELM_VERSION : '0' ),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Read a feed body into the shape the screen wants, or reject it whole.
	 *
	 * Every plan is checked before any plan is kept. A feed carrying three good
	 * rows and one malformed one is a feed nobody should be shown, because the
	 * screen cannot tell which of the four the buyer was going to pick.
	 *
	 * @param string|null $body The response body.
	 *
	 * @return array{plans: list<array<string, mixed>>, includes: list<string>, note: string}|null
	 */
	private static function parse( ?string $body ): ?array {
		if ( null === $body || '' === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || self::FEED_VERSION !== ( $data['version'] ?? null ) ) {
			return null;
		}

		if ( ! isset( $data['plans'] ) || ! is_array( $data['plans'] ) || [] === $data['plans'] ) {
			return null;
		}

		$plans = [];

		foreach ( $data['plans'] as $raw ) {
			$plan = self::plan( $raw );

			if ( null === $plan ) {
				return null;
			}

			$plans[] = $plan;
		}

		return [
			'plans'    => $plans,
			'includes' => self::strings( $data['includes'] ?? null ) ?? self::FALLBACK_INCLUDES,
			'note'     => is_string( $data['note'] ?? null ) && '' !== $data['note'] ? $data['note'] : self::FALLBACK_NOTE,
		];
	}

	/**
	 * One plan from the feed, or null if it is not one.
	 *
	 * @param mixed $raw The decoded entry.
	 *
	 * @return array{id: string, name: string, sites: string, who: string, featured: bool, pricingId: string, annual: array{list: float, now: float}, lifetime: array{list: float, now: float}|null}|null
	 */
	private static function plan( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		foreach ( [ 'id', 'name', 'sites', 'who', 'pricingId' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) || '' === $raw[ $key ] ) {
				return null;
			}
		}

		$annual = self::price( $raw['annual'] ?? null );

		if ( null === $annual ) {
			return null;
		}

		// A pricing id is put into a checkout URL. Anything but the digits
		// Freemius issues is a feed being read as instructions.
		if ( 1 !== preg_match( '/^[0-9]{1,20}$/', $raw['pricingId'] ) ) {
			return null;
		}

		$lifetime = array_key_exists( 'lifetime', $raw ) && null !== $raw['lifetime']
			? self::price( $raw['lifetime'] )
			: null;

		if ( array_key_exists( 'lifetime', $raw ) && null !== $raw['lifetime'] && null === $lifetime ) {
			return null;
		}

		return [
			'id'        => $raw['id'],
			'name'      => $raw['name'],
			'sites'     => $raw['sites'],
			'who'       => $raw['who'],
			'featured'  => true === ( $raw['featured'] ?? false ),
			'pricingId' => $raw['pricingId'],
			'annual'    => $annual,
			'lifetime'  => $lifetime,
		];
	}

	/**
	 * A list/now pair, or null if it is not one.
	 *
	 * @param mixed $raw The decoded pair.
	 *
	 * @return array{list: float, now: float}|null
	 */
	private static function price( $raw ): ?array {
		if ( ! is_array( $raw ) || ! isset( $raw['list'], $raw['now'] ) ) {
			return null;
		}

		if ( ! is_numeric( $raw['list'] ) || ! is_numeric( $raw['now'] ) ) {
			return null;
		}

		$list = (float) $raw['list'];
		$now  = (float) $raw['now'];

		// A price of nothing, a negative price, or a "discount" that costs more
		// than the anchor is a feed to disbelieve rather than to render.
		if ( $now <= 0.0 || $list < $now ) {
			return null;
		}

		return [
			'list' => $list,
			'now'  => $now,
		];
	}

	/**
	 * A list of non-empty strings, or null.
	 *
	 * @param mixed $raw The decoded value.
	 *
	 * @return list<string>|null
	 */
	private static function strings( $raw ): ?array {
		if ( ! is_array( $raw ) || [] === $raw ) {
			return null;
		}

		$strings = [];

		foreach ( $raw as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}

			$strings[] = $value;
		}

		return $strings;
	}

	/**
	 * A price as the screen prints it: whole dollars stay whole.
	 *
	 * @param float $amount The amount.
	 */
	public static function money( float $amount ): string {
		return 0.0 === fmod( $amount, 1.0 )
			? '$' . number_format_i18n( $amount )
			: '$' . number_format_i18n( $amount, 2 );
	}
}
