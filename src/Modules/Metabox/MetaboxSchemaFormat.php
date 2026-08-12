<?php
/**
 * Projects a Meta Box field definition into the shape the schema declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

/**
 * Turns one Meta Box field definition array into one response object, and holds the
 * vocabulary the module's field responses share.
 *
 * THE INVERSION FIRST, BECAUSE EVERYTHING ELSE HERE DEPENDS ON IT. A Meta Box field
 * definition stores the META KEY in `id` and the HUMAN LABEL in `name` (spec §5).
 * That is backwards from what a reader assumes, and it is the single mistake this
 * module is most likely to make: a projection that swapped them would emit a response
 * whose identifiers are display strings, and every later operation takes its input
 * from this response. The two members are therefore never read through a shared
 * helper that could be pointed at the wrong one — each is named where it is read, and
 * every schema description below says which is which in words.
 *
 * A DEFINITION IS A THIRD-PARTY-WRITABLE, SPARSE, snake_case ARRAY, the same three
 * hazards AcfSchemaFormat absorbs for ACF, and they are answered the same way:
 *
 * THIRD-PARTY-WRITABLE — Meta Box's groups are declared through the public
 * `rwmb_meta_boxes` filter and their settings pass through `rwmb_meta_box_settings`,
 * so anything at all can sit in any position. Nothing here is cast blind: `(string)`
 * on an array is a fatal, and a fatal inside a read is worse than a member reported
 * empty. A member that fails its shape check is dropped, not guessed at.
 *
 * SPARSE — a key's absence and a key's emptiness are different states, and the
 * projection preserves the difference. `description: ''` on a field that declares no
 * description asserts that the field declares a description of nothing, a claim the
 * site never made, so the member is omitted instead.
 *
 * snake_case — two spellings of every optional member, related by ONE private table
 * below and nowhere else, so the pair cannot drift the way two match arms in two
 * methods would.
 *
 * THE DEPTH BOUND IS REPORTED, NOT ONLY APPLIED. Meta Box's `group` fields nest as
 * far as an administrator cares to build and clonable groups multiply that, so an
 * uncapped recursion is an unbounded response. At the cap the flat projection is
 * returned, `subFields` is not emitted AT ALL — an empty list is the claim "this
 * field has no children", which at the cap is false — and `truncated` is emitted so a
 * cut tree is distinguishable from a genuinely shallow one (spec §9).
 *
 * THIS FILE NAMES NO RWMB SYMBOL and calls no WordPress function. It is handed arrays
 * and answers arrays, which is what makes it testable without stubbing anything and
 * what keeps the containment rule (spec §4) cheap to hold.
 *
 * IT ALSO HOLDS THE MODULE'S SHARED FIELD VOCABULARY — the provider name, the two
 * bounds, the version ranges and the recursive schema fragment — for the reason
 * AcfFields records: a field-definition projection appears in more than one
 * operation's response, and two copies of a schema is two schemas that drift. The ACF
 * module put them in a file of their own; this module's file structure declares no
 * such file, and the spec's own table calls this class the module's SHARED
 * field-definition formatting, so the vocabulary lives beside the projection that
 * emits it rather than in a file the plan does not have.
 *
 * @package SiteHelm
 */
final class MetaboxSchemaFormat {

	/**
	 * The provider name every Metabox response reports.
	 *
	 * REQ-0048 through REQ-0051 are the Meta Box half of a `fields-*` dispatcher pair
	 * ACF already occupies, so a response has to say whose field vocabulary its
	 * identifiers belong to. `subtitle` is a meta key to Meta Box and nothing at all
	 * to ACF, whose keys look like `field_5f3a1b2c`.
	 */
	public const PROVIDER = 'metabox';

	/**
	 * How deep the recursive field-definition projection descends.
	 *
	 * TEN, AND THE FIELD AT DEPTH TEN IS INSIDE THE BOUND. The outermost field sits
	 * at depth 0, so eleven levels are reported and the twelfth is not. Ten is chosen
	 * as a bound no genuine field group reaches — three or four levels of nesting is
	 * already an unusual design — rather than as a number tuned to any site, and it
	 * is the same bound ACF's projection and the value normalization use so that one
	 * number describes the module's whole depth posture (spec §9).
	 */
	public const MAX_DEPTH = 10;

