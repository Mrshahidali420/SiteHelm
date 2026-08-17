<?php
/**
 * Pure projection of a normalized Elementor tree into a compact digest.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * REQ-0078: what a page CONTAINS, without paying to read the whole tree.
 *
 * `elementor-document-get` already answers "what is on this page" completely,
 * and that completeness is the problem this class exists for: a page of five
 * hundred elements returns five hundred eight-field nodes, and a client that
 * only wanted to know which band holds the pricing table has spent its context
 * window learning the identifier of every icon in the footer.
 *
 * THIS CLASS REASONS ABOUT SHAPE AND NOTHING ELSE. It takes the tree
 * ElementorTree already normalized — so the two bounds, the null-id honesty and
 * the untyped-element handling are all inherited rather than restated — and
 * projects it. It reads no meta, calls no WordPress function, and refuses
 * nothing: every bound that can be breached was breached before this runs.
 *
 * THE DIGEST IS STRICTLY SMALLER THAN THE TREE IT SUMMARIZES, in every case and
 * by construction. `bands` holds one entry per TOP-LEVEL element, never per
 * element; the two censuses hold one entry per distinct TYPE, never per
 * occurrence; and each band's widget-type list is capped. A digest that could
 * grow with the node count would be a second full read wearing a smaller name.
 *
 * TWO COUNTS ARE REPORTED THAT `elementor-document-get` DOES NOT REPORT, and
 * they are the reason a digest is worth reading before a write rather than only
 * instead of a read:
 *
 *   - `unidentifiedElements` — elements whose stored data declares no `id`.
 *     ElementorTree reports each one as `id: null` because there is no honest
 *     alternative, but a caller reading a tree node by node has to notice the
 *     nulls itself. Every element write in this module addresses its target by
 *     identifier, so this count is exactly "how much of this page cannot be
 *     changed by SiteHelm at all" — an answer worth having before planning.
 *   - `untypedElements` — elements whose stored data declares no `elType`.
 *     These are read as containers, deliberately and conservatively, so the
 *     count is the size of that assumption on this particular page.
 *
 * Both are counted across the WHOLE tree, not just the top level, because that
 * is the question being asked.
 */
final class ElementorComposition {

	/**
	 * The greatest number of distinct widget types named on one band.
	 *
	 * A band's widget-type list exists so an operator can recognize the band —
	 * "the one with the pricing table" — not to enumerate it. Twelve names is
	 * more than enough to recognize a band and few enough that a page of forty
	 * bands cannot turn the digest back into a full read. When a band holds more
	 * distinct types than this the list is truncated and `widgetTypeCount` still
	 * reports the true total, so the truncation is visible rather than silent.
	 */
	public const MAX_BAND_WIDGET_TYPES = 12;

