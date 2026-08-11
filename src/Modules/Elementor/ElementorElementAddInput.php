<?php
/**
 * The input reader the Elementor element writes validate their arguments with.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The element vocabulary every Elementor element write speaks, and the five
 * validators that turn a caller's arguments into it.
 *
 * SEPARATE FROM THE OPERATION BECAUSE IT IS NOT ONE OPERATION'S VOCABULARY.
 * `elType`, `widgetType`, `settings`, `index` and `parentElementId` are the
 * words the element-add, element-update, element-move, element-duplicate,
 * element-remove and widget-settings-update writes all use, and the bounds each
 * one may take have to be declared once for the whole block. A second copy of
 * `ALLOWED_EL_TYPES`, or a second widget-type pattern, would be two spellings of
 * one rule that could then disagree about what a document may hold.
 *
 * STATELESS AND SAFE TO SHARE. Every method takes everything it judges as an
 * argument and returns the judged value; nothing here remembers a call. The two
 * collaborators are the widget registry the settings check consults and the tree
 * walk the parent lookup needs, both of which are themselves shared across the
 * write block.
 *
 * EVERY REFUSAL IS `InvalidInput` EXCEPT THE PARENT LOOKUP, which is
 * `TargetNotFound`: an identifier a stored element could never carry is a
 * malformed argument, but a well-formed identifier this document does not hold
 * is a target that is not there, and telling an operator to correct their
 * argument in that case would send them to fix something that was never wrong.
 *
 * @package SiteHelm
 */
final class ElementorElementAddInput {

	/**
	 * The input property naming the element the new one is placed inside.
	 */
	public const INPUT_PARENT_ELEMENT_ID = 'parentElementId';

	/**
	 * The input property naming the position among the destination's children.
	 */
	public const INPUT_INDEX = 'index';

	/**
	 * The input property naming the kind of element to add.
	 */
	public const INPUT_EL_TYPE = 'elType';

	/**
	 * The input property naming which widget to add.
	 */
	public const INPUT_WIDGET_TYPE = 'widgetType';

	/**
	 * The input property carrying the new element's settings.
	 */
	public const INPUT_SETTINGS = 'settings';

	/**
	 * The element kinds an Elementor document is built from.
	 *
	 * CLOSED, and closed deliberately. Elementor renders nothing at all for an
	 * `elType` it does not know, so an unconstrained string would let an
	 * unattended caller store an element that is present in the document, counted
	 * by every total the write operations promise, and invisible on the page — a
	 * write that verifies and does nothing. `container` is the modern layout
	 * element and the three older ones are what documents built before it still
	 * hold.
	 *
	 * @var string[]
	 */
	public const ALLOWED_EL_TYPES = [ 'container', 'section', 'column', 'widget' ];

	/**
	 * The `elType` that requires a widget type, and the only one that admits one.
	 */
	public const EL_TYPE_WIDGET = 'widget';

	/**
	 * The greatest number of characters a widget type name may have.
	 */
	public const WIDGET_TYPE_MAX_LENGTH = 64;

	/**
	 * The form a widget type name may take.
	 *
	 * The same character set `ElementorWriteFields::ELEMENT_ID_PATTERN` admits,
	 * because a widget type name reaches Elementor's registry as a lookup key and
	 * whitespace or a separator in one names nothing that could be registered.
	 *
	 * The length bound is CONCATENATED FROM `WIDGET_TYPE_MAX_LENGTH` rather than
	 * spelled a second time inside the pattern. Two spellings of one number is a
	 * pair that drifts the first time one of them is raised, and this file is
	 * shared by the whole Elementor write block.
	 */
	private const WIDGET_TYPE_PATTERN = '/^[A-Za-z0-9_-]{1,' . self::WIDGET_TYPE_MAX_LENGTH . '}$/';

