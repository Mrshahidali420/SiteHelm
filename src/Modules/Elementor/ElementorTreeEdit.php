<?php
/**
 * Structural surgery on the raw stored Elementor element tree.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Finds, inserts, removes and moves elements in a RAW stored tree.
 *
 * IT OPERATES ON THE RAW TREE `ElementorDocument::elements()` RETURNS, NEVER ON
 * `ElementorTree`'s normalized output, and that is not a preference. The
 * normalizer emits nine keys — `id`, `elType`, `widgetType`, `kind`, `label`,
 * `depth`, `childCount`, `children` — and DROPS everything else the document
 * stores: `settings`, `styles`, `editor_settings`, and every key a third-party
 * plugin wrote. A write that round-tripped a document through it would report
 * success while silently deleting all of that. Every method here therefore
 * copies whole stored elements and touches nothing but the child lists it must.
 *
 * NO PARTIAL STATE IS PRODUCIBLE. Nothing is mutated in place: each method
 * returns a NEW tree and leaves the caller's untouched, and `move()` completes
 * every validation before the first of its two edits. A move into a parent that
 * does not exist therefore leaves the tree byte-identical rather than
 * removed-but-not-reinserted — a state that would delete an element and report
 * a failure at the same time.
 *
 * AN ELEMENT WITH NO STORED ID IS UNMATCHABLE, by design. `find()` matches on
 * the STORED id, and Phase 6a made that id nullable precisely because old
 * exported templates contain sibling elements that declare none. Matching the
 * first idless node would let a write edit an element the operator never named.
 * Such a node is still walked THROUGH — an addressable child of an
 * unaddressable parent is addressable — but `find()` then reports its parent as
 * `parentId: null, parentAddressable: false`. Callers MUST read that flag before
 * feeding `parentId` back into `insert()`, because a null parent means the
 * document root there: re-inserting a copy "beside its original" inside an
 * idless container would otherwise silently move it to the top level.
 *
 * PURE: no WordPress function, no `\Elementor\` symbol, no state.
 *
 * @package SiteHelm
 */
final class ElementorTreeEdit {

	/**
	 * The raw key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The raw key holding a node's identifier.
	 */
	private const ID_KEY = 'id';

	/**
	 * Separates the parent id from the position in a path.
	 */
	private const PATH_SEPARATOR = '/';

	/**
	 * One element, with where it sits, or null when the tree does not hold it.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The stored element id to look for.
	 *
	 * @return array<string, mixed>|null Keys 'node', 'path', 'parentId',
	 *                                   'parentAddressable' and 'index'.
	 */
	public function find( array $tree, string $element_id ): ?array {
		return $this->locate( $tree, $element_id, null, true );
	}

