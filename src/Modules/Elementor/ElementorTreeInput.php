<?php
/**
 * The shared gates every caller-supplied Elementor tree passes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0104: the one place that decides whether a tree a CALLER built is safe to
 * store, for the three operations that accept one.
 *
 * THREE OPERATIONS, ONE FORMULA. `elementor-template-import`,
 * `elementor-document-build` and `elementor-document-create` all take a layout
 * an agent composed rather than one this plugin read out of a page, and all
 * three face the same failure: Elementor's parser DROPS an unrecognised setting
 * key silently, so a tree stored with `content` where the widget declares
 * `title` is stored with that text already gone. Three private copies of these
 * gates would be three chances for one of them to lose a check, and the check
 * that goes missing is invisible — the write succeeds and the content is
 * quietly not there.
 *
 * THE ORDER IS LOAD-BEARING, and each gate exists to make the next one able to
 * run:
 *
 *   1. SHAPE, first, because every gate below walks the tree and a node that is
 *      not an object cannot be walked. Bounded by `ElementorTree::MAX_DEPTH` on
 *      its own rather than trusting the normalizer's bound, because this walk
 *      runs before the normalizer and a hand-built tree can nest arbitrarily.
 *   2. SIZE, second, in encoded bytes, because everything below decodes and
 *      re-encodes and a tree past the storage bound will be refused anyway.
 *   3. BOUNDS, third, through `ElementorTree::normalize()`, which is also what
 *      produces the widget counts the next gate reads.
 *   4. AVAILABILITY, fourth: a widget this site does not register has no prop
 *      schema, so the key gate below it would have nothing to check against.
 *   5. DECLARED KEYS, fifth, and the reason the other four exist.
 *   6. REFERENCED STYLES, last, and only reachable once the shape gate above has
 *      proved every node is walkable. It refuses a local style definition no
 *      element wears, which is the same "stored but never rendered" failure the
 *      key gate catches one vocabulary over.
 *
 * NO REFUSAL QUOTES THE CALLER'S TREE, beyond a style id short and ordinary
 * enough to be an Elementor class name. It is arbitrary text of arbitrary length
 * that will be read by whoever opens the activity log, and the depth and the
 * member name are enough to find the offending node in a payload the caller
 * sent and still has.
 *
 * @package SiteHelm
 */
final class ElementorTreeInput {

	/**
	 * The greatest number of encoded bytes a caller-supplied tree may hold.
	 *
	 * The snapshot bound, deliberately: a document this plugin cannot record
	 * cannot be rolled back, so accepting content larger than that would be
	 * accepting a write whose reversal was already known to be unavailable.
	 */
	public const MAX_CONTENT_BYTES = ElementorWriteTarget::MAX_SNAPSHOT_BYTES;

	/**
	 * The member naming an element's kind in Elementor's stored shape.
	 */
	private const NODE_EL_TYPE = 'elType';

	/**
	 * The member holding a node's own local style definitions.
	 */
	private const NODE_STYLES = 'styles';

	/**
	 * The setting holding the node's class binding.
	 */
	private const SETTING_CLASSES = 'classes';

	/**
	 * The class binding's member holding the referenced class names.
	 */
	private const CLASSES_VALUE = 'value';

	/**
	 * A style definition's own copy of its name.
	 */
	private const STYLE_ID = 'id';

	/**
	 * Separates class names where they are stored as one string.
	 */
	private const CLASS_SEPARATOR = ' ';