	/**
	 * Constructs the reader.
	 *
	 * @param ElementorPropCoercion $coercion The prop normalizer and key guard.
	 * @param ElementorTreeEdit     $edit     The raw-tree surgery primitives.
	 */
	public function __construct(
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorTreeEdit $edit,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * The requested element kind.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The element kind.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function requestedElType( array $input ): string {
		$el_type = $input[ self::INPUT_EL_TYPE ] ?? null;

		if ( ! is_string( $el_type ) || ! in_array( $el_type, self::ALLOWED_EL_TYPES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The kind of element to add must be one of: ' . implode( ', ', self::ALLOWED_EL_TYPES ) . '.',
				'Retry naming one of those kinds. Use "container" for a layout box and "widget" for a piece of content.'
			);
		}

		return $el_type;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no caller value.
	/**
	 * The requested widget type, or null for an element that is not a widget.
	 *
	 * BOTH DIRECTIONS ARE REFUSED. A `widget` with no widget type names nothing
	 * Elementor can render, and a container carrying one would store a member the
	 * editor ignores while the request read as though it had been honoured.
	 *
	 * @param string               $el_type The requested element kind.
	 * @param array<string, mixed> $input   The validated arguments.
	 *
	 * @return string|null The widget type.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function requestedWidgetType( string $el_type, array $input ): ?string {
		$widget_type = $input[ self::INPUT_WIDGET_TYPE ] ?? null;

		if ( self::EL_TYPE_WIDGET !== $el_type ) {
			if ( null !== $widget_type ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Only an element of kind "widget" carries a widget type.',
					'Retry with elType "widget" to add that widget, or without a widget type to add the layout element you named.'
				);
			}

			return null;
		}

		if ( ! is_string( $widget_type ) || 1 !== preg_match( self::WIDGET_TYPE_PATTERN, $widget_type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'An element of kind "widget" needs the name of the widget to add.',
				'Call elementor-widget-availability to see the widgets this site has, then retry naming one of them.'
			);
		}

		return $widget_type;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no caller value.
	/**
	 * The requested settings, checked against the widget's own declaration.
	 *
	 * A MAP OR NOTHING. Two shapes are refused here rather than one: a value that
	 * is not an array at all, and a non-empty JSON LIST. A list is an array, so a
	 * bare `is_array()` guard admits `['a','b']` — and for a container, a section
	 * or a column, where the widget-registry check below is correctly skipped,
	 * nothing downstream would then object to storing settings keyed 0, 1, 2 that
	 * Elementor reads as nothing at all. The empty array stays accepted: it is how
	 * an empty settings map arrives, and refusing it would refuse an element that
	 * simply carries no settings.
	 *
	 * `assertKnownKeys()` runs for a WIDGET AND FOR NOTHING ELSE, because it asks
	 * Elementor's widget registry what a widget type declares and there is no such
	 * declaration for a container, a section, or a column. Running it on those
	 * would refuse every layout element on this site, since the registry has no
	 * entry to answer with — a guard whose failure mode is refusing correct
	 * requests rather than catching wrong ones.
	 *
	 * @param string|null          $widget_type The requested widget type.
	 * @param array<string, mixed> $input       The validated arguments.
	 *
	 * @return array<string, mixed> The settings.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the settings are
	 *                           not a map or hold a key the widget does not declare,
	 *                           or ErrorCode::ExecutionFailed when the widget's
	 *                           schema cannot be read.
	 */
	public function requestedSettings( ?string $widget_type, array $input ): array {
		$settings = $input[ self::INPUT_SETTINGS ] ?? [];

		if ( ! is_array( $settings ) || ( [] !== $settings && array_is_list( $settings ) ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The settings for the new element must be given as a set of named values.',
				'Retry sending settings as an object keyed by setting name, or omit them entirely.'
			);
		}

		if ( null !== $widget_type ) {
			$this->coercion->assertKnownKeys( $widget_type, $settings );
		}

		return $settings;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no caller value.
	/**
	 * The requested position, bounded at the boundary.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return int The zero-based position.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function requestedIndex( array $input ): int {
		$index = $input[ self::INPUT_INDEX ] ?? 0;

		if ( ! is_int( $index ) || $index < 0 ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The position for the new element must be a whole number, counting from 0 for first.',
				'Retry with 0 to place the element first, or the number of elements already there to place it last.'
			);
		}

		return $index;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no caller value.
	/**
	 * The element the new one goes inside, or null for the document root.
	 *
	 * The named parent is looked up HERE rather than left to
	 * `ElementorTreeEdit::insert()`, so that a parent the document does not hold
	 * is refused while the plan is being built rather than at apply, when the
	 * refusal would arrive after an operator had already approved the change.
	 *
	 * @param array[]              $tree  The raw stored tree.
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string|null The parent's stored id.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the identifier
	 *                           is not one a stored element can carry, or
	 *                           ErrorCode::TargetNotFound when the document does
	 *                           not hold it.
	 */
	public function requestedParent( array $tree, array $input ): ?string {
		$parent_id = $input[ self::INPUT_PARENT_ELEMENT_ID ] ?? null;

		if ( null === $parent_id ) {
			return null;
		}

		if ( ! is_string( $parent_id ) || 1 !== preg_match( '/' . ElementorWriteFields::ELEMENT_ID_PATTERN . '/', $parent_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The identifier of the element to add inside is not one a stored element can carry.',
				'Read the page with elementor-document-get and retry with an identifier it reports, or send no parent to add the element at the top level.'
			);
		}

		if ( null === $this->edit->find( $tree, $parent_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'This page holds no element with the identifier the request names, so there is nowhere to add the new element.',
				'Read the page with elementor-document-get and retry with an identifier it reports.'
			);
		}

		return $parent_id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