	/**
	 * Where one element sits, as `parentId/index`, or null when absent.
	 *
	 * A root-level element has no parent and reads `/index`.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The stored element id.
	 *
	 * @return string|null The path.
	 */
	public function path( array $tree, string $element_id ): ?string {
		$found = $this->find( $tree, $element_id );

		return null === $found ? null : $found['path'];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every usable stored id in the tree, in document order.
	 *
	 * This is what seeds `ElementorIdMint`'s collision walk, so it reports only
	 * ids a write could actually collide with: an absent, empty or non-scalar
	 * id names nothing and is skipped.
	 *
	 * @param array[] $tree The raw stored tree.
	 *
	 * @return string[] The stored ids.
	 */
	public function collectIds( array $tree ): array {
		$ids = [];

		$this->gather( $tree, $ids );

		return $ids;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * A copy of the tree with one element inserted.
	 *
	 * The index is clamped to the destination's bounds rather than refused: a
	 * position past the end means "last", which is what an append asks for, and
	 * the write operations bound the caller-supplied value themselves.
	 *
	 * @param array[]              $tree      The raw stored tree.
	 * @param string|null          $parent_id The destination parent, null for the document root.
	 * @param int                  $index     The zero-based position among the destination's children.
	 * @param array<string, mixed> $node      The raw element to insert.
	 *
	 * @return array[] The new tree.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the parent
	 *                           is not in the tree.
	 */
	public function insert( array $tree, ?string $parent_id, int $index, array $node ): array {
		if ( null === $parent_id ) {
			return $this->spliced( $tree, $index, $node );
		}

		$inserted = $this->into( $tree, $parent_id, $index, $node );

		if ( null === $inserted ) {
			throw $this->absent();
		}

		return $inserted;
	}

	/**
	 * A copy of the tree with one element, and everything under it, gone.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The stored element id to remove.
	 *
	 * @return array[] The new tree.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the element
	 *                           is not in the tree.
	 */
	public function remove( array $tree, string $element_id ): array {
		$without = $this->excluding( $tree, $element_id );

		if ( null === $without ) {
			throw $this->absent();
		}

		return $without;
	}

	/**
	 * A copy of the tree with one element relocated.
	 *
	 * FIND, VALIDATE, REMOVE, INSERT — in that order, and every validation
	 * happens before the first mutation. That ordering is the whole safety
	 * property of this method: a refused move returns the caller's tree
	 * unchanged, so no state exists in which the element was removed from its
	 * old home and never reached its new one.
	 *
	 * The index counts positions in the destination AFTER the element has left
	 * its old position, which is the only interpretation under which moving an
	 * element three places later within its own parent means what it says.
	 *
	 * @param array[]     $tree       The raw stored tree.
	 * @param string      $element_id The stored element id to move.
	 * @param string|null $parent_id  The destination parent, null for the document root.
	 * @param int         $index      The zero-based destination position.
	 *
	 * @return array[] The new tree.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the element
	 *                           or the destination parent is absent, and with
	 *                           ErrorCode::InvalidInput when the destination is
	 *                           inside the element being moved.
	 */
	public function move( array $tree, string $element_id, ?string $parent_id, int $index ): array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal carrying no value from the request; the sniff registers on T_THROW and cannot tell.
		$found = $this->find( $tree, $element_id );

		if ( null === $found ) {
			throw $this->absent();
		}

		if ( null !== $parent_id ) {
			// The element itself and every descendant of it, in one test. A move
			// into either detaches the subtree from the document entirely.
			if ( null !== $this->find( [ $found['node'] ], $parent_id ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'An element cannot be moved inside itself or inside one of its own children.',
					'Choose a destination outside the element being moved, and retry.'
				);
			}

			if ( null === $this->find( $tree, $parent_id ) ) {
				throw $this->absent();
			}
		}

		return $this->insert( $this->remove( $tree, $element_id ), $parent_id, $index, $found['node'] );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Walks one child list looking for an element.
	 *
	 * @param array<array-key, mixed> $children  One raw child list.
	 * @param string                  $element_id The stored element id.
	 * @param string|null             $parent_id The id of the list's owner, null at the root.
	 * @param bool                    $addressable Whether the list's owner can be named.
	 *
	 * @return array<string, mixed>|null The location, or null.
	 */
	private function locate( array $children, string $element_id, ?string $parent_id, bool $addressable ): ?array {
		$position = 0;

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$stored = $child[ self::ID_KEY ] ?? null;
			$id     = is_scalar( $stored ) && '' !== (string) $stored ? (string) $stored : null;

			// An idless node is unmatchable. Its children are not: the walk goes
			// through it, carrying the fact that its parent cannot be named.
			if ( null !== $id && $id === $element_id ) {
				return [
					'node'              => $child,
					'path'              => ( $parent_id ?? '' ) . self::PATH_SEPARATOR . $position,
					'parentId'          => $parent_id,
					'parentAddressable' => $addressable,
					'index'             => $position,
				];
			}

			$grandchildren = $child[ self::CHILDREN_KEY ] ?? null;

			if ( is_array( $grandchildren ) ) {
				$found = $this->locate( $grandchildren, $element_id, $id, null !== $id );

				if ( null !== $found ) {
					return $found;
				}
			}

			++$position;
		}

		return null;
	}

	/**
	 * Collects the stored ids of one child list and everything below it.
	 *
	 * @param array<array-key, mixed> $children One raw child list.
	 * @param string[]                $ids      The running id list, by reference.
	 */
	private function gather( array $children, array &$ids ): void {
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$stored = $child[ self::ID_KEY ] ?? null;

			if ( is_scalar( $stored ) && '' !== (string) $stored ) {
				$ids[] = (string) $stored;
			}

			$grandchildren = $child[ self::CHILDREN_KEY ] ?? null;

			if ( is_array( $grandchildren ) ) {
				$this->gather( $grandchildren, $ids );
			}
		}
	}

