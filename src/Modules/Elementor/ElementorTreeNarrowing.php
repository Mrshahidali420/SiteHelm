<?php
/**
 * Keeps a document read inside a size a client can actually receive.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * The ceiling on an Elementor document read, and the marker that says so.
 *
 * A LONG PAGE IS NOT A RARE PAGE. Elementor documents nest containers inside
 * containers, and a real landing page reaches several hundred elements without
 * anybody thinking of it as large. Returned whole, that tree is megabytes of
 * JSON, and the client on the other end of the transport either truncates it,
 * spends its whole context on it, or drops the response — three failures that
 * all look like "the read did not work" and none of which say why.
 *
 * So the read answers a SMALLER TRUE TREE rather than a larger one nobody
 * receives, and it says out loud that it did. The narrowing is by DEPTH,
 * because depth is the axis along which an Elementor tree loses the least by
 * being cut: the top-level bands of a page are the part an operator is
 * orienting themselves in, and the leaves are the part they ask for by name
 * once they know which band to ask about.
 *
 * Two rules make the shortened answer honest rather than merely shorter:
 *
 * 1. A node whose children were dropped KEEPS ITS TRUE `childCount` and reports
 *    an empty `children` array. The count is how a client learns there is more
 *    below than it was handed. A pruned node reporting `childCount` 0 would be
 *    a lie the client could not detect.
 * 2. `totals` is computed from the WHOLE tree, before anything is dropped, so
 *    the numbers describe the document rather than the excerpt.
 *
 * The ceiling is a CONSTANT and never an input. A caller who could raise it
 * could ask for the response that kills it, which is the failure this class
 * exists to prevent.
 */
final class ElementorTreeNarrowing {

	/**
	 * The response member carrying the narrowing report.
	 */
	public const FIELD_NARROWED = 'narrowed';

	/**
	 * The most bytes the `nodes` member may occupy, encoded the way the
	 * transport encodes it.
	 *
	 * 256 KiB. Chosen against the payload rather than against a limit anything
	 * enforces: it is comfortably below what an MCP client will accept in one
	 * message, and it holds a normalized tree of roughly a thousand nodes, which
	 * is past the size of any page a person authored by hand.
	 */
	public const MAX_NODES_BYTES = 262144;

