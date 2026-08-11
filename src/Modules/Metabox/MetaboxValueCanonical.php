<?php
/**
 * The one place a value being WRITTEN becomes a digest-stable projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

/**
 * Projects a value an operator wants written into its one canonical spelling.
 *
 * THE MIRROR IMAGE OF MetaboxValueNormalizer, AND DELIBERATELY NOT THE SAME
 * FUNCTION. The normalizer takes what Meta Box hands BACK and shapes it for a
 * response, redacting and truncating as a report may; this takes what a client
 * sends IN and turns it into the one spelling a digest can be taken over. Merging
 * them would drag the read side's redaction into a write path, and a value that
 * was redacted on its way into a payload is a value the operator never approved.
 *
 * IT IS PURE, AND THAT IS THE WHOLE POINT. No clock, no globals, no Meta Box call,
 * no WordPress function. `PlanAdmission::assertPayloadMatches()` digests this
 * projection when a change is PREVIEWED and compares the digest when it is
 * APPLIED, which are two separate requests in two separate processes. Anything
 * ambient reaching in here would make an unchanged plan fail admission, or — far
 * worse — make two different payloads digest alike and apply something the
 * operator never approved.
 *
 * IT DISPATCHES ON THE RUNTIME SHAPE OF THE VALUE AND NEVER ON THE FIELD TYPE.
 * There is no `$field['type']` here and no definition passed in for one to be read
 * from. Meta Box ships dozens of field types and a site can register more, but a
 * value has only a handful of shapes, and the canonical spelling of a list of clone
 * rows does not depend on whether the field declaring it is called `group` or
 * something a third party invented.
 *
 * THE TWO ARRAY RULES ARE WHAT MAKE THE DIGEST STABLE:
 *
 *   - An array whose keys are all integers is a LIST and is re-indexed. Meta Box
 *     hands clone rows back carrying whatever integer keys the editor's last delete
 *     left behind, and `[ 0 => 'a', 2 => 'b' ]` JSON-encodes as an object where
 *     `[ 'a', 'b' ]` encodes as an array — the same two rows, two digests.
 *   - Any other array is a MAP and is key-sorted. JSON preserves insertion order,
 *     so two clients sending the same row with its members in a different order
 *     would otherwise digest differently and one of them would be refused at apply
 *     for a change nobody made. `PayloadNormalizer::normalize()` ksorts only
 *     non-list arrays, so the list branch has to sort its own keys before the
 *     re-index or a reordered list would pass untouched into the sha256.
 *
 * BOOLEANS BECOME 1 AND 0 because that is what a postmeta row holds for a checkbox
 * or a switch. A payload previewed as `true` and read back as `1` is the same value
 * spelled two ways, and only one of them can be the one the digest is taken over.
 *
 * Nothing here names an RWMB symbol (spec §4). It is handed a value the caller sent
 * and never asks the plugin anything.
 *
 * @package SiteHelm
 */
final class MetaboxValueCanonical {

	/**
	 * The canonical spelling of one value being written.
	 *
	 * THE DEPTH CAP IS MetaboxSchemaFormat::MAX_DEPTH, the same bound the read side
	 * reports to, so a value read out of a post and sent straight back in is not cut
	 * at two different levels by the two halves of one round trip. There is no second
	 * cap declared anywhere in this module.
	 *
	 * A SCALAR IS PROJECTED AT ANY DEPTH, which is why the scalar branch runs before
	 * the cap: the cap exists to bound a WALK, and a leaf is not a walk. Blanking a
	 * string that happens to sit exactly at the cap would drop a value the operator
	 * really sent from a request that still reported success.
	 *
	 * AT THE CAP A STRUCTURE BECOMES null AND NEVER A SENTINEL STRING, for the reason
	 * the normalizer records: a sentinel is indistinguishable from a string a site
	 * really stores, and this projection is what a digest is taken over.
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

		if ( $depth >= MetaboxSchemaFormat::MAX_DEPTH ) {
			return null;
		}

		// AT THE SAME DEPTH, NOT ONE DEEPER. Unwrapping an object is the same step
		// expressed differently, and charging a level for it would make an object and
		// the map it flattens to project differently — so the digest of one write
		// would depend on whether the client sent a JSON object or an equivalent PHP
		// array. `get_object_vars()` called from outside a class reads public
		// properties only.
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
	 * Whether project() would answer null for something that is not null.
	 *
	 * THE QUESTION A SNAPSHOT HAS TO ASK BEFORE IT RECORDS. The projection is lossy
	 * by design at the depth cap: a structure sitting at MetaboxSchemaFormat::MAX_DEPTH
	 * becomes null, which is indistinguishable from a field that really holds nothing.
	 * That is an acceptable trade for a value a caller sent, which is echoed back
	 * beside the request that produced it — and an unacceptable one for a value being
	 * recorded to roll BACK to, because restore() would then write null over content
	 * the operator still had and report success for doing it.
	 *
	 * It mirrors project() branch for branch and answers over the same walk, so the
	 * two cannot drift into disagreeing about where the cut falls. It is pure for the
	 * same reason project() is, and it reads no value out — only shapes.
	 *
	 * A resource answers true as well: project() turns it into null, and a recording
	 * that cannot carry it is unfaithful for the same reason a capped structure is.
	 *
	 * @param mixed $value The value to examine.
	 * @param int   $depth How deep this value sits; 0 for the field's own value.
	 *
	 * @return bool True when projecting this value would lose part of it.
	 */
	public function truncates( mixed $value, int $depth = 0 ): bool {
		if ( is_bool( $value ) || null === $value || is_scalar( $value ) ) {
			return false;
		}

		if ( $depth >= MetaboxSchemaFormat::MAX_DEPTH ) {
			return true;
		}

		// At the same depth, for the reason project() unwraps an object there.
		if ( is_object( $value ) ) {
			return $this->any_member_truncates( get_object_vars( $value ), $depth );
		}

		if ( is_array( $value ) ) {
			return $this->any_member_truncates( $value, $depth );
		}

		return true;
	}

