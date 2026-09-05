<?php
/**
 * Normalizes advertised JSON Schema fragments for the wire.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use stdClass;

/**
 * Makes an operation description safe to encode as JSON Schema.
 *
 * PHP cannot tell an empty object from an empty list, so an operation that takes
 * no arguments would advertise `"properties": []` and be rejected by a strict
 * client. Two surfaces advertise schemas — the dispatcher catalog and the
 * on-demand schema lookup — and they must agree, so the rule lives here rather
 * than in either of them.
 *
 * @package SiteHelm
 */
final class SchemaShape {

	/**
	 * JSON Schema members whose value must serialize as an object, never a list.
	 */
	private const OBJECT_VALUED_KEYS = [ 'properties' ];

	/**
	 * Members of a usage example whose value must serialize as an object.
	 */
	private const OBJECT_VALUED_EXAMPLE_KEYS = [ 'arguments' ];

	/**
	 * Replaces empty arrays with objects for the keys whose JSON Schema type must
	 * be an object.
	 *
	 * Only `properties` anywhere, and `arguments` directly inside an example, are
	 * converted. List-valued members such as `required` are left untouched:
	 * JSON_FORCE_OBJECT would wrongly convert those as well.
	 *
	 * An example reaches this method two ways: alone under `example`, or as one
	 * numbered member of the `examples` list a catalog entry publishes. Both have
	 * to be recognised, or an example with no arguments would serialize as `[]`
	 * in the list and as `{}` on its own — the same operation described two ways
	 * by the same catalog.
	 *
	 * @param array<array-key, mixed> $value           The value to normalize.
	 * @param bool                    $inside_example  Whether this array is a usage example.
	 * @param bool                    $is_example_list Whether this array is the list of examples.
	 *
	 * @return array<array-key, mixed> The normalized value.
	 */
	public static function normalize( array $value, bool $inside_example = false, bool $is_example_list = false ): array {
		$normalized = [];

		foreach ( $value as $key => $member ) {
			if ( ! is_array( $member ) ) {
				$normalized[ $key ] = $member;
				continue;
			}

			$must_be_object = in_array( $key, self::OBJECT_VALUED_KEYS, true )
				|| ( $inside_example && in_array( $key, self::OBJECT_VALUED_EXAMPLE_KEYS, true ) );

			if ( [] === $member && $must_be_object ) {
				$normalized[ $key ] = new stdClass();
				continue;
			}

			$member_is_example = 'example' === $key || ( $is_example_list && is_int( $key ) );

			$normalized[ $key ] = self::normalize( $member, $member_is_example, 'examples' === $key );
		}

		return $normalized;
	}
}
