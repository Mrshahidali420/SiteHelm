<?php
/**
 * The settings-merge machinery the Elementor settings writes share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * What `elementor-element-update` and `elementor-widget-settings-update` do
 * IDENTICALLY: name an element, read the type whose schema governs it, refuse a
 * setting key that type does not declare, merge the requested values over the
 * ones already stored, and put the result back into the tree.
 *
 * SEPARATE FROM BOTH OPERATIONS FOR THE REASON `ElementorElementAddInput` IS
 * SEPARATE FROM `ElementorElementAdd`. Two copies of the elType dispatch is two
 * chances for one of them to stop checking; two copies of the merge is two
 * answers to "does an update replace the settings map or add to it"; and two
 * copies of a refusal message is a pair that drifts the first time one is
 * reworded. The difference between the two operations is the DEVICE SUFFIX and
 * nothing else, and that lives in the operation that has a device.
 *
 * THE elType DISPATCH IS THE POINT OF THIS CLASS. Elementor's own
 * `update-atomic-widget` does not check `elType` before reading a node's
 * `widgetType`, so it "succeeds" against a container by writing settings no
 * renderer will ever read — a write that verifies, reports done, and changes
 * nothing an operator can see. `node()` reads `elType` FIRST and resolves each
 * node's schema from its own registry: a widget's from the widget registry, a
 * container's, a section's and a column's from the element registry. A
 * container is therefore never checked against widget schema, and — unlike the
 * blanket refusal this class carried until the container-padding defect — its
 * padding, width, background and gap are writable.
 *
 * THE MERGE IS ADDITIVE AND THE BASE IS THE CALLER'S PROBLEM, not this class's:
 * `merged()` is handed the base it should merge over. Both operations hand it
 * the settings read at APPLY rather than the ones read at preview, which is the
 * pattern `MenuItemUpdate` establishes — a setting somebody else edited between
 * preview and apply is left alone instead of being silently reverted by an
 * operation that never claimed to touch it.
 *
 * STATELESS AND SAFE TO SHARE. Every method takes what it judges and returns
 * the judged value; nothing here remembers a call.
 *
 * @package SiteHelm
 */
final class ElementorSettingsMerge {

	/**
	 * The raw key holding a node's element kind.
	 */
	public const NODE_EL_TYPE = 'elType';

	/**
	 * The raw key holding a node's identifier.
	 */
	public const NODE_ID = 'id';

	/**
	 * The `node()` member naming the registry type whose schema governs a node.
	 *
	 * NOT A KEY ANY STORED NODE CARRIES. It is this class's own answer to "which
	 * type name should the registry be asked about", which is `widgetType` for a
	 * widget and `elType` for everything else. Naming it separately is what
	 * stops a caller from reaching for `widgetType` on a container and getting
	 * either null or, worse, a leftover import value.
	 */
	public const NODE_SCHEMA_TYPE = 'schemaType';

	/**
	 * The delimited form of the shared element-id declaration.
	 *
	 * Built from `ElementorWriteFields::ELEMENT_ID_PATTERN`, which is stored in
	 * JSON Schema's undelimited dialect, so the bound an id is checked against
	 * in code and the bound the catalog advertises are one declaration.
	 */
	public const ELEMENT_ID_REGEX = '/' . ElementorWriteFields::ELEMENT_ID_PATTERN . '/';

