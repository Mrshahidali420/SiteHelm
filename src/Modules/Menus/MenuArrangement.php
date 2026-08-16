<?php
/**
 * The arrangement of one navigation menu's items.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * EVERYTHING A REORDER NEEDS TO KNOW ABOUT WHERE A MENU'S ITEMS SIT: the stored
 * rows they are computed from, the arrangement those rows currently describe,
 * the arrangement a batch of entries would produce, whether that projection is a
 * legal tree, and the argument set that moves one row without disturbing
 * anything else about it.
 *
 * ONE RESPONSIBILITY: it answers questions about an arrangement and never writes
 * one. Nothing here calls `wp_update_nav_menu_item()`, so every method is safe to
 * call at preview time, at apply time, and again during verification — which is
 * what lets MenuItemsReorder promise, snapshot, and read back through the same
 * projection instead of three spellings of it.
 *
 * Extracted from MenuItemsReorder, which carried both this computation and the
 * WriteOperation lifecycle in one file. The lifecycle decides WHAT to write and
 * WHEN to refuse; this decides WHERE things are. They change for different
 * reasons, and the file that held both was over the project's size limit.
 *
 * WordPress is not loaded in unit tests, so every core answer is guarded on its
 * SHAPE rather than on `instanceof WP_Post`, exactly as MenuFields and MenuTarget
 * do: a filter can substitute a WP_Error or an array on a site that is otherwise
 * healthy, and `(int)` on either is not what the guard above it assumed.
 *
 * @package SiteHelm
 */
