<?php
/**
 * The contract a write implements to make its own snapshots restorable.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0081: restoration for a write whose target is not a post.
 *
 * `content-rollback-apply` was built when every snapshot in the plugin belonged
 * to a post, and it resolved a stored `target_key` by parsing a post id out of
 * it. Writes whose targets are redirects, comments and user accounts have shipped
 * since, and each of them records a snapshot and hands the caller a
 * `rollbackRef` — which then could not be redeemed, because the reference
 * resolved through the post parser and answered `target_not_found`. The offer was
 * real; the redemption was not.
 *
 * A write that implements this interface takes its own snapshots back. The
 * rollback operation keeps the two-phase flow, the audit row, the fresh
 * pre-rollback snapshot and the verification; what it delegates is the three
 * things only the origin knows — how to resolve one of its target keys, who may
 * overwrite it, and what a restoration of the recorded state actually promises.
 *
 * THE CAPABILITY RE-CHECK IS PART OF resolveRollbackTarget(), DELIBERATELY.
 * `content-rollback-apply` declares `edit_post` at the front gate, which is the
 * wrong question to ask about a comment or a user account: a caller holding
 * `edit_post` and nothing else would otherwise reach a comment moderation or a
 * role change through the rollback path that the origin operation's own gate
 * refuses. Folding the check into resolution makes it impossible to resolve a
 * delegated target without asking it, and resolution runs in BOTH phases.
 *
 * The interface extends WriteOperation rather than standing beside it because
 * restoration also uses `restore()`, `readBack()` and `captureSnapshot()`, which
 * every write already has: a delegate is a write that can additionally be
 * entered from a stored reference, not a separate kind of thing.
 *
 * @package SiteHelm
 */
interface RollbackDelegate extends WriteOperation {

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Target keys and restore states are named as the engine names them.
	/**
	 * Resolves one of this operation's stored target keys, and re-checks that
	 * this caller may overwrite what it names.
	 *
	 * Called in both the preview and the apply phase, so a permission withdrawn
	 * between them refuses the apply.
	 *
	 * @param string           $targetKey The target key the snapshot recorded.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The current state of the recorded target.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the key
	 *                           names nothing this operation can resolve, or
	 *                           ErrorCode::Forbidden when the caller may not
	 *                           overwrite it.
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState;

	/**
	 * The field map a restoration of this recorded state would produce.
	 *
	 * This is what the rollback promises at preview and what WriteVerifier
	 * compares the read-back against, so it must be expressed in the same
	 * vocabulary `readBack()` projects — not in the vocabulary the restore state
	 * happens to be stored in. The two differ in practice: a redirect snapshot
	 * records the whole table under `redirects`, while the read-back projects one
	 * row, and a generic key-by-key promise would promise the path back to itself
	 * and verify having restored nothing.
	 *
	 * An EMPTY map means the recorded state holds nothing this restore could put
	 * back. The caller turns that into `rollback_unavailable` rather than
	 * applying a promise of nothing.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised after-state, or an empty map.
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