	/**
	 * The only style-id shape a refusal repeats back verbatim.
	 *
	 * Elementor's own local class name is `e-<elementId>-<hash>`, which this
	 * matches with room to spare. Anything else is described rather than quoted,
	 * because a caller's tree is arbitrary text that ends up in the activity log.
	 *
	 * @var string
	 */
	private const STYLE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * Constructs the shared gate.
	 *
	 * @param ElementorTree         $tree     The tree normalizer and its bounds.
	 * @param ElementorPropCoercion $coercion The prop normalizer and key guard.
	 * @param ElementorPresence     $presence The registered-widget reader.
	 */
	public function __construct(
		private readonly ElementorTree $tree,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Runs all six gates over one caller-supplied tree.
	 *
	 * @param array  $content The caller's tree.
	 * @param string $subject What the tree is, in words, for the refusals.
	 * @param string $source  Where a good tree comes from, for the refusals.
	 *
	 * @return array<string, mixed> The normalizer's totals for the tree.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function assertUsable( array $content, string $subject, string $source ): array {
		$this->assert_shape( $content, 0, $subject, $source );
		$this->assert_size( $content, $subject, $source );

		$totals = $this->tree->normalize( $content )['totals'];

		$this->assert_renderable( $totals['widgetTypeCounts'], $subject );
		$this->assert_declared_keys( $content );
		$this->assert_referenced_styles( $content );

		return $totals;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is built from literals and the operation's own subject wording; no caller value reaches one.
	/**
	 * Refuses a tree whose nodes are not shaped like Elementor elements.
	 *
	 * @param array  $nodes   One level of the caller's tree.
	 * @param int    $depth   The zero-based depth of this level.
	 * @param string $subject What the tree is, in words.
	 * @param string $source  Where a good tree comes from.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_shape( array $nodes, int $depth, string $subject, string $source ): void {
		if ( $depth >= ElementorTree::MAX_DEPTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The %s is nested more deeply than SiteHelm will store.', $subject ),
				'Send the layout in parts, or flatten the nesting and try again.'
			);
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				throw $this->malformed( $depth, 'an element that is not an object', $subject, $source );
			}

			$el_type = $node[ self::NODE_EL_TYPE ] ?? null;

			if ( ! is_string( $el_type ) || '' === $el_type ) {
				throw $this->malformed( $depth, 'an element with no elType', $subject, $source );
			}

			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;

			if ( null !== $widget_type && ! is_string( $widget_type ) ) {
				throw $this->malformed( $depth, 'an element whose widgetType is not a name', $subject, $source );
			}

			$settings = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

			if ( null !== $settings && ! is_array( $settings ) ) {
				throw $this->malformed( $depth, 'an element whose settings are not an object', $subject, $source );
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( null !== $children && ! is_array( $children ) ) {
				throw $this->malformed( $depth, 'an element whose elements member is not a list', $subject, $source );
			}

			if ( is_array( $children ) ) {
				$this->assert_shape( $children, $depth + 1, $subject, $source );
			}
		}
	}

	/**
	 * The one malformed-tree refusal.
	 *
	 * @param int    $depth   The zero-based depth the problem was found at.
	 * @param string $detail  What was wrong, in words, quoting nothing.
	 * @param string $subject What the tree is, in words.
	 * @param string $source  Where a good tree comes from.
	 *
	 * @return OperationException The refusal.
	 */
	private function malformed( int $depth, string $detail, string $subject, string $source ): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			sprintf(
				'The %s is not in the shape Elementor stores: at nesting level %d it holds %s.',
				$subject,
				$depth + 1,
				$detail
			),
			$source
		);
	}

	/**
	 * Refuses a tree too large for this plugin to handle safely.
	 *
	 * @param array  $content The caller's tree.
	 * @param string $subject What the tree is, in words.
	 * @param string $source  Where a good tree comes from.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_size( array $content, string $subject, string $source ): void {
		$json = wp_json_encode( $content );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The %s could not be encoded for storage, so nothing was planned.', $subject ),
				'Check the content for text that is not valid UTF-8, then try again.'
			);
		}

		if ( strlen( $json ) > self::MAX_CONTENT_BYTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The %s is larger than SiteHelm will store in one document.', $subject ),
				'Send the layout in parts and apply them in turn. ' . $source
			);
		}
	}

	/**
	 * Refuses a tree naming widget types this site does not have installed.
	 *
	 * MANDATORY, and not merely advisory, because the gate below it reads a live
	 * prop schema for every widget in the tree and a widget this site does not
	 * register has none. Storing a layout whose settings could not be checked
	 * would store exactly the unvalidated props upstream defect #101 locks a page
	 * over.
	 *
	 * A SITE WHOSE REGISTRY CANNOT BE READ AT ALL is let through here; the key
	 * gate below refuses on its own terms, with the message written for a
	 * registry that is not answering.
	 *
	 * @param array<string, int> $widget_counts The tree's widget type counts.
	 * @param string             $subject       What the tree is, in words.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	private function assert_renderable( array $widget_counts, string $subject ): void {
		$registered = $this->presence->widgetTypes();

		if ( null === $registered ) {
			return;
		}

		$missing = array_values( array_diff( array_keys( $widget_counts ), $registered ) );

		if ( [] === $missing ) {
			return;
		}

		sort( $missing, SORT_STRING );

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			sprintf(
				'The %s uses %d widget type(s) this site does not have installed, so its content cannot be checked before storing: %s.',
				$subject,
				count( $missing ),
				implode( ', ', $missing )
			),
			'Activate the plugins that provide those widgets and try again. elementor-widget-availability reports what this site registers.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The media advisories a whole submitted tree earns.
	 *
	 * SEPARATE FROM `assertUsable()`, DELIBERATELY, and not folded into its
	 * return value. Every gate that runs in there refuses; this one reports, and
	 * a caller that discards the totals (`ElementorDocumentBuild` does) would
	 * silently discard the advisories with them. A second call the operation has
	 * to make on purpose cannot be dropped by accident.
	 *
	 * IT WALKS THE SAME SHAPE `assert_declared_keys()` WALKS, one level at a
	 * time, descending into `elements`, and it judges layout elements as well as
	 * widgets: a container's background image is a media control like any other,
	 * and the el_type answers its schema when there is no widget type.
	 *
	 * THE PER-ELEMENT SHAPE IS PRESERVED as far as `condense()`, rather than
	 * being flattened here, because the summary a bulk write earns counts
	 * elements as well as settings — and a whole page cloned from another site is
	 * told apart from one widget with a missed upload by exactly that ratio.
	 *
	 * @param array $content The caller's tree, already through assertUsable().
	 *
	 * @return array<int, string> The advisories, empty when every media value carries its attachment.
	 */
	public function mediaWarnings( array $content ): array {
		return ElementorMediaAdvisory::condense( $this->media_warnings_per_element( $content ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One entry per element in tree order, each holding that element's advisories.
	 *
	 * @param array $nodes One level of the caller's tree.
	 *
	 * @return array<int, array<int, string>> The per-element advisories.
	 */
	private function media_warnings_per_element( array $nodes ): array {
		$collected = [];

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;
			$el_type     = $node[ ElementorSettingsMerge::NODE_EL_TYPE ] ?? null;
			$settings    = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;
			$is_widget   = is_string( $widget_type ) && '' !== $widget_type;
			$schema_type = $is_widget ? $widget_type : $el_type;

			if ( is_string( $schema_type ) && '' !== $schema_type && is_array( $settings ) && [] !== $settings ) {
				$collected[] = $this->coercion->mediaWarnings(
					$schema_type,
					$settings,
					$is_widget ? ElementorElementAddInput::EL_TYPE_WIDGET : $schema_type
				);
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( is_array( $children ) ) {
				foreach ( $this->media_warnings_per_element( $children ) as $child ) {
					$collected[] = $child;
				}
			}
		}

		return $collected;
	}

	/**
	 * Refuses any setting key the widget that carries it does not declare.
	 *
	 * THE #102 GATE, and the reason this class exists. Elementor's parser drops
	 * an unrecognised key silently, so a layout stored with `content` where the
	 * widget declares `title` is stored with that text already gone. Every gate
	 * above this one exists to make sure this one can run.
	 *
	 * @param array $nodes One level of the caller's tree.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an undeclared
	 *                           key, or ErrorCode::ExecutionFailed when a schema
	 *                           cannot be read.
	 */
	private function assert_declared_keys( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;
			$settings    = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

			if ( is_string( $widget_type ) && '' !== $widget_type && is_array( $settings ) && [] !== $settings ) {
				$this->coercion->assertKnownKeys( $widget_type, $settings );
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( is_array( $children ) ) {
				$this->assert_declared_keys( $children );
			}
		}
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal plus a style id that describe_style_id() has already bounded to a short, safe token; nothing else from the caller's tree reaches it.
	/**
	 * Refuses a local style definition no element in the tree wears.
	 *
	 * THE SAME JUDGEMENT `ElementorConditionGate` MAKES, one vocabulary over:
	 * refuse the write that stores something and renders nothing. A local style
	 * class lives under the owning node's `styles` AND is referenced from that
	 * node's `settings.classes.value`, and `ElementorStyleRemap` says in as many
	 * words that either half alone is a defect. A definition with no reference
	 * is stored, read back verbatim, survives every existing check, and paints
	 * nothing at all — the silent no-render this module refuses on principle.
	 *
	 * ONLY THE CALLER'S OWN TREE IS JUDGED, because this gate runs nowhere else.
	 * A page that already holds an orphaned definition is the site's own history
	 * and stays saveable, which is the split `ElementorPropCoercion` draws
	 * between sweeping a stored tree and judging a caller's input.
	 *
	 * THE REFERENCE IS LOOKED FOR ON THE OWNING NODE ONLY. Elementor resolves a
	 * local class from the element that defines it, so a reference on a sibling
	 * does not make the definition render.
	 *
	 * @param array $nodes One level of the caller's tree.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_referenced_styles( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$styles = $node[ self::NODE_STYLES ] ?? null;

			if ( is_array( $styles ) && [] !== $styles ) {
				$this->assert_every_style_is_worn( $styles, self::referenced_classes( $node ) );
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( is_array( $children ) ) {
				$this->assert_referenced_styles( $children );
			}
		}
	}

	/**
	 * Refuses the first of one node's style definitions its settings never name.
	 *
	 * @param array              $styles     The node's style definitions.
	 * @param array<int, string> $referenced The class names the node's settings wear.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_every_style_is_worn( array $styles, array $referenced ): void {
		foreach ( $styles as $key => $definition ) {
			$declared = is_array( $definition ) ? ( $definition[ self::STYLE_ID ] ?? null ) : null;
			$id       = is_string( $declared ) && '' !== $declared ? $declared : (string) $key;

			if ( '' === $id || in_array( $id, $referenced, true ) ) {
				continue;
			}

			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'This layout defines the local style class %s on an element whose settings never wear it, so Elementor would store the definition and render nothing from it.',
					self::describe_style_id( $id )
				),
				sprintf(
					'Add %s to that element\'s settings.classes.value, or drop the styles entry that defines it.',
					self::describe_style_id( $id )
				)
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The class names one node's settings wear.
	 *
	 * Both spellings are read, because Elementor stores the binding as a list on
	 * an atomic element and as a space-separated string on a classic one, and a
	 * gate that understood only one would refuse every legitimate write in the
	 * other vocabulary.
	 *
	 * @param array<string, mixed> $node One raw element.
	 *
	 * @return array<int, string> The referenced class names.
	 */
	private static function referenced_classes( array $node ): array {
		$value = $node[ ElementorPropCoercion::NODE_SETTINGS ][ self::SETTING_CLASSES ][ self::CLASSES_VALUE ] ?? null;

		if ( is_string( $value ) ) {
			$value = explode( self::CLASS_SEPARATOR, $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$names = [];

		foreach ( $value as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * How a style id is named in a refusal.
	 *
	 * Quoted verbatim only while it is a short, ordinary token — which every
	 * name Elementor mints is. Anything else is described instead, because the
	 * refusal is read in the activity log and the caller's tree is arbitrary
	 * text of arbitrary length.
	 *
	 * @param string $id The style definition's id.
	 *
	 * @return string The phrase naming it.
	 */
	private static function describe_style_id( string $id ): string {
		return 1 === preg_match( self::STYLE_ID_PATTERN, $id )
			? sprintf( '"%s"', $id )
			: 'under a name this refusal will not repeat back';
	}
}
