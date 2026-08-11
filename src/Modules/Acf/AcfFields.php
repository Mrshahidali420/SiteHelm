<?php
/**
 * The vocabulary every ACF operation shares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Shared constants, version ranges, and JSON Schema fragments for the ACF module.
 *
 * The fragments live here rather than beside the operations that emit them for
 * the reason ElementorFields records: a field-definition projection appears in
 * more than one operation's output, and two copies of a schema is two schemas
 * that drift. Every operation that reports a field definition points at the one
 * fragment below, so a change to the projection cannot be applied to one
 * response and forgotten in another.
 *
 * THIS FILE NAMES NO ACF SYMBOL. It reaches AcfPresence::MIN_VERSION, which is a
 * SiteHelm constant that happens to describe ACF, and that is the whole of its
 * dependency — containment (spec Decision 2) permits only AcfPresence and AcfApi
 * to name an `acf_*` function, `get_field`, or `ACF_VERSION`.
 *
 * @package SiteHelm
 */
final class AcfFields {

	/**
	 * The provider name this module reports for every field it describes.
	 *
	 * REQ-0044 through REQ-0047 are the ACF half of a `fields-*` dispatcher pair
	 * that Meta Box will later join, so a response has to say which plugin's
	 * vocabulary its field keys belong to. `field_abc123` means one thing to ACF
	 * and nothing to Meta Box.
	 */
	public const PROVIDER = 'acf';

	/**
	 * How deep the recursive field-definition projection descends.
	 *
	 * A repeater inside a group inside a flexible-content layout nests as far as
	 * an administrator cares to build, and there is no ACF-side limit at all, so
	 * an uncapped recursion here is an unbounded response and an unbounded
	 * response is a denial of service against the gateway's own memory limit.
	 *
	 * Ten is chosen as a bound no genuine field group reaches — three or four
	 * levels is already an unusual design — rather than as a number tuned to any
	 * particular site. A projection that hits it says so through the `truncated`
	 * flag and the warning list rather than ending silently.
	 */
	public const MAX_DEPTH = 10;

	/**
	 * How many field groups one listing reports.
	 *
	 * The same bound for the same reason, one axis out. A site with a group per
	 * post type per language reaches the low hundreds legitimately, so this is a
	 * ceiling on the response rather than a claim about what is reasonable.
	 */
	public const MAX_GROUPS = 200;

	/**
	 * The `$defs` name the recursive field-definition schema is published under.
	 *
	 * A recursive schema has to name itself to point at itself, and the name has
	 * to be identical in the `$defs` key and in every `$ref` that reaches it. It
	 * is a constant so those two spellings cannot drift — the ElementorFields
	 * NODE_DEF precedent.
	 */
	public const FIELD_SCHEMA_DEF = 'acfFieldSchema';

	/**
	 * The version ranges every ACF operation declares.
	 *
	 * EVERY OperationDefinition in this module MUST carry the `acf` range:
	 * ModuleId::Acf is in OperationDefinition::PLUGIN_BACKED_MODULES and the
	 * constructor throws without it. Definitions call this method rather than
	 * writing a literal, so raising AcfPresence::MIN_VERSION raises every
	 * operation's floor at once and cannot be applied to three of four.
	 *
	 * @return array<string, string> Range expressions keyed by component.
	 */
	public static function supportedVersions(): array {
		return [
			'wordpress' => '>=' . SITEHELM_MIN_WP,
			'acf'       => '>=' . AcfPresence::MIN_VERSION,
		];
	}