	/**
	 * Inserts into the named parent's child list, wherever that parent is.
	 *
	 * Returns null rather than throwing so the recursion can report "not in
	 * this branch" to itself, and the one refusal is raised once by `insert()`.
	 *
	 * @param array<array-key, mixed> $children  One raw child list.
	 * @param string                  $parent_id The destination parent.
	 * @param int                     $index     The zero-based destination position.
	 * @param array<string, mixed>    $node      The raw element to insert.
	 *
	 * @return array<array-key, mixed>|null The rewritten list, or null when the
	 *                                      parent is not in this branch.
	 */
	private function into( array $children, string $parent_id, int $index, array $node ): ?array {
		foreach ( $children as $key => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$stored        = $child[ self::ID_KEY ] ?? null;
			$grandchildren = $child[ self::CHILDREN_KEY ] ?? null;
			$existing      = is_array( $grandchildren ) ? $grandchildren : [];

			if ( is_scalar( $stored ) && '' !== (string) $stored && (string) $stored === $parent_id ) {
				$child[ self::CHILDREN_KEY ] = $this->spliced( $existing, $index, $node );
				$children[ $key ]            = $child;

				return $children;
			}

			$deeper = $this->into( $existing, $parent_id, $index, $node );

			if ( null !== $deeper ) {
				$child[ self::CHILDREN_KEY ] = $deeper;
				$children[ $key ]            = $child;

				return $children;
			}
		}

		return null;
	}

	/**
	 * Drops one element from a child list, wherever it is.
	 *
	 * @param array<array-key, mixed> $children   One raw child list.
	 * @param string                  $element_id The stored element id.
	 *
	 * @return array<array-key, mixed>|null The rewritten list, or null when the
	 *                                      element is not in this branch.
	 */
	private function excluding( array $children, string $element_id ): ?array {
		foreach ( $children as $key => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$stored = $child[ self::ID_KEY ] ?? null;

			if ( is_scalar( $stored ) && '' !== (string) $stored && (string) $stored === $element_id ) {
				unset( $children[ $key ] );

				return array_values( $children );
			}

			$grandchildren = $child[ self::CHILDREN_KEY ] ?? null;

			if ( ! is_array( $grandchildren ) ) {
				continue;
			}

			$deeper = $this->excluding( $grandchildren, $element_id );

			if ( null !== $deeper ) {
				$child[ self::CHILDREN_KEY ] = $deeper;
				$children[ $key ]            = $child;

				return $children;
			}
		}

		return null;
	}

	/**
	 * One child list with a node inserted at a bounded position.
	 *
	 * @param array<array-key, mixed> $children One raw child list.
	 * @param int                     $index    The requested position.
	 * @param array<string, mixed>    $node     The raw element to insert.
	 *
	 * @return array[] The new list.
	 */
	private function spliced( array $children, int $index, array $node ): array {
		$list  = array_values( $children );
		$bound = max( 0, min( $index, count( $list ) ) );

		array_splice( $list, $bound, 0, [ $node ] );

		return $list;
	}

	/**
	 * The one refusal a target this class cannot find produces.
	 *
	 * IT NAMES NO ELEMENT ID. The id came from the caller's payload and the
	 * envelope repeats no value; the field is named in `data.state` by the
	 * operation that raised this.
	 *
	 * @return OperationException The refusal.
	 */
	private function absent(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The element this change names is not in the page\'s stored Elementor content.',
			'Re-read the page\'s element tree, confirm the element is still there, and retry with a current id.'
		);
	}
}
