<?php
/**
 * Pruning expired tokens and abandoned registrations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Keeps the two OAuth tables from growing without limit.
 *
 * It runs two ways. The daily retention cron calls {@see self::collect()}; the
 * bearer path calls {@see self::collectThrottled()}, which does the same work at
 * most once every fifteen minutes. A site whose cron is broken — and there are
 * many — still prunes, and a site whose cron works pays for it once a day.
 *
 * The one rule that matters here is what it will not delete. A registration
 * that ever completed a consent is never pruned, whatever state its tokens are
 * in. A refresh token that simply lapsed after a month of disuse leaves a
 * registration that looks exactly like an abandoned one, and deleting it turns
 * a working saved connection into "invalid client" with nothing to point at.
 *
 * @package SiteHelm
 */
final class OAuthGarbageCollector {

	/**
	 * How long a registration that never completed a consent is kept.
	 */
	public const ABANDONED_SECONDS = 86400;

	/**
	 * The shortest gap between two opportunistic runs.
	 */
	public const THROTTLE_SECONDS = 900;

	/**
	 * The throttle transient.
	 */
	private const THROTTLE_KEY = 'sitehelm_oauth_gc';

	/**
	 * Constructs the collector.
	 *
	 * @param OAuthStore $store The OAuth store.
	 */
	public function __construct( private readonly OAuthStore $store ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Prunes both tables.
	 *
	 * @param int $now The current time.
	 *
	 * @return array<string, int> Rows deleted per table.
	 */
	public function collect( int $now ): array {
		return [
			'oauth_tokens'  => $this->store->pruneExpiredTokens( $now ),
			'oauth_clients' => $this->store->pruneNeverAuthorizedClients( $now - self::ABANDONED_SECONDS ),
		];
	}

	/**
	 * Prunes both tables, but not more than once per throttle window.
	 *
	 * @param int $now The current time.
	 *
	 * @return array<string, int>|null Rows deleted, or null when skipped.
	 */
	public function collectThrottled( int $now ): ?array {
		if ( false !== get_transient( self::THROTTLE_KEY ) ) {
			return null;
		}

		set_transient( self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS );

		return $this->collect( $now );
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
