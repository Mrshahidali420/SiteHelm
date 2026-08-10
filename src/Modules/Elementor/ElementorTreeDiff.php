<?php
/**
 * The before-and-after element tree diff a preview carries.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

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
 * inherited here rather than restated so the two can never drift apart. The one
 * bound `ElementorTree` does NOT provide is on the depth of an element's
 * `settings` array — it drops settings, so it never walks them — and this class
 * does walk them when it compares stored content. MAX_SETTINGS_DEPTH is that
 * bound, and it refuses rather than truncating for the same reason: a comparison
 * made against a silently shortened value would report "no change" for a change.
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
 * `moved` IS DECIDED ON POSITION AMONG SURVIVORS, NOT ON THE PATH. A path is a
 * chain of zero-based child positions, so inserting or removing ONE child
 * renumbers every later sibling; deciding `moved` by path equality would render
 * a single insertion at the top of a twenty-child section as "1 added, 20
 * moved", and every one of those twenty entries would be true about the path and
 * false about the event. The comparison key is instead the pair
 * (owning parent, rank among the siblings that exist in BOTH trees), so
 * renumbering caused purely by a sibling appearing or disappearing produces no
 * entry at all. `fromPath`/`toPath` still report the real, full, unfiltered
 * paths — the operator needs actual coordinates — and the filtered rank is only
 * ever a comparison key, never output.
 *
 * KNOWN BOUND ON THAT RULE: a parent is identified by its own stored id, and an
 * element whose stored `id` is absent, null or non-scalar has none to identify
 * it by. For those — and only those — the parent's raw path stands in as the
 * comparison key. No synthetic id is invented, so the consequence is accepted
 * openly: if an IDLESS parent is itself renumbered, its addressable children can
 * still report a `moved` nobody performed. That is the narrow, old-template case;
 * every element Elementor itself writes carries an id.
 *
 * @package SiteHelm
 */
final class ElementorTreeDiff {

	public const OP_ADDED   = 'added';
	public const OP_REMOVED = 'removed';
	public const OP_MOVED   = 'moved';
	public const OP_UPDATED = 'updated';

	/**
	 * The greatest number of levels an element's stored content may nest.
	 *
	 * `ElementorTree` bounds ELEMENT nesting; it never walks `settings`, because
	 * it drops them. This class does walk them, to compare stored content, and
	 * `_elementor_data` is decoded JSON writable by any plugin that can write
	 * post meta — so the depth of that array is caller-influenced input and an
	 * unbounded recursion over it is a stack overflow, which is a dropped
	 * connection rather than a refusal.
	 *
	 * Set far above any legitimate widget: real settings nest a handful of levels
	 * (a repeater of controls, each holding a responsive value), never dozens.
	 */
	public const MAX_SETTINGS_DEPTH = 64;

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
	 * The comparison key of an element that sits at the document root.
	 */
	private const ROOT_PARENT = 'root';

	/**
	 * Prefixes a parent identified by its own stored id.
	 */
	private const PARENT_BY_ID = 'id:';

	/**
	 * Prefixes a parent that has no stored id and stands on its raw path.
	 */
	private const PARENT_BY_PATH = 'path:';

