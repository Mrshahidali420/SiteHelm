<?php
/**
 * Projects an ACF field definition into the shape the schema declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Turns one ACF field definition array into one response object.
 *
 * ACF stores a field definition as a sparse, snake_case, third-party-writable
 * array. Every one of those three words is a hazard this class exists to absorb:
 *
 * SPARSE means a key's absence and a key's emptiness are different states, and
 * the projection preserves the difference. A member the field does not declare
 * is omitted rather than emitted empty, because `returnFormat: ''` on a text
 * field asserts that the field declares a return format of nothing, which is a
 * claim the site never made.
 *
 * SNAKE_CASE means two spellings of every optional member. They are related by
 * ONE private table below and nowhere else, so the pair cannot drift the way two
 * match arms in two methods would.
 *
 * THIRD-PARTY-WRITABLE means `acf/load_field` — a filter any plugin may hook —
 * can put anything at all in any position. Nothing here is cast blind: `(string)`
 * on an array is a fatal and `(int)` on one is 1, and a fatal inside a read is
 * worse than a member reported empty. Every member is shape-checked and a
 * member that fails its check is dropped, not guessed at.
 *
 * THIS FILE NAMES NO ACF SYMBOL and calls no WordPress function. It is handed
 * arrays and returns arrays, which is what makes it testable without stubbing
 * anything and what keeps the containment rule (spec Decision 2) intact.
 *
 * @package SiteHelm
 */
final class AcfSchemaFormat {

	/**
	 * The four string members every projected field carries, in emission order.
	 *
	 * `required` is the fifth always-present member and is not in this table
	 * because it is a boolean read from a different stored spelling; it is
	 * emitted directly in field() right after this loop.
	 *
	 * They are always present because a field with no readable label is still a
	 * field, and a client walking the list needs a uniform shape to walk. A
	 * member that cannot be read answers empty rather than being dropped, which
	 * is the opposite of the optional members' rule and deliberately so: these
	 * five are required by the schema.
	 */
	private const ALWAYS_PRESENT = [ 'key', 'name', 'label', 'type' ];

	/**
	 * The one table relating ACF's stored spelling to the emitted one.
	 *
	 * Keyed by ACF's snake_case source key; each entry is the camelCase output
	 * key and the shape rule that reads it. One table rather than one match
	 * statement per member, so adding a member is one line and the two spellings
	 * are stated once.
	 *
	 * The kinds, which also decide what counts as a declaration — see declares():
	 *  - `text`   a scalar read as a string; a non-scalar is dropped.
	 *  - `map`    an array read as a list of value/label pairs, dropping any
	 *             entry whose label cannot be read.
	 *  - `raw`    passed through untouched, because its type follows the field's.
	 *             ZERO-VALUED IS STILL DECLARED: a number field's
	 *             `default_value => 0` and a true_false field's
	 *             `default_value => false` are real answers, so this kind is
	 *             tested with isset() and not with emptiness.
	 *  - `flag`   a boolean; only ever emitted true, since a falsy flag is not a
	 *             declaration (see the emptiness rule in declares()).
	 *  - `number` a bound, kept in whatever numeric or string form ACF stored.
	 *             Also tested with isset(): `min => 0` is a bound, not an absence.
	 *  - `names`  a list of identifiers, normalised from ACF's string-or-list.
	 */
	private const OPTIONAL_MEMBERS = [
		'instructions'  => [ 'instructions', 'text' ],
		'choices'       => [ 'choices', 'map' ],
		'default_value' => [ 'defaultValue', 'raw' ],
		'return_format' => [ 'returnFormat', 'text' ],
		'min'           => [ 'min', 'number' ],
		'max'           => [ 'max', 'number' ],
		'multiple'      => [ 'multiple', 'flag' ],
		'allow_null'    => [ 'allowNull', 'flag' ],
		'post_type'     => [ 'postType', 'names' ],
		'taxonomy'      => [ 'taxonomy', 'names' ],
	];