final class MenuArrangement {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	/**
	 * One menu's item rows, keyed by identifier.
	 *
	 * Rows without a usable `ID` are dropped, applying the same filter
	 * `MenuFields::itemTree()` applies and for the same reason: a row a
	 * `wp_get_nav_menu_items` filter appended — a plugin's synthetic "Log in"
	 * entry is the common case — carries no `ID` and takes identifier 0, and 0 is
	 * also how "top level" is spelled. Conflating "absent" with the root sentinel
	 * already produced an unbounded recursion in this module.
	 *
	 * A row that is not an object at all takes identifier 0 through the same
	 * expression and is dropped by the same test. There is deliberately no
	 * separate `is_object()` branch: `$row->ID ?? 0` answers null for an array or
	 * a string without a warning, so such a branch could never change an answer
	 * and no test could prove it ran.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<int, object> The rows, keyed by identifier.
	 */
	public function itemRows( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$rows = [];

		foreach ( $items as $item ) {
			$id = is_object( $item ) ? (int) ( $item->ID ?? 0 ) : 0;

			if ( $id > 0 ) {
				$rows[ $id ] = $item;
			}
		}

		return $rows;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	/**
	 * One menu's arrangement: each item's identifier, parent, and position.
	 *
	 * A LIST sorted by identifier rather than a map keyed by it, because the
	 * change engine's canonicalizer keeps a list's order and key-sorts a map;
	 * sorting here once means the promise, the snapshot, and the read-back are
	 * comparable byte for byte without depending on which of the two rules a
	 * particular identifier set happens to fall under.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<int, array<string, int>> The arrangement.
	 */
	public function currentOrder( int $menu_id ): array {
		$order = [];

		foreach ( $this->itemRows( $menu_id ) as $id => $row ) {
			$order[] = [
				'id'       => $id,
				'parent'   => (int) ( $row->menu_item_parent ?? 0 ),
				'position' => (int) ( $row->menu_order ?? 0 ),
			];
		}

		usort( $order, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );

		return $order;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	/**
	 * The arrangement a batch as a whole produces.
	 *
	 * Every item of the menu appears, whether the batch named it or not, because
	 * that is what makes the promise the operator approves the WHOLE arrangement
	 * rather than a list of deltas.
	 *
	 * @param array<int, array<string, mixed>> $before    The current arrangement.
	 * @param array<int, array<string, int>>   $requested The normalized entries, keyed by identifier.
	 *
	 * @return array<int, array<string, int>> The projected arrangement.
	 */
	public function projectedOrder( array $before, array $requested ): array {
		$after = [];

		foreach ( $before as $row ) {
			$id    = (int) ( $row['id'] ?? 0 );
			$entry = $requested[ $id ] ?? null;

			$after[] = [
				'id'       => $id,
				'parent'   => is_array( $entry ) && array_key_exists( 'parent', $entry )
					? $entry['parent']
					: (int) ( $row['parent'] ?? 0 ),
				'position' => is_array( $entry ) ? $entry['position'] : (int) ( $row['position'] ?? 0 ),
			];
		}

		return $after;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Refuses a batch whose resulting arrangement contains a cycle.
	 *
	 * The walk starts from the items the batch MOVED, not from every item, so a
	 * cycle that was already stored — one a direct database edit produced, which
	 * `MenuFields::itemTree()` roots rather than refuses — is not blamed on a
	 * batch that did not touch it. A batch that nests an item INTO such a cycle
	 * is refused, and correctly: the item would never reach the root, so the menu
	 * would not render it.
	 *
	 * The walk terminates on any input. It stops at 0, at an identifier the
	 * arrangement does not name, and — the case this exists for — on the second
	 * visit to any identifier. An item that names itself is just the shortest
	 * such chain and needs no separate branch.
	 *
	 * @param array<int, array<string, int>> $after     The projected arrangement.
	 * @param int[]                          $moved_ids The identifiers the batch repositions.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function assertNoCycle( array $after, array $moved_ids ): void {
		$parents = [];

		foreach ( $after as $row ) {
			$parents[ (int) $row['id'] ] = (int) $row['parent'];
		}

		foreach ( $moved_ids as $moved_id ) {
			$seen   = [];
			$cursor = (int) $moved_id;

			while ( 0 !== $cursor && array_key_exists( $cursor, $parents ) ) {
				if ( isset( $seen[ $cursor ] ) ) {
					throw new OperationException(
						ErrorCode::InvalidInput,
						'The requested nesting would place a menu item inside itself, so none of the requested order was written.',
						'Choose a parent that is not the item itself or one of its own descendants, then request a fresh preview.'
					);
				}

				$seen[ $cursor ] = true;
				$cursor          = $parents[ $cursor ];
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The menus module's shared vocabulary is camelCase across every class.
	/**
	 * The full argument set `wp_update_nav_menu_item()` needs to reposition one
	 * item WITHOUT losing anything else about it.
	 *
	 * `wp_update_nav_menu_item()` overwrites every field it is handed
	 * and defaults every field it is not, so a partial argument set silently
	 * blanks the item's title, url, and classes — which is why the whole record
	 * has to travel. But the record has to be merged from the STORED columns:
	 * `wp_setup_nav_menu_item()` derives `title` through the_title filters,
	 * `description` through `wp_trim_words( post_content, 200 )`, and
	 * `attr_title` through a filter, so merging from those rewrites the item's
	 * own text a little more on every reorder.
	 *
	 * `menu-item-status` carries the row's own status rather than a hardcoded
	 * 'publish', which the porting source uses: `wp_update_nav_menu_item()`
	 * resolves anything other than 'publish' to 'draft', so passing the stored
	 * value preserves a draft item instead of publishing it as a side effect of
	 * a reorder.
	 *
	 * `menu-item-url` is the one derived value that has to stay derived, because
	 * there is no column for it. For a custom link the derived value IS the
	 * stored meta, so it round-trips exactly; for a post_type or taxonomy item
	 * WordPress recomputes the url on every read and never consults the stored
	 * meta, so what lands there is unobservable through any read path.
	 *
	 * @param object $row       The stored menu item row.
	 * @param int    $parent_id The parent identifier to write.
	 * @param int    $position  The position to write.
	 *
	 * @return array<string, mixed> The argument set.
	 */
	public function itemArgs( object $row, int $parent_id, int $position ): array {
		$classes = $row->classes ?? [];

		return [
			'menu-item-db-id'       => (int) ( $row->ID ?? 0 ),
			'menu-item-object-id'   => (int) ( $row->object_id ?? 0 ),
			'menu-item-object'      => (string) ( $row->object ?? '' ),
			'menu-item-type'        => (string) ( $row->type ?? '' ),
			'menu-item-status'      => (string) ( $row->post_status ?? 'publish' ),
			'menu-item-title'       => (string) ( $row->post_title ?? '' ),
			'menu-item-attr-title'  => (string) ( $row->post_excerpt ?? '' ),
			'menu-item-description' => (string) ( $row->post_content ?? '' ),
			'menu-item-url'         => (string) ( $row->url ?? '' ),
			'menu-item-target'      => (string) ( $row->target ?? '' ),
			'menu-item-xfn'         => (string) ( $row->xfn ?? '' ),
			'menu-item-classes'     => is_array( $classes )
				? implode( ' ', array_filter( array_map( 'strval', $classes ), static fn( string $c ): bool => '' !== $c ) )
				: '',
			'menu-item-parent-id'   => $parent_id,
			'menu-item-position'    => $position,
		];
	}

	/**
	 * The first identifier whose stored arrangement differs from the intended one.
	 *
	 * `wp_update_nav_menu_item()` returning an identifier proves the row was saved,
	 * not that `menu_order` holds what was sent — a `wp_update_nav_menu_item` filter
	 * can rewrite the arguments, and on a restore path there is no WriteVerifier
	 * downstream to notice. So the arrangement is re-read and compared rather than
	 * assumed.
	 *
	 * EVERY POSITION IS COMPARED LITERALLY, INCLUDING 0. This comparison used to
	 * exempt a recorded 0, on the grounds that `wp_update_nav_menu_item()`
	 * substitutes "the end of the menu" for one and so it could never read back as
	 * 0. That was a true observation about core and the wrong conclusion from it: a
	 * recorded 0 means the item was stored FIRST, restoring it appended it LAST, and
	 * the exemption reported that as a successful rollback. The hazard had been
	 * diagnosed and its only symptom suppressed. Callers now correct the substituted
	 * position through MenuTarget::correctAppendedPosition() before asking, so there
	 * is a real value to compare and no reason left to skip one.
	 *
	 * ANSWERS, NEVER REFUSES. Returning the offending identifier rather than
	 * throwing is what keeps this class a question and leaves the decision to refuse
	 * — and the envelope wording that goes with it — where the rest of the refusals
	 * live.
	 *
	 * @param int                            $menu_id  The menu's term identifier.
	 * @param array<int, array<string, int>> $intended The intended parent and position per identifier.
	 *
	 * @return int|null The first mismatched identifier, or null when every one landed.
	 */
	public function firstMisplaced( int $menu_id, array $intended ): ?int {
		$stored = [];

		foreach ( $this->currentOrder( $menu_id ) as $row ) {
			$stored[ (int) $row['id'] ] = $row;
		}

		foreach ( $intended as $id => $target ) {
			$row = $stored[ $id ] ?? null;

			// null rather than 0 for a missing row: 0 is a legitimate stored parent,
			// so a row the menu no longer holds must not compare equal to one
			// recorded at top level.
			$parent   = is_array( $row ) ? (int) $row['parent'] : null;
			$position = is_array( $row ) ? (int) $row['position'] : null;

			if ( $parent !== $target['parent'] || $position !== $target['position'] ) {
				return (int) $id;
			}
		}

		return null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
