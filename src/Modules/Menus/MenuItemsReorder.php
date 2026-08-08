<?php
/**
 * Nav menu item reordering write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0030: menu item reordering. An agency operator restructures a client's
 * navigation in one call — new positions, new nesting, or both.
 *
 * Ported from EMCP Tools' `op_reorder_items()` (GPL-2.0-or-later) WITH ITS
 * CENTRAL BEHAVIOUR DELIBERATELY REVERSED.
 *
 * EMCP `continue`s past every entry it cannot use — an identifier that names no
 * menu item, one belonging to another menu, an illegal parent — writes whatever
 * is left, and returns an `updated` count. That is a partial write reported as
 * success: the operator asked for one arrangement and got a different one, with
 * no signal beyond a number they would have to compare against their own request
 * to notice. Worse, the half that landed is not a state the operator ever asked
 * for, so there is nothing to roll back TO except a snapshot nobody took.
 *
 * SITEHELM REFUSES THE WHOLE BATCH IN planChange(), BEFORE ANYTHING IS WRITTEN.
 * Every entry is checked against the resolved menu first — the item exists, it
 * belongs to THIS menu, any parent is legal within this menu, no identifier
 * appears twice, and the arrangement the batch as a whole produces contains no
 * cycle. One failure refuses all of it. That is the same rule REQ-0015 applied
 * to a payload with four fields, applied here to a payload with N entries.
 *
 * THE CYCLE CHECK RUNS OVER THE PROJECTED ARRANGEMENT, not over each entry
 * against the stored tree, because a batch can build a cycle out of two entries
 * that are each individually legal: "nest A under B" and "nest B under A" both
 * pass a per-entry parent check and together render both items invisible.
 * WordPress stores that relation without complaint.
 *
 * THE PROMISE IS THE WHOLE MENU'S ARRANGEMENT, not just the named entries.
 * `order` carries every item's identifier, parent, and position, so the plan
 * shows the operator the arrangement they are approving rather than a list of
 * deltas they would have to apply mentally to the tree they read earlier.
 *
 * @package SiteHelm
 */
final class MenuItemsReorder implements WriteOperation {

	/**
	 * The one field this operation promises and verifies.
	 *
	 * Named once because planChange() promises it, resolveTarget() and readBack()
	 * both project it, and captureSnapshot() records it. A second spelling is the
	 * kind of drift that makes a verification silently compare nothing.
	 */
	private const ORDER_FIELD = 'order';

