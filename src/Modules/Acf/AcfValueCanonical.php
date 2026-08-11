<?php
/**
 * The one place a value being WRITTEN becomes a digest-stable projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Projects a value an operator wants written into its one canonical spelling.
 *
 * THE MIRROR IMAGE OF AcfValueNormalizer, AND DELIBERATELY NOT THE SAME FUNCTION.
 * The normalizer takes what ACF hands BACK — posts, users, terms, attachment rows —
 * and turns it into something a response can carry. This takes what a client sends
 * IN, where none of those objects can appear, and turns it into the one spelling a
 * digest can be taken over. They agree on the shapes they both see, and merging
 * them would force one function to carry the read side's WordPress projections into
 * a write path that must never invent an `id`/`title` member the caller did not
 * send.
 *
 * IT IS PURE, AND THAT IS THE WHOLE POINT. No clock, no globals, no ACF call, no
 * WordPress function. `PlanAdmission::assertPayloadMatches()` digests this
 * projection when a change is PREVIEWED and compares the digest when it is
 * APPLIED, which are two separate requests in two separate processes. Anything
 * ambient reaching in here would make an unchanged plan fail admission on a
 * Tuesday, or — far worse — make two different payloads digest alike and apply
 * something the operator never approved.
 *
 * IT DISPATCHES ON THE RUNTIME SHAPE OF THE VALUE AND NEVER ON THE FIELD TYPE
 * (spec Decision 4). There is no `$field['type']` here and there is no field
 * definition passed in for one to be read from. ACF ships more than thirty types
 * and a site can register more, but a value has only a handful of shapes, and the
 * canonical spelling of a list of rows does not depend on whether the field
 * declaring it is called `repeater` or something a third party invented.
 *
 * THE TWO ARRAY RULES ARE WHAT MAKE THE DIGEST STABLE:
 *
 *   - An array whose keys are all integers is a LIST and is re-indexed. ACF hands
 *     rows back carrying whatever integer keys the editor's last delete left
 *     behind, and `[ 0 => 'a', 2 => 'b' ]` JSON-encodes as an object where
 *     `[ 'a', 'b' ]` encodes as an array — the same two rows, two digests.
 *   - Any other array is a MAP and is key-sorted. JSON preserves insertion order,
 *     so two clients sending the same row with its members in a different order
 *     would otherwise digest differently and one of them would be refused at apply
 *     for a change nobody made.
 *
 * BOOLEANS BECOME 1 AND 0 (spec Decision 8b) because that is what ACF stores for a
 * true/false field. A payload previewed as `true` and read back as `1` is the same
 * value spelled two ways, and only one of them can be the one the digest is taken
 * over.
 *
 * Nothing here names an ACF symbol (spec Decision 2). It is handed a value the
 * caller sent and never asks the plugin anything.
 *
 * @package SiteHelm
 */
final class AcfValueCanonical {

	/**
	 * The canonical spelling of one value being written.
	 *
	 * THE DEPTH CAP IS AcfFields::MAX_DEPTH, the same bound the read side reports
	 * to, so a value read out of a post and sent straight back in is not cut at two
	 * different levels by the two halves of one round trip.
	 *
	 * A SCALAR IS PROJECTED AT ANY DEPTH, which is why the scalar branch runs
	 * before the cap: the cap exists to bound a WALK, and a leaf is not a walk.
	 * Blanking a string that happens to sit exactly at the cap would drop a value
	 * the operator really sent from a request that still reported success.
	 *
	 * AT THE CAP A STRUCTURE BECOMES null AND NEVER A SENTINEL STRING, for the
	 * reason the normalizer records: a sentinel is indistinguishable from a string
	 * a site really stores, and this projection is what a digest is taken over.
	 *
	 * @param mixed $value The value the caller sent, of any shape.
	 * @param int   $depth How deep this value sits; 0 for the field's own value.
	 *
	 * @return mixed The canonical projection.
	 */
	public function project( mixed $value, int $depth = 0 ): mixed {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}

		if ( $depth >= AcfFields::MAX_DEPTH ) {
			return null;
		}

		// AT THE SAME DEPTH, NOT ONE DEEPER. Unwrapping an object is the same step
		// expressed differently, and charging a level for it would make an object
		// and the map it flattens to project differently — so the digest of one
		// write would depend on whether the client sent a JSON object or an
		// equivalent PHP array. `get_object_vars()` called from outside a class
		// reads public properties only.
		if ( is_object( $value ) ) {
			return $this->members( get_object_vars( $value ), $depth );
		}

		if ( is_array( $value ) ) {
			return $this->members( $value, $depth );
		}

		// A resource, or anything else PHP can hold that JSON cannot carry. Never
		// cast: `(string)` on a resource is the text 'Resource id #3', a value the
		// site does not store arriving in the payload as though it did.
		return null;
	}

	/**
	 * The canonical spelling of an array's members, list rule or map rule.
	 *
	 * THE LIST TEST IS "EVERY KEY IS AN INTEGER" AND NOT array_is_list().
	 * `array_is_list()` is false for `[ 0 => 'a', 2 => 'b' ]`, which is precisely
	 * the gapped list this rule exists to close; treating it as a map would sort it
	 * by key and leave the gap in place, and the two spellings of those two rows
	 * would still digest apart.
	 *
	 * THE LIST SORT IS SORT_NUMERIC because a positional array's keys are all
	 * integers by construction, and sorting them as strings would order 10 before 2.
	 *
	 * THE MAP SORT IS SORT_STRING because a map's keys can be integers and strings
	 * at once. PHP's default comparison for a mixed set is not a total order a
	 * later PHP version is obliged to keep, and a sort whose result could change
	 * under the runtime is not a sort a stored digest can be compared against.
	 *
	 * The sort runs AFTER the members are projected, over the keys alone, so no
	 * value takes part in the ordering.
	 *
	 * @param array<array-key, mixed> $value The array to project.
	 * @param int                     $depth How deep it sits.
	 *
	 * @return mixed[] The projected members.
	 */
	private function members( array $value, int $depth ): array {
		$projected = [];

		foreach ( $value as $key => $member ) {
			$projected[ $key ] = $this->project( $member, $depth + 1 );
		}

		if ( $this->is_positional( $value ) ) {
			// SORTED BEFORE IT IS RE-INDEXED, not merely re-indexed. Insertion order
			// and key order are not the same thing: `{"2":"a","0":"b"}` decodes to
			// `[ 2 => 'a', 0 => 'b' ]` and `{"0":"b","2":"a"}` to `[ 0 => 'b', 2 => 'a' ]`,
			// so array_values() alone answers ['a','b'] for one and ['b','a'] for the
			// other — two JSON spellings of one value digesting apart, which surfaces
			// as a stale_plan no operator can diagnose.
			ksort( $projected, SORT_NUMERIC );

			return array_values( $projected );
		}

		ksort( $projected, SORT_STRING );

		return $projected;
	}

	/**
	 * Whether an array is a list of rows rather than a map of named members.
	 *
	 * An empty array is a list, and is answered as one so that clearing a repeater
	 * projects to `[]` rather than to an empty map — the two encode identically
	 * today only because PHP has one empty array, and stating the rule keeps that
	 * accident from becoming the reason.
	 *
	 * @param array<array-key, mixed> $value The array to classify.
	 *
	 * @return bool True when every key is an integer.
	 */
	private function is_positional( array $value ): bool {
		foreach ( $value as $key => $ignored ) {
			if ( ! is_int( $key ) ) {
				return false;
			}
		}

		return true;
	}
}
