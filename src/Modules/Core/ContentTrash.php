<?php
/**
 * Move-to-trash write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0019: move content to trash. An agency operator retires client content
 * recoverably so a mistake never destroys data.
 *
 * The only operation in V1 whose rollbackPolicy is `required`, which
 * OperationDefinition's cross-field rule forces SnapshotPolicy::Required
 * alongside. That rule is proved rather than assumed: temporarily declaring
 * SnapshotPolicy::Supported here fails every test that builds the registry with
 * "Operation 'content-trash': rollbackPolicy required forces snapshotPolicy
 * required." This is the only operation that can exercise it.
 *
 * TRASH MUST ACTUALLY BE ENABLED, and this operation refuses when it is not.
 * wp_trash_post() opens with `if ( ! EMPTY_TRASH_DAYS ) { return
 * wp_delete_post( $post_id, true ); }` — read from wp-includes/post.php of the
 * WordPress 6.8.1 install on this machine, not copied from a design note. That
 * is a PERMANENT delete. On a site that defines EMPTY_TRASH_DAYS as 0, calling
 * this operation would destroy the content outright, and the required rollback
 * could not put it back: the row is gone, so wp_update_post() against it fails
 * and the rollback answers execution_failed over destroyed data. Nothing after
 * the call can guard that, because by the time anything could measure, the post
 * no longer exists. The refusal is Forbidden, not Conflict: the condition is
 * WordPress-side configuration only a site administrator can change, which is
 * precisely the reasoning ErrorCode::isRetryable() gives for the non-retryable
 * set, and Conflict is declared retryable so a client would retry into the same
 * refusal forever.
 *
 * The refusal lives in planChange() rather than in the definition's
 * requiredCapabilities, because PolicyEngine::authorize() receives the
 * definition, the context and one target id and never a site configuration.
 * That placement is as strong as a gate check for the reason ContentStatusSet
 * gives: ChangeEngine calls planChange() at preview AND again at apply before
 * applyChange(), so the guard cannot be previewed past.
 *
 * A PRE-WRITE GUARD USUALLY ONLY PROVES A FACT ABOUT THE MOMENT IT RUNS —
 * WordPress writes fire hooks and hooks run arbitrary code — but this one is
 * the exception, and the reason is worth stating so nobody adds a redundant
 * re-check: EMPTY_TRASH_DAYS is a PHP constant, and a constant cannot be
 * redefined once set, so no hook fired by anything between the guard and the
 * call can change the answer. What hooks CAN still do is veto the trash
 * (pre_trash_post) or destroy the post from wp_trash_post / trashed_post, and
 * applyChange()'s post-write measurement is the check on that axis.
 *
 * The constant is treated as ENABLED when it is not defined at all. In a real
 * WordPress process it always is — core defines it during
 * wp_plugin_directory_constants() before any plugin loads — so `undefined` can
 * only occur in a unit-test process, and a PHP constant cannot be redefined once
 * set. Reading it the other way round would make one branch or the other
 * permanently unreachable in the suite.
 *
 * THE SLUG RENAME IS PROMISED, AND AN ADJUSTMENT IS ACCEPTED. Trashing renames
 * the slug to `slug__trashed`, so a plan promising only post_status would leave
 * post_name changed but unpromised. But core truncates the base to 191
 * characters first and then runs wp_unique_post_slug(), which appends a numeric
 * suffix on collision against whatever else exists at the moment of the insert —
 * unknowable at plan time. So the operation promises the value it expects and
 * accepts an adjustment: WriteVerifier's three-way rule reports a stored value
 * differing from both the promise and the prior value as ADJUSTED, ChangeEngine
 * names the field in a warning, and the stored value is disclosed in data.state.
 * This is the first operation to rely on verified-with-adjustments as a designed
 * outcome rather than as a safety net, and that is deliberate. Do not try to
 * predict the exact slug: modelling a transformation that depends on database
 * state manufactures a guarantee that cannot be kept, which
 * ContentFields::sanitizeForSave()'s docblock sets out at length.
 *
 * The one part of the rename that IS a pure function of the input is modelled: a
 * slug already ending in `__trashed` keeps it, because core returns early for
 * exactly that case.
 *
 * RESTORE IS EXPLICIT, NOT wp_untrash_post(). WordPress stores the pre-trash
 * status in _wp_trash_meta_status and wp_untrash_post() reads it, which is the
 * platform-native path — but it depends on meta another plugin can clear, and it
 * restores a status this plugin never recorded or promised. The engine's
 * contract is to restore the state the SNAPSHOT recorded, and honouring that
 * literally is what makes rollback auditable. The trade, recorded here rather
 * than hidden: explicit restoration skips the `untrash_post` action other
 * plugins may hook.
 *
 * isDestructive is false, and that is a claim about this operation rather than
 * about trash in general. WordPress's trash is a recoverable state; the one
 * configuration in which this operation would destroy anything is refused above.
 * The three policies are Required regardless, because rollbackPolicy Required
 * forces snapshotPolicy Required and previewPolicy is declared Required outright,
 * so nothing about the operation's protection depends on this flag.
 *
 * @package SiteHelm
 */
