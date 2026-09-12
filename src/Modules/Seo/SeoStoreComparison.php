<?php
/**
 * Comparing a restored SEO store against the state that was recorded.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Answers whether a store now holds what a recorded snapshot said it held.
 *
 * A recorded state does not come back from storage in the order it went in.
 * {@see \SiteHelm\Change\PayloadNormalizer::canonicalJson()} key-sorts every
 * associative array on the way in, so that logically identical states always
 * hash identically. A provider builds its live reading in its own key order
 * instead — the order of its owned keys, or the order the database returns its
 * columns — and PHP's `===` on two arrays requires the same keys in the same
 * order. Comparing the two with `===` therefore reports a mismatch whenever
 * the provider's own order is not alphabetical, even though every value
 * agrees, and a correct rollback is then reported as a failed one.
 *
 * So key ORDER is ignored and everything else is not. Lists keep their order,
 * because a list's order is part of its meaning. Values are compared with
 * `===`, so `0` and `"0"` still differ, and a key present on one side and
 * absent on the other is still a mismatch.
 *
 * @package SiteHelm
 */
trait SeoStoreComparison {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the camelCase methods of the providers that use it.
	/**
	 * Whether a live reading matches a recorded one, ignoring key order.
	 *
	 * @param mixed $current  What the store holds now.
	 * @param mixed $recorded What the snapshot recorded.
	 *
	 * @return bool True when the two say the same thing.
	 */
	protected function storeMatches( mixed $current, mixed $recorded ): bool {
		if ( ! is_array( $current ) || ! is_array( $recorded ) ) {
			return $current === $recorded;
		}

		if ( count( $current ) !== count( $recorded ) ) {
			return false;
		}

		if ( array_is_list( $current ) || array_is_list( $recorded ) ) {
			if ( ! array_is_list( $current ) || ! array_is_list( $recorded ) ) {
				return false;
			}

			foreach ( $current as $index => $member ) {
				if ( ! $this->storeMatches( $member, $recorded[ $index ] ) ) {
					return false;
				}
			}

			return true;
		}

		foreach ( $current as $key => $member ) {
			if ( ! array_key_exists( $key, $recorded ) ) {
				return false;
			}

			if ( ! $this->storeMatches( $member, $recorded[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