	/**
	 * The operation's registered definition.
	 *
	 * `position` carries `minimum: 1` and that bound is LOAD BEARING rather than
	 * decorative: `wp_update_nav_menu_item()` treats a 0 position as "unset" and
	 * replaces it with the menu's item count plus one, so an entry asking for
	 * position 0 would silently land LAST — the opposite of what a client sending
	 * a zero-based index intends. SchemaValidator enforces `minimum`, so the
	 * dispatcher refuses it; planChange() refuses it again for a caller that
	 * reaches the operation without going through the dispatcher.
	 *
	 * `parent` carries `minimum: 0` because 0 is how WordPress spells top level,
	 * and it is OPTIONAL because omitting it means "leave the nesting alone".
	 * Those are two different instructions and the schema must be able to express
	 * both, which is why there is no default.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             menu-items-reorder.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-items-reorder',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Reposition and optionally re-nest several items of one navigation menu in a single change. Every entry is validated before anything is written, and one invalid entry refuses the whole batch.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'menu'  => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The menu to reorder, named by its identifier, its slug, or its name.',
					],
					'items' => [
						'type'        => 'array',
						'description' => 'The items to reposition. Every entry must name an item of this menu; one invalid entry refuses the whole request.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'id'       => [
									'type'        => 'integer',
									'minimum'     => 1,
									'description' => 'Identifier of the menu item to reposition.',
								],
								'parent'   => [
									'type'        => 'integer',
									'minimum'     => 0,
									'description' => 'Identifier of the item this one should sit under, or 0 for top level. Omit it to leave the nesting unchanged.',
								],
								'position' => [
									'type'        => 'integer',
									'minimum'     => 1,
									'description' => 'The item\'s position among its siblings, counting from 1.',
								],
							],
							'required'             => [ 'id', 'position' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'menu', 'items' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_theme_options' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-items-reorder',
				'arguments' => [
					'menu'  => 'primary-navigation',
					'items' => [
						[
							'id'       => 15,
							'position' => 1,
						],
						[
							'id'       => 13,
							'parent'   => 0,
							'position' => 2,
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MenuFields $fields The shared menu projection and validators.
	 */
	public function __construct( private readonly MenuFields $fields ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId is a contract property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Resolves the menu the input names, with its current arrangement.
	 *
	 * The capability is asked BEFORE the menu is resolved, for the reason
	 * MenuGet gives: otherwise the difference between the two refusals tells a
	 * caller who may not manage menus which menu keys exist on the site.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                            ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, 'edit_theme_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s navigation menus.',
				'Ask a site administrator to grant your WordPress user the edit_theme_options capability.'
			);
		}

		// is_string() rather than a cast for the reason MenuGet documents:
		// `(string) [ 'primary' ]` is a fatal, and a key that is not a string
		// names no menu, which is the truth about it.
		$key  = $input['menu'] ?? null;
		$menu = is_string( $key ) ? $this->fields->menuFromKey( $key ) : null;

		if ( null === $menu ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No navigation menu on this site matches the requested identifier.',
				'Call menu-list to see this site\'s menus with their identifiers, slugs, and names.'
			);
		}

		$menu_id = (int) ( $menu->term_id ?? 0 );

		return new TargetState( MenuFields::MENU_PREFIX . $menu_id, true, $this->menu_state( $menu ) );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Validates EVERY entry and refuses the whole batch if any one of them fails.
	 *
	 * The order of the four passes is load-bearing and each has a test:
	 *
	 * 1. Refuse an empty batch. The input schema cannot express it — the subset
	 *    of JSON Schema this project validates implements no `minItems` — and an
	 *    empty batch names no change, so there is nothing to promise.
	 * 2. Normalize every entry, refusing a duplicated identifier. Two entries for
	 *    one item name two positions for one row, so there is no arrangement the
	 *    operation could honestly promise.
	 * 3. Check every entry against the MENU: the item is one of this menu's
	 *    items, and any parent is legal within it. `menuTermIdForItem()` is what
	 *    proves ownership; membership of the current arrangement is checked
	 *    beside it because an item can own the term while being absent from
	 *    `wp_get_nav_menu_items()` — a draft menu item is exactly that — and
	 *    applyChange() has no row to merge from for such an item.
	 * 4. Only then project the resulting arrangement and walk it for cycles.
	 *
	 * Every refusal is invalid_input: the request is malformed relative to the
	 * menu it names, and correcting it is what makes it work. None of the
	 * messages names an identifier or a menu, because the refusal is read by a
	 * caller who may be probing.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised arrangement.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$menu_id = (int) ( $current->fields['id'] ?? 0 );
		$before  = is_array( $current->fields[ self::ORDER_FIELD ] ?? null ) ? $current->fields[ self::ORDER_FIELD ] : [];
		$entries = $input['items'] ?? null;

		if ( ! is_array( $entries ) || [] === $entries ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No menu items were supplied, so there is nothing to reorder.',
				'Name at least one item with a position of 1 or more, then request a fresh preview.'
			);
		}

		$requested = [];
		foreach ( $entries as $entry ) {
			$normalized = $this->normalized_entry( $entry );

			if ( array_key_exists( $normalized['id'], $requested ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One menu item was named more than once, so the requested order is ambiguous and none of it was written.',
					'Send each menu item exactly once, then request a fresh preview.'
				);
			}

			$requested[ $normalized['id'] ] = $normalized;
		}

		$known = array_column( $before, 'id' );

		foreach ( $requested as $id => $entry ) {
			if ( ! in_array( $id, $known, true ) || ! is_nav_menu_item( $id ) || $this->fields->menuTermIdForItem( $id ) !== $menu_id ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the requested entries does not name an item of this menu, so none of the requested order was written.',
					'Call menu-get to read this menu\'s items, then send only identifiers it lists and request a fresh preview.'
				);
			}

			if ( array_key_exists( 'parent', $entry ) && ! $this->fields->validateParent( $entry['parent'], $menu_id ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the requested entries names a parent that is not an item of this menu, so none of the requested order was written.',
					'Call menu-get to read this menu\'s items, then send a parent it lists or 0 for top level, and request a fresh preview.'
				);
			}
		}

		$after = $this->projected_order( $before, $requested );
		$this->assert_no_cycle( $after, array_keys( $requested ) );

		// The payload keeps the CALLER'S ORDER, deliberately unsorted. applyChange()
		// writes in payload order and reports its progress positionally — "menu
		// item 2 of 5" — so whoever reads a mid-batch failure can resume from
		// entry 3 of the request they sent. Sorting here would silently renumber
		// those positions against a list the caller never wrote, which turns the
		// one useful fact in the refusal into a misleading one. The PROMISE is
		// sorted instead, in current_order(), where sorting costs nothing.
		return new PlannedChange(
			[ 'items' => array_values( $requested ) ],
			[ self::ORDER_FIELD => $after ],
			[ self::ORDER_FIELD ]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Records the arrangement of EVERY item in the menu, not only the named ones.
	 *
	 * A reorder is only reversible against the complete prior arrangement. A
	 * snapshot of the named entries alone would restore those items to their old
	 * positions while leaving every item that WordPress renumbered around them
	 * where the write left it, which is a third arrangement nobody asked for.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, which the contract requires
	 * because the engine calls it once at preview for snapshot eligibility and
	 * again at apply for real: it reads `$current->fields` and calls no WordPress
	 * function at all, so the second call cannot see anything the first changed.
	 *
	 * The key order is sorted, matching every other snapshot in the codebase:
	 * restore state is stored as canonical JSON, so a stable order keeps the
	 * stored row identical for identical state.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the state
	 *                                   identifies no menu.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$menu_id = (int) ( $current->fields['id'] ?? 0 );

		if ( $menu_id <= 0 ) {
			return null;
		}

		$order = $current->fields[ self::ORDER_FIELD ] ?? null;

		$snapshot = [
			'items'   => is_array( $order ) ? $order : [],
			'menu_id' => $menu_id,
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Writes the approved arrangement, one item at a time.
	 *
	 * VALIDATION DOES NOT MAKE THIS LOOP INFALLIBLE, and pretending otherwise is
	 * the failure this method is shaped around. Every entry passed planChange,
	 * but WordPress can still refuse the third of five: a save_post filter can
	 * veto it, the row can be deleted between the preview and the apply, and a
	 * plugin can shorten the item list under us. When that happens the first two
	 * items HAVE been written, and the refusal has to say so.
	 *
	 * So `$completed` is ACCUMULATED as each write lands rather than declared up
	 * front. ContentTermsAssign on main declares its steps ahead of the loop and
	 * therefore reports "nothing was written" after a mid-loop refusal that left
	 * two rows changed; that shape is not reproduced here. The steps count items
	 * rather than naming identifiers, because a step is read by whoever inspects
	 * the failure and a position is what they need to resume from.
	 *
	 * The merged record carries the item's STORED COLUMNS, never the derived
	 * properties `wp_setup_nav_menu_item()` computes. `description` is
	 * `wp_trim_words( post_content, 200 )` and `title` is `post_title` through
	 * the_title filters, so merging from those would silently truncate a long
	 * description and texturize a title on every reorder. The porting source
	 * merges `$existing->description`, and that is a data loss this port fixes
	 * rather than carries.
	 *
	 * The whole record is slashed on the way in, because
	 * `wp_update_nav_menu_item()` hands it to `wp_update_post()` and
	 * `update_post_meta()`, both of which unslash before storing — so an
	 * unslashed title holding a backslash or a quote is stored one character
	 * short every time the menu is reordered.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		// Accumulated as each write lands, never declared up front, so a refusal
		// on the third entry cannot claim that nothing was written.
		$completed = [ 'plan approved', 'snapshot captured' ];

		$menu_id = (int) ( $current->fields['id'] ?? 0 );
		$entries = is_array( $planned->payload['items'] ?? null ) ? $planned->payload['items'] : [];
		$rows    = $this->item_rows( $menu_id );
		$total   = count( $entries );
		$step    = 0;

		foreach ( $entries as $entry ) {
			++$step;
			$id  = (int) $entry['id'];
			$row = $rows[ $id ] ?? null;

			if ( ! is_object( $row ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'One of the approved menu items could no longer be read, so the rest of the order was not written.',
					'Request a fresh preview and retry.',
					$completed
				);
			}

			$parent = array_key_exists( 'parent', $entry )
				? (int) $entry['parent']
				: (int) ( $row->menu_item_parent ?? 0 );

			$written = wp_update_nav_menu_item(
				$menu_id,
				$id,
				wp_slash( $this->item_args( $row, $parent, (int) $entry['position'] ) )
			);

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to reposition one of the approved menu items, so the rest of the order was not written.',
					'Request a fresh preview and retry.',
					$completed
				);
			}

			$completed[] = sprintf( 'menu item %d of %d repositioned', $step, $total );
		}

		return MenuFields::MENU_PREFIX . $menu_id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey is a contract parameter name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is a contract property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Re-reads the menu's arrangement so the engine can verify it.
	 *
	 * No cache is cleared here, and that is deliberate rather than an omission:
	 * `wp_update_nav_menu_item()` writes through `wp_update_post()`, which calls
	 * `clean_post_cache()` for every row it touches, so a second invalidation
	 * would be a call that can only ever be a no-op.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$menu_id = $this->menu_id_from_key( $targetKey );
		$menu    = null === $menu_id ? null : $this->fields->menuFromKey( (string) $menu_id );

		if ( null === $menu ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The navigation menu could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $this->menu_state( $menu ) );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState is a contract parameter name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Writes a recorded arrangement back.
	 *
	 * EVERY RECORDED ITEM IS CHECKED BEFORE ANY OF THEM IS WRITTEN, which is the
	 * forward operation's all-or-nothing rule applied to the reverse one. A
	 * snapshot naming an item the menu no longer holds cannot be put back in
	 * full, and restoring the rest would produce a third arrangement rather than
	 * the one recorded — so it refuses with nothing written.
	 *
	 * EACH FIELD IS GATED ON array_key_exists(), NEVER ON `??`. A recorded parent
	 * of 0 means "put this item back at top level" and an ABSENT parent key means
	 * "do not touch the nesting"; `?? 0` collapses those two, and for a menu the
	 * collapse silently promotes every restored item to the root. Zero is a
	 * legitimate stored value for both parent and position here, which is exactly
	 * the shape that made `?? ''` dangerous in the core block.
	 *
	 * EVERY RESTORED VALUE IS RE-READ, because a restore has no WriteVerifier
	 * downstream: if this method does not measure what it stored, nothing does.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$menu_id  = (int) ( $restoreState['menu_id'] ?? 0 );
		$recorded = $restoreState['items'] ?? null;

		if ( $menu_id <= 0 || ! is_array( $recorded ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not describe a navigation menu, so its order cannot be put back.',
				'Reorder the menu on the WordPress menus screen instead.'
			);
		}

		$rows     = $this->item_rows( $menu_id );
		$intended = $this->intended_restore( $recorded, $rows );

		$completed = [];
		$step      = 0;
		$total     = count( $intended );

		foreach ( $intended as $id => $target ) {
			++$step;

			$written = wp_update_nav_menu_item(
				$menu_id,
				$id,
				wp_slash( $this->item_args( $rows[ $id ], $target['parent'], $target['position'] ) )
			);

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to put one of the recorded menu items back, so the recorded order was only partly restored.',
					'Reorder the menu on the WordPress menus screen instead.',
					$completed
				);
			}

			$completed[] = sprintf( 'menu item %d of %d restored', $step, $total );
		}

		$this->assert_restored( $menu_id, $intended, $completed );

		return MenuFields::MENU_PREFIX . $menu_id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One menu's identity beside its current arrangement.
	 *
	 * @param object $menu The resolved menu term.
	 *
	 * @return array<string, mixed> The normalized field map.
	 */
	private function menu_state( object $menu ): array {
		$menu_id = (int) ( $menu->term_id ?? 0 );

		return [
			'id'              => $menu_id,
			'name'            => (string) ( $menu->name ?? '' ),
			'slug'            => (string) ( $menu->slug ?? '' ),
			self::ORDER_FIELD => $this->current_order( $menu_id ),
		];
	}

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
	private function current_order( int $menu_id ): array {
		$order = [];

		foreach ( $this->item_rows( $menu_id ) as $id => $row ) {
			$order[] = [
				'id'       => $id,
				'parent'   => (int) ( $row->menu_item_parent ?? 0 ),
				'position' => (int) ( $row->menu_order ?? 0 ),
			];
		}

		usort( $order, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );

		return $order;
	}

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
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<int, object> The rows, keyed by identifier.
	 */
	private function item_rows( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$rows = [];

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$id = (int) ( $item->ID ?? 0 );

			if ( $id > 0 ) {
				$rows[ $id ] = $item;
			}
		}

		return $rows;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * One caller-supplied entry, normalized, or a refusal.
	 *
	 * The shape checks look like defence in depth against the input schema, and
	 * for the `id` and `position` TYPES they are — but the LOWER BOUND on
	 * position is not decorative even where the schema also carries it. A 0
	 * position is not "first": `wp_update_nav_menu_item()` replaces it with the
	 * menu's item count plus one, so the item silently lands last.
	 *
	 * An absent `parent` is preserved as absent rather than defaulted to 0,
	 * because "leave the nesting alone" and "move this to top level" are
	 * different instructions and the rest of this class distinguishes them.
	 *
	 * @param mixed $entry One entry from the `items` array.
	 *
	 * @return array<string, int> The normalized entry.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function normalized_entry( mixed $entry ): array {
		$id       = is_array( $entry ) ? $entry['id'] ?? null : null;
		$position = is_array( $entry ) ? $entry['position'] ?? null : null;

		if ( ! is_int( $id ) || $id < 1 || ! is_int( $position ) || $position < 1 ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Every entry must name a menu item identifier and a position of 1 or more, so none of the requested order was written.',
				'Send each entry as an item identifier with a position counting from 1, then request a fresh preview.'
			);
		}

		$normalized = [
			'id'       => $id,
			'position' => $position,
		];

		if ( is_array( $entry ) && array_key_exists( 'parent', $entry ) ) {
			$parent = $entry['parent'];

			if ( ! is_int( $parent ) || $parent < 0 ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One entry names a parent that is not a menu item identifier, so none of the requested order was written.',
					'Send a parent identifier or 0 for top level, then request a fresh preview.'
				);
			}

			$normalized['parent'] = $parent;
		}

		return $normalized;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The arrangement the batch as a whole produces.
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
	private function projected_order( array $before, array $requested ): array {
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
	private function assert_no_cycle( array $after, array $moved_ids ): void {
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

	/**
	 * The full argument set `wp_update_nav_menu_item()` needs to reposition one
	 * item WITHOUT losing anything else about it.
	 *
	 * Ported from EMCP's `merge_existing_item()`, with the source of four values
	 * changed. `wp_update_nav_menu_item()` overwrites every field it is handed
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
	private function item_args( object $row, int $parent_id, int $position ): array {
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * The parent and position a restore intends to write for each recorded item,
	 * refusing before anything is written if the menu can no longer carry them.
	 *
	 * Resolving the whole set up front is what makes the restore all-or-nothing:
	 * the first write happens only once every recorded item is known to be
	 * writable.
	 *
	 * @param array<int, mixed>  $recorded The recorded arrangement.
	 * @param array<int, object> $rows     The menu's current rows, keyed by identifier.
	 *
	 * @return array<int, array<string, int>> The intended parent and position per identifier.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	private function intended_restore( array $recorded, array $rows ): array {
		$intended = [];

		foreach ( $recorded as $entry ) {
			$id = is_array( $entry ) && isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;

			if ( $id <= 0 || ! array_key_exists( $id, $rows ) ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'The recorded order names a menu item this menu no longer holds, so the order cannot be put back as it was.',
					'Reorder the menu on the WordPress menus screen instead.'
				);
			}

			$row = $rows[ $id ];

			// array_key_exists(), never `??`: a recorded 0 is a value to write and
			// an absent key is an instruction to leave the stored value alone.
			$intended[ $id ] = [
				'parent'   => array_key_exists( 'parent', $entry ) && is_numeric( $entry['parent'] )
					? (int) $entry['parent']
					: (int) ( $row->menu_item_parent ?? 0 ),
				'position' => array_key_exists( 'position', $entry ) && is_numeric( $entry['position'] )
					? (int) $entry['position']
					: (int) ( $row->menu_order ?? 0 ),
			];
		}

		return $intended;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * Re-reads the menu and refuses unless every intended value landed.
	 *
	 * `wp_update_nav_menu_item()` returning an identifier proves the row was
	 * saved, not that `menu_order` holds what was sent — a `wp_update_nav_menu_item`
	 * filter can rewrite the arguments, and a recorded position of 0 is replaced
	 * by WordPress with the menu's item count plus one. On a restore path there
	 * is no WriteVerifier downstream to notice either.
	 *
	 * @param int                            $menu_id   The menu's term identifier.
	 * @param array<int, array<string, int>> $intended  The intended parent and position per identifier.
	 * @param string[]                       $completed The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_restored( int $menu_id, array $intended, array $completed ): void {
		$stored = [];

		foreach ( $this->current_order( $menu_id ) as $row ) {
			$stored[ (int) $row['id'] ] = $row;
		}

		foreach ( $intended as $id => $target ) {
			$row = $stored[ $id ] ?? null;

			if ( ! is_array( $row )
				|| (int) $row['parent'] !== $target['parent']
				|| (int) $row['position'] !== $target['position'] ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different menu order than the recorded snapshot held.',
					'Reorder the menu on the WordPress menus screen instead.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The menu identifier one concrete target key names.
	 *
	 * @param string $key The target key, e.g. 'menu:5'.
	 *
	 * @return int|null The menu's term identifier, or null when the key names none.
	 */
	private function menu_id_from_key( string $key ): ?int {
		if ( ! str_starts_with( $key, MenuFields::MENU_PREFIX ) ) {
			return null;
		}

		$id = substr( $key, strlen( MenuFields::MENU_PREFIX ) );

		return ctype_digit( $id ) && (int) $id > 0 ? (int) $id : null;
	}
}