	/**
	 * Constructs the merge.
	 *
	 * @param ElementorTreeEdit     $edit     The raw-tree surgery primitives.
	 * @param ElementorPropCoercion $coercion The prop normalizer and key guard.
	 */
	public function __construct(
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorPropCoercion $coercion,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no caller value.
	/**
	 * The identifier of the element to change.
	 *
	 * Bounded here as well as in the schema because the value reaches a tree
	 * walk: an empty identifier would match every node carrying no id at all,
	 * which is the one input a walk must never receive.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The element identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function requestedElementId( array $input ): string {
		$element_id = $input[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? null;

		if ( ! is_string( $element_id ) || 1 !== preg_match( self::ELEMENT_ID_REGEX, $element_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The identifier of the element to change is not one a stored element can carry.',
				'Read the page with elementor-document-get and retry with an identifier it reports.'
			);
		}

		return $element_id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The node the request names, with the type whose schema governs it and the
	 * settings it currently holds.
	 *
	 * EVERY NODE IS VALIDATED AGAINST ITS OWN REGISTRY'S SCHEMA, AND A CONTAINER
	 * IS NEVER VALIDATED AGAINST WIDGET SCHEMA. That is the invariant, and it is
	 * the reason `elType` is read BEFORE `widgetType` rather than a reason to
	 * refuse a container outright. Elementor renders a container from its own
	 * settings and ignores widget settings entirely, so a container checked
	 * against a widget's vocabulary — because it carries a leftover `widgetType`
	 * from an import, or because the code simply assumed widget-ness — would
	 * accept keys no renderer reads: a write that verifies, reports done, and
	 * changes nothing an operator can see.
	 *
	 * THE REMEDY IS RESOLUTION, NOT REFUSAL. A widget's schema is read from the
	 * widget registry by its `widgetType`; every other node's is read from the
	 * element registry by its own `elType`, which is where a container, a
	 * section and a column declare their controls. Refusing every layout element
	 * instead — which this class did until the container-padding defect — left
	 * padding, width, background and gap unwritable by any operation, so a page
	 * built through this plugin could never be made full-bleed.
	 *
	 * THE ONLY REFUSAL LEFT IS A NODE WHOSE TYPE CANNOT BE READ AT ALL: a node
	 * stored as a widget that records no `widgetType`, or a node recording no
	 * usable `elType`. Both name the member that is missing, because the operator
	 * has to know which one to go and fix. A type the registry does not KNOW is
	 * a different refusal and belongs one layer down, in
	 * `ElementorPropCoercion`, which is where the registry is read.
	 *
	 * A well-formed identifier the document does not hold is `TargetNotFound`
	 * rather than `InvalidInput`, on `ElementorElementAddInput`'s reasoning: the
	 * argument was not the thing that was wrong.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The element identifier.
	 *
	 * @return array<string, mixed> Keys 'elType' (string), 'schemaType' (string,
	 *                              the registry type whose schema governs the
	 *                              node) and 'settings' (array).
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           does not hold the element, or
	 *                           ErrorCode::InvalidInput when the node records no
	 *                           readable type.
	 */
	public function node( array $tree, string $element_id ): array {
		$found = $this->edit->find( $tree, $element_id );

		if ( null === $found ) {
			throw $this->elementNotFound();
		}

		$node    = is_array( $found['node'] ?? null ) ? $found['node'] : [];
		$el_type = $node[ self::NODE_EL_TYPE ] ?? null;

		if ( ! is_string( $el_type ) || '' === $el_type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The element this request names does not record what kind of element it is, so there is no vocabulary its settings could be checked against.',
				'Open the page in the Elementor editor and re-save it so the element records its type, then retry.'
			);
		}

		$schema_type = $el_type;

		if ( ElementorElementAddInput::EL_TYPE_WIDGET === $el_type ) {
			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;

			if ( ! is_string( $widget_type ) || '' === $widget_type ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The element this request names is stored as a widget but does not record which widget it is, so its settings cannot be checked before they are written.',
					'Open the page in the Elementor editor and re-save it so the element records its widget type, then retry.'
				);
			}

			$schema_type = $widget_type;
		}

		$settings = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

