<?php
/**
 * Navigation menu item deletion write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Change\TargetState;
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
 * Remove one item from a navigation menu.
 *
 * The menus module could add an item, repoint it, reorder the menu and assign it
 * to a theme location, and it could not take an item away. A launch-week
 * navigation edit is rarely additive — placeholder anchors written before the
 * inner pages existed have to go somewhere — and a menu that ends up shorter than
 * it started could not be reached through this dispatcher at all.
 *
 * THE DELETION IS FORCED, not trashed, matching the reversal `menu-item-create`
 * already performs and matching what Appearance then Menus does. A trashed
 * `nav_menu_item` keeps its term relationship, so the menu would still hold an
 * item this operation reported as removed.
 *
 * AN ITEM WITH CHILDREN IS REFUSED rather than deleted. Every child stores its
 * parent as that item's post identifier, and the restore below puts the item back
 * under a NEW identifier — so a deletion that took a parent with it would leave
 * children pointing at a row that no longer exists and a rollback that could not
 * reunite them. Delete the children first, or repoint them with
 * `menu-item-update`, and the parent then deletes cleanly.
 *
 * THE RESTORE RE-CREATES; IT DOES NOT REVIVE. `wp_update_nav_menu_item()` handed
 * the identifier of a row that is gone inserts a new row rather than failing, so
 * the reversal is honest about producing a new identifier and answers the key of
 * what it actually created. That is also why this class implements
 * RollbackDelegate: a `menu-item:` reference resolved through
 * `content-rollback-apply`'s post parser would answer `target_not_found` for a
 * target this operation deliberately left absent, and the offer of a
 * `rollbackRef` would be one the plugin could not honour.
 *
 * @package SiteHelm
 */
final class MenuItemDelete implements RollbackDelegate {

	/**
	 * Builds the operation.
	 *
	 * @param MenuFields $fields  The menus module's shared reader.
	 * @param MenuTarget $targets The menus module's shared target resolver.
	 */
	public function __construct(
		private readonly MenuFields $fields,
		private readonly MenuTarget $targets
	) {
	}

