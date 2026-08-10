<?php
/**
 * The pure normalizer for a raw Elementor element tree.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Turns a raw stored element tree into stable nodes plus totals.
 *
 * PURE. It calls no WordPress function and names no `\Elementor\` symbol, which
 * is what lets it be tested without a WordPress double at all — and a rule with
 * no double behind it is a rule no unfaithful double can quietly break.
 *
 * THE NODE SHAPE IS FROZEN BY THE SPECIFICATION (Decision 4) and Phase 6b diffs
 * against it:
 *
 *     node   := { id, elType, widgetType|null, kind, label, depth, childCount, children[] }
 *     totals := { nodeCount, maxDepth, widgetTypeCounts{ <type>: <count> } }
 *
 * IT REFUSES RATHER THAN TRUNCATES. Both bounds below throw; neither trims. A
 * partial tree that LOOKS complete is the shape that produces a wrong diff in
 * Phase 6b, and a wrong diff is an approved plan that does not describe the
 * change being applied. The menus module shipped an unbounded item tree and it
 * became a deferred minor; Elementor trees are deeper, larger, and writable by
 * any plugin that can write post meta, so the bound is designed in here rather
 * than deferred again.
 *
 * `settings` ARE NOT RETURNED. They are large, may carry arbitrary third-party
 * data, and REQ-0033's acceptance asks for structure, not content. Phase 6b's
 * element read will need them and can add a scoped, opt-in projection then.
 *
 * @package SiteHelm
 */
final class ElementorTree {

	/**
	 * The greatest number of nesting levels a tree may have.
	 *
	 * A tree nested deeper than this is malformed, and — the reason the bound is
	 * checked BEFORE each descent rather than after — A RECURSIVE NORMALIZER ON
	 * A CYCLIC OR HOSTILE STRUCTURE IS A STACK OVERFLOW, WHICH IS A CRASH AND
	 * NOT AN ERROR RESPONSE. On a crash the dispatcher never refuses, no audit
	 * entry is written, and the operator sees a dropped connection.
	 */
	public const MAX_DEPTH = 50;

	/**
	 * The greatest number of elements a tree may hold.
	 *
	 * A whole-tree walk that never terminates is a request that never returns.
	 */
	public const MAX_NODES = 5000;

	/**
	 * The raw key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The `elType` value that means "this element renders a widget".
	 */
	private const WIDGET_TYPE = 'widget';

	/**
	 * Normalizes a raw element tree.
	 *
	 * @param array[] $raw The raw decoded element list.
	 *
	 * @return array<string, mixed> `['nodes' => array[], 'totals' => array]`.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when either
	 *                           bound is breached.
	 */
	public function normalize( array $raw ): array {
		$counts     = [];
		$node_count = 0;
		$max_depth  = 0;

		$nodes = $this->walk( $raw, 0, $node_count, $max_depth, $counts );

		return [
			'nodes'  => $nodes,
			'totals' => [
				'nodeCount'        => $node_count,
				// LEVELS, not the greatest zero-based depth: a tree whose deepest
				// node sits at depth 2 has three levels, and an empty tree has
				// none. Counting levels is what makes this directly comparable
				// with MAX_DEPTH without an off-by-one at the comparison site.
				'maxDepth'         => $max_depth,
				'widgetTypeCounts' => $counts,
			],
		];
	}

	/**
	 * Normalizes one level of raw nodes, descending into each.
	 *
	 * THE DEPTH BOUND IS TESTED BEFORE ANY RECURSION, on entry to the level
	 * rather than on the way out of it. That ordering is the whole guarantee:
	 * whatever the structure is — deep, hostile, or a reference cycle no
	 * `json_decode()` could produce but any array-building caller can — this can
	 * enter at most MAX_DEPTH levels before it throws, so the stack is bounded
	 * by a constant rather than by the data.
	 *
	 * A member that is not an array is SKIPPED rather than refused. Refusing
	 * would make a stray value in one branch cost the caller the whole document,
	 * and unlike a bound breach a skipped non-node changes no structure a diff
	 * depends on — the child counts and totals below are computed from what was
	 * actually kept, so the answer stays self-consistent.
	 *
	 * @param mixed              $raw        The raw child list.
	 * @param int                $depth      The zero-based depth of this level.
	 * @param int                $node_count The running node total, by reference.
	 * @param int                $max_depth  The running level total, by reference.
	 * @param array<string, int> $counts     The running widget type counts, by reference.
	 *
	 * @return array[] The normalized nodes at this level.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when either
	 *                           bound is breached.
	 */
	private function walk( mixed $raw, int $depth, int &$node_count, int &$max_depth, array &$counts ): array {
		if ( ! is_array( $raw ) || [] === $raw ) {
			return [];
		}

		if ( $depth >= self::MAX_DEPTH ) {
			throw $this->refuse(
				'This page\'s Elementor content is nested more deeply than SiteHelm will read.'
			);
		}

		$max_depth = max( $max_depth, $depth + 1 );
		$nodes     = [];

		foreach ( $raw as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			++$node_count;

			if ( $node_count > self::MAX_NODES ) {
				throw $this->refuse(
					'This page holds more Elementor elements than SiteHelm will read in one response.'
				);
			}

			$nodes[] = $this->node( $element, $depth, $node_count, $max_depth, $counts );
		}

		return $nodes;
	}

