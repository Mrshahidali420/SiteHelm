<?php
/**
 * Canonical ordering and hashing for change payloads and states.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * Turns any nested value into a canonical form, canonical JSON, and a
 * fingerprint, so that logically identical inputs always hash identically.
 *
 * Associative arrays are key-sorted recursively; list arrays keep their order,
 * because a list's order is part of its meaning (callers who need order-free
 * comparison sort the list before handing it over). Scalar types are never
 * coerced, so 0 and "0" remain distinguishable.
 *
 * @package SiteHelm
 */
final class PayloadNormalizer {

	/**
	 * Encoding flags. Slashes and unicode stay literal so the same text always
	 * produces the same bytes regardless of the PHP build.
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	/**
	 * Recursively canonicalizes a value.
	 *
	 * @param mixed $value The value to canonicalize.
	 *
	 * @return mixed The canonical form.
	 */
	public function normalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = [];
		foreach ( $value as $key => $member ) {
			$normalized[ $key ] = $this->normalize( $member );
		}
		if ( ! array_is_list( $normalized ) ) {
			ksort( $normalized, SORT_STRING );
		}

		return $normalized;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The canonical JSON encoding of a value.
	 *
	 * @param mixed $value The value to encode.
	 *
	 * @return string The canonical JSON.
	 */
	public function canonicalJson( mixed $value ): string {
		return (string) wp_json_encode( $this->normalize( $value ), self::JSON_FLAGS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The deterministic fingerprint of a value.
	 *
	 * @param mixed $value The value to fingerprint.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 */
	public function fingerprint( mixed $value ): string {
		return hash( 'sha256', $this->canonicalJson( $value ) );
	}
}