	/**
	 * Separates a parent key from the rank within it.
	 */
	private const RANK_SEPARATOR = '#';

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
	 * @throws OperationException With ErrorCode::ExecutionFailed when either tree
	 *                           breaches ElementorTree's bounds or an element's
	 *                           stored content nests past MAX_SETTINGS_DEPTH.
	 */
	public function diff( array $before_raw, array $after_raw ): array {
		// Normalization runs FIRST on both sides, so a bound breach refuses
		// before any index is built and no partial answer can be assembled.
		$before = $this->tree->normalize( $before_raw )['nodes'];
		$after  = $this->tree->normalize( $after_raw )['nodes'];

		$before_records = $this->collect( $before_raw );
		$after_records  = $this->collect( $after_raw );

		$before_index = $this->index( $before_records );
		$after_index  = $this->index( $after_records );

		$common = $this->common( $before_index, $after_index );

		return [
			'before'  => $before,
			'after'   => $after,
			'changes' => $this->changes(
				$before_index,
				$after_index,
				$this->positions( $before_records, $before_index, $common ),
				$this->positions( $after_records, $after_index, $common )
			),
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
	 * @param array<string, array<string, mixed>|null> $before           The before index.
	 * @param array<string, array<string, mixed>|null> $after            The after index.
	 * @param array<string, string>                    $before_positions Comparison keys, before.
	 * @param array<string, string>                    $after_positions  Comparison keys, after.
	 *
	 * @return array[] The change entries, each `{op, elementId, fromPath, toPath}`.
	 */
	private function changes( array $before, array $after, array $before_positions, array $after_positions ): array {
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

			// Position among the elements BOTH trees hold, never the raw path:
			// a sibling added or removed elsewhere renumbers paths without moving
			// anything. See the note on this class.
			if ( ( $before_positions[ $id ] ?? '' ) !== ( $after_positions[ $id ] ?? '' ) ) {
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
	 * @return array<string, mixed> Keys 'op', 'elementId', 'fromPath', 'toPath'.
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
	 * Walks one raw tree into a flat, document-ordered record per addressable element.
	 *
	 * An element with no usable stored id contributes no record — there is
	 * nothing to key it by — but its children are still walked, because an
	 * addressable child of an unaddressable parent is a real change.
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
	 * @param mixed       $raw         The raw child list.
	 * @param string      $prefix      The path of the parent, '' at the root.
	 * @param string|null $parent_path The raw path of the parent, null at the root.
	 * @param mixed       $parent_id   The parent's raw stored id, null at the root.
	 * @param array[]     $records     The running record list, by reference.
	 *
	 * @return array[] One record per addressable element, in document order.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when an element's
	 *                           stored content nests past MAX_SETTINGS_DEPTH.
	 */
	private function collect(
		mixed $raw,
		string $prefix = '',
		?string $parent_path = null,
		mixed $parent_id = null,
		array &$records = []
	): array {
		if ( ! is_array( $raw ) ) {
			return $records;
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
				$records[] = [
					'id'          => (string) $id,
					'path'        => $path,
					'self'        => $this->stored( $element ),
					'parent_path' => $parent_path,
					'parent_id'   => $this->addressable( $parent_id ),
				];
			}

			$this->collect( $element[ self::CHILDREN_KEY ] ?? null, $path, $path, $id, $records );
		}

		return $records;
	}

	/**
	 * A stored id reduced to the string a write could name, or null.
	 *
	 * @param mixed $id The raw stored id.
	 *
	 * @return string|null The usable id, null when the element has none.
	 */
	private function addressable( mixed $id ): ?string {
		return is_scalar( $id ) && '' !== (string) $id ? (string) $id : null;
	}

	/**
	 * Indexes the records of one tree by stored element id.
	 *
	 * An id seen twice has its entry replaced by null — the ambiguity marker —
	 * rather than overwritten, so neither occurrence is ever reported. It is
	 * recorded rather than simply skipped because the SECOND occurrence must
	 * also un-report the first, and a plain `isset()` skip would keep it.
	 *
	 * @param array[] $records The records of one tree, in document order.
	 *
	 * @return array<string, array<string, mixed>|null> One record per id, null when ambiguous.
	 */
	private function index( array $records ): array {
		$index = [];

		foreach ( $records as $record ) {
			$key           = $record['id'];
			$index[ $key ] = array_key_exists( $key, $index ) ? self::AMBIGUOUS : $record;
		}

		return $index;
	}

	/**
	 * The ids both trees hold and both can address.
	 *
	 * These, and only these, are the elements a rank is computed over: an added,
	 * a removed and an ambiguous element are all filtered out before ranking, so
	 * that the positions they shift do not read as movement.
	 *
	 * @param array<string, array<string, mixed>|null> $before The before index.
	 * @param array<string, array<string, mixed>|null> $after  The after index.
	 *
	 * @return array<string, bool> A set keyed by id.
	 */
	private function common( array $before, array $after ): array {
		$common = [];

		foreach ( $before as $id => $entry ) {
			if ( self::AMBIGUOUS === $entry ) {
				continue;
			}

			if ( array_key_exists( $id, $after ) && self::AMBIGUOUS !== $after[ $id ] ) {
				$common[ $id ] = true;
			}
		}

		return $common;
	}

	/**
	 * The comparison key of every surviving element in one tree.
	 *
	 * The key pairs the element's owning parent with its rank among that parent's
	 * children THAT ALSO EXIST IN THE OTHER TREE, in document order. Records
	 * arrive in document order, so a per-parent counter yields exactly that rank.
	 *
	 * @param array[]                                  $records The records of one tree.
	 * @param array<string, array<string, mixed>|null> $index   That tree's index.
	 * @param array<string, bool>                      $common  The surviving id set.
	 *
	 * @return array<string, string> The comparison key of each surviving id.
	 */
	private function positions( array $records, array $index, array $common ): array {
		$ranks     = [];
		$positions = [];

		foreach ( $records as $record ) {
			$id = $record['id'];

			if ( ! array_key_exists( $id, $common ) ) {
				continue;
			}

			$parent = $this->parent_key( $record, $index );
			$rank   = $ranks[ $parent ] ?? 0;

			$ranks[ $parent ] = $rank + 1;
			$positions[ $id ] = $parent . self::RANK_SEPARATOR . $rank;
		}

		return $positions;
	}

	/**
	 * The key naming the parent an element hangs from.
	 *
	 * A parent is named by its own stored id wherever it has one that is
	 * unambiguous in its tree, which is what makes the key survive its own
	 * renumbering. A parent with no addressable id falls back to its raw path;
	 * see the known bound recorded on this class.
	 *
	 * @param array<string, mixed>                     $record One element record.
	 * @param array<string, array<string, mixed>|null> $index  That tree's index.
	 *
	 * @return string The parent key.
	 */
	private function parent_key( array $record, array $index ): string {
		if ( null === $record['parent_path'] ) {
			return self::ROOT_PARENT;
		}

		$parent_id = $record['parent_id'];

		if ( null !== $parent_id && self::AMBIGUOUS !== ( $index[ $parent_id ] ?? self::AMBIGUOUS ) ) {
			return self::PARENT_BY_ID . $parent_id;
		}

		return self::PARENT_BY_PATH . $record['parent_path'];
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
	 * @return array<array-key, mixed> The comparable stored content.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the content
	 *                           nests past MAX_SETTINGS_DEPTH.
	 */
	private function stored( array $element ): array {
		unset( $element[ self::CHILDREN_KEY ] );

		return $this->canonical( $element );
	}

	/**
	 * Sorts array keys at every level so comparison ignores stored key order.
	 *
	 * IT REFUSES RATHER THAN TRUNCATES past MAX_SETTINGS_DEPTH, and the bound is
	 * tested BEFORE any recursion so the stack is bounded by a constant rather
	 * than by the data. Stopping short instead would drop the deeper values from
	 * the comparison, and a comparison blind to part of an element reports "no
	 * change" for a change that is about to be applied.
	 *
	 * @param array<array-key, mixed> $value The array to canonicalize.
	 * @param int                     $depth The zero-based depth of this level.
	 *
	 * @return array<array-key, mixed> The key-sorted array.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the bound is
	 *                           breached.
	 */
	private function canonical( array $value, int $depth = 0 ): array {
		if ( $depth >= self::MAX_SETTINGS_DEPTH ) {
			throw $this->refuse();
		}

		foreach ( $value as $key => $member ) {
			if ( is_array( $member ) ) {
				$value[ $key ] = $this->canonical( $member, $depth + 1 );
			}
		}

		ksort( $value );

		return $value;
	}

	/**
	 * The refusal a breached settings bound produces.
	 *
	 * NO PART OF THE TREE APPEARS IN EITHER STRING, for the same reason
	 * `ElementorTree` names none: a bound is breached by data a third party may
	 * have written, and an envelope is not where an operator should learn what
	 * that data contained.
	 *
	 * ErrorCode::ExecutionFailed matches `ElementorTree`'s own refusal, and the
	 * eleven public codes are frozen — none names "stored site data is too deep
	 * to read". InvalidInput would misdirect: nothing about the caller's request
	 * is invalid.
	 *
	 * @return OperationException The refusal.
	 */
	private function refuse(): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			'One of this page\'s Elementor elements stores settings nested more deeply than SiteHelm will compare.',
			'Open the page in the Elementor editor and simplify that element\'s settings, then retry.'
		);
	}
}
