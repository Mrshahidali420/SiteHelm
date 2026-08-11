<?php
/**
 * Deterministic element id minting for the Elementor writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Mints the element ids a write stores, deterministically (spec Decision 2).
 *
 * DETERMINISM IS THE LOAD-BEARING PROPERTY OF THIS CLASS, and it is the reason
 * every obvious alternative was rejected. `planChange()` runs twice — once to
 * build the preview an operator approves, once at apply — and the two payloads
 * are digest-compared. An id drawn from `wp_unique_id()`, `uniqid()`, `rand()`,
 * or a clock differs between those two runs, so the digests differ, so the plan
 * is un-appliable: not intermittently, but every time, for every write in this
 * phase. There is therefore NO source of entropy in this file, and there must
 * never be one. It reads no clock, no global, no request, and no randomness; it
 * is a pure function of its arguments.
 *
 * The derivation the specification freezes:
 *
 *     seed = operationId . "\0" . postId . "\0" . stateFingerprint . "\0" . payloadSeed
 *     id   = substr( hash( 'sha256', seed . "\0" . attempt ), 0, 7 )
 *
 * The caller assembles the first line, because every value in it is one the plan
 * already pins; this class owns the second. Seven lowercase hex characters is
 * Elementor's own id shape, so a minted id is indistinguishable from one the
 * editor would have written.
 *
 * THE COLLISION WALK IS DETERMINISTIC TOO. `attempt` starts at 0 and increments
 * while the candidate collides with an id the document already holds, and the
 * document is pinned across the two runs by `assertStateUnchanged()`, which
 * ChangeEngine runs BEFORE `assertPayloadMatches()`. A document edited between
 * preview and apply therefore reports `conflict` rather than producing a silent
 * id divergence here. The walk always terminates: `existing_ids` is bounded by
 * ElementorTree's node ceiling, which is a rounding error against the 268
 * million ids seven hex characters address.
 *
 * THIS IS NOT THE DERIVED-IDENTITY DEFECT PHASE 6a REJECTED. There, a READ would
 * have synthesized a positional identifier for an element whose real identity is
 * absent, and reported it as stored. Here the element does not exist yet and the
 * minted id BECOMES the stored id, so nothing is misrepresented.
 *
 * @package SiteHelm
 */
final class ElementorIdMint {

	/**
	 * Elementor's own id shape: seven lowercase hex characters.
	 */
	public const ID_LENGTH = 7;

	/**
	 * The raw key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The raw key holding a node's identifier.
	 */
	private const ID_KEY = 'id';

	/**
	 * Separates the parts of a seed. NUL cannot occur in any part, so no two
	 * different tuples can assemble the same seed string.
	 */
	private const SEED_SEPARATOR = "\0";

	/**
	 * Separates the positions of a node's path within the subtree.
	 */
	private const PATH_SEPARATOR = '.';

	/**
	 * The digest the derivation is frozen on.
	 */
	private const ALGORITHM = 'sha256';

	/**
	 * One element id, derived from the seed and free of collisions.
	 *
	 * @param string   $seed         The caller-assembled seed, attempt excluded.
	 * @param string[] $existing_ids The ids the document already holds.
	 *
	 * @return string Seven lowercase hex characters.
	 */
	public function mint( string $seed, array $existing_ids ): string {
		$taken   = $this->taken( $existing_ids );
		$attempt = 0;

		while ( true ) {
			$candidate = substr(
				hash( self::ALGORITHM, $seed . self::SEED_SEPARATOR . $attempt ),
				0,
				self::ID_LENGTH
			);

			if ( ! isset( $taken[ $candidate ] ) ) {
				return $candidate;
			}

			++$attempt;
		}
	}

	/**
	 * Re-ids one subtree and EVERY descendant it holds.
	 *
	 * The returned map is what `ElementorStyleRemap` consumes: re-iding an
	 * element without remapping the local style classes bound to its old id is
	 * issue #97, style bleed between a copy and its source. A caller that
	 * re-ids and does not remap has half-done the work.
	 *
	 * A node that stores no usable id is left UNNAMED rather than given one.
	 * Old exported templates are full of such elements; they were unaddressable
	 * where they stood and the copy is unaddressable in exactly the same way,
	 * which is the honest outcome. Inventing an id for one would be Phase 6a's
	 * rejected derived identity, one level down.
	 *
	 * Each descendant folds its own position and source id into the seed, so
	 * two siblings with identical stored content still take different ids, and
	 * both take the same ids on the second run.
	 *
	 * @param array<string, mixed> $subtree      One raw element, the subtree root.
	 * @param string               $seed         The caller-assembled seed.
	 * @param string[]             $existing_ids The ids the document already holds.
	 *
	 * @return array<string, mixed> Keys 'tree' (the re-ided subtree) and 'map'
	 *                              (old id => new id, in document order).
	 */
	public function reassign( array $subtree, string $seed, array $existing_ids ): array {
		$taken = $existing_ids;
		$map   = [];

		return [
			'tree' => $this->rewrite( $subtree, $seed, '', $taken, $map ),
			'map'  => $map,
		];
	}

	/**
	 * Re-ids one element and recurses into its children.
	 *
	 * Every minted id is appended to `$taken` before the walk continues, so a
	 * descendant can never be given an id one of its own relatives just took.
	 *
	 * @param array<string, mixed>  $element One raw element.
	 * @param string                $seed    The caller-assembled seed.
	 * @param string                $path    This node's position path, '' at the root.
	 * @param string[]              $taken   The running id set, by reference.
	 * @param array<string, string> $map     The running old-to-new map, by reference.
	 *
	 * @return array<string, mixed> The re-ided element.
	 */
	private function rewrite( array $element, string $seed, string $path, array &$taken, array &$map ): array {
		$stored = $element[ self::ID_KEY ] ?? null;

		if ( is_scalar( $stored ) && '' !== (string) $stored ) {
			$old   = (string) $stored;
			$fresh = $this->mint( $seed . self::SEED_SEPARATOR . $path . self::SEED_SEPARATOR . $old, $taken );

			$taken[]                 = $fresh;
			$map[ $old ]             = $fresh;
			$element[ self::ID_KEY ] = $fresh;
		}

		$children = $element[ self::CHILDREN_KEY ] ?? null;

		if ( ! is_array( $children ) ) {
			return $element;
		}

		$position = 0;

		foreach ( $children as $key => $child ) {
			// A scalar where a child element belongs is a damaged export. It is
			// skipped without consuming a position, exactly as ElementorTreeDiff
			// skips it, so a path here names the same node a diff path does.
			if ( ! is_array( $child ) ) {
				continue;
			}

			$element[ self::CHILDREN_KEY ][ $key ] = $this->rewrite(
				$child,
				$seed,
				'' === $path ? (string) $position : $path . self::PATH_SEPARATOR . $position,
				$taken,
				$map
			);
			++$position;
		}

		return $element;
	}

	/**
	 * The existing ids as a lookup set.
	 *
	 * A non-scalar member is dropped rather than cast. `collectIds()` never
	 * produces one, but an id read straight off a damaged document can be an
	 * array, and casting it would put the string `Array` in the set and block a
	 * candidate for no reason.
	 *
	 * @param mixed[] $existing_ids The ids the document already holds.
	 *
	 * @return array<string, bool> The lookup set.
	 */
	private function taken( array $existing_ids ): array {
		$taken = [];

		foreach ( $existing_ids as $id ) {
			if ( is_scalar( $id ) ) {
				$taken[ (string) $id ] = true;
			}
		}

		return $taken;
	}
}
