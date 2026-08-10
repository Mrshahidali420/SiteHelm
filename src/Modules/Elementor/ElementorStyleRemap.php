<?php
/**
 * Local style class remapping for re-ided Elementor subtrees.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Rebinds an element's local CSS classes to its new id (issue #97).
 *
 * Elementor stores a per-element local class inside `_elementor_data`, under the
 * owning element's `styles` key, named `e-<element_id>-<hash>` and referenced
 * from that element's `settings.classes.value`. The name is BOUND TO THE OWNING
 * ELEMENT'S ID. Duplicating or re-iding an element without rewriting those names
 * leaves the copy sharing one class definition with its source, so editing
 * either one silently restyles the other. That is issue #97, and it is why this
 * class exists as a separate, mandatory pass after `ElementorIdMint::reassign()`.
 *
 * BOTH SIDES ARE REWRITTEN, and either one alone is a defect. Rewriting only the
 * `styles` keys leaves `settings.classes.value` pointing at a class that no
 * longer exists, so the copy renders unstyled. Rewriting only the references
 * leaves two elements sharing one definition, which is the bleed itself.
 *
 * GLOBAL CLASSES ARE OUT OF SCOPE, DELIBERATELY AND PERMANENTLY. A `g-` prefixed
 * class lives in Elementor's own class repository, NOT in `_elementor_data`. No
 * write in this phase touches one, and — stated here so nobody later reads a
 * document snapshot as a complete style backup — no snapshot of `_elementor_data`
 * captures one either. A restore therefore restores local classes and leaves
 * global ones exactly as the site currently holds them.
 *
 * PURE, like the mint it follows: no WordPress function, no `\Elementor\`
 * symbol, no state.
 *
 * @package SiteHelm
 */
final class ElementorStyleRemap {

	/**
	 * The raw key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The raw key holding a node's own style definitions.
	 */
	private const STYLES_KEY = 'styles';

	/**
	 * The raw key holding a node's settings.
	 */
	private const SETTINGS_KEY = 'settings';

	/**
	 * The settings key holding the node's class binding.
	 */
	private const CLASSES_KEY = 'classes';

	/**
	 * The class binding's key holding the referenced class names.
	 */
	private const VALUE_KEY = 'value';

	/**
	 * A style definition's own copy of its name.
	 */
	private const ID_KEY = 'id';

	/**
	 * The prefix marking a class as local to one element. A `g-` prefixed
	 * global class does not match it and is therefore never touched.
	 */
	private const LOCAL_PREFIX = 'e-';

	/**
	 * Separates the parts of a local class name, and its tokens in a string.
	 */
	private const CLASS_SEPARATOR = '-';

	/**
	 * Separates class names when they are stored as one string.
	 */
	private const TOKEN_SEPARATOR = ' ';

	/**
	 * Rebinds every local class in a subtree to the new owning element ids.
	 *
	 * The whole map is applied at every node rather than only that node's own
	 * entry, because one element in a duplicated subtree may legitimately
	 * reference a class its sibling or ancestor owns; matching only the local
	 * entry would leave that reference pointing back into the source.
	 *
	 * @param array<string, mixed>  $subtree One raw element, the subtree root.
	 * @param array<string, string> $id_map  Old element id => new element id.
	 *
	 * @return array<string, mixed> The rebound subtree.
	 */
	public function remap( array $subtree, array $id_map ): array {
		$styles = $subtree[ self::STYLES_KEY ] ?? null;

		if ( is_array( $styles ) ) {
			$subtree[ self::STYLES_KEY ] = $this->definitions( $styles, $id_map );
		}

		$referenced = $subtree[ self::SETTINGS_KEY ][ self::CLASSES_KEY ][ self::VALUE_KEY ] ?? null;

		if ( null !== $referenced ) {
			$subtree[ self::SETTINGS_KEY ][ self::CLASSES_KEY ][ self::VALUE_KEY ] = $this->references( $referenced, $id_map );
		}

		$children = $subtree[ self::CHILDREN_KEY ] ?? null;

		if ( ! is_array( $children ) ) {
			return $subtree;
		}

		foreach ( $children as $key => $child ) {
			if ( is_array( $child ) ) {
				$subtree[ self::CHILDREN_KEY ][ $key ] = $this->remap( $child, $id_map );
			}
		}

		return $subtree;
	}

	/**
	 * Renames one element's style definitions.
	 *
	 * The definition's own `id` member is renamed WITH its key, and only when
	 * the two already agree. Elementor writes the name in both places, and a
	 * copy whose key and inner id disagreed would be a document the editor
	 * reads one way and the CSS generator another.
	 *
	 * Insertion order is preserved: the definitions are rebuilt in the order
	 * read, so a re-ided document is byte-comparable against a second run.
	 *
	 * @param array<array-key, mixed> $styles One node's style definitions.
	 * @param array<string, string>   $id_map Old element id => new element id.
	 *
	 * @return array<array-key, mixed> The renamed definitions.
	 */
	private function definitions( array $styles, array $id_map ): array {
		$renamed = [];

		foreach ( $styles as $key => $definition ) {
			$name = is_string( $key ) ? $this->rename( $key, $id_map ) : $key;

			if ( is_array( $definition ) && ( $definition[ self::ID_KEY ] ?? null ) === $key ) {
				$definition[ self::ID_KEY ] = $name;
			}

			$renamed[ $name ] = $definition;
		}

		return $renamed;
	}

	/**
	 * Rewrites the class names one node references.
	 *
	 * Both stored forms are handled: the list Elementor's atomic widgets write,
	 * and the single space-separated string older documents carry. A member
	 * that is neither is returned untouched rather than cast, because a
	 * reference this class does not understand is not a reference it may
	 * rewrite.
	 *
	 * @param mixed                 $referenced The stored `classes.value`.
	 * @param array<string, string> $id_map     Old element id => new element id.
	 *
	 * @return mixed The rewritten value, in the form it arrived in.
	 */
	private function references( mixed $referenced, array $id_map ): mixed {
		if ( is_string( $referenced ) ) {
			return implode(
				self::TOKEN_SEPARATOR,
				array_map(
					fn( string $token ): string => $this->rename( $token, $id_map ),
					explode( self::TOKEN_SEPARATOR, $referenced )
				)
			);
		}

		if ( ! is_array( $referenced ) ) {
			return $referenced;
		}

		foreach ( $referenced as $key => $name ) {
			if ( is_string( $name ) ) {
				$referenced[ $key ] = $this->rename( $name, $id_map );
			}
		}

		return $referenced;
	}

	/**
	 * One class name, rebound to its owner's new id.
	 *
	 * A name that does not carry the local prefix, does not carry an owner
	 * segment, or whose owner is not in the map is returned unchanged — which
	 * is what keeps global classes and classes owned by elements outside the
	 * copied subtree out of the rewrite.
	 *
	 * @param string                $name   One class name.
	 * @param array<string, string> $id_map Old element id => new element id.
	 *
	 * @return string The rebound name.
	 */
	private function rename( string $name, array $id_map ): string {
		if ( ! str_starts_with( $name, self::LOCAL_PREFIX ) ) {
			return $name;
		}

		$remainder = substr( $name, strlen( self::LOCAL_PREFIX ) );
		$boundary  = strpos( $remainder, self::CLASS_SEPARATOR );

		if ( false === $boundary ) {
			return $name;
		}

		$owner = substr( $remainder, 0, $boundary );

		if ( ! isset( $id_map[ $owner ] ) ) {
			return $name;
		}

		return self::LOCAL_PREFIX . $id_map[ $owner ] . substr( $remainder, $boundary );
	}
}
