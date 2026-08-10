<?php
/**
 * The before-and-after element tree diff a preview carries.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Describes what one tree write will do to a document's structure (REQ-0035).
 *
 * PURE. Like `ElementorTree`, it calls no WordPress function and names no
 * `\Elementor\` symbol, so there is no double behind it that could be
 * unfaithful.
 *
 * The result is the machine-only `previewDetail` a `PlannedChange` carries:
 *
 *     { before: node[], after: node[], changes: change[] }
 *     change := { op: added|removed|moved|updated, elementId, fromPath, toPath }
 *
 * `before` and `after` are `ElementorTree`-normalized node lists, so the frozen
 * node shape is the only shape a client has to know, and `settings` — which the
 * normalizer drops — never reaches a response for the whole tree.
 *
 * IT REFUSES RATHER THAN TRUNCATES, and it does so by construction: both trees
 * are normalized through `ElementorTree` FIRST, so `MAX_NODES` and `MAX_DEPTH`
 * throw before any indexing runs. A short tree that looks complete is how a
 * preview stops describing the change it asks approval for, and that bound is
 * inherited here rather than restated so the two can never drift apart.
 *
 * ONLY AN ADDRESSABLE ELEMENT PRODUCES A CHANGE ENTRY. `ElementorTree` reports
 * `id: null` for a stored element that declares none — old exported templates
 * are full of them — because such an element cannot be named by any write. Two
 * kinds of node are therefore skipped when the change list is built:
 *
 *  - one with no stored `id`, since there is nothing to key it by; and
 *  - one whose `id` occurs more than once on its own side, since keying by a
 *    repeated id would match the wrong sibling and report a change against an
 *    element the write would not touch.
 *
 * In both cases the node still appears in `before`/`after`, and its descendants
 * are still walked: an addressable child of an unaddressable parent is a real
 * change, and hiding it behind an old template's untyped wrapper would be worse
 * than reporting it.
 *
 * @package SiteHelm
 */
final class ElementorTreeDiff {

	public const OP_ADDED   = 'added';
	public const OP_REMOVED = 'removed';
	public const OP_MOVED   = 'moved';
	public const OP_UPDATED = 'updated';

	/**
	 * The raw key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The raw key holding a node's identifier.
	 */
	private const ID_KEY = 'id';

	/**
	 * Separates the zero-based child positions in a path.
	 */
	private const PATH_SEPARATOR = '.';

	/**
	 * The marker an index entry carries once its id proved ambiguous.
	 */
	private const AMBIGUOUS = null;

	/**
	 * Constructs the diff over the shared normalizer.
	 *
	 * @param ElementorTree $tree The normalizer whose bounds this inherits.
	 */
	public function __construct( private readonly ElementorTree $tree ) {
	}

	/**
	 * Diffs two raw stored element trees.
	 *
	 * @param array[] $before_raw The raw decoded tree as stored.
	 * @param array[] $after_raw  The raw decoded tree the write would store.
	 *
	 * @return array<string, mixed> Keys 'before', 'after' and 'changes'.
	 *
	 * @throws \SiteHelm\Contracts\OperationException With ErrorCode::ExecutionFailed
	 *                           when either tree breaches ElementorTree's bounds.
	 */
	public function diff( array $before_raw, array $after_raw ): array {
		// Normalization runs FIRST on both sides, so a bound breach refuses
		// before any index is built and no partial answer can be assembled.
		$before = $this->tree->normalize( $before_raw )['nodes'];
		$after  = $this->tree->normalize( $after_raw )['nodes'];

		$before_index = $this->index( $before_raw );
		$after_index  = $this->index( $after_raw );

		return [
			'before'  => $before,
			'after'   => $after,
			'changes' => $this->changes( $before_index, $after_index ),
		];
	}