	/**
	 * How many field groups one listing reports.
	 *
	 * The same kind of bound one axis out. A site declaring a group per post type per
	 * language reaches the low hundreds legitimately, so this is a ceiling on the
	 * response rather than a claim about what is reasonable, and a listing that
	 * reaches it says so rather than ending silently.
	 */
	public const MAX_GROUPS = 200;

	/**
	 * The `$defs` name the recursive field-definition schema is published under.
	 *
	 * A recursive schema has to name itself to point at itself, and the name has to be
	 * identical in the `$defs` key and in every `$ref` that reaches it. It is a
	 * constant so those two spellings cannot drift.
	 */
	public const FIELD_SCHEMA_DEF = 'metaboxFieldSchema';

	/**
	 * The three string members every projected field carries, in emission order.
	 *
	 * `required` is the fourth always-present member and is not in this table because
	 * it is a boolean rather than a string; it is emitted directly in formatField()
	 * right after this loop.
	 *
	 * They are always present because a field with no readable label is still a field
	 * and a client walking the list needs a uniform shape to walk. A member that
	 * cannot be read answers empty rather than being dropped, which is the opposite of
	 * the optional members' rule and deliberately so: these four are required by the
	 * schema.
	 */
	private const ALWAYS_PRESENT = [ 'id', 'name', 'type' ];

	/**
	 * The one table relating Meta Box's stored spelling to the emitted one.
	 *
	 * Keyed by Meta Box's source key; each entry is the camelCase output key and the
	 * shape rule that reads it. One table rather than one branch per member, so adding
	 * a member is one line and the two spellings are stated once.
	 *
	 * The kinds, which also decide what counts as a declaration — see declares():
	 *  - `text`   a scalar read as a string; a non-scalar is dropped.
	 *  - `map`    an array read as a list of value/label pairs, dropping any entry
	 *             whose label cannot be read.
	 *  - `raw`    passed through untouched, because its type follows the field's.
	 *             ZERO-VALUED IS STILL DECLARED: a number field's `std => 0` and a
	 *             checkbox's `std => false` are real answers, so this kind is tested
	 *             with isset() rather than with emptiness.
	 *  - `flag`   a boolean; only ever emitted true, since a lowered flag is not a
	 *             declaration.
	 *  - `number` a bound, kept in whatever numeric or string form Meta Box stored.
	 *             Also tested with isset(): `min => 0` is a bound, not an absence.
	 *  - `names`  a list of identifiers, normalized from Meta Box's string-or-list.
	 *
	 * `clone` IS META BOX'S SPELLING OF REPETITION and is emitted as `clonable`. The
	 * stored word is a PHP reserved word in every position but an array key, and a
	 * response member called `clone` would be the one member of this schema a client
	 * generating code from it could not name.
	 */
	private const OPTIONAL_MEMBERS = [
		'desc'      => [ 'description', 'text' ],
		'std'       => [ 'defaultValue', 'raw' ],
		'options'   => [ 'options', 'map' ],
		'min'       => [ 'min', 'number' ],
		'max'       => [ 'max', 'number' ],
		'multiple'  => [ 'multiple', 'flag' ],
		'clone'     => [ 'clonable', 'flag' ],
		'post_type' => [ 'postType', 'names' ],
		'taxonomy'  => [ 'taxonomy', 'names' ],
	];

	/**
	 * The member Meta Box files a field's children under.
	 *
	 * `fields`, the same word a GROUP files its fields under, which is why the output
	 * member is renamed to `subFields`: a response in which `fields` means one thing on
	 * a group and another on a field inside it is a response a client has to
	 * special-case by nesting level.
	 */
	private const CHILDREN_MEMBER = 'fields';

