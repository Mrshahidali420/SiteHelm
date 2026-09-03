<?php
/**
 * Minting and hashing the opaque secrets the OAuth flow issues.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * The only place in the plugin that produces OAuth randomness.
 *
 * Every secret this class mints is 32 bytes from the CSPRNG rendered as
 * unpadded base64url — 43 characters, no prefix, no structure, nothing a client
 * or a log reader can decode. Nothing is ever stored in that form: callers
 * persist {@see self::fingerprint()} and compare with `hash_equals`, so a stolen
 * database row cannot be replayed as a credential.
 *
 * @package SiteHelm
 */
final class TokenFactory {

	/**
	 * Bytes of entropy behind every issued secret.
	 */
	private const ENTROPY_BYTES = 32;

	/**
	 * Bytes of entropy behind every public identifier.
	 */
	private const IDENTIFIER_BYTES = 12;

	/**
	 * Mints one opaque secret.
	 *
	 * @return string 43 characters of base64url.
	 */
	public function mint(): string {
		return self::encode( random_bytes( self::ENTROPY_BYTES ) );
	}

	/**
	 * Mints one public identifier.
	 *
	 * An identifier is not a secret — it travels in query strings and is written
	 * to the activity log — but it still has to be unguessable enough that
	 * nobody can enumerate the registrations on a site. Hex, because it survives
	 * a URL, a log line and a copy-paste without ever needing escaping.
	 *
	 * @param string $prefix A short human-readable prefix.
	 *
	 * @return string The identifier.
	 */
	public function identifier( string $prefix ): string {
		return $prefix . bin2hex( random_bytes( self::IDENTIFIER_BYTES ) );
	}

	/**
	 * The at-rest form of a secret.
	 *
	 * @param string $secret The raw secret as the client holds it.
	 *
	 * @return string 64 lowercase hex characters.
	 */
	public function fingerprint( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * Renders bytes as unpadded base64url.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string The URL-safe rendering.
	 */
	private static function encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is the wire format RFC 6749 tokens are defined in; this is an encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
