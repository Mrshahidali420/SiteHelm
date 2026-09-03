<?php
/**
 * Proof Key for Code Exchange, S256 only.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Verifies that whoever redeems an authorization code is the same party that
 * requested it.
 *
 * `plain` is refused outright rather than supported and discouraged: a plain
 * challenge is the verifier, so an attacker who can read the authorize request
 * can redeem the code, which is the entire attack PKCE exists to stop.
 *
 * @package SiteHelm
 */
final class Pkce {

	/**
	 * The only challenge method this server will accept.
	 */
	public const METHOD = 'S256';

	/**
	 * RFC 7636 §4.1 bounds on a code verifier.
	 */
	private const MIN_VERIFIER = 43;
	private const MAX_VERIFIER = 128;

	/**
	 * Whether a string is a well-formed code verifier.
	 *
	 * @param string $verifier The verifier as the client sent it.
	 *
	 * @return bool True when the length and alphabet are legal.
	 */
	public function accepts( string $verifier ): bool {
		$length = strlen( $verifier );

		if ( $length < self::MIN_VERIFIER || $length > self::MAX_VERIFIER ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9\-._~]+$/', $verifier );
	}

	/**
	 * Whether a challenge is a well-formed S256 challenge.
	 *
	 * A challenge is the base64url of a 32-byte digest, so it is always exactly
	 * 43 characters. Anything else is either a `plain` challenge wearing the
	 * S256 label or a truncated one, and both are refused.
	 *
	 * @param string $challenge The challenge as the client sent it.
	 *
	 * @return bool True when the shape is right.
	 */
	public function wellFormedChallenge( string $challenge ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.
		return 43 === strlen( $challenge ) && 1 === preg_match( '/^[A-Za-z0-9\-_]+$/', $challenge );
	}

	/**
	 * Whether a verifier satisfies a challenge.
	 *
	 * @param string $verifier  The verifier presented at the token endpoint.
	 * @param string $challenge The challenge recorded at the authorize endpoint.
	 *
	 * @return bool True when they match.
	 */
	public function verify( string $verifier, string $challenge ): bool {
		if ( ! $this->accepts( $verifier ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 defines the S256 challenge as base64url of the digest; the encoding is the specification.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return hash_equals( $challenge, $computed );
	}
}
