<?php
/**
 * The applicable-field index for one post.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Which ACF fields apply to one post, flattened to one entry per top-level field.
 *
 * Every ACF operation that names a post starts here. `acf-field-list` reports this
 * index, `acf-field-get` reads the values of the fields in it, and
 * `acf-field-update` refuses to write anything that is not in it — so the same
 * question is answered by one object rather than three, and a post whose field set
 * one operation disagrees with another about is impossible by construction.
 *
 * ACF DOES THE LOCATION MATCHING (spec Decision 5). This class asks
 * AcfApi::groups( $post_id ) for the groups that apply to the post and takes what
 * it is given; it does not parse a location rule. ACF's rule engine covers post
 * type, page template, taxonomy term, post status, user role and a dozen more,
 * several of them filterable by third parties, and a reimplementation here would
 * disagree with ACF on some site — in the direction of offering an operator a
 * field that does not apply, or hiding one that does.
 *
 * THERE IS NO CACHE, PER REQUEST OR OTHERWISE (spec Decision 5). The index is
 * rebuilt on every call. A cache here would have to be invalidated after every
 * write, and a cache whose only job is to be invalidated correctly is a defect
 * waiting for the one path that forgets — a write that then reported the field set
 * as it stood before it ran.
 *
 * NULL AND AN EMPTY INDEX ARE DIFFERENT ANSWERS, and keeping them apart is the
 * reason forPost() returns what it returns. `null` is "the applicable groups could
 * not be read"; an index whose `fields` list is empty is "they were read and this
 * post has none". Coalescing the first into the second tells an operator a post
 * carries no custom fields when nothing was consulted at all.
 *
 * A SKIPPED GROUP IS A SECOND CHANNEL, NOT A SILENCE. One group whose field
 * definitions cannot be read must not blank the groups that can, so it is skipped
 * — but its key comes back in `skippedGroups`, because a shorter field list
 * reported as complete is the same lie as coalescing null, one group at a time.
 *
 * Nothing here names an ACF symbol. Every fact about the plugin arrives through
 * AcfApi (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfFieldIndex {

	/**
	 * The key a group whose name could not be read is recorded under.
	 *
	 * A group ACF answered with that is not an array cannot be asked for its
	 * fields and carries no readable key either. It is still a skipped group and
	 * still has to be reported, so it is recorded under a key no real group can
	 * have; the operation above counts it instead of naming it.
	 */
	public const UNNAMEABLE_GROUP = '';

	/**
	 * Constructs the index.
	 *
	 * @param AcfApi $api The one wrapper around ACF's own reads.
	 */
	public function __construct(
		private readonly AcfApi $api,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The top-level fields that apply to one post.
	 *
	 * THE RETURN IS A TWO-KEY ARRAY AND NOT A BARE LIST, because a bare list can
	 * only say what was read and not what was missed. `fields` is one entry per
	 * applicable top-level field, in the order ACF answered — group by group, field
	 * by field — and `skippedGroups` holds the key of every group whose field
	 * definitions could not be read.
	 *
	 * TOP-LEVEL FIELDS ONLY. A repeater's sub-fields and a flexible-content
	 * layout's fields stay nested inside `definition` and are reached through it.
	 * Flattening them would offer an operator names that `get_field()` cannot
	 * resolve at the top level, which is a write that fails after being planned.
	 *
	 * @param int $post_id The post whose applicable fields are wanted.
	 *
	 * @return array<string, mixed>|null `{ fields: array[], skippedGroups: string[] }`,
	 *                                   or null when the applicable groups could not
	 *                                   be read at all.
	 */
	public function forPost( int $post_id ): ?array {
		$groups = $this->api->groups( $post_id );

		// THE NULL-VERSUS-EMPTY GUARD. `$groups ?? []` here would report a post as
		// carrying no custom fields having read nothing at all.
		if ( null === $groups ) {
			return null;
		}

		$fields  = [];
		$skipped = [];
		$seen    = [];

		foreach ( $groups as $group ) {
			// Not an array: AcfApi::fields() takes one, so this group cannot even be
			// asked. It is a skip, not a silence.
			if ( ! is_array( $group ) ) {
				$skipped[] = self::UNNAMEABLE_GROUP;
				continue;
			}

			$definitions = $this->api->fields( $group );

			if ( null === $definitions ) {
				$skipped[] = $this->text( $group, 'key' );
				continue;
			}

			$this->collect( $definitions, $group, $fields, $seen );
		}

		return [
			'fields'        => $fields,
			'skippedGroups' => $skipped,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Adds one group's usable definitions to the index being built.
	 *
	 * FIRST SEEN WINS on a duplicate key. ACF permits the same key in two groups
	 * that both apply to one post, and `get_field()` resolves a key to exactly one
	 * definition; reporting it twice would offer a choice ACF does not have, and
	 * letting the last win would make this index disagree with the group listing
	 * about which group the field belongs to.
	 *
	 * @param array<array-key, mixed> $definitions The group's field definitions.
	 * @param array<string, mixed>    $group       The group they came from.
	 * @param array[]                 $fields      The index being built, by reference.
	 * @param array<string, true>     $seen        The keys already taken, by reference.
	 */
	private function collect( array $definitions, array $group, array &$fields, array &$seen ): void {
		$group_key   = $this->text( $group, 'key' );
		$group_title = $this->text( $group, 'title' );

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$key  = $this->identifier( $definition, 'key' );
			$name = $this->identifier( $definition, 'name' );

			// A field with no key is not addressable by any later operation, and one
			// with no name is not reportable beside it. Neither is cast into
			// existence: `(string)` on an array is a fatal, and a key coerced to
			// 'Array' is a row every later lookup would miss.
			if ( null === $key || null === $name || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$fields[] = [
				'key'        => $key,
				'name'       => $name,
				'label'      => $this->text( $definition, 'label' ),
				'type'       => $this->text( $definition, 'type' ),
				'required'   => $this->flag( $definition, 'required' ),
				'groupKey'   => $group_key,
				'groupTitle' => $group_title,
				// Carried whole and unprojected. The sub-fields of a repeater and the
				// layouts of a flexible-content field live in here, and the write
				// validation reads them from here rather than asking ACF twice.
				'definition' => $definition,
			];
		}
	}

	/**
	 * Reads one member as an identifier, or null when it cannot serve as one.
	 *
	 * The empty string is treated as absent rather than as an identifier: it is
	 * scalar, so a shape guard alone would let it through, and an entry keyed on it
	 * would collide with every other unkeyed entry in the dedup.
	 *
	 * @param array<string, mixed> $source The definition to read from.
	 * @param string               $member The member to read.
	 *
	 * @return string|null The identifier, or null.
	 */
	private function identifier( array $source, string $member ): ?string {
		$value = $source[ $member ] ?? null;

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = (string) $value;

		return '' === $value ? null : $value;
	}

	/**
	 * Reads one member as a string, answering '' for anything unreadable.
	 *
	 * Never a cast: `(string)` on an array is a fatal, and every value reaching
	 * this method came through a public ACF filter.
	 *
	 * @param array<string, mixed> $source The array to read from.
	 * @param string               $member The member to read.
	 *
	 * @return string The member's value, or ''.
	 */
	private function text( array $source, string $member ): string {
		$value = $source[ $member ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Reads one member as a boolean.
	 *
	 * ACF stores `required` as the integer 0 or 1. The index reports a boolean so
	 * that a client branching on `1 === $required` and one branching on
	 * `true === $required` cannot get different answers from the same site. A
	 * non-scalar is false rather than the `true` a bare cast on an array produces.
	 *
	 * @param array<string, mixed> $source The array to read from.
	 * @param string               $member The member to read.
	 *
	 * @return bool The member's truth, or false.
	 */
	private function flag( array $source, string $member ): bool {
		$value = $source[ $member ] ?? null;

		return is_scalar( $value ) && (bool) $value;
	}

	/**
	 * The index entry a name or key identifies, or null when nothing carries it.
	 *
	 * KEY IS TRIED FIRST, ACROSS THE WHOLE INDEX, BEFORE ANY NAME IS CONSIDERED. A
	 * key is assigned by ACF and unambiguous; a name is administrator-chosen text
	 * that could in principle equal a different field's key. Matching names first
	 * would hand a caller who asked for a real key the definition of some other
	 * field, and a write built on it would land on the wrong field reporting
	 * success.
	 *
	 * @param array[] $index        The index's `fields` list.
	 * @param string  $name_or_key  The identifier to resolve.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	public function find( array $index, string $name_or_key ): ?array {
		foreach ( $index as $entry ) {
			if ( is_array( $entry ) && ( $entry['key'] ?? null ) === $name_or_key ) {
				return $entry;
			}
		}

		foreach ( $index as $entry ) {
			if ( is_array( $entry ) && ( $entry['name'] ?? null ) === $name_or_key ) {
				return $entry;
			}
		}

		return null;
	}
}
