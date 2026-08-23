<?php
/**
 * The site's Pro licence: stored key, its state, and the gate Pro units call.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Licence;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Reads the stored key and answers one question: may Pro run on this site?
 *
 * States: `missing` (no key), `invalid` (malformed or forged), `other_site`
 * (signed for a different host), `expired`, `active`. Only `active` opens the
 * gate. The host comparison strips a leading `www.` and is case-insensitive;
 * a key whose `site` is `*` fits any host.
 */
final class Licence {

	public const OPTION = 'sitehelm_pro_licence';

	public const STATE_MISSING    = 'missing';
	public const STATE_INVALID    = 'invalid';
	public const STATE_OTHER_SITE = 'other_site';
	public const STATE_EXPIRED    = 'expired';
	public const STATE_ACTIVE     = 'active';

	/**
	 * Choose the verifying key and the clock.
	 *
	 * @param string|null   $public_key Hex public key override, for tests.
	 * @param callable|null $today      Returns today's `Y-m-d`; wall clock by default.
	 */
	public function __construct(
		private readonly ?string $public_key = null,
		private $today = null,
	) {
	}

	/**
	 * The stored key, or '' when none is stored.
	 */
	public function key(): string {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Store a key as typed; validity is judged on read, so a bad key shows
	 * its reason on the Health tab instead of vanishing.
	 *
	 * @param string $key The key.
	 */
	public function save( string $key ): void {
		update_option( self::OPTION, trim( $key ), false );
	}

	/**
	 * Forget the stored key.
	 */
	public function remove(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The verified payload of the stored key, or null.
	 *
	 * @return array{site: string, plan: string, exp: ?string, id: string}|null
	 */
	public function payload(): ?array {
		$key = $this->key();

		return '' === $key ? null : LicenceKey::parse( $key, $this->public_key );
	}

	/**
	 * One of the STATE_* values for the stored key on this site.
	 */
	public function state(): string {
		$key = $this->key();
		if ( '' === $key ) {
			return self::STATE_MISSING;
		}

		$payload = LicenceKey::parse( $key, $this->public_key );
		if ( null === $payload ) {
			return self::STATE_INVALID;
		}
		if ( '*' !== $payload['site'] && self::host() !== $payload['site'] ) {
			return self::STATE_OTHER_SITE;
		}
		if ( null !== $payload['exp'] && $payload['exp'] < $this->today() ) {
			return self::STATE_EXPIRED;
		}

		return self::STATE_ACTIVE;
	}

	/**
	 * True only when the stored key is valid for this site today.
	 */
	public function active(): bool {
		return self::STATE_ACTIVE === $this->state();
	}

	/**
	 * Refuse a Pro operation on an unlicensed site.
	 *
	 * @throws OperationException When the licence is not active.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function gate(): void {
		if ( $this->active() ) {
			return;
		}

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			'This operation belongs to SiteHelm Pro and the site has no active Pro licence.',
			'A site administrator can enter the licence key on the Health tab of the SiteHelm console.'
		);
	}

	/**
	 * This site's host as licences name it: lower-case, no leading `www.`.
	 */
	public static function host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Today's date, `Y-m-d`, in UTC.
	 */
	private function today(): string {
		return null !== $this->today ? (string) ( $this->today )() : gmdate( 'Y-m-d' );
	}
}
