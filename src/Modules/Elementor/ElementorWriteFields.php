<?php
/**
 * The shared declarations of the Elementor write block.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\WriteOutputSchema;

/**
 * What all six Elementor writes DECLARE: the four fields their state is
 * measured in, the order a preview ranks those fields in, and the two input
 * properties every one of them accepts.
 *
 * SEPARATE FROM `ElementorFields`, and deliberately. That class is shared with
 * the read operations and projects a document for DISPLAY — `label`, `kind`,
 * `depth` and `childCount` are all computed there for a human reading a tree.
 * None of those is in a stored row, so none of them can be restored, and a
 * write that promised one would be promising something a rollback cannot put
 * back. The four fields here are the opposite kind of value: every one is
 * computable from the persisted document alone, which is what `readBack()`
 * requires — it receives a target key and nothing else, so a field it cannot
 * recompute from storage is a field verification cannot check.
 *
 * @package SiteHelm
 */
final class ElementorWriteFields {

	/**
	 * The fingerprint of the RAW stored document, as stored.
	 *
	 * The one field that answers "did the stored bytes move at all", which is
	 * the question a silent Elementor save (#98) makes it necessary to ask.
	 */
	public const FIELD_DIGEST = 'documentDigest';

	/**
	 * The number of elements in the stored tree.
	 */
	public const FIELD_COUNT = 'elementCount';

	/**
	 * The number of nesting LEVELS in the stored tree, not the greatest
	 * zero-based depth: a tree whose deepest node sits at depth 2 has three
	 * levels, and an empty tree has none. Same convention as
	 * `ElementorTree::normalize()`, because this field is read from it.
	 */
	public const FIELD_DEPTH = 'maxDepth';

	/**
	 * How many of each widget type the stored tree holds, keyed by type name.
	 */
	public const FIELD_WIDGETS = 'widgetTypeCounts';

	/**
	 * Field order for PreviewRenderer: digest, count, depth, widgets.
	 *
	 * The digest leads because it is the only field that distinguishes "the
	 * document changed" from "the document did not", and an operator reading a
	 * plan needs that before the shape totals that describe how. The three
	 * totals follow coarsest first.
	 *
	 * @var string[]
	 */
	public const FIELD_ORDER = [ self::FIELD_DIGEST, self::FIELD_COUNT, self::FIELD_DEPTH, self::FIELD_WIDGETS ];

	/**
	 * The input property naming the document to change.
	 */
	public const INPUT_DOCUMENT = 'document';

	/**
	 * The input property naming the element to act on.
	 */
	public const INPUT_ELEMENT_ID = 'elementId';

	/**
	 * The greatest number of characters a stored element id may have.
	 */
	public const ELEMENT_ID_MAX_LENGTH = 64;

	/**
	 * The form a stored element id may take, in JSON Schema's undelimited
	 * dialect.
	 *
	 * Elementor mints seven hex characters (`ElementorIdMint::ID_LENGTH`), and
	 * this accepts a wider set than that on purpose: documents imported from a
	 * template kit, or built by an older Elementor, carry ids this module did
	 * not mint and must still be able to name. What it excludes is what a tree
	 * walk must never receive — an EMPTY id, which would otherwise match every
	 * node carrying no id at all, and anything holding whitespace or a
	 * separator character.
	 */
	public const ELEMENT_ID_PATTERN = '^[A-Za-z0-9_-]{1,64}$';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The two input properties every Elementor write shares.
	 *
	 * Returned as a property MAP rather than a whole schema, so an operation
	 * merges it into its own `properties` beside whatever else it accepts and
	 * keeps one `additionalProperties => false` of its own. Six copies of these
	 * two declarations is six chances for the bound on an element id to drift.
	 *
	 * @return array<string, mixed> The shared properties, keyed by name.
	 */
	public static function documentInput(): array {
		return [
			self::INPUT_DOCUMENT   => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Identifier of the Elementor document to change. Call elementor-document-list for the documents Elementor controls.',
			],
			self::INPUT_ELEMENT_ID => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => self::ELEMENT_ID_MAX_LENGTH,
				'pattern'     => self::ELEMENT_ID_PATTERN,
				'description' => 'Stable identifier of the element to act on, exactly as elementor-document-get reports it.',
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The output schema every Elementor write declares.
	 *
	 * The SHARED union with its apply branch's `state` typed. `WriteOutputSchema`
	 * describes `state` as an untyped object because it has to cover every
	 * module at once; here the verified persisted state is exactly what
	 * `ElementorWriteTarget::fieldsFor()` returns, so the four members can be
	 * named and the object closed. A client reading the catalog then knows what
	 * an apply answers without applying one.
	 *
	 * The branch is found by looking for the one that declares `state` rather
	 * than by index, so this does not silently refine the wrong half if the
	 * shared union is ever reordered. Its test freezes the result, so a shared
	 * schema that stopped declaring `state` at all surfaces as a failure here
	 * rather than as a quiet no-op.
	 *
	 * @return array<string, mixed> The declared output schema.
	 */
	public static function outputSchema(): array {
		$schema = WriteOutputSchema::schema();

		foreach ( $schema['oneOf'] as $index => $branch ) {
			if ( array_key_exists( 'state', $branch['properties'] ?? [] ) ) {
				$schema['oneOf'][ $index ]['properties']['state'] = self::state_schema();
			}
		}

		return $schema;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The verified persisted state of one Elementor document.
	 *
	 * CLOSED, and holding exactly `FIELD_ORDER`. Adding a member here without
	 * adding it to `fieldsFor()` declares a field no response carries; adding it
	 * to `fieldsFor()` without adding it here makes every apply response fail
	 * its own declaration. Both directions are caught by the same test.
	 *
	 * `widgetTypeCounts` declares its VALUES rather than its keys, because the
	 * keys are widget type names — third-party plugins register those, so the
	 * set is open and cannot be enumerated in a schema.
	 *
	 * @return array<string, mixed> The state schema.
	 */
	private static function state_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				self::FIELD_DIGEST  => [
					'type'        => 'string',
					'description' => 'Fingerprint of the document exactly as it is stored. Two reads of an unchanged document give the same value.',
				],
				self::FIELD_COUNT   => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'How many elements the stored document holds, counting every nesting level.',
				],
				self::FIELD_DEPTH   => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'How many nesting levels the stored document has. An empty document has none.',
				],
				self::FIELD_WIDGETS => [
					'type'                 => 'object',
					'additionalProperties' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'description'          => 'How many of each widget type the stored document holds, keyed by widget type name.',
				],
			],
			'required'             => self::FIELD_ORDER,
			'additionalProperties' => false,
		];
	}
}
