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
 * THE OBVIOUS IMPLEMENTATION IS DELIBERATELY REJECTED HERE.
 *
 * Skipping past every entry that cannot be used — an identifier that names no
 * menu item, one belonging to another menu, an illegal parent — writing whatever
 * is left, and returning an `updated` count is a partial write reported as
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
						'maxLength'   => MenuFields::MAX_MENU_REFERENCE_LENGTH,
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
			requiredCapabilities: [ MenuTarget::REQUIRED_CAPABILITY ],
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
	 * The shared menus target resolver.
	 *
	 * Built here rather than injected because MenusModule constructs this
	 * operation with the MenuFields it already holds, and MenuTarget needs
	 * nothing else. Constructing it internally is what lets the capability
	 * assertion, the target-key spelling, and the key parser be the module's
	 * single copy without changing the registered constructor signature.
	 *
	 * @var MenuTarget
	 */
	private readonly MenuTarget $target;

	/**
	 * The arrangement projection this operation promises, writes, and verifies.
	 *
	 * @var MenuArrangement
	 */
	private readonly MenuArrangement $arrangement;

	/**
	 * Constructs the operation.
	 *
	 * @param MenuFields $fields The shared menu projection and validators.
	 */
	public function __construct( private readonly MenuFields $fields ) {
		$this->target      = new MenuTarget( $fields );
		$this->arrangement = new MenuArrangement();
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $resolved->targetKey is a TargetState contract property name.
	/**
	 * Resolves the menu the input names, with its current arrangement.
	 *
	 * THE CAPABILITY AND THE LOOKUP BOTH GO THROUGH MenuTarget::resolveMenu(),
	 * which asks the capability BEFORE resolving the menu for the reason MenuGet
	 * gives: otherwise the difference between the two refusals tells a caller who
	 * may not manage menus which menu keys exist on the site. Asking it here
	 * instead would be a second copy of that ordering, and the copy that drifts is
	 * the one that turns an operation into a probe.
	 *
	 * A non-string key is passed through as the empty key, which names no menu —
	 * the truth about it — rather than cast, for the reason MenuGet documents:
	 * `(string) [ 'primary' ]` is a fatal.
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
		$key      = $input['menu'] ?? null;
		$resolved = $this->target->resolveMenu( is_string( $key ) ? $key : '', $context );
		$menu_id  = MenuTarget::menuIdFromKey( $resolved->targetKey ) ?? 0;

		return new TargetState( $resolved->targetKey, true, $this->menu_state( $menu_id ) );
	}
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

		$after = $this->arrangement->projectedOrder( $before, $requested );
		$this->arrangement->assertNoCycle( $after, array_keys( $requested ) );

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
		$rows    = $this->arrangement->itemRows( $menu_id );
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
				wp_slash( $this->arrangement->itemArgs( $row, $parent, (int) $entry['position'] ) )
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

		return MenuTarget::menuTargetKey( $menu_id );
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
	 * THE MENU IS RE-RESOLVED BY TERM ID, never by the id/slug/name key resolver.
	 * `wp_get_nav_menu_object()` falls back to a slug and then a name lookup when
	 * the term lookup finds nothing, so on a site where some OTHER menu carries
	 * the bare-number slug "5", a request that wrote menu 5 and then lost it
	 * would verify against that other menu and report it as the written state.
	 * menu_by_id() answers only a term whose own identifier is the one asked for.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$menu_id = MenuTarget::menuIdFromKey( $targetKey );

		if ( null === $menu_id || null === $this->menu_by_id( $menu_id ) ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The navigation menu could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $this->menu_state( $menu_id ) );
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

		$rows     = $this->arrangement->itemRows( $menu_id );
		$intended = $this->intended_restore( $recorded, $rows );

		$completed = [];
		$step      = 0;
		$total     = count( $intended );

		foreach ( $intended as $id => $target ) {
			++$step;

			$args    = $this->arrangement->itemArgs( $rows[ $id ], $target['parent'], $target['position'] );
			$written = wp_update_nav_menu_item( $menu_id, $id, wp_slash( $args ) );

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to put one of the recorded menu items back, so the recorded order was only partly restored.',
					'Reorder the menu on the WordPress menus screen instead.',
					$completed
				);
			}

			// A RECORDED 0 IS A REAL POSITION and core cannot be asked for it: it
			// reads 0 as "append" and lands the item last. Correcting it here is
			// what makes assert_restored() able to compare every recorded position
			// literally, instead of exempting the one value the restore was most
			// likely to get wrong.
			MenuTarget::correctAppendedPosition( $id, $args );

			$completed[] = sprintf( 'menu item %d of %d restored', $step, $total );
		}

		$this->assert_restored( $menu_id, $intended, $completed );

		return MenuTarget::menuTargetKey( $menu_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One menu's identity beside its current arrangement.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return array<string, mixed> The normalized field map.
	 */
	private function menu_state( int $menu_id ): array {
		$menu = $this->menu_by_id( $menu_id );

		return [
			'id'              => $menu_id,
			'name'            => (string) ( $menu->name ?? '' ),
			'slug'            => (string) ( $menu->slug ?? '' ),
			self::ORDER_FIELD => $this->arrangement->currentOrder( $menu_id ),
		];
	}

	/**
	 * The menu one term identifier names, and only that menu.
	 *
	 * `wp_get_nav_menu_object()` — which `MenuFields::menuFromKey()` wraps —
	 * tries the term lookup, then a SLUG lookup, then a NAME lookup. That chain
	 * is right for a caller-supplied key and wrong for an identifier the plugin
	 * itself just wrote: if the term is gone, a menu whose slug happens to be the
	 * bare number "5" answers instead, and the operation would verify a write
	 * against a menu it never touched. Comparing the resolved term's own
	 * identifier is what closes that, without reaching past MenuFields into
	 * `get_term()`.
	 *
	 * @param int $menu_id The menu's term identifier.
	 *
	 * @return object|null The menu term, or null when no term carries that id.
	 */
	private function menu_by_id( int $menu_id ): ?object {
		if ( $menu_id <= 0 ) {
			return null;
		}

		$menu = $this->fields->menuFromKey( (string) $menu_id );

		return null !== $menu && (int) ( $menu->term_id ?? 0 ) === $menu_id ? $menu : null;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Envelope text, never echoed.
	/**
	 * One caller-supplied entry, normalized, or a refusal.
	 *
	 * The shape checks look like defence in depth against the input schema, and
	 * for the `id` and `position` TYPES they are — but BOTH LOWER BOUNDS earn
	 * their place, and each has a test that reaches it by calling planChange()
	 * directly, the way any caller that skips the dispatcher would.
	 *
	 * A 0 position is not "first": `wp_update_nav_menu_item()` replaces it with the
	 * menu's item count plus one, so the item silently lands last.
	 *
	 * A 0 identifier is refused HERE so that it is refused for what it is. The
	 * membership check in planChange() would reject it a moment later, but with
	 * "does not name an item of this menu" — which sends the operator looking up
	 * a menu that never had an item 0 to find. 0 is also the root-parent sentinel,
	 * the conflation that has already produced one unbounded recursion in this
	 * module, so it does not travel further into the entry set than this.
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
		// Narrowed ONCE, here. An entry that is not an array carries no `id`, so
		// the guard below refuses it; re-testing the shape at the `parent` branch
		// would be a conjunct no fixture could ever make false.
		$fields   = is_array( $entry ) ? $entry : [];
		$id       = $fields['id'] ?? null;
		$position = $fields['position'] ?? null;

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

		if ( array_key_exists( 'parent', $fields ) ) {
			$parent = $fields['parent'];

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
	 * filter can rewrite the arguments. On a restore path there is no
	 * WriteVerifier downstream to notice either.
	 *
	 * The comparison itself lives on MenuArrangement, which owns every other
	 * question about where a menu's items sit; this method owns only the decision
	 * to refuse and the wording that carries it. Positions are compared literally,
	 * including a recorded 0 — see MenuArrangement::firstMisplaced() for why that
	 * value once carried an exemption and why it no longer needs one.
	 *
	 * The message names plainly that the rows were written, because they were:
	 * this refusal is only ever reached after the whole loop completed.
	 *
	 * @param int                            $menu_id   The menu's term identifier.
	 * @param array<int, array<string, int>> $intended  The intended parent and position per identifier.
	 * @param string[]                       $completed The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_restored( int $menu_id, array $intended, array $completed ): void {
		if ( null === $this->arrangement->firstMisplaced( $menu_id, $intended ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::ExecutionFailed,
			'Every recorded menu item was written, but WordPress stored a different order than the recorded snapshot held.',
			'Reorder the menu on the WordPress menus screen instead.',
			$completed
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