	/**
	 * The flat change list, in a deterministic order.
	 *
	 * Every addressable element of the before-tree is considered in document
	 * order first, then every element the after-tree newly holds. A determinate
	 * order matters because the operator approves this list and an apply
	 * re-derives it: two orderings of the same facts would fingerprint
	 * differently and look like two different plans.
	 *
	 * A relocated element whose stored content ALSO changed reports both a
	 * `moved` and an `updated`, rather than one of them. Collapsing the pair
	 * would show an operator a relocation and silently carry an edit with it.
	 *
	 * @param array<string, array<string, mixed>|null> $before The before index.
	 * @param array<string, array<string, mixed>|null> $after  The after index.
	 *
	 * @return array[] The change entries.
	 */
	private function changes( array $before, array $after ): array {
		$changes = [];

		foreach ( $before as $id => $entry ) {
			if ( self::AMBIGUOUS === $entry ) {
				continue;
			}

			if ( ! array_key_exists( $id, $after ) ) {
				$changes[] = $this->change( self::OP_REMOVED, (string) $id, $entry['path'], null );
				continue;
			}

			$destination = $after[ $id ];
			// The id is unaddressable on the other side, so nothing about it can
			// be reported honestly — least of all a removal it did not undergo.
			if ( self::AMBIGUOUS === $destination ) {
				continue;
			}

			if ( $entry['path'] !== $destination['path'] ) {
				$changes[] = $this->change( self::OP_MOVED, (string) $id, $entry['path'], $destination['path'] );
			}

			if ( $entry['self'] !== $destination['self'] ) {
				$changes[] = $this->change( self::OP_UPDATED, (string) $id, $entry['path'], $destination['path'] );
			}
		}

		foreach ( $after as $id => $entry ) {
			if ( self::AMBIGUOUS === $entry || array_key_exists( $id, $before ) ) {
				continue;
			}

			$changes[] = $this->change( self::OP_ADDED, (string) $id, null, $entry['path'] );
		}

		return $changes;
	}

	/**
	 * One change entry in the shape Tasks 6-9 consume.
	 *
	 * @param string      $op        One of the four OP_ constants.
	 * @param string      $elementId The stored element id.
	 * @param string|null $fromPath  The path in the before-tree, null when added.
	 * @param string|null $toPath    The path in the after-tree, null when removed.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function change( string $op, string $elementId, ?string $fromPath, ?string $toPath ): array {
		return [
			'op'        => $op,
			'elementId' => $elementId,
			'fromPath'  => $fromPath,
			'toPath'    => $toPath,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Indexes one raw tree by stored element id.
	 *
	 * An id seen twice has its entry replaced by null — the ambiguity marker —
	 * rather than overwritten, so neither occurrence is ever reported. It is
	 * recorded rather than simply skipped because the SECOND occurrence must
	 * also un-report the first, and a plain `isset()` skip would keep it.
	 *
	 * Non-array members are skipped exactly as `ElementorTree` skips them, so a
	 * path here indexes the same node the normalized list holds at that
	 * position. Diverging on that one rule would make every path after a stray
	 * value name a different element than the one it describes.
	 *
	 * The recursion is bounded because `diff()` normalized this same tree first
	 * and `ElementorTree` threw if it was deeper than MAX_DEPTH or larger than
	 * MAX_NODES.
	 *
	 * @param mixed                                    $raw    The raw child list.
	 * @param string                                   $prefix The path of the parent, '' at the root.
	 * @param array<string, array<string, mixed>|null> $index  The running index, by reference.
	 *
	 * @return array<string, array<string, mixed>|null> The index.
	 */
	private function index( mixed $raw, string $prefix = '', array &$index = [] ): array {
		if ( ! is_array( $raw ) ) {
			return $index;
		}

		$position = 0;

		foreach ( $raw as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $position : $prefix . self::PATH_SEPARATOR . $position;
			++$position;

			$id = $element[ self::ID_KEY ] ?? null;
			if ( is_scalar( $id ) && '' !== (string) $id ) {
				$key           = (string) $id;
				$index[ $key ] = array_key_exists( $key, $index )
					? self::AMBIGUOUS
					: [
						'path' => $path,
						'self' => $this->stored( $element ),
					];
			}

			$this->index( $element[ self::CHILDREN_KEY ] ?? null, $path, $index );
		}

		return $index;
	}

	/**
	 * One element's own stored content, children excluded, in a stable order.
	 *
	 * The comparison is made against the RAW element rather than the normalized
	 * node deliberately. `ElementorTree` drops `settings`, so a diff computed
	 * from normalized nodes alone would see nothing at all for a widget settings
	 * write — a preview reporting no change for the change about to be made.
	 * The values are compared, never returned: the result of this method reaches
	 * no response.
	 *
	 * Keys are sorted at every level because `_elementor_data` is decoded JSON
	 * written by whatever last saved the document, and two encoders can store
	 * the same element with its keys in a different order. Reporting that as an
	 * edit would put a phantom change in front of an operator.
	 *
	 * @param array<string, mixed> $element One raw element.
	 *
	 * @return array<string, mixed> The comparable stored content.
	 */
	private function stored( array $element ): array {
		unset( $element[ self::CHILDREN_KEY ] );

		return $this->canonical( $element );
	}

	/**
	 * Sorts array keys at every level so comparison ignores stored key order.
	 *
	 * @param array<array-key, mixed> $value The array to canonicalize.
	 *
	 * @return array<array-key, mixed> The key-sorted array.
	 */
	private function canonical( array $value ): array {
		foreach ( $value as $key => $member ) {
			if ( is_array( $member ) ) {
				$value[ $key ] = $this->canonical( $member );
			}
		}

		ksort( $value );

		return $value;
	}
}