final class ContentTrash implements WriteOperation {

	/**
	 * The status WordPress stores for trashed content.
	 */
	private const TRASH_STATUS = 'trash';

	/**
	 * The suffix core appends to a trashed post's slug.
	 */
	private const TRASHED_SUFFIX = '__trashed';

	/**
	 * The two post meta keys wp_trash_post() adds directly, which an explicit
	 * restore must remove.
	 *
	 * Core writes them with add_post_meta(), not update_post_meta().
	 * Leaving them behind on a restored post means a later trash through the
	 * WordPress admin adds a SECOND row, and wp_untrash_post() reads the key with
	 * single=true, which answers the first — so the admin's Restore button would
	 * return the post to a status two edits stale. Removing them is what makes an
	 * explicit restore leave the post in the state the snapshot recorded rather
	 * than in that state plus residue.
	 *
	 * `_wp_trash_meta_comments_status` is deliberately NOT a third member, and
	 * `_wp_desired_post_slug` is deliberately not a fourth. Both are removed by
	 * core itself on the paths restore() already takes — see restore() — and
	 * deleting the comments key here rather than through
	 * wp_untrash_post_comments() would strand every comment at `post-trashed`
	 * with the record of its prior status destroyed, which is strictly worse than
	 * leaving the residue.
	 *
	 * @var string[]
	 */
	private const TRASH_META_KEYS = [ '_wp_trash_meta_status', '_wp_trash_meta_time' ];

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-trash.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-trash',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Move one existing content item to the WordPress trash, recording its prior status and slug so the move can be reversed.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to move to the trash.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'delete_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-trash',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised trash transition.
	 *
	 * Two keys are promised, so this one DOES ksort where the single-field writes
	 * do not: the promise is fingerprinted and rendered in order, and two keys
	 * built by hand have an order for a sort to make deterministic.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when this site is
	 *                           configured to delete rather than trash.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$this->assert_trash_is_enabled();

