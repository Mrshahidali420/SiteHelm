<?php
/**
 * Short-lived, single-use authorization codes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Holds an approved consent for the few minutes between the browser redirect
 * and the client's token request.
 *
 * Codes live in transients rather than a table: they are not a record of
 * anything, they are a handoff, and a row that must be deleted on read is worse
 * bookkeeping than a key that expires on its own.
 *
 * Two properties matter. The key is the sha256 of the code, never the code, so
 * a leaked options row cannot be replayed. And a read deletes before it
 * returns, so a code presented twice is refused the second time even if both
 * requests arrive at once.
 *
 * Five minutes, not one. Command-line clients print a URL and wait for a person
 * to paste something back, and a minute is not enough time for that.
 *
 * @package SiteHelm
 */
final class AuthorizationCodes {

	/**
	 * How long an approved consent may be redeemed for.
	 */
	public const TTL_SECONDS = 300;

	/**
	 * Prefix on the transient key.
	 */
	private const PREFIX = 'sitehelm_oauth_code_';

	/**
	 * Constructs the store.
	 *
	 * @param TokenFactory $factory The randomness source.
	 */
	public function __construct( private readonly TokenFactory $factory ) {
	}

	/**
	 * Mints and stores one code.
	 *
	 * @param array<string, mixed> $grant What the administrator approved.
	 *
	 * @return string The code to put on the redirect. Never stored in this form.
	 */
	public function issue( array $grant ): string {
		$code = $this->factory->mint();

		set_transient( self::PREFIX . $this->factory->fingerprint( $code ), $grant, self::TTL_SECONDS );

		return $code;
	}

	/**
	 * Reads and destroys one code.
	 *
	 * @param string $code The code the client presented.
	 *
	 * @return array<string, mixed>|null The approved grant, or null when the
	 *                                   code is unknown, expired or already used.
	 */
	public function consume( string $code ): ?array {
		$key   = self::PREFIX . $this->factory->fingerprint( $code );
		$grant = get_transient( $key );

		delete_transient( $key );

		return is_array( $grant ) ? $grant : null;
	}
}
