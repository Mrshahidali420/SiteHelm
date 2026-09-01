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
	 * The raw key holding a node's settings map.
	 */
	private const SETTINGS_KEY = 'settings';

	/**
	 * The key Elementor gives every REPEATER ROW. It is deliberately not
	 * `self::ID_KEY`: a row's identity lives beside the element's, one level
	 * down, and the two are addressed by different CSS selectors.
	 */
	private const ROW_ID_KEY = '_id';

	/**
	 * Tags the row half of the seed space so that a row seed can never coincide
	 * with an element seed. Without it a caller who named an element `0.1` — a
	 * legal Elementor id, and exactly the shape a position path takes — could
	 * assemble the same seed string two different ways.
	 */
	private const ROW_DOMAIN = 'repeater';

	/**
	 * The key set Elementor's ATTACHMENT-LIST controls store, and the one
	 * structural false positive worth excluding by name; see `repeats()`.
	 */
	private const ATTACHMENT_KEYS = [
		'id'  => true,
		'url' => true,
	];

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
	 * rejected derived identity, one level down. DO NOT "UNIFY" THIS RULE WITH
	 * `nameTree()`, which names exactly the nodes this method leaves alone: that
	 * method's nodes have never existed anywhere, so naming one originates an
	 * identity rather than deriving a missing one. The two rules look
	 * contradictory and are not; see `nameTree()` for the other half.
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

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class, and this sits beside reassign() and coerceTree().
	/**
	 * Names every node in a caller-supplied LIST of elements that has no id.
	 *
	 * THIS IS THE OPPOSITE CASE TO `reassign()`, AND THE TWO MUST NOT BE UNIFIED.
	 * `reassign()` COPIES elements that already exist somewhere: a node there that
	 * stores no usable id was unaddressable where it stood, so the copy is left
	 * unaddressable in exactly the same way, because inventing an identity for an
	 * element that has one — an absent one — is the derived-identity defect Phase
	 * 6a rejected. The nodes reaching THIS method have never existed anywhere.
	 * Naming one invents nothing, because the minted id BECOMES the stored id the
	 * moment the write lands; this is origination, which is precisely what
	 * `ElementorElementAdd` already does for the single element it inserts. The
	 * only difference here is that the caller sent a whole tree instead of a leaf.
	 *
	 * IT IS NOT COSMETIC, AND THE COST OF NOT DOING IT IS A DESTROYED PAGE.
	 * Elementor's per-element CSS generator emits every rule under the selector
	 * `.elementor-element-<id>`. A document stored with unnamed nodes therefore
	 * generates every one of its rules under `.elementor-element-`, the empty
	 * suffix, which matches every element on the page at once: one page built this
	 * way rendered 175 elements carrying `data-id=""` and a stylesheet holding a
	 * single selector with 27 merged rules, so every padding, colour and width the
	 * caller wrote landed on everything. The stored tree was correct and the page
	 * was unusable, which is why this cannot be left to the caller to remember.
	 *
	 * A NODE THAT ALREADY CARRIES A USABLE ID IS LEFT EXACTLY AS IT WAS SENT.
	 * A caller re-importing an export, or writing back a tree an
	 * `elementor-document-get` reported, keeps the correspondence between what
	 * they hold and what this site stores; only the gap is filled.
	 *
	 * DETERMINISTIC, WHICH IS WHAT MAKES IT SAFE TO CALL FROM `planChange()`.
	 * Every id is a pure function of the caller-assembled seed and the node's
	 * position path, so the preview run and the apply run — which see the same
	 * post and the same input — derive the same ids and fingerprint the same
	 * payload. Nothing here reads a clock, a counter or a global; see this class's
	 * own docblock for why there is no entropy in this file at all.
	 *
	 * Every id the walk meets or mints joins the running set before the walk
	 * continues, so a minted id can collide neither with an id the caller supplied
	 * elsewhere in the same tree nor with one a relative just took.
	 *
	 * EVERY REPEATER ROW IN EVERY NODE'S SETTINGS IS NAMED TOO, by the same rule
	 * and for the same reason one level down; see `nameRepeaters()`, which owns
	 * that half and which `ElementorElementAdd` calls on its own for the single
	 * element it originates.
	 *
	 * @param array[]  $elements     The caller's raw element list.
	 * @param string   $seed         The caller-assembled seed.
	 * @param string[] $existing_ids The ids the destination already holds.
	 *
	 * @return array[] The same list, with every unnamed node and row named.
	 */
	public function nameTree( array $elements, string $seed, array $existing_ids ): array {
		$taken    = $this->collect( $elements, $existing_ids );
		$position = 0;

		foreach ( $elements as $key => $element ) {
			// A non-array where an element belongs is skipped WITHOUT consuming a
			// position, exactly as `rewrite()` and ElementorTreeDiff skip it, so a
			// path here names the same node a diff path does.
			if ( ! is_array( $element ) ) {
				continue;
			}

			$elements[ $key ] = $this->name( $element, $seed, (string) $position, $taken );
			++$position;
		}

		return $elements;
	}
	/**
	 * Names every REPEATER ROW in one element's settings map that has no `_id`.
	 *
	 * THIS IS `nameTree()` ONE LEVEL FURTHER DOWN, AND THE SAME DEFECT. Elementor
	 * gives every repeater row its own `_id` and generates that row's CSS under
	 * `.elementor-repeater-item-<_id>`, exactly as it generates an element's CSS
	 * under `.elementor-element-<id>`. A row stored without one is therefore
	 * unstylable — not wrongly styled, but permanently beyond the reach of any
	 * per-row rule — and Elementor's editor JS, which tracks the active tab, the
	 * open accordion panel and the current slide by row `_id`, has no stable
	 * handle on it. A live page written this way rendered 93 icon-list rows with
	 * correct content, zero occurrences of `elementor-repeater-item` in its HTML
	 * and zero `.elementor-repeater-item-*` selectors in its generated
	 * stylesheet. The blast radius is every repeater-backed widget there is:
	 * icon-list, tabs, accordion, toggle, slides, carousel, price-list,
	 * social-icons, form fields, and any third-party widget with a repeater
	 * control.
	 *
	 * THE SAME ORIGINATION RULE AS `nameTree()`, AND THE SAME REASON IT IS NOT
	 * `reassign()`'s. These rows are being written for the first time, so the
	 * minted `_id` BECOMES the stored `_id` and nothing is misrepresented. Rows
	 * reached by COPYING or ADDRESSING an element that already exists are not
	 * touched by anything in this class, which is why `ElementorTemplateApply`,
	 * `ElementorElementDuplicate`, `ElementorElementUpdate`,
	 * `ElementorElementsUpdate` and `ElementorWidgetSettingsUpdate` do not call
	 * this method: minting there would be the derived-identity defect Phase 6a
	 * rejected. See `reassign()` and `nameTree()` for the two halves of that rule
	 * stated in full; DO NOT UNIFY THIS METHOD WITH EITHER OF THEM.
	 *
	 * A ROW `_id` THE CALLER SUPPLIED IS KEPT BYTE FOR BYTE, exactly as
	 * `nameTree()` keeps a caller's element id, so re-writing a tree an
	 * `elementor-document-get` reported preserves the correspondence.
	 *
	 * HOW A REPEATER IS RECOGNIZED, WITHOUT A CONTROL SCHEMA. Nothing at plan
	 * time knows which controls a widget declares — the widget may not even be a
	 * core one — so the test is structural, and every clause of it is a guard
	 * against writing `_id` into a setting that is not a repeater:
	 *
	 *   1. the value is a non-empty LIST (sequential integer keys from zero);
	 *   2. every member is a non-empty ARRAY;
	 *   3. every member is ASSOCIATIVE, never itself a list; and
	 *   4. the members are not Elementor's attachment-list shape (see below).
	 *
	 * Clause 3 is what separates a repeater from the nested numeric arrays that
	 * appear in settings such as a box-shadow's or a slider's stored structure:
	 * a repeater row is a map of control name to value, so it is associative by
	 * construction, while those are lists of lists. Clause 4 excludes the one
	 * realistic Elementor control whose value genuinely IS a list of associative
	 * arrays and is NOT a repeater: the gallery controls — `gallery`,
	 * `background_slideshow_gallery` and their kin — store attachments as
	 * `[ 'id' => 12, 'url' => '…' ]`, and writing `_id` into those would put a
	 * key Elementor never wrote into a media list. The trade this makes is
	 * explicit: a genuine repeater whose rows declare only controls literally
	 * named `id` and `url` is not recognized, so its rows go unnamed. That is
	 * today's behaviour for every repeater, it corrupts nothing, and erring
	 * toward NOT minting is the safe direction — a row without an `_id` merely
	 * cannot be styled individually, whereas an `_id` written into a non-repeater
	 * setting is data the widget never asked for.
	 *
	 * A MALFORMED MEMBER DISQUALIFIES THE WHOLE SETTING rather than being skipped
	 * in place. `nameTree()` skips a scalar where an element belongs because the
	 * surrounding list is unambiguously an element list; here the scalar is
	 * itself evidence that the value was never a repeater, so the honest reading
	 * is that clause 2 fails and no row in it is touched. Nothing is corrupted
	 * either way, and this direction cannot half-name a widget.
	 *
	 * NESTED REPEATERS FALL OUT NATURALLY AND ARE NOT BOUNDED. A row is itself a
	 * settings map, so the walk simply re-enters it; recursion terminates because
	 * a PHP array is a finite tree. Only rows are re-entered — a setting that is
	 * an ordinary map is NOT searched for repeaters nested inside it, because
	 * doing so would widen the false-positive surface for no case Elementor
	 * actually produces.
	 *
	 * DETERMINISTIC, WHICH IS WHY IT IS SAFE TO CALL FROM `planChange()`. The
	 * caller seeds it with the ELEMENT's own id, and each row folds in its
	 * setting key and its position, so two widgets holding byte-identical
	 * repeater content still take different row ids, two different repeater
	 * controls on one widget do too, and so do rows 0 and 1 of one control. See
	 * this class's own docblock for why there is no entropy in this file at all.
	 *
	 * @param array<string, mixed> $settings     One element's raw settings map.
	 * @param string               $seed         The caller-assembled seed, which
	 *                                           must quote the element's id.
	 * @param string[]             $existing_ids The ids already spoken for.
	 *
	 * @return array<string, mixed> The same map, with every unnamed row named.
	 */
	public function nameRepeaters( array $settings, string $seed, array $existing_ids ): array {
		$taken = $this->harvest( $settings, $existing_ids );

		return $this->rows( $settings, $seed, $taken );
	}

	/**
	 * Every row `_id` a settings map already holds, added to the given set.
	 *
	 * FOR THE CALLER THAT NAMES ONE HALF OF A MERGE. A partial settings update
	 * carries only the controls the request mentions, so naming its rows sees
	 * only those rows — while the element goes on holding every OTHER repeater
	 * the document already stored, whose row ids are exactly the ones a fresh
	 * name must not land on. `nameRepeaters()` cannot reach them, because the
	 * map it is handed is not the one the element will end up with. This is the
	 * seam through which that caller reads them out of the stored half and
	 * hands them in as ids already spoken for.
	 *
	 * @param array<string, mixed> $settings One element's raw settings map.
	 * @param string[]             $carried  The ids already spoken for.
	 *
	 * @return string[] The running id set.
	 */
	public function rowIds( array $settings, array $carried ): array {
		return $this->harvest( $settings, $carried );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Names every unnamed row of every repeater one settings map holds.
	 *
	 * @param array<string, mixed> $settings One element's raw settings map.
	 * @param string               $seed     The caller-assembled seed.
	 * @param string[]             $taken    The running id set, by reference.
	 *
	 * @return array<string, mixed> The map, with its rows named.
	 */
	private function rows( array $settings, string $seed, array &$taken ): array {
		foreach ( $settings as $key => $value ) {
			if ( ! $this->repeats( $value ) ) {
				continue;
			}

			foreach ( $value as $index => $row ) {
				$value[ $index ] = $this->row(
					$row,
					implode(
						self::SEED_SEPARATOR,
						[ $seed, self::ROW_DOMAIN, (string) $key, (string) $index ]
					),
					$taken
				);
			}

			$settings[ $key ] = $value;
		}

		return $settings;
	}

	/**
	 * Names one row if it needs a name, then recurses into its own repeaters.
	 *
	 * @param array<string, mixed> $row   One raw repeater row.
	 * @param string               $seed  This row's assembled seed.
	 * @param string[]             $taken The running id set, by reference.
	 *
	 * @return array<string, mixed> The named row.
	 */
	private function row( array $row, string $seed, array &$taken ): array {
		$stored = $row[ self::ROW_ID_KEY ] ?? null;

		if ( ! is_scalar( $stored ) || '' === (string) $stored ) {
			$fresh                   = $this->mint( $seed, $taken );
			$taken[]                 = $fresh;
			$row[ self::ROW_ID_KEY ] = $fresh;
		}

		return $this->rows( $row, $seed, $taken );
	}

	/**
	 * Whether one setting value is structurally a repeater.
	 *
	 * The four clauses and the reasoning behind each are stated in full in
	 * `nameRepeaters()`; this method is only their transcription.
	 *
	 * @param mixed $value One setting value.
	 *
	 * @return bool Whether rows may be named inside it.
	 */
	private function repeats( $value ): bool {
		if ( ! is_array( $value ) || [] === $value || ! array_is_list( $value ) ) {
			return false;
		}

		$attachments = true;

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || [] === $row || array_is_list( $row ) ) {
				return false;
			}

			foreach ( $row as $control => $ignored ) {
				if ( self::ROW_ID_KEY !== $control && ! isset( self::ATTACHMENT_KEYS[ $control ] ) ) {
					$attachments = false;
				}
			}
		}

		return ! $attachments;
	}

	/**
	 * Every row `_id` a settings map already carries, added to the given set.
	 *
	 * IN A PASS OF ITS OWN, BEFORE ANY MINTING, for the reason `collect()` gives:
	 * a row named early must not be handed an `_id` a later row of the same
	 * widget already stores, and one interleaved walk would not yet know about it.
	 *
	 * @param array<string, mixed> $settings One element's raw settings map.
	 * @param string[]             $carried  The ids already spoken for.
	 *
	 * @return string[] The running id set.
	 */
	private function harvest( array $settings, array $carried ): array {
		foreach ( $settings as $value ) {
			if ( ! $this->repeats( $value ) ) {
				continue;
			}

			foreach ( $value as $row ) {
				$stored = $row[ self::ROW_ID_KEY ] ?? null;

				if ( is_scalar( $stored ) && '' !== (string) $stored ) {
					$carried[] = (string) $stored;
				}

				$carried = $this->harvest( $row, $carried );
			}
		}

		return $carried;
	}

	/**
	 * Names one element if it needs a name, then recurses into its children.
	 *
	 * @param array<string, mixed> $element One raw element.
	 * @param string               $seed    The caller-assembled seed.
	 * @param string               $path    This node's position path.
	 * @param string[]             $taken   The running id set, by reference.
	 *
	 * @return array<string, mixed> The named element.
	 */
	private function name( array $element, string $seed, string $path, array &$taken ): array {
		$stored = $element[ self::ID_KEY ] ?? null;

		if ( ! is_scalar( $stored ) || '' === (string) $stored ) {
			$fresh                   = $this->mint( $seed . self::SEED_SEPARATOR . $path, $taken );
			$taken[]                 = $fresh;
			$element[ self::ID_KEY ] = $fresh;
		}

		// The rows of this element's repeaters are named here rather than left to
		// the caller, for the reason `nameRepeaters()` gives at length: a stored
		// row with no `_id` renders but can never be styled. The row seed quotes
		// the element's OWN id — the caller's if it kept one, the minted one if
		// not — so two widgets holding identical repeater content still diverge.
		$settings = $element[ self::SETTINGS_KEY ] ?? null;

		if ( is_array( $settings ) ) {
			$element[ self::SETTINGS_KEY ] = $this->rows(
				$settings,
				$seed . self::SEED_SEPARATOR . (string) $element[ self::ID_KEY ],
				$taken
			);
		}

		$children = $element[ self::CHILDREN_KEY ] ?? null;

		if ( ! is_array( $children ) ) {
			return $element;
		}

		$position = 0;

		foreach ( $children as $key => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$element[ self::CHILDREN_KEY ][ $key ] = $this->name(
				$child,
				$seed,
				$path . self::PATH_SEPARATOR . $position,
				$taken
			);
			++$position;
		}

		return $element;
	}

	/**
	 * Every id the caller's list already carries, added to the given set.
	 *
	 * COLLECTED IN A PASS OF ITS OWN, BEFORE ANY MINTING, and that ordering is the
	 * point: a node minted at the front of the tree must not be handed an id a
	 * node further down the SAME tree already stores, and a single interleaved
	 * walk would not yet know about the later one. The ids are gathered by
	 * walking the raw list rather than through `ElementorTree`, because this runs
	 * on input that has passed the shape gates but has not been normalized.
	 *
	 * @param array[]  $elements The caller's raw element list.
	 * @param string[] $carried  The ids the destination already holds.
	 *
	 * @return string[] The running id set.
	 */
	private function collect( array $elements, array $carried ): array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$stored = $element[ self::ID_KEY ] ?? null;

			if ( is_scalar( $stored ) && '' !== (string) $stored ) {
				$carried[] = (string) $stored;
			}

			// Row `_id`s join the SAME running set as element ids. Elementor
			// addresses the two through different selectors, so a shared value
			// would harm nothing; keeping one set is simply cheaper to reason
			// about than proving that claim holds for every future caller.
			$settings = $element[ self::SETTINGS_KEY ] ?? null;

			if ( is_array( $settings ) ) {
				$carried = $this->harvest( $settings, $carried );
			}

			$children = $element[ self::CHILDREN_KEY ] ?? null;

			if ( is_array( $children ) ) {
				$carried = $this->collect( $children, $carried );
			}
		}

		return $carried;
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