	/**
	 * The element type ElementorTree marks a widget with.
	 */
	private const WIDGET_TYPE = 'widget';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Projects a normalized tree into the digest.
	 *
	 * Takes ElementorTree::normalize()'s whole return value rather than its two
	 * halves, so `nodeCount` and `maxDepth` are reported from the numbers the
	 * normalizer actually counted instead of being re-derived here from the tree
	 * it counted them over. Two walks that agree are one walk with an untested
	 * duplicate; two walks that drift are a digest that contradicts the read it
	 * was meant to replace.
	 *
	 * @param array<string, mixed> $normalized ElementorTree::normalize()'s return value.
	 *
	 * @return array<string, mixed> The digest, without any node in it.
	 */
	public function digest( array $normalized ): array {
		$nodes  = is_array( $normalized['nodes'] ?? null ) ? $normalized['nodes'] : [];
		$totals = is_array( $normalized['totals'] ?? null ) ? $normalized['totals'] : [];
		$counts = is_array( $totals['widgetTypeCounts'] ?? null ) ? $totals['widgetTypeCounts'] : [];

		$containers   = [];
		$widget_total = 0;
		$untyped      = 0;
		$unidentified = 0;
		$bands        = [];

		foreach ( array_values( $nodes ) as $index => $node ) {
			$bands[] = $this->band( $node, $index );
		}

		$this->tally( $nodes, $containers, $widget_total, $untyped, $unidentified );

		return [
			'totals'               => [
				'nodeCount'      => (int) ( $totals['nodeCount'] ?? 0 ),
				'maxDepth'       => (int) ( $totals['maxDepth'] ?? 0 ),
				'widgetCount'    => $widget_total,
				'containerCount' => array_sum( $containers ),
				'bandCount'      => count( $bands ),
			],
			'widgets'              => $this->census( $counts ),
			'containers'           => $this->census( $containers ),
			'bands'                => $bands,
			'untypedElements'      => $untyped,
			'unidentifiedElements' => $unidentified,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One top-level element, described without its subtree.
	 *
	 * `descendantCount` is exclusive of the band itself, so a band holding one
	 * column of three widgets reports four rather than five: the number the
	 * operator is deciding whether to spend a read on is the number of elements
	 * they have not seen yet.
	 *
	 * @param mixed $node  One normalized top-level node.
	 * @param int   $index The band's zero-based position in stored order.
	 *
	 * @return array<string, mixed> The band entry.
	 */
	private function band( mixed $node, int $index ): array {
		$node     = is_array( $node ) ? $node : [];
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];

		$types = [];
		$this->widgetTypesIn( $children, $types );

		if ( self::WIDGET_TYPE === ( $node['kind'] ?? null ) && is_string( $node['widgetType'] ?? null ) ) {
			$types[ $node['widgetType'] ] = true;
		}

		$names = array_keys( $types );
		sort( $names );

		return [
			'index'           => $index,
			'id'              => is_string( $node['id'] ?? null ) ? $node['id'] : null,
			'elType'          => is_string( $node['elType'] ?? null ) ? $node['elType'] : '',
			'label'           => is_string( $node['label'] ?? null ) ? $node['label'] : 'element',
			'childCount'      => count( $children ),
			'descendantCount' => $this->descendants( $children ),
			'widgetTypeCount' => count( $names ),
			'widgetTypes'     => array_slice( $names, 0, self::MAX_BAND_WIDGET_TYPES ),
		];
	}

	/**
	 * Counts every node beneath a level, at any depth.
	 *
	 * @param array[] $nodes The child list.
	 *
	 * @return int The descendant count.
	 */
	private function descendants( array $nodes ): int {
		$total = 0;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			++$total;
			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$total   += $this->descendants( $children );
		}

		return $total;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Collects the distinct widget types anywhere beneath a level.
	 *
	 * Keyed by type rather than appended, so a band holding forty headings
	 * carries one name and the collection cannot grow with the occurrence count.
	 *
	 * @param array[]             $nodes The child list.
	 * @param array<string, bool> $types The running set, by reference.
	 */
	private function widgetTypesIn( array $nodes, array &$types ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$widget_type = $node['widgetType'] ?? null;

			if ( is_string( $widget_type ) && '' !== $widget_type ) {
				$types[ $widget_type ] = true;
			}

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$this->widgetTypesIn( $children, $types );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Walks the whole tree once, accumulating the counts the digest reports.
	 *
	 * The container census is keyed on `elType` and an untyped element is keyed
	 * on the empty string, so the census stays a faithful account of what is
	 * stored while `untypedElements` names the same elements in a way a client
	 * does not have to read a census key to notice.
	 *
	 * @param array[]            $nodes        The node list at this level.
	 * @param array<string, int> $containers   The running container census, by reference.
	 * @param int                $widget_total The running widget total, by reference.
	 * @param int                $untyped      The running untyped total, by reference.
	 * @param int                $unidentified The running unidentified total, by reference.
	 */
	private function tally( array $nodes, array &$containers, int &$widget_total, int &$untyped, int &$unidentified ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$el_type = is_string( $node['elType'] ?? null ) ? $node['elType'] : '';

			if ( self::WIDGET_TYPE === ( $node['kind'] ?? null ) ) {
				++$widget_total;
			} else {
				$containers[ $el_type ] = ( $containers[ $el_type ] ?? 0 ) + 1;
			}

			if ( '' === $el_type ) {
				++$untyped;
			}

			if ( ! is_string( $node['id'] ?? null ) ) {
				++$unidentified;
			}

			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
			$this->tally( $children, $containers, $widget_total, $untyped, $unidentified );
		}
	}

	/**
	 * Turns a type-keyed tally into a deterministically ordered census.
	 *
	 * Ordered by count descending, then by type ascending. The tie-break is not
	 * cosmetic: PHP's sort is not stable across every build, and a census whose
	 * order can vary between two identical reads of an unchanged page invites a
	 * client to diff two digests and see a change that did not happen.
	 *
	 * @param array<string, int> $tally The type-keyed counts.
	 *
	 * @return array[] The census entries.
	 */
	private function census( array $tally ): array {
		$types = array_keys( $tally );
		sort( $types );

		usort(
			$types,
			static function ( string $a, string $b ) use ( $tally ): int {
				$by_count = $tally[ $b ] <=> $tally[ $a ];

				return 0 === $by_count ? strcmp( $a, $b ) : $by_count;
			}
		);

		return array_map(
			static fn( string $type ): array => [
				'type'  => $type,
				'count' => $tally[ $type ],
			],
			$types
		);
	}
}