	/**
	 * Projects one field definition, recursing into its children.
	 *
	 * THE DEPTH PARAMETER IS THE BOUND ON THE WHOLE RESPONSE. ACF imposes no
	 * nesting limit of its own, so a repeater inside a group inside a layout can
	 * be built as deep as an administrator has patience for, and an uncapped
	 * recursion here would let one field group exhaust the request's memory. At
	 * the cap the flat projection is returned and neither `subFields` nor
	 * `layouts` is emitted AT ALL — not emitted empty. An empty list is the claim
	 * "this field has no children", and at the cap that claim is false; an absent
	 * key says only that none are reported, which is what happened.
	 *
	 * @param array<string, mixed> $field The ACF field definition array.
	 * @param int                  $depth How deep this field sits; 0 at the top.
	 *
	 * @return array<string, mixed> The projected field definition.
	 */
	public function field( array $field, int $depth = 0 ): array {
		$projection = [];

		foreach ( self::ALWAYS_PRESENT as $member ) {
			$value = $field[ $member ] ?? '';

			$projection[ $member ] = is_scalar( $value ) ? (string) $value : '';
		}

		// ACF stores this as 0 or 1, never as a boolean, so the projection is
		// where the two spellings become the one the schema declares. A client
		// comparing against `true` would otherwise find every required field
		// optional.
		$projection['required'] = ! empty( $field['required'] );

		foreach ( self::OPTIONAL_MEMBERS as $source => $target ) {
			if ( ! $this->declares( $field, $source, $target[1] ) ) {
				continue;
			}

			$read = $this->readOptional( $field[ $source ], $target[1] );

			if ( null !== $read ) {
				$projection[ $target[0] ] = $read;
			}
		}

		if ( $depth >= AcfFields::MAX_DEPTH ) {
			return $projection;
		}

		if ( $this->declares( $field, 'sub_fields' ) && is_array( $field['sub_fields'] ) ) {
			$projection['subFields'] = $this->children( $field['sub_fields'], $depth + 1 );
		}

		if ( $this->declares( $field, 'layouts' ) && is_array( $field['layouts'] ) ) {
			$projection['layouts'] = $this->layouts( $field['layouts'], $depth );
		}

		return $projection;
	}