	/**
	 * Normalizes one raw element into the frozen node shape.
	 *
	 * EVERY RAW MEMBER IS READ THROUGH `??` AND SHAPE-GUARDED, never indexed
	 * directly. `_elementor_data` is arbitrary stored JSON: a template exported
	 * from an older Elementor has nodes carrying no `elType` at all, and reading
	 * one unguarded is the undefined-index notice the upstream walker emitted
	 * mid-response. `id` is likewise guarded on being scalar, because `(string)`
	 * on an array is a fatal.
	 *
	 * @param array<string, mixed> $element    One raw element.
	 * @param int                  $depth      This node's zero-based depth.
	 * @param int                  $node_count The running node total, by reference.
	 * @param int                  $max_depth  The running level total, by reference.
	 * @param array<string, int>   $counts     The running widget type counts, by reference.
	 *
	 * @return array<string, mixed> The normalized node.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when either
	 *                           bound is breached below this node.
	 */
	private function node( array $element, int $depth, int &$node_count, int &$max_depth, array &$counts ): array {
		$id        = $element['id'] ?? null;
		$el_type   = $element['elType'] ?? null;
		$el_type   = is_scalar( $el_type ) ? (string) $el_type : '';
		$is_widget = self::WIDGET_TYPE === $el_type;

		$widget_type = $element['widgetType'] ?? null;
		$widget_type = $is_widget && is_scalar( $widget_type ) && '' !== (string) $widget_type
			? (string) $widget_type
			: null;

		if ( null !== $widget_type ) {
			$counts[ $widget_type ] = ( $counts[ $widget_type ] ?? 0 ) + 1;
		}

		$children = $this->walk(
			$element[ self::CHILDREN_KEY ] ?? null,
			$depth + 1,
			$node_count,
			$max_depth,
			$counts
		);

		return [
			'id'         => is_scalar( $id ) ? (string) $id : '',
			'elType'     => $el_type,
			'widgetType' => $widget_type,
			// An untyped element is reported as a container, because `widget` is
			// the claim that carries meaning: a Phase 6b write treats a widget as
			// a replaceable leaf, and calling an unknown node one would invite
			// exactly that.
			'kind'       => $is_widget ? self::WIDGET_TYPE : 'container',
			'label'      => $this->label( $el_type, $widget_type, $is_widget ),
			'depth'      => $depth,
			'childCount' => count( $children ),
			'children'   => $children,
		];
	}

	/**
	 * A short human string naming what this element is.
	 *
	 * **`label` IS DERIVED, AND THE RESPONSE MARKS IT AS SUCH. IT IS FOR DISPLAY
	 * ONLY.** No `_elementor_data` row holds it; it is computed here from
	 * `elType` and `widgetType` on every read. A PHASE 6B SNAPSHOT MUST NEVER
	 * RECORD `label` AS IF IT WERE A STORED VALUE.
	 *
	 * That prohibition is written this plainly because the codebase has already
	 * shipped the defect once: the menus module recorded
	 * `wp_setup_nav_menu_item()`'s COMPUTED `description` — a
	 * `wp_trim_words( post_content, 200 )` — as though it were the stored
	 * column, so a rollback wrote the rendering back over the source and the
	 * same request applied twice never converged. It took a whole-branch review
	 * to find. Deriving a display string is fine; recording a derived string as
	 * state is not.
	 *
	 * For the same reason this is deliberately NOT the widget's stored title,
	 * which is the value a later "make the label more useful" change would reach
	 * for. A label drawn from stored content is indistinguishable from stored
	 * content once it is in a snapshot.
	 *
	 * @param string      $el_type     The element type, '' when absent.
	 * @param string|null $widget_type The widget type, null when not a widget.
	 * @param bool        $is_widget   Whether this element renders a widget.
	 *
	 * @return string The derived display label.
	 */
	private function label( string $el_type, ?string $widget_type, bool $is_widget ): string {
		if ( $is_widget ) {
			return $widget_type ?? self::WIDGET_TYPE;
		}

		return '' === $el_type ? 'element' : $el_type;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The refusal a breached bound produces.
	 *
	 * NO PART OF THE TREE APPEARS IN EITHER STRING. A bound is breached by data
	 * a third party may have written, and an envelope is not where an operator
	 * should learn what that data contained.
	 *
	 * ErrorCode::ExecutionFailed rather than a validation code: the eleven
	 * public codes are frozen and none names "stored site data is too large to
	 * read". Of the eleven this is the one whose contract fits — the read could
	 * not be completed — and it is marked retryable, which is true, because
	 * simplifying the page in the editor clears the condition. InvalidInput
	 * would misdirect: nothing about the caller's request is invalid.
	 *
	 * @param string $message The user-facing explanation.
	 *
	 * @return OperationException The refusal.
	 */
	private function refuse( string $message ): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			$message,
			'Open the page in the Elementor editor and reduce the number or the nesting of its elements, then retry.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
