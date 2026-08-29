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
 *   5. DECLARED KEYS, last, and the reason the other four exist.
 *
 * NO REFUSAL QUOTES THE CALLER'S TREE. It is arbitrary text of arbitrary length
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
	 * Runs all five gates over one caller-supplied tree.
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
}
