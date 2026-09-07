<?php
/**
 * Normalizes operation payloads for the wire.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use stdClass;

/**
 * Makes a payload member that a schema declares an object serialize as one.
 *
 * PHP cannot tell an empty map from an empty list, and json_encode resolves the
 * ambiguity against us: an empty array goes out as `[]`. A member a schema
 * declares `"type": "object"` then reaches the client as an array, and a client
 * that validates the response rejects it — over a case that means nothing more
 * than "this post stores no custom fields".
 *
 * SchemaShape does this for the schemas an operation advertises. This does it
 * for the payloads an operation answers with. They are the same rule on the two
 * halves of the same promise, and until this class existed only the first half
 * had one: ten producers each wrote the coercion by hand, six correctly and
 * four not at all.
 *
 * @package SiteHelm
 */
final class PayloadShape {

	/**
	 * The value to answer with for a member a schema declares an object.
	 *
	 * An empty map becomes a stdClass so it encodes as `{}`. Everything else is
	 * returned untouched: a populated map already encodes as an object, and this
	 * class has no business rewriting what an operation read off the site.
	 *
	 * @param array<array-key, mixed> $map The member's value.
	 *
	 * @return array<array-key, mixed>|stdClass The value to put on the wire.
	 */
	public static function map( array $map ): array|stdClass {
		return [] === $map ? new stdClass() : $map;
	}
}
