<?php
/**
 * The one switch that turns OAuth on and off.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Reads and writes the enable flag.
 *
 * The default is not stored: it is computed, and it is "on when this site is
 * served over HTTPS". A site that cannot carry a bearer token safely never
 * advertises that it can, and an operator who fixes their TLS does not then
 * have to find a checkbox. Once anyone touches the switch the stored value
 * wins, in both directions.
 *
 * @package SiteHelm
 */
final class AuthSettings {

	/**
	 * The stored flag. Absent means "use the HTTPS default".
	 */
	public const OPTION = 'sitehelm_oauth_enabled';

	/**
	 * Constructs the reader.
	 *
	 * @param PublicUrl|null $urls The address resolver; null for a fresh one.
	 */
	public function __construct( private ?PublicUrl $urls = null ) {
		$this->urls = $urls ?? new PublicUrl();
	}

	/**
	 * Whether the OAuth surfaces exist on this site.
	 *
	 * @return bool True when the routes answer and bearer tokens are accepted.
	 */
	public function enabled(): bool {
		$stored = get_option( self::OPTION, null );

		if ( null === $stored || '' === $stored ) {
			return $this->urls->isSecure();
		}

		return (bool) $stored;
	}

	/**
	 * Sets the flag explicitly.
	 *
	 * @param bool $on Whether OAuth should be available.
	 *
	 * @return bool True when the option was written.
	 */
	public function set( bool $on ): bool {
		return (bool) update_option( self::OPTION, $on ? '1' : '0', true );
	}
}