		$promised = [
			'post_status' => self::TRASH_STATUS,
			'post_name'   => $this->trashed_slug( (string) ( $current->fields['post_name'] ?? '' ) ),
		];
		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * ContentTarget::snapshotOf() is exactly right here and is used unchanged: it
	 * records post_status and post_name, the two columns this write changes, plus
	 * the three text columns, and the trash does not touch those. This is the one
	 * operation in this plan that does not need its own capture, because
	 * everything it can change is already a post column on the shared list.
	 *
	 * THE LOSSY-PROJECTION DIAGNOSTIC, applied to all five recorded fields, since
	 * that check has found a real defect on each of the last two tasks: does the
	 * read path have an inverse? ContentFields::read() projects post_title,
	 * post_content, post_excerpt, post_status and post_name as bare
	 * `(string) $post->…` casts of the post columns, and ContentTarget's restore
	 * loop writes those same five names straight back through one
	 * wp_update_post(). Read and write are the identity on the same column in
	 * both directions, so `project(S)` reconstructs S for every stored state and
	 * none of the five can be recorded faithfully while being restored to
	 * something else. The two narrowed projections in this module —
	 * ContentFields::meta() collapsing structured and multi-row values, and
	 * ContentFields::terms() over a `sort => true` taxonomy — are reached by
	 * neither this snapshot nor this write, because wp_trash_post() changes no
	 * meta this operation records and no term relationship at all.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Moves the item to the trash, and judges the result by measurement.
	 *
	 * The return value of wp_trash_post() is NOT a success signal, in three
	 * separate directions, all read from core rather than assumed:
	 *
	 * - On success it returns the `$post` object it fetched BEFORE the update, so
	 *   its post_status holds the OLD status. Anything reading the returned
	 *   object's status would conclude the trash did not happen.
	 * - It returns FALSE when the post is already trashed — `if ( 'trash' ===
	 *   $post->post_status ) { return false; }` — which means the promised state
	 *   already holds. Treating that as failure would break the declared
	 *   idempotence for the ordinary case of a retried apply.
	 * - It returns false ALSO when the pre_trash_post filter vetoes and when the
	 *   inner wp_update_post() fails, which are genuine failures. One return
	 *   value, three meanings.
	 *
	 * So the post is re-read and its status compared, which is unambiguous about
	 * all three. It is the same verify-by-measurement ContentFeaturedMediaSet and
	 * ContentTarget::restore_featured_media() use, and it is also the post-write
	 * check the class docblock owes the pre-write guard: wp_trash_post() fires
	 * `wp_trash_post` and `trashed_post`, and a plugin hooked to either can undo
	 * or destroy the write the guard cleared.
	 *
	 * No clean_post_cache() before the read, and that is a decision rather than an
	 * omission. Every path through wp_trash_post() leaves the cache agreeing with
	 * the row: the successful path cleans it from inside wp_insert_post(), and
	 * every early return — already trashed, vetoed, missing — makes no write at
	 * all, so the cached object still holds the status this measurement must see.
	 * readBack() cleans the cache anyway before the state the engine verifies
	 * against is read.
	 *
	 * A post that has vanished entirely reaches the same refusal. That is the
	 * shape a permanent delete leaves behind, and while planChange() refuses the
	 * configuration that causes it, a filter on pre_trash_post can delete too.
	 *
	 * THE THREE TERMS OF THAT GUARD ARE NOT EQUALLY LOAD BEARING, and which is
	 * which was settled by mutation rather than by reading:
	 *
	 * - `self::TRASH_STATUS !== (string) $stored->post_status` is the measurement
	 *   itself; deleting it accepts every vetoed trash.
	 * - `! isset( $stored->post_status )` IS load bearing, and its assertable
	 *   input is an object resolving the member only through __get — the shape a
	 *   lazy object-cache proxy has, and one WP_Post itself uses for its derived
	 *   members. Deleting it accepts a status produced by arbitrary code as
	 *   though it had been measured.
	 * - `! is_object( $stored )` is DEFENCE IN DEPTH and currently cannot change
	 *   the answer for any input, because PHP answers false to `isset( $x->p )`
	 *   for every non-object $x, so the next term already refuses those shapes.
	 *   Deleting it alone survives the suite; deleting it TOGETHER with the isset
	 *   term fails only incidentally, on "Attempt to read property on null",
	 *   which is what proves that statement rather than merely asserting it. It
	 *   is kept deliberately — it is free, it states the intended shape, and any
	 *   edit that narrowed or reordered the isset term makes it load bearing
	 *   again. Do not delete it on the strength of a surviving mutation.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		wp_trash_post( $post_id );

		$stored = get_post( $post_id );