		return [
			self::NODE_EL_TYPE                   => $el_type,
			self::NODE_SCHEMA_TYPE               => $schema_type,
			ElementorPropCoercion::NODE_SETTINGS => is_array( $settings ) ? $settings : [],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Refuses every requested key the node's own type does not declare.
	 *
	 * BEFORE ANY WRITE, always, and delegated whole to `ElementorPropCoercion`
	 * so there is one answer to what a type accepts. Elementor discards an
	 * unrecognised alias key instead of refusing it (#102), so a check made
	 * after the save is made on content that is already gone.
	 *
	 * IT TAKES THE WHOLE DESCRIPTOR `node()` RETURNED rather than a bare type
	 * name, and that is the guard rather than a convenience. The kind and the
	 * type name have to travel together to pick the registry; a caller that
	 * passed a container's type without its kind would have it looked up among
	 * the widgets, which is the wrong-vocabulary write this class exists to
	 * prevent.
	 *
	 * The keys checked are the ones the CALLER SENT, before any device suffix is
	 * applied, because the registry declares `padding` and never
	 * `padding_tablet`.
	 *
	 * THE NODE'S STORED SETTINGS TRAVEL WITH THE REQUEST, and this method is the
	 * reason the coercion layer accepts them at all. Elementor renders a control
	 * only while its declared `condition` holds against the settings the element
	 * WILL hold, so the renderability half of that check needs the stored side as
	 * well as the requested one. This is the only caller that has the stored side
	 * — `node()` already put it there, at the cost of nothing, so no extra read
	 * happens here — and passing it is what stops a partial update being refused
	 * for omitting a companion switcher the element has held since the day it was
	 * created. A caller with genuinely no stored side (a new element, a
	 * whole-tree build) passes none, and its requested map is correctly the
	 * effective one.
	 *
	 * THE SIGNATURE IS UNCHANGED ON PURPOSE. Every caller here already hands over
	 * the whole descriptor, so the stored settings were always in reach; making
	 * them a parameter would have put the burden of remembering them on four call
	 * sites and given each one a way to forget.
	 *
	 * A DEVICE-SUFFIXED REQUEST IS JUDGED ON ITS BASE-NAMED KEYS AND THAT IS
	 * CORRECT: conditions reference base control names, so `padding` is the name
	 * a condition would cite. A condition satisfied only on one breakpoint is a
	 * shape the gate does not model and it fails open there, per its own rules.
	 *
	 * @param array<string, mixed> $node     The descriptor `node()` returned.
	 * @param array<string, mixed> $settings The caller's requested settings.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a key is not
	 *                            declared or would be stored without rendering,
	 *                            or ErrorCode::ExecutionFailed when the type's
	 *                            schema cannot be read.
	 */
	public function assertKnownKeys( array $node, array $settings ): void {
		$stored = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? [];

		$this->coercion->assertKnownKeys(
			(string) ( $node[ self::NODE_SCHEMA_TYPE ] ?? '' ),
			$settings,
			(string) ( $node[ self::NODE_EL_TYPE ] ?? '' ),
			is_array( $stored ) ? $stored : []
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The advisories this write earns, in the node shape every caller here holds.
	 *
	 * THE STORED SIDE IS NOT PASSED, and that is the difference from
	 * `assertKnownKeys()`. A condition is judged against the settings the
	 * element WILL hold, so the stored half is load-bearing there. A media value
	 * is judged on itself: an image the document already holds without an
	 * attachment is the site's own history, and re-reporting it on every
	 * unrelated write to the same widget would make the advisory noise.
	 *
	 * @param array<string, mixed> $node     The descriptor `node()` returned.
	 * @param array<string, mixed> $settings The caller's requested settings.
	 *
	 * @return array<int, string> The advisories, empty when there are none.
	 */
	public function mediaWarnings( array $node, array $settings ): array {
		return $this->coercion->mediaWarnings(
			(string) ( $node[ self::NODE_SCHEMA_TYPE ] ?? '' ),
			$settings,
			(string) ( $node[ self::NODE_EL_TYPE ] ?? '' )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The requested values laid over the ones already stored.
	 *
	 * ADDITIVE, never replacing: a key the request does not mention keeps the
	 * value the document holds, which is what makes this a partial update rather
	 * than a settings overwrite. `array_merge()` is deliberately not used —
	 * a setting key that is a decimal string would be renumbered by it, which
	 * would silently move the value to a key no widget declares.
	 *
	 * Key ORDER is the stored order followed by the requested keys the document
	 * did not already hold, which is a function of the two inputs alone. Two
	 * merges of the same pair therefore produce the same order, and the digest
	 * taken over the encoded result is stable.
	 *
	 * @param array<string, mixed> $stored    The settings the document holds.
	 * @param array<string, mixed> $requested The settings this change asks for.
	 *
	 * @return array<string, mixed> The merged settings.
	 */
	public function merged( array $stored, array $requested ): array {
		foreach ( $requested as $key => $value ) {
			$stored[ $key ] = $value;
		}

		return $stored;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * A copy of the tree with one element's settings replaced.
	 *
	 * A REPLACEMENT RATHER THAN A REMOVE-AND-INSERT, because remove-and-insert
	 * would drop the element's children and its position and then have to put
	 * both back — two more chances to lose something than editing the one member
	 * that is changing.
	 *
	 * The argument is never mutated, so a caller keeps the pre-merge tree for a
	 * diff.
	 *
	 * @param array[]              $tree       The raw stored tree.
	 * @param string               $element_id The element to change.
	 * @param array<string, mixed> $settings   The settings to store on it.
	 *
	 * @return array[] The new tree.
	 */
	public function withSettings( array $tree, string $element_id, array $settings ): array {
		foreach ( $tree as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( ( $node[ self::NODE_ID ] ?? null ) === $element_id ) {
				$node[ ElementorPropCoercion::NODE_SETTINGS ] = $settings;
				$tree[ $index ]                               = $node;

				continue;
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( is_array( $children ) ) {
				$node[ ElementorPropCoercion::NODE_CHILDREN ] = $this->withSettings( $children, $element_id, $settings );
				$tree[ $index ]                               = $node;
			}
		}

		return $tree;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The digest of the stored document as it was BEFORE the write.
	 *
	 * READ OUT OF THE SNAPSHOT rather than computed here.
	 * `ElementorDocumentWriter::write()` compares this value against one it
	 * computes itself after the save, and that comparison is the whole of the
	 * silent-save defence: two values produced by two formulas would make every
	 * write look silent, or none ever look silent.
	 * `ElementorWriteTarget::snapshot()` records the digest with
	 * `ElementorDocumentWriter::storedDigest()`'s formula, so threading the
	 * recorded value through keeps one formula on both sides. The fallback is
	 * that same formula read a second time, not a second rule.
	 *
	 * @param array<string, mixed>|null $snapshot The captured snapshot, if any.
	 * @param int                       $post_id  The document's post identifier.
	 *
	 * @return string The pre-write digest.
	 */
	public function priorDigest( ?array $snapshot, int $post_id ): string {
		$recorded = $snapshot[ ElementorWriteFields::FIELD_DIGEST ] ?? null;

		return is_string( $recorded ) ? $recorded : ElementorDocumentWriter::storedDigest( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether a value carries nothing at all.
	 *
	 * `0`, `0.0` and `false` are NOT blank. Every one of them is a value a
	 * setting legitimately holds — a zero margin, a disabled toggle — and
	 * folding them in with the empty string would make the post-write check
	 * refuse writes that stored exactly what they were asked to.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when the value holds nothing.
	 */
	public function isBlank( mixed $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Names a setting key, or describes it when it cannot safely be named.
	 *
	 * An operator has to learn WHICH setting is missing, and a key is
	 * caller-supplied text an envelope must not echo unbounded. The bound is
	 * `ElementorPropCoercion`'s own, so a key that class would quote is a key
	 * this one quotes.
	 *
	 * @param string $key The setting key.
	 *
	 * @return string The rendering.
	 */
	public function describeKey( string $key ): string {
		return 1 === preg_match( ElementorPropCoercion::KEY_PATTERN, $key )
			? '"' . $key . '"'
			: 'one of the requested';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The refusal a document Elementor does not control produces.
	 *
	 * The same conflated vocabulary `ElementorWriteTarget` uses for its own
	 * not-found: a caller must not be able to learn from the difference between
	 * two refusals whether a page they may not touch exists.
	 *
	 * @return OperationException The refusal.
	 */
	public function documentNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The refusal an element the document does not hold produces.
	 *
	 * @return OperationException The refusal.
	 */
	public function elementNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'This page holds no element with the identifier the request names, so there is nothing to change.',
			'Read the page with elementor-document-get and retry with an identifier it reports.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The refusal an element that left the page between preview and apply
	 * produces.
	 *
	 * `Conflict` RATHER THAN `TargetNotFound`, and the difference is the whole
	 * message: the element WAS there when the plan was approved, so the caller's
	 * request was never wrong. Something else changed the page, and a retry with
	 * a fresh plan is the remedy.
	 *
	 * @return OperationException The refusal.
	 */
	public function elementGone(): OperationException {
		return new OperationException(
			ErrorCode::Conflict,
			'The element this change was approved for is no longer on the page, so nothing was written.',
			'Read the page with elementor-document-get to see what it now holds, then preview the change again.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