	/**
	 * Whether the field declares a member, as opposed to merely carrying the key.
	 *
	 * ACF writes `'choices' => []`, `'instructions' => ''` and `'multiple' => 0`
	 * into every definition it saves from the editor, whether the administrator
	 * filled them in or not. Treating "the key exists" as "the field declares it"
	 * would therefore put an empty member on nearly every field in the response,
	 * which is the noise the sparse-projection rule exists to remove.
	 *
	 * EMPTINESS IS NOT ABSENCE FOR EVERY KIND, AND THAT IS THE POINT OF $kind. A
	 * number field really does declare `min => 0`, and a true_false field really
	 * does default to `false`; both are falsy and neither is an absence. Reading
	 * them with emptiness dropped a stated bound and a stated default from the
	 * response and made the projection say the field declared nothing. The two
	 * kinds whose whole value range includes falsy values — `number` and `raw` —
	 * are therefore tested with isset(), which still treats a stored null as no
	 * declaration. Every other kind keeps the emptiness rule, because '' is not
	 * an instruction, [] is not a choice list, and 0 is not a raised flag.
	 *
	 * @param array<string, mixed> $field  The field definition.
	 * @param string               $member The source key.
	 * @param string               $kind   The member's kind, or '' for the plain
	 *                                     emptiness rule used by the containers.
	 *
	 * @return bool True when the member holds a declaration.
	 */
	private function declares( array $field, string $member, string $kind = '' ): bool {
		if ( 'number' === $kind || 'raw' === $kind ) {
			return isset( $field[ $member ] );
		}

		return ! empty( $field[ $member ] );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Reads one optional member according to its kind.
	 *
	 * Answers null for a value that cannot be read in the declared shape, and the
	 * caller then omits the member. Dropping beats guessing: a `choices` that is
	 * a string is not a choices map, and reporting one built from it would be an
	 * invention rather than a reading.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $kind  One of the kinds named in OPTIONAL_MEMBERS.
	 *
	 * @return mixed The projected value, or null to omit the member.
	 */
	private function readOptional( mixed $value, string $kind ): mixed {
		switch ( $kind ) {
			case 'text':
				return is_scalar( $value ) ? (string) $value : null;

			case 'flag':
				// Only reached for a non-empty value, so this is always true. It is
				// still written as a cast rather than a literal so the member reads
				// as what it is rather than as a constant someone will later
				// mistake for a bug.
				return (bool) $value;

			case 'number':
				return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) ? $value : null;

			case 'map':
				return is_array( $value ) ? $this->choices( $value ) : null;

			case 'names':
				return $this->names( $value );

			default:
				// 'raw'. The default value's type follows the field type — an image
				// field defaults to an attachment id, a checkbox to a list — so
				// there is no shape to check it against and none is invented.
				return $value;
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Reads a choices map as a LIST of value/label pairs.
	 *
	 * A LIST AND NOT AN OBJECT, FOR THE REASON LAYOUTS ARE A LIST. ACF stores
	 * choices keyed by the stored value, and those keys are administrator-chosen
	 * text that no `additionalProperties: false` schema can declare. Worse, the
	 * commonest choice set of all — `[ 1 => 'Yes', 2 => 'No' ]` — cannot survive
	 * as an object at all in PHP: an array key that looks like an integer IS an
	 * integer, so a projection keyed by value re-numbers itself into a list and
	 * `json_encode` emits `["Yes","No"]`, silently violating the object the schema
	 * published. Emitting a list of pairs closes that hole in the structure rather
	 * than leaning on an encoding flag that every future caller has to remember.
	 *
	 * An entry whose label cannot be read is dropped; `(string)` on an array is a
	 * fatal, and a label invented from one would be a fabrication rather than a
	 * reading. A map whose entries are all unreadable answers null so the member
	 * is omitted, because an empty list would claim the field offers no choices.
	 *
	 * @param array<array-key, mixed> $choices The stored map.
	 *
	 * @return array[]|null Pairs of stored value and displayed label, or null.
	 */
	private function choices( array $choices ): ?array {
		$read = [];

		foreach ( $choices as $value => $label ) {
			if ( is_scalar( $label ) ) {
				$read[] = [
					'value' => (string) $value,
					'label' => (string) $label,
				];
			}
		}

		return [] === $read ? null : $read;
	}

	/**
	 * Normalises ACF's string-or-list identifier members into a list.
	 *
	 * `post_type` and `taxonomy` are stored as lists by the editor, but a filter
	 * or a hand-registered field group may leave a bare string, and the schema
	 * declares one shape. An empty result answers null so the member is omitted
	 * rather than emitted as an empty list, which would claim the field selects
	 * from no post types at all.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string[]|null The identifiers, or null to omit the member.
	 */
	private function names( mixed $value ): ?array {
		if ( is_string( $value ) ) {
			return [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$names = array_values( array_filter( $value, 'is_string' ) );

		return [] === $names ? null : $names;
	}

	/**
	 * Projects a list of child field definitions.
	 *
	 * A member that is not an array is dropped rather than recursed into.
	 * `acf/load_field` is a public filter, so a malformed member is an ordinary
	 * outcome, and a TypeError in the middle of a read would lose the whole
	 * group over one bad child.
	 *
	 * @param array<array-key, mixed> $fields The stored children.
	 * @param int                     $depth  The depth the children sit at.
	 *
	 * @return array[] The projected children.
	 */
	private function children( array $fields, int $depth ): array {
		$children = [];

		foreach ( $fields as $child ) {
			if ( is_array( $child ) ) {
				$children[] = $this->field( $child, $depth );
			}
		}

		return $children;
	}

	/**
	 * Projects flexible-content layouts as a list.
	 *
	 * ACF stores them as a map keyed by layout key. The response is a LIST
	 * because the map's keys are administrator-chosen text, and a schema that
	 * sets `additionalProperties: false` — as every schema in this codebase does
	 * — cannot describe an object whose property names are unknown in advance.
	 * The key is not lost; it moves into each member as `key`.
	 *
	 * `subFields` is always emitted here, unlike on a field, because a layout
	 * genuinely has a sub-field list that may be empty rather than having no such
	 * concept, and the layout schema requires it.
	 *
	 * @param array<array-key, mixed> $layouts The stored layouts.
	 * @param int                     $depth   The depth the owning field sits at.
	 *
	 * @return array[] The projected layouts.
	 */
	private function layouts( array $layouts, int $depth ): array {
		$projected = [];

		foreach ( $layouts as $key => $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$sub_fields = ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) )
				? $this->children( $layout['sub_fields'], $depth + 1 )
				: [];

			$projected[] = [
				'key'       => $this->layoutMember( $layout, 'key', (string) $key ),
				'name'      => $this->layoutMember( $layout, 'name', '' ),
				'label'     => $this->layoutMember( $layout, 'label', '' ),
				'subFields' => $sub_fields,
			];
		}

		return $projected;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Reads one scalar member of a layout, falling back when it cannot be read.
	 *
	 * The fallback for `key` is the map key ACF filed the layout under, which is
	 * the same value in every well-formed layout and the only identifier
	 * available in a malformed one.
	 *
	 * @param array<string, mixed> $layout   The stored layout.
	 * @param string               $member   The member to read.
	 * @param string               $fallback What to answer when it cannot be read.
	 *
	 * @return string The member's value.
	 */
	private function layoutMember( array $layout, string $member, string $fallback ): string {
		$value = $layout[ $member ] ?? null;

		return is_scalar( $value ) ? (string) $value : $fallback;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
