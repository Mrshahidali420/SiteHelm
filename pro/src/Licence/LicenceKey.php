<?php
/**
 * Signed licence keys — parsed and verified offline.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Licence;

/**
 * A licence key is `SHP1.<payload>.<signature>`: a base64url JSON payload
 * and its Ed25519 signature over the raw payload bytes. The payload carries
 * `site` (the host the key is for, or `*`), `plan`, `exp` (a `Y-m-d` date
 * or null for a lifetime key) and `id`. No server is consulted: the public
 * key below verifies the signature, so a forged or edited key fails here.
 */
final class LicenceKey {

	public const PREFIX = 'SHP1';

	/** Hex-encoded Ed25519 public key the issuing tool signs against. */
	public const PUBLIC_KEY = '511929b3e54f55e6571e910be010bf72a06da9eef164307b39078a88a1b9a5ba';

	/**
	 * Parse and verify a key.
	 *
	 * @param string      $key        The key as the user typed it.
	 * @param string|null $public_key Hex public key; the shipped one by default.
	 * @return array{site: string, plan: string, exp: ?string, id: string}|null Null when the key is malformed, forged, or incomplete.
	 */
	public static function parse( string $key, ?string $public_key = null ): ?array {
		$parts = explode( '.', trim( $key ) );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}

		$payload   = self::decode( $parts[1] );
		$signature = self::decode( $parts[2] );
		$public    = @hex2bin( $public_key ?? self::PUBLIC_KEY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad hex key is a "not verified", not a warning.

		if ( null === $payload || null === $signature || false === $public
			|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature )
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public ) ) {
			return null;
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $payload, $public ) ) {
			return null;
		}

		$data = json_decode( $payload, true );
		if ( ! is_array( $data )
			|| ! is_string( $data['site'] ?? null ) || '' === $data['site']
			|| ! is_string( $data['plan'] ?? null ) || '' === $data['plan']
			|| ! is_string( $data['id'] ?? null ) || '' === $data['id']
			|| ! ( null === ( $data['exp'] ?? null ) || ( is_string( $data['exp'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['exp'] ) ) ) ) {
			return null;
		}

		return [
			'site' => strtolower( $data['site'] ),
			'plan' => $data['plan'],
			'exp'  => $data['exp'] ?? null,
			'id'   => $data['id'],
		];
	}

	/**
	 * Build a key from a payload and a secret key — the issuing side.
	 *
	 * @param array<string, mixed> $payload    site, plan, exp, id.
	 * @param string               $secret_key Hex-encoded Ed25519 secret key.
	 */
	public static function issue( array $payload, string $secret_key ): string {
		$json      = (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the CLI issuing tool runs outside WordPress.
		$signature = sodium_crypto_sign_detached( $json, (string) hex2bin( $secret_key ) );

		return self::PREFIX . '.' . self::encode( $json ) . '.' . self::encode( $signature );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw Bytes.
	 */
	private static function encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of a signed payload, not obfuscation.
	}

	/**
	 * Inverse of {@see self::encode()}; null when the text is not base64url.
	 *
	 * @param string $text Encoded text.
	 */
	private static function decode( string $text ): ?string {
		if ( '' === $text || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
			return null;
		}
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of a signed payload.

		return false === $raw ? null : $raw;
	}
}