	/**
	 * The schema for a field group's location rules, as ACF stores them.
	 *
	 * ACF's location model is an OR of ANDs: the outer list is a set of rule
	 * groups any one of which may match, and each inner list is a set of
	 * conditions all of which must. That is why this is an array of arrays
	 * rather than a flat list, and the nesting is reported rather than flattened
	 * because flattening would turn "post type is page OR the page is the front
	 * page" into a claim the site never made.
	 *
	 * The rules are reported and not interpreted (spec Decision 5). SiteHelm
	 * does not decide which groups apply to which posts; ACF does, through
	 * `acf_get_field_groups( [ 'post_id' => ... ] )`. Reimplementing the match
	 * would produce a second answer that disagrees with the first the moment ACF
	 * adds a location type.
	 *
	 * @return array<string, mixed> A JSON Schema fragment.
	 */
	public static function locationRulesSchema(): array {
		return [
			'type'        => 'array',
			'description' => 'Location rule groups, as ACF stores them: the outer list is an OR, each inner list an AND. Reported, not interpreted.',
			'items'       => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'param' ],
					'properties'           => [
						'param'    => [
							'type'        => 'string',
							'description' => 'What the rule tests, for example post_type or page_template.',
						],
						'operator' => [
							'type'        => 'string',
							'description' => 'How it is tested, for example == or !=.',
						],
						'value'    => [
							'type'        => [ 'string', 'number', 'boolean', 'null' ],
							'description' => 'What it is tested against. Null when ACF stored no readable value.',
						],
					],
				],
			],
		];
	}

	/**
	 * The recursive schema for one field definition.
	 *
	 * Published under FIELD_SCHEMA_DEF in the emitting operation's `$defs` and
	 * reached from there by `$ref`, which is the only way a schema can describe a
	 * structure that contains itself. An operation using this fragment must
	 * therefore declare both an `$id` and the `$defs` entry; a `$ref` with no
	 * document to resolve against resolves to nothing.
	 *
	 * ONLY THE FIVE ALWAYS-PRESENT MEMBERS ARE REQUIRED. The rest are declared so
	 * a client knows their shape when they appear, and omitted from the response
	 * when the field does not declare them, because emitting `returnFormat: ''`
	 * beside a text field would assert that the field declares a return format of
	 * nothing — a different and false claim.
	 *
	 * @return array<string, mixed> A JSON Schema fragment.
	 */
	public static function fieldSchemaSchema(): array {
		return [
			'type'                 => 'object',
			'description'          => 'One ACF field definition. Container types carry subFields or layouts, recursively, to a depth of ' . self::MAX_DEPTH . '.',
			'additionalProperties' => false,
			'required'             => [ 'key', 'name', 'label', 'type', 'required' ],
			'properties'           => array_merge(
				[
					'key'      => [
						'type'        => 'string',
						'description' => 'ACF\'s field key, for example field_5f3a1b2c. The stable identifier; the name can be renamed.',
					],
					'name'     => [
						'type'        => 'string',
						'description' => 'The field name used in template code and as the meta key suffix.',
					],
					'label'    => [
						'type'        => 'string',
						'description' => 'The label shown in the editor.',
					],
					'type'     => [
						'type'        => 'string',
						'description' => 'The ACF field type, for example text, repeater, or flexible_content.',
					],
					'required' => [
						'type'        => 'boolean',
						'description' => 'Whether ACF marks the field required in the editor.',
					],
				],
				self::optionalMemberSchemas(),
				[
					'subFields' => [
						'type'        => 'array',
						'description' => 'Child field definitions, for container types such as repeater and group. Absent at the depth cap, where the children exist but are not reported.',
						'items'       => [ '$ref' => '#/$defs/' . self::FIELD_SCHEMA_DEF ],
					],
					'layouts'   => [
						'type'        => 'array',
						'description' => 'Flexible-content layouts, each with its own sub-fields. A list rather than the map ACF stores, because the map is keyed by administrator-chosen text no closed schema can declare.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'key', 'name', 'label', 'subFields' ],
							'properties'           => [
								'key'       => [ 'type' => 'string' ],
								'name'      => [ 'type' => 'string' ],
								'label'     => [ 'type' => 'string' ],
								'subFields' => [
									'type'  => 'array',
									'items' => [ '$ref' => '#/$defs/' . self::FIELD_SCHEMA_DEF ],
								],
							],
						],
					],
				]
			),
		];
	}

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
			'instructions' => [
				'type'        => 'string',
				'description' => 'The editor help text.',
			],
			'choices'      => [
				'type'                 => 'object',
				'description'          => 'Selectable values, as stored value => displayed label.',
				'additionalProperties' => [ 'type' => 'string' ],
			],
			'defaultValue' => [
				'description' => 'The value ACF applies when none is stored. Its type follows the field type, so none is declared here.',
			],
			'returnFormat' => [
				'type'        => 'string',
				'description' => 'How ACF returns the value, for example array, url, or id.',
			],
			'min'          => [
				'type'        => [ 'number', 'string' ],
				'description' => 'The lower bound, as ACF stored it. ACF stores editor input verbatim, so a string is a real answer.',
			],
			'max'          => [
				'type'        => [ 'number', 'string' ],
				'description' => 'The upper bound, as ACF stored it.',
			],
			'multiple'     => [
				'type'        => 'boolean',
				'description' => 'Whether the field accepts more than one value.',
			],
			'allowNull'    => [
				'type'        => 'boolean',
				'description' => 'Whether the field accepts an empty selection.',
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
}