	/**
	 * The sentence a narrowed response carries.
	 *
	 * It names the two operations that answer the part that was dropped, because
	 * a marker that only says "there was more" leaves the client guessing at the
	 * call that would show it.
	 */
	private const MESSAGE = 'Response narrowed to depth %d: %d of %d nodes omitted. Ask for a subtree with rootId, or one element with elementor-element-get; elementor-composition-get names the bands.';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The tree as it will be sent, and the report of what was left out.
	 *
	 * Counts are taken from the nodes THIS CALL was given, not from the whole
	 * document, so that a subtree read narrowed further reports how much of the
	 * subtree it dropped. The document's own totals travel separately and are
	 * always whole-document.
	 *
	 * @param array<int, array<string, mixed>> $nodes The normalized tree.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, narrowed: array<string, mixed>} The tree and the report.
	 */
	public static function narrow( array $nodes ): array {
		$total   = self::countNodes( $nodes );
		$deepest = self::deepest( $nodes );

		if ( self::bytes( $nodes ) <= self::MAX_NODES_BYTES ) {
			return [
				'nodes'              => $nodes,
				self::FIELD_NARROWED => self::report( false, $deepest, 0, $total ),
			];
		}

		for ( $depth = $deepest - 1; $depth >= 0; $depth-- ) {
			$pruned = self::toDepth( $nodes, $depth );

			if ( self::bytes( $pruned ) <= self::MAX_NODES_BYTES ) {
				$kept = self::countNodes( $pruned );

				return [
					'nodes'              => $pruned,
					self::FIELD_NARROWED => self::report( true, $depth, $total - $kept, $total ),
				];
			}
		}

		// Even the top-level band alone is too large, which means the page has
		// more sections than the ceiling holds. Keep the longest run of them that
		// fits rather than answering nothing: the first sections of a page are
		// the ones an operator is looking at.
		$top    = self::toDepth( $nodes, 0 );
		$prefix = [];

		foreach ( $top as $node ) {
			$candidate = array_merge( $prefix, [ $node ] );

			if ( self::bytes( $candidate ) > self::MAX_NODES_BYTES ) {
				break;
			}

			$prefix = $candidate;
		}

		return [
			'nodes'              => $prefix,
			self::FIELD_NARROWED => self::report( true, 0, $total - count( $prefix ), $total ),
		];
	}

	/**
	 * One element's own node, with everything below it, or null.
	 *
	 * Searched on the NORMALIZED tree rather than the stored one, because the
	 * caller is naming an id it read out of a previous response, and the
	 * normalized node is what it asked to be handed back.
	 *
	 * @param array<int, array<string, mixed>> $nodes     The normalized tree.
	 * @param string                           $root_id   The element identifier.
	 *
	 * @return array<string, mixed>|null The node, or null when nothing carries the id.
	 */
	public static function subtree( array $nodes, string $root_id ): ?array {
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ( $node['id'] ?? null ) === $root_id ) {
				return $node;
			}

			$children = $node['children'] ?? [];
			$found    = is_array( $children ) ? self::subtree( $children, $root_id ) : null;

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The declared schema for the narrowing report.
	 *
	 * Required rather than optional, and present on every response including the
	 * ones that were not narrowed, because a member that only appears when
	 * something went missing is a member clients do not read.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'applied'      => [
					'type'        => 'boolean',
					'description' => 'True when this response holds less of the tree than the document does. False means the tree below is complete.',
				],
				'keptDepth'    => [
					'type'        => 'integer',
					'description' => 'The greatest zero-based nesting depth this response carries. Elements below it were dropped; the ones that were kept still report their true childCount.',
				],
				'omittedNodes' => [
					'type'        => 'integer',
					'description' => 'How many elements of the requested tree are not in this response.',
				],
				'message'      => [
					'type'        => 'string',
					'description' => 'A sentence naming what was left out and which call returns it. Empty when nothing was narrowed.',
				],
			],
			'required'             => [ 'applied', 'keptDepth', 'omittedNodes', 'message' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * One narrowing report.
	 *
	 * @param bool $applied  Whether anything was dropped.
	 * @param int  $depth    The deepest level kept.
	 * @param int  $omitted  How many elements were dropped.
	 * @param int  $total    How many elements the requested tree holds.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function report( bool $applied, int $depth, int $omitted, int $total ): array {
		return [
			'applied'      => $applied,
			'keptDepth'    => max( 0, $depth ),
			'omittedNodes' => $omitted,
			'message'      => $applied ? sprintf( self::MESSAGE, max( 0, $depth ), $omitted, $total ) : '',
		];
	}

	/**
	 * The tree with everything below one depth removed.
	 *
	 * @param array<int, array<string, mixed>> $nodes The tree.
	 * @param int                              $depth The deepest level to keep.
	 *
	 * @return array<int, array<string, mixed>> The pruned tree.
	 */
	private static function toDepth( array $nodes, int $depth ): array {
		$pruned = [];

		foreach ( $nodes as $node ) {
			$children = is_array( $node['children'] ?? null ) ? $node['children'] : [];

			// The count stays whatever the document says. Rewriting it to match
			// the shortened `children` array is the one change that would make
			// the omission invisible.
			$node['children'] = $depth > (int) ( $node['depth'] ?? 0 )
				? self::toDepth( $children, $depth )
				: [];

			$pruned[] = $node;
		}

		return $pruned;
	}

	/**
	 * How many elements a tree holds, at every level.
	 *
	 * @param array<int, array<string, mixed>> $nodes The tree.
	 *
	 * @return int The count.
	 */
	private static function countNodes( array $nodes ): int {
		$count = 0;

		foreach ( $nodes as $node ) {
			++$count;
			$children = $node['children'] ?? [];
			$count   += is_array( $children ) ? self::countNodes( $children ) : 0;
		}

		return $count;
	}

	/**
	 * The greatest zero-based depth present in a tree.
	 *
	 * @param array<int, array<string, mixed>> $nodes The tree.
	 * @param int                              $depth The level this call is walking.
	 *
	 * @return int The deepest level, and 0 for an empty tree.
	 */
	private static function deepest( array $nodes, int $depth = 0 ): int {
		$deepest = 0;

		foreach ( $nodes as $node ) {
			$children = $node['children'] ?? [];
			$below    = is_array( $children ) && [] !== $children ? self::deepest( $children, $depth + 1 ) : $depth;
			$deepest  = max( $deepest, $below );
		}

		return $deepest;
	}

	/**
	 * How many bytes a tree occupies once encoded.
	 *
	 * Measured with the encoder the transport itself uses, because a measure
	 * taken with a different one is a measure of a different string — and a
	 * ceiling that is wrong in the direction of "it fits" is no ceiling.
	 *
	 * @param array<int, array<string, mixed>> $nodes The tree.
	 *
	 * @return int The encoded length in bytes.
	 */
	private static function bytes( array $nodes ): int {
		$encoded = wp_json_encode( $nodes );

		return is_string( $encoded ) ? strlen( $encoded ) : 0;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