		if ( ! is_object( $stored ) || ! isset( $stored->post_status )
			|| self::TRASH_STATUS !== (string) $stored->post_status ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not move the content item to the trash.',
				'Generate a fresh preview and retry; the prior status and slug remain recorded for rollback.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded status and slug back, restores the comments the trash
	 * hid, then clears the trash residue.
	 *
	 * The column write is ContentTarget::restoreFields(), unchanged and shared.
	 * Everything after it is this operation's own, because what it undoes is what
	 * this operation's own apply caused wp_trash_post() to do.
	 *
	 * The two column writes agree with each other by construction, which is worth
	 * stating because it looks like a conflict: core's wp_insert_post() reads
	 * _wp_desired_post_slug when a post leaves the trash and substitutes it for
	 * the submitted slug — but that meta holds the pre-trash slug, which is
	 * exactly what the snapshot recorded, so both paths land on the same value.
	 * Core deletes that key itself on the same branch, which is why it is not in
	 * TRASH_META_KEYS.
	 *
	 * COMMENTS ARE RESTORED, AND THIS IS A DEVIATION FROM THE BRIEF, recorded
	 * here rather than made quietly. wp_trash_post() calls wp_trash_post_comments(),
	 * which sets EVERY comment on the post to `comment_approved = 'post-trashed'`
	 * and stashes the prior statuses in _wp_trash_meta_comments_status. A restore
	 * that wrote back only the columns would put the post back on the live site
	 * with every comment on it still hidden, and would report the rollback
	 * verified — because the engine verifies the fields the snapshot recorded, and
	 * comment_approved is not one of them. Worse, the post is no longer in the
	 * trash by then, so the admin's Restore button — the only UI that calls
	 * wp_untrash_post_comments() — is no longer offered for it, leaving the
	 * comments hidden with the recovery data present and unreachable. On the one
	 * operation in V1 whose rollback is REQUIRED, that is the failure this
	 * requirement exists to prevent. wp_untrash_post_comments() is core's own
	 * public function for exactly this, it returns early when nothing was
	 * recorded, and it deletes _wp_trash_meta_comments_status itself.
	 *
	 * Its result is NOT measured, deliberately and unlike every other write in
	 * this module. It answers null on both the nothing-recorded path and the
	 * completed path, so there is no return to judge; and a refusal here would
	 * turn a restore whose recorded columns all landed into a reported rollback
	 * failure over comment residue, which would be a worse answer than the one it
	 * replaced. The claim this method makes is therefore exactly "the recorded
	 * snapshot was restored", which the engine verifies, plus a best-effort
	 * cleanup, which it does not.
	 *
	 * The cleanup runs AFTER the column write. Removing _wp_desired_post_slug
	 * before wp_update_post() ran would take away the value core falls back on if
	 * anything filtered the submitted slug away, and both remaining keys are read
	 * by core paths the column write can reach.
	 *
	 * KNOWN LIMITATION, recorded rather than hidden: content-rollback-apply does
	 * not call this method. It rebuilds a restore state from the shared field
	 * lists and calls ContentTarget::restoreFields() directly, so a rollback
	 * issued through that operation restores the status and slug correctly and
	 * leaves the comments hidden and the two trash meta rows in place. The rows
	 * are inert on a post that is not in the trash; the consequence is that a
	 * later trash through the WordPress admin adds a second _wp_trash_meta_status
	 * row, and the admin's Restore button then reads the older of the two.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$target_key = $this->targets->restoreFields( $restoreState );
		$post_id    = (int) ( $restoreState['post_id'] ?? 0 );

		wp_untrash_post_comments( $post_id );

		foreach ( self::TRASH_META_KEYS as $key ) {
			delete_post_meta( $post_id, $key );
		}

		return $target_key;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses when this site is configured to delete rather than trash.
	 *
	 * See the class docblock. `defined()` first, because constant() on an
	 * undefined name is an Error in PHP 8, and an undefined constant means a
	 * process WordPress did not boot rather than a site with the trash disabled.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_trash_is_enabled(): void {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! constant( 'EMPTY_TRASH_DAYS' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This site is configured to delete content permanently rather than move it to the trash, so this operation cannot retire content recoverably.',
				'Ask a site administrator to enable the WordPress trash, or remove the content through the WordPress administration screens instead.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The slug the trash is expected to produce.
	 *
	 * Only the part that is a PURE function of the input is modelled: core returns
	 * the slug unchanged when it already ends in the suffix, and appends the
	 * suffix otherwise. The truncation to 191 characters and the numeric
	 * uniquifier are deliberately NOT modelled — the first is a multibyte-aware
	 * core-private helper and the second queries the database — and both surface
	 * as verified-with-adjustments, which is the designed outcome.
	 *
	 * An empty prior slug produces `__trashed`, and that is left alone rather than
	 * special-cased. WordPress fills an empty slug from the title during the same
	 * insert, so whatever it stores is an adjustment either way, and inventing a
	 * different promise here would only make the preview less honest.
	 *
	 * @param string $slug The prior slug.
	 *
	 * @return string The expected trashed slug.
	 */
	private function trashed_slug( string $slug ): string {
		return str_ends_with( $slug, self::TRASHED_SUFFIX ) ? $slug : $slug . self::TRASHED_SUFFIX;
	}
}