	/**
	 * The operation's declaration.
	 *
	 * @return OperationDefinition The declaration.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-item-delete',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Remove one item from a navigation menu. An item that has items beneath it is refused until those are removed or repointed first.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'item' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the menu item to remove.',
					],
				],
				'required'             => [ 'item' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ MenuTarget::REQUIRED_CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: true,
			// Not idempotent: the identifier the request names is gone after the
			// first call, so the second call answers target_not_found rather than
			// repeating the same outcome.
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-item-delete',
				'arguments' => [
					'item' => 412,
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The contract's own camelCase parameter names.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId and $current->targetKey are contract property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The contract's own camelCase method names.
	/**
	 * Resolves the item the request names.
	 *
	 * The capability is asked inside resolveItem(), before the lookup, so a caller
	 * who may not administer menus cannot learn which item identifiers this site
	 * holds by reading which of them answer target_not_found.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$item_id = is_numeric( $input['item'] ?? null ) ? (int) $input['item'] : 0;

		return $this->targets->resolveItem( $item_id, $context );
	}

	/**
	 * Promises the absence this write produces, and refuses a parent.
	 *
	 * The child check lives here rather than in applyChange() because the engine
	 * runs this method in both phases: a refusal an operator would meet at apply
	 * is the one outcome the preview contract exists to prevent.
	 *
	 * THE PROMISED AFTER-STATE IS `exists: false` AND NOTHING ELSE. A removal has
	 * no field values afterwards, so promising a title or an address would ask
	 * WriteVerifier to compare values against a row that is meant to be gone — and
	 * that comparison passes most convincingly in exactly the case where the
	 * deletion silently did not happen.
	 *
	 * The payload carries the item's identity as the plan recorded it, so an
	 * operator approving this preview approved removing THIS item.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$item_id  = MenuTarget::itemIdFromKey( $current->targetKey ) ?? 0;
		$children = $this->child_ids( $item_id );

		if ( [] !== $children ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf(
					'This navigation menu item has %d item(s) beneath it, and removing it would leave them pointing at an item that no longer exists.',
					count( $children )
				),
				sprintf(
					'Remove the items beneath it first, or move them with menu-item-update by setting their parent to 0 or to another item. Their identifiers are: %s.',
					implode( ', ', array_map( 'strval', $children ) )
				)
			);
		}

		return new PlannedChange(
			[
				'item'  => $item_id,
				'menu'  => $this->fields->menuTermIdForItem( $item_id ) ?? 0,
				'title' => (string) ( $current->fields['title'] ?? '' ),
				'url'   => (string) ( $current->fields['url'] ?? '' ),
			],
			[ 'exists' => false ],
			[ 'exists' ]
		);
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Records the item's whole field set so it can be put back.
	 *
	 * Side-effect free and callable twice, as the engine requires: it reads the
	 * item and its menu and writes nothing.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the key
	 *                                   names no item.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$item_id = MenuTarget::itemIdFromKey( $current->targetKey );

		return null === $item_id ? null : $this->targets->snapshotItem( $item_id );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Deletes the item.
	 *
	 * Verified by re-reading rather than by trusting `wp_delete_post()`'s answer,
	 * because a `before_delete_post` handler can veto a deletion the return value
	 * still reports as attempted — the same reason `menu-item-create` re-reads
	 * before calling its own reversal done.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$item_id = MenuTarget::itemIdFromKey( $current->targetKey );

		if ( null === $item_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the menu item this removal was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		wp_delete_post( $item_id, true );
		clean_post_cache( $item_id );

		if ( is_nav_menu_item( $item_id ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The navigation menu item is still present after the removal.',
				'Remove the item under Appearance then Menus in the WordPress administration screens instead.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $current->targetKey;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Re-reads the item's presence for verification.
	 *
	 * MenuTarget::verifyRead() is deliberately not reused: it throws when the item
	 * is absent, which is the state this operation exists to produce. `exists` is
	 * the field promised and it is the field reported, so an item still present
	 * after the write reads back as existing — exactly the disagreement
	 * WriteVerifier is looking for.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$item_id = MenuTarget::itemIdFromKey( $targetKey );

		if ( null === $item_id ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The menu item could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		clean_post_cache( $item_id );

		$exists = is_nav_menu_item( $item_id );

		return new TargetState( $targetKey, $exists, [ 'exists' => $exists ] );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Puts the recorded item back into its menu.
	 *
	 * THE ANSWER IS A NEW TARGET KEY, and that is not a defect to paper over. The
	 * row was force-deleted, so there is nothing to revive; core inserts. Reporting
	 * the old identifier would name a row that does not exist and hand
	 * WriteVerifier something it could never read back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The re-created item's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->recreateItem( $restoreState );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Resolves one of this operation's recorded keys for a stored rollback.
	 *
	 * This operation's own resolveTarget() is not reused, and here it could not
	 * be: it refuses an identifier that names no item, which is precisely the
	 * state this operation leaves behind and the one a rollback of it starts from.
	 *
	 * @param string           $targetKey The target key the snapshot recorded.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The current state of the recorded target.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, MenuTarget::REQUIRED_CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not administer this site\'s navigation menus.',
				'Ask a site administrator to grant the ability to edit theme options, then retry.'
			);
		}

		$item_id = MenuTarget::itemIdFromKey( $targetKey );

		if ( null === $item_id ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		$exists = is_nav_menu_item( $item_id );

		return new TargetState( $targetKey, $exists, [ 'exists' => $exists ] );
	}

	/**
	 * What a restoration of this recorded state would leave behind.
	 *
	 * Promised in this operation's own read vocabulary — `exists`, the single key
	 * readBack() projects — rather than in the `menu-item-*` spelling the snapshot
	 * is stored in. A state that names an item and its menu restores to an item
	 * being there; a state that names neither restores to nothing, and the caller
	 * turns an empty map into `rollback_unavailable`.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised presence, or an empty map.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		$item_id = is_numeric( $restoreState['item_id'] ?? null ) ? (int) $restoreState['item_id'] : 0;
		$menu_id = is_numeric( $restoreState['menu_id'] ?? null ) ? (int) $restoreState['menu_id'] : 0;

		if ( $item_id <= 0 || $menu_id <= 0 ) {
			return [];
		}

		return [ 'exists' => true ];
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The identifiers of the items sitting directly beneath one item.
	 *
	 * Read from the menu the item belongs to rather than from a query on
	 * `_menu_item_menu_item_parent`, so the answer comes through
	 * `wp_setup_nav_menu_item()` — the same filtered view every other reader in
	 * this module walks.
	 *
	 * @param int $item_id The menu item post identifier.
	 *
	 * @return int[] The child identifiers, ascending.
	 */
	private function child_ids( int $item_id ): array {
		if ( $item_id <= 0 ) {
			return [];
		}

		$menu_id = $this->fields->menuTermIdForItem( $item_id );

		if ( null === $menu_id ) {
			return [];
		}

		$items = wp_get_nav_menu_items( $menu_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$children = [];

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$child_id = (int) ( $item->ID ?? 0 );

			if ( 0 < $child_id && (int) ( $item->menu_item_parent ?? 0 ) === $item_id ) {
				$children[] = $child_id;
			}
		}

		sort( $children, SORT_NUMERIC );

		return $children;
	}
}