	/**
	 * Whether a stored value is the value a plan promised.
	 *
	 * THE DROPPED-WRITE GUARD ASKS THIS AND NOTHING ELSE ASKS IT. `rwmb_set_meta()`
	 * returns void (spec §5), so the only evidence a write landed is the value that
	 * comes back out — and a comparison made with `===` would refuse almost every
	 * correct write, because postmeta is a text column and an integer 5 sent in comes
	 * back out as the string '5'. Two tolerances are allowed here and no others:
	 *
	 *   - THE EMPTY FORMS ARE ONE VALUE. `null`, `''` and `[]` are the three
	 *     spellings of "this field holds nothing", and `rwmb_meta()` answers `''` for
	 *     a field with no row at all (spec §5). A request to clear a field is
	 *     therefore satisfied by any of them, and treating them apart would refuse
	 *     every clear as a dropped write.
	 *   - A SCALAR IS COMPARED BY ITS STRING SPELLING, which is the coercion the
	 *     storage itself performs, and only after both sides have been projected — so
	 *     a boolean is already 1 or 0 and never the empty string PHP would cast false
	 *     to.
	 *
	 * Structures are compared member by member over BOTH canonical projections, so
	 * key order plays no part; a member on one side and not the other is a
	 * difference, which is what catches a write that stored a truncated structure.
	 *
	 * @param mixed $promised The canonical value the approved plan promised.
	 * @param mixed $stored   The canonical projection of what was read back.
	 *
	 * @return bool True when the stored value is the promised one.
	 */
	public function matches( mixed $promised, mixed $stored ): bool {
		if ( $this->is_empty_form( $promised ) && $this->is_empty_form( $stored ) ) {
			return true;
		}

		if ( is_array( $promised ) !== is_array( $stored ) ) {
			return false;
		}

		if ( ! is_array( $promised ) || ! is_array( $stored ) ) {
			// Both are scalars or nulls, and the empty-form branch above has already
			// answered every pairing either of them could be null in.
			return is_scalar( $promised )
				&& is_scalar( $stored )
				&& (string) $promised === (string) $stored;
		}

		if ( count( $promised ) !== count( $stored ) ) {
			return false;
		}

		foreach ( $promised as $key => $member ) {
			if ( ! array_key_exists( $key, $stored ) || ! $this->matches( $member, $stored[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a canonical value is one of the forms a site stores as nothing at all.
	 *
	 * `0` and `false` are deliberately absent: both project to a stored `0`, which is
	 * a value Meta Box writes a row for and an operator can see on the editing screen.
	 *
	 * @param mixed $value The canonical projection.
	 *
	 * @return bool True when the value asks for nothing to be stored.
	 */
	private function is_empty_form( mixed $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * Whether any member of an array would be lost by the projection.
	 *
	 * @param array<array-key, mixed> $value The array to examine.
	 * @param int                     $depth How deep it sits.
	 *
	 * @return bool True when at least one member would be lost.
	 */
	private function any_member_truncates( array $value, int $depth ): bool {
		foreach ( $value as $member ) {
			if ( $this->truncates( $member, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The canonical spelling of an array's members, list rule or map rule.
	 *
	 * THE LIST TEST IS "EVERY KEY IS AN INTEGER" AND NOT array_is_list().
	 * `array_is_list()` is false for `[ 0 => 'a', 2 => 'b' ]`, which is precisely the
	 * gapped list this rule exists to close; treating it as a map would sort it by key
	 * and leave the gap in place, and the two spellings of those two rows would still
	 * digest apart.
	 *
	 * THE LIST SORT IS SORT_NUMERIC because a positional array's keys are all integers
	 * by construction, and sorting them as strings would order 10 before 2.
	 *
	 * THE MAP SORT IS SORT_STRING because a map's keys can be integers and strings at
	 * once. PHP's default comparison for a mixed set is not a total order a later PHP
	 * version is obliged to keep, and a sort whose result could change under the
	 * runtime is not a sort a stored digest can be compared against.
	 *
	 * The sort runs AFTER the members are projected, over the keys alone, so no value
	 * takes part in the ordering.
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
			// as a stale plan no operator can diagnose.
			ksort( $projected, SORT_NUMERIC );

			return array_values( $projected );
		}

		ksort( $projected, SORT_STRING );

		return $projected;
	}

	/**
	 * Whether an array is a list of rows rather than a map of named members.
	 *
	 * An empty array is a list, and is answered as one so that clearing a clonable
	 * field projects to `[]` rather than to an empty map — the two encode identically
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
