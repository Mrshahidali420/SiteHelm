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
 * IDENTICALLY: name an element, refuse it if it is not a widget, refuse a
 * setting key the widget does not declare, merge the requested values over the
 * ones already stored, and put the result back into the tree.
 *
 * SEPARATE FROM BOTH OPERATIONS FOR THE REASON `ElementorElementAddInput` IS
 * SEPARATE FROM `ElementorElementAdd`. Two copies of the elType guard is two
 * chances for one of them to stop checking; two copies of the merge is two
 * answers to "does an update replace the settings map or add to it"; and two
 * copies of a refusal message is a pair that drifts the first time one is
 * reworded. The difference between the two operations is the DEVICE SUFFIX and
 * nothing else, and that lives in the operation that has a device.
 *
 * THE elType GUARD IS THE POINT OF THIS CLASS. Elementor's own
 * `update-atomic-widget` does not check `elType` before reading a node's
 * `widgetType`, so it "succeeds" against a container by writing settings no
 * renderer will ever read — a write that verifies, reports done, and changes
 * nothing an operator can see. `widget()` refuses that as `InvalidInput` before
 * any tree is edited.
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
	/**
	 * The widget the request names, with its stored settings.
	 *
	 * `elType` IS READ BEFORE `widgetType`, which is the whole guard. A node's
	 * `widgetType` member is only meaningful on a node whose `elType` is
	 * `widget`; a container that happens to carry one — a leftover from an
	 * import, or a member a third-party plugin wrote — would otherwise be
	 * treated as a widget and written to, and Elementor renders a container from
	 * its own settings and ignores the widget ones entirely. The write would
	 * verify. Nothing on the page would change.
	 *
	 * A well-formed identifier the document does not hold is `TargetNotFound`
	 * rather than `InvalidInput`, on `ElementorElementAddInput`'s reasoning: the
	 * argument was not the thing that was wrong.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The element identifier.
	 *
	 * @return array<string, mixed> Keys 'widgetType' (string) and 'settings' (array).
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           does not hold the element, or
	 *                           ErrorCode::InvalidInput when it is not a widget.
	 */
	public function widget( array $tree, string $element_id ): array {
		$found = $this->edit->find( $tree, $element_id );

		if ( null === $found ) {
			throw $this->elementNotFound();
		}

		$node = is_array( $found['node'] ?? null ) ? $found['node'] : [];

		if ( ElementorElementAddInput::EL_TYPE_WIDGET !== ( $node[ self::NODE_EL_TYPE ] ?? null ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The element this request names is a layout element rather than a widget, and only a widget has settings this operation can change.',
				'Read the page with elementor-document-get and retry naming an element it reports as a widget.'
			);
		}

		$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;

		if ( ! is_string( $widget_type ) || '' === $widget_type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The element this request names is stored as a widget but does not record which widget it is, so its settings cannot be checked before they are written.',
				'Open the page in the Elementor editor and re-save it so the element records its widget type, then retry.'
			);
		}

		$settings = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

		return [
			ElementorPropCoercion::NODE_WIDGET_TYPE => $widget_type,
			ElementorPropCoercion::NODE_SETTINGS    => is_array( $settings ) ? $settings : [],
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Refuses every requested key the widget does not declare.
	 *
	 * BEFORE ANY WRITE, always, and delegated whole to `ElementorPropCoercion`
	 * so there is one answer to what a widget accepts. Elementor discards an
	 * unrecognised alias key instead of refusing it (#102), so a check made
	 * after the save is made on content that is already gone.
	 *
	 * The keys checked are the ones the CALLER SENT, before any device suffix is
	 * applied, because the widget registry declares `title` and never
	 * `title_tablet`.
	 *
	 * @param string               $widget_type The stored widget type.
	 * @param array<string, mixed> $settings    The caller's requested settings.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a key is not
	 *                            declared, or ErrorCode::ExecutionFailed when the
	 *                            widget's schema cannot be read.
	 */
	public function assertKnownKeys( string $widget_type, array $settings ): void {
		$this->coercion->assertKnownKeys( $widget_type, $settings );
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