	/**
	 * The members that carry a position on the server's disk, in lower case.
	 *
	 * DECLARED HERE BECAUSE MORE THAN ONE PATH EMITS A VALUE. Spec §8 forbids a
	 * filesystem path in ANY envelope this plugin emits, and the module has two value
	 * projections: MetaboxValueNormalizer, which shapes what a read returns, and
	 * MetaboxValueCanonical, which shapes the before-state, the plan payload and the
	 * read-back of a write. A rule spelled in one of them is a rule the other leaks
	 * through, and this module already shipped exactly that: an attachment field's
	 * `path` reached the write's projected before-state, which is fingerprinted,
	 * previewed and returned to the caller.
	 *
	 * IT IS A LIST OF MEMBER NAMES AND NOT A TEST OF THE VALUE. A string that looks
	 * like a path is data an operator may have stored on purpose; the member name is
	 * what says the site put a server location there. `url` and `full_url` are
	 * deliberately absent: a URL is the addressable half of an attachment and is what
	 * the caller came for.
	 *
	 * `path` is what Meta Box's own file and image info arrays publish. `full_path`
	 * and `file_path` are the spellings that travel beside `full_url` in the values
	 * custom field classes and `rwmb_*_value` filters assemble, and `dir`, `basedir`
	 * and `basepath` are the upload-directory members such a value is built from.
	 *
	 * @var string[]
	 */
	private const SERVER_PATH_MEMBERS = [
		'path',
		'full_path',
		'file_path',
		'dir',
		'basedir',
		'basepath',
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether a member name carries a position on the server's disk.
	 *
	 * THE ONE PREDICATE BOTH VALUE PROJECTIONS ASK. Neither of them may answer this
	 * question itself: two spellings of "which members are a disclosure" is how one
	 * channel comes to strip a member the other publishes.
	 *
	 * COMPARED IN LOWER CASE AND ONLY FOR STRING KEYS, at every depth. A numeric key
	 * is a row index and can never be one of these names; a `Path` written by a filter
	 * that capitalises its members is the same disclosure as a `path`.
	 *
	 * @param int|string $key The member name.
	 *
	 * @return bool True when the member must not be emitted.
	 */
	public static function isServerPathMember( int|string $key ): bool {
		return is_string( $key ) && in_array( strtolower( $key ), self::SERVER_PATH_MEMBERS, true );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Projects one field definition, recursing into its children.
	 *
	 * THE DEPTH PARAMETER IS THE BOUND ON THE WHOLE RESPONSE. At the cap the flat
	 * projection is returned and `subFields` is not emitted at all; `truncated` is
	 * emitted in its place, and only when there were children to lose. A field that
	 * sits at the cap and genuinely has none reports no truncation, which is what keeps
	 * the flag a statement about the CUT rather than about the depth.
	 *
	 * @param array<string, mixed> $field The Meta Box field definition array.
	 * @param int                  $depth How deep this field sits; 0 at the top.
	 *
	 * @return array<string, mixed> The projected field definition.
	 */
	public function formatField( array $field, int $depth = 0 ): array {
		$projection = [];

		foreach ( self::ALWAYS_PRESENT as $member ) {
			$value = $field[ $member ] ?? '';

			$projection[ $member ] = is_scalar( $value ) ? (string) $value : '';
		}

		// Meta Box stores this as a boolean, as 1, or not at all depending on how the
		// group was declared, so the projection is where the spellings become the one
		// the schema publishes.
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

		$children = $field[ self::CHILDREN_MEMBER ] ?? null;

		if ( ! is_array( $children ) || [] === $children ) {
			return $projection;
		}

		if ( $depth >= self::MAX_DEPTH ) {
			$projection['truncated'] = true;

			return $projection;
		}

		$projection['subFields'] = $this->children( $children, $depth + 1 );

		return $projection;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether any field in a projected tree was cut off at the depth cap.
	 *
	 * THE REASON `truncated` IS WORTH EMITTING AT ALL. The flag sits on the field that
	 * lost its children, which may be ten levels inside the response, and a listing has
	 * to raise ONE notice about a whole group rather than expecting a client to walk
	 * for it. This walks instead, over the PROJECTED tree rather than the stored one,
	 * so it cannot disagree with what was emitted.
	 *
	 * @param array<array-key, mixed> $fields Projected field definitions.
	 *
	 * @return bool True when any field in the tree reports truncation.
	 */
	public static function containsTruncation( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( ! empty( $field['truncated'] ) ) {
				return true;
			}

			$children = $field['subFields'] ?? null;

			if ( is_array( $children ) && self::containsTruncation( $children ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The version ranges every Metabox operation declares.
	 *
	 * EVERY OperationDefinition in this module MUST carry the `metabox` range:
	 * ModuleId::Metabox is in OperationDefinition::PLUGIN_BACKED_MODULES and the
	 * constructor throws without it. Definitions call this method rather than writing a
	 * literal, so raising MetaboxPresence::MIN_VERSION raises every operation's floor
	 * at once and cannot be applied to three of four.
	 *
	 * @return array<string, string> Range expressions keyed by component.
	 */
	public static function supportedVersions(): array {
		return [
			'wordpress' => '>=' . SITEHELM_MIN_WP,
			'metabox'   => '>=' . MetaboxPresence::MIN_VERSION,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The recursive schema for one field definition.
	 *
	 * Published under FIELD_SCHEMA_DEF in the emitting operation's `$defs` and reached
	 * from there by `$ref`, which is the only way a schema can describe a structure
	 * containing itself. An operation using this fragment must declare both an `$id`
	 * and the `$defs` entry; a `$ref` with no document to resolve against resolves to
	 * nothing.
	 *
	 * ONLY THE FOUR ALWAYS-PRESENT MEMBERS ARE REQUIRED. The rest are declared so a
	 * client knows their shape when they appear and omitted from the response when the
	 * field does not declare them.
	 *
	 * @return array<string, mixed> A JSON Schema fragment.
	 */
	public static function fieldSchemaSchema(): array {
		return [
			'type'                 => 'object',
			'description'          => 'One Meta Box field definition. Group fields carry subFields recursively, to a depth of ' . self::MAX_DEPTH . '.',
			'additionalProperties' => false,
			'required'             => [ 'id', 'name', 'type', 'required' ],
			'properties'           => array_merge(
				[
					'id'        => [
						'type'        => 'string',
						'description' => 'The field identifier, which is the META KEY the value is stored under and the identifier every other Meta Box operation takes as input. It is NOT the human label; that is name.',
					],
					'name'      => [
						'type'        => 'string',
						'description' => 'The HUMAN LABEL shown in the editor. It is NOT the meta key; that is id. Meta Box\'s naming is inverted from the usual reading.',
					],
					'type'      => [
						'type'        => 'string',
						'description' => 'The Meta Box field type, for example text, select, image_advanced, or group.',
					],
					'required'  => [
						'type'        => 'boolean',
						'description' => 'Whether Meta Box marks the field required in the editor.',
					],
					'subFields' => [
						'type'        => 'array',
						'description' => 'Child field definitions, for the group type. Absent at the depth cap, where the children exist but are not reported — truncated says so when that happens. Meta Box stores them under `fields`; the response renames them so the member means one thing at every nesting level.',
						'items'       => [ '$ref' => '#/$defs/' . self::FIELD_SCHEMA_DEF ],
					],
					'truncated' => [
						'type'        => 'boolean',
						'description' => 'Present and true only when this field has children that were not reported because the depth cap was reached. Absent otherwise, so a cut tree is distinguishable from a genuinely shallow one.',
					],
				],
				self::optionalMemberSchemas()
			),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The declarations for the members a field carries only when it declares them.
	 *
	 * Split out of fieldSchemaSchema() to keep that method readable, not because
	 * anything else needs them. Private for that reason.
	 *
	 * @return array<string, mixed> Schema fragments keyed by output member name.
	 */
	private static function optionalMemberSchemas(): array {
		return [
			'description'  => [
				'type'        => 'string',
				'description' => 'The editor help text Meta Box stores as desc.',
			],
			'defaultValue' => [
				'description' => 'The value Meta Box applies when none is stored, its std setting. Its type follows the field type, so none is declared here.',
			],
			'options'      => [
				'type'        => 'array',
				'description' => 'Selectable values, each as a stored value with the label shown beside it. A list rather than the map Meta Box stores, because the keys are administrator-chosen text no closed schema can declare, and a numeric-looking key cannot survive as an object key in PHP at all.',
				'items'       => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'value', 'label' ],
					'properties'           => [
						'value' => [
							'type'        => 'string',
							'description' => 'The value Meta Box stores when this option is selected.',
						],
						'label' => [
							'type'        => 'string',
							'description' => 'The label shown in the editor.',
						],
					],
				],
			],
			'min'          => [
				'type'        => [ 'number', 'string' ],
				'description' => 'The lower bound, as Meta Box stored it. Editor input is stored verbatim, so a string is a real answer.',
			],
			'max'          => [
				'type'        => [ 'number', 'string' ],
				'description' => 'The upper bound, as Meta Box stored it.',
			],
			'multiple'     => [
				'type'        => 'boolean',
				'description' => 'Present and true when the field accepts more than one value.',
			],
			'clonable'     => [
				'type'        => 'boolean',
				'description' => 'Present and true when the field is repeatable. Meta Box stores this as clone; the response renames it because clone is a PHP reserved word a generated client could not name.',
			],
			'postType'     => [
				'type'        => 'array',
				'description' => 'Post types the field selects from.',
				'items'       => [ 'type' => 'string' ],
			],
			'taxonomy'     => [
				'type'        => 'array',
				'description' => 'Taxonomies the field selects from.',
				'items'       => [ 'type' => 'string' ],
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether the field declares a member, as opposed to merely carrying the key.
	 *
	 * EMPTINESS IS NOT ABSENCE FOR EVERY KIND, AND THAT IS THE POINT OF $kind. A number
	 * field really does declare `min => 0`, and a checkbox really does default to
	 * `false`; both are falsy and neither is an absence. Reading them with emptiness
	 * would drop a stated bound and a stated default from the response and make the
	 * projection say the field declared nothing. The two kinds whose value range
	 * includes falsy values — `number` and `raw` — are therefore tested with isset(),
	 * which still treats a stored null as no declaration. Every other kind keeps the
	 * emptiness rule, because '' is not a description, [] is not an option list, and 0
	 * is not a raised flag.
	 *
	 * @param array<string, mixed> $field  The field definition.
	 * @param string               $member The source key.
	 * @param string               $kind   The member's kind.
	 *
	 * @return bool True when the member holds a declaration.
	 */
	private function declares( array $field, string $member, string $kind ): bool {
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
	 * caller then omits the member. Dropping beats guessing: an `options` that is a
	 * string is not an option list, and reporting one built from it would be an
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
				// still written as a cast rather than a literal so the member reads as
				// what it is rather than as a constant someone will later mistake for
				// a bug.
				return (bool) $value;

			case 'number':
				return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) ? $value : null;

			case 'map':
				return is_array( $value ) ? $this->options( $value ) : null;

			case 'names':
				return $this->names( $value );

			default:
				// 'raw'. The default value's type follows the field type — an image
				// field defaults to an attachment id, a checkbox list to a list — so
				// there is no shape to check it against and none is invented.
				return $value;
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Reads an options map as a LIST of value/label pairs.
	 *
	 * A LIST AND NOT AN OBJECT. Meta Box stores options keyed by the stored value, and
	 * those keys are administrator-chosen text that no `additionalProperties: false`
	 * schema can declare. Worse, the commonest option set of all — `[ 1 => 'Yes',
	 * 2 => 'No' ]` — cannot survive as an object in PHP at all: an array key that looks
	 * like an integer IS an integer, so a projection keyed by value re-numbers itself
	 * into a list and `json_encode` emits `["Yes","No"]`, silently violating the object
	 * the schema published. Emitting a list of pairs closes that hole in the structure
	 * rather than leaning on an encoding flag every future caller has to remember.
	 *
	 * An entry whose label cannot be read is dropped; `(string)` on an array is a fatal
	 * and a label invented from one would be a fabrication rather than a reading. A map
	 * whose entries are all unreadable answers null so the member is omitted, because
	 * an empty list would claim the field offers no options.
	 *
	 * @param array<array-key, mixed> $options The stored map.
	 *
	 * @return array[]|null Pairs of stored value and displayed label, or null.
	 */
	private function options( array $options ): ?array {
		$read = [];

		foreach ( $options as $value => $label ) {
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
	 * Normalizes Meta Box's string-or-list identifier members into a list.
	 *
	 * `post_type` and `taxonomy` are accepted by Meta Box as a bare string as readily
	 * as as an array, and the schema declares one shape. An empty result answers null
	 * so the member is omitted rather than emitted as an empty list, which would claim
	 * the field selects from no post types at all.
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
	 * `rwmb_meta_boxes` is a public filter, so a malformed child is an ordinary outcome
	 * and a TypeError in the middle of a read would lose the whole group over one bad
	 * entry.
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
				$children[] = $this->formatField( $child, $depth );
			}
		}

		return $children;
	}
}
