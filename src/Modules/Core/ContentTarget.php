<?php
/**
 * Shared target resolution for the content write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The three things every content write does identically: resolve the target,
 * re-read it for verification, and write a recorded restore state back.
 *
 * Extracted so create, update, rollback, featured-media assignment and status
 * change share one implementation rather than five that could drift apart.
 *
 * @package SiteHelm
 */
final class ContentTarget {

	/**
	 * Constructs the resolver.
	 *
	 * @param ContentFields $fields The normalized field map.
	 */
	public function __construct( private readonly ContentFields $fields ) {
	}

	/**
	 * The post columns a content snapshot records, and the half of a restore that
	 * `wp_update_post()` writes.
	 *
	 * Public because `ContentRollbackApply` needs the same list to promise what
	 * a restore will put back, and a second copy of it would drift: the copy
	 * that drifts decides which columns a rollback silently fails to restore.
	 *
	 * BEFORE ADDING A FIELD HERE, check it is really a post column. Every loop
	 * over this list casts to string and hands the result to `wp_update_post()`,
	 * so anything stored as post meta would be recorded, promised, silently
	 * ignored on the way in, and then reported as restored. That is what
	 * `RESTORABLE_MEDIA_FIELDS` exists for, and why it is a second list rather
	 * than more entries here: one list cannot serve two write mechanisms.
	 *
	 * `post_status` and `post_name` joined the original three because a write
	 * that moves content between statuses, or one that trashes it, changes both
	 * — and WordPress renames a trashed slug to `slug__trashed`. Without them
	 * recorded, a rollback restores the words and loses where the content was
	 * in its workflow.
	 */
	public const RESTORABLE_FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
		'post_status',
		'post_name',
	];

	/**
	 * The restorable values a content write can change that are NOT post
	 * columns, and therefore cannot be written by wp_update_post().
	 *
	 * `featured_media` is stored as the `_thumbnail_id` post meta, so a restore
	 * has to go through set_post_thumbnail() / delete_post_thumbnail() instead.
	 * It is a SEPARATE list rather than a sixth entry in RESTORABLE_FIELDS
	 * because every loop over that list casts its values to string and hands
	 * them to wp_update_post(): a string thumbnail id added there would be
	 * recorded, promised, silently ignored on the way in, and reported as
	 * restored. One list with two write mechanisms is how that happens.
	 *
	 * Values in this list are integers, not strings.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_MEDIA_FIELDS = [ 'featured_media' ];

	/**
	 * The restorable value a content write can change that lives in the post
	 * meta table under administrator-permitted keys.
	 *
	 * A THIRD list rather than more entries in either of the two above, for the
	 * reason RESTORABLE_MEDIA_FIELDS gives for being the second: one list cannot
	 * serve two write mechanisms. RESTORABLE_FIELDS is written by one
	 * wp_update_post() call; RESTORABLE_MEDIA_FIELDS by set_post_thumbnail() with
	 * one integer; this one by a loop of update_post_meta() calls over a map.
	 *
	 * The value in this list is an ARRAY — meta key to string value — covering
	 * every key ContentFields::allowlist() permitted at the moment of capture,
	 * not only the keys a write changed. That is what makes it comparable to the
	 * read-back, which projects the same complete map.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_CUSTOM_FIELDS = [ 'meta' ];

	/**
	 * The restorable value a content write can change that lives in the term
	 * relationship tables.
	 *
	 * The fourth list, and the fourth write mechanism: wp_set_object_terms(),
	 * one call per taxonomy. It cannot be folded into RESTORABLE_CUSTOM_FIELDS
	 * even though both hold a map, because the values are int[] rather than
	 * strings and the write is per-taxonomy rather than per-key.
	 *
	 * The value is a map of taxonomy name to a sorted, deduplicated list of term
	 * ids, matching exactly what ContentFields::read() projects, so a restore can
	 * be verified by re-reading through the same path.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_TAXONOMY_FIELDS = [ 'terms' ];

	/**
	 * The restorable post columns a content write can change that are whole
	 * numbers rather than text.
	 *
	 * A FIFTH list but not a fifth write mechanism, and it is the only one of
	 * the five that is not. Both are post columns like the five in
	 * RESTORABLE_FIELDS and both ride the same wp_update_post() call; they are
	 * kept apart because every loop over that list casts its values to string,
	 * and a position recorded as 3 and promised as '3' would not equal the 3
	 * the read-back projects. A correct rollback would then report itself
	 * adjusted, every time. `post_parent` joins for the same reason and shares
	 * the same treatment.
	 *
	 * Values in this list are integers, not strings.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_ORDER_FIELDS = [ 'menu_order', 'post_parent' ];

	/**
	 * The restorable value a content write can change that lives in protected
	 * post meta.
	 *
	 * A SIXTH list and a fifth write mechanism, for the reason the media list
	 * gives for being the second: one list cannot serve two write mechanisms.
	 * `page_template` looks like a post column because wp_update_post() accepts
	 * it, but it is meta, and the accepting is conditional — core ignores an
	 * empty `page_template` outright. An item that had no template of its own
	 * records '' here, so restoring it through that call would quietly leave the
	 * written template in place and report the rollback done. It is written
	 * directly against its meta key instead, where '' means delete.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_TEMPLATE_FIELDS = [ 'page_template' ];

	/**
	 * Resolves one existing content item.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when absent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolve( int $postId ): TargetState {
		$fields = $this->fields->read( $postId );
		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The requested content item does not exist or is not visible to your WordPress user.',
				'Confirm the content identifier and that your WordPress user may edit that item.'
			);
		}

		return new TargetState( $this->fields->targetKey( $postId ), true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The state of a target that does not exist yet.
	 *
	 * @return TargetState The pending state.
	 */
	public function pending(): TargetState {
		return new TargetState( $this->fields->pendingTargetKey(), false, [] );
	}

	/**
	 * Re-reads a target after a write so the engine can verify it.
	 *
	 * The post cache is invalidated first. That is both correct for verification
	 * and the module's declared cache-cleanup obligation: a change is visible on
	 * the live site immediately after a verified write.
	 *
	 * @param string $targetKey     The concrete target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                           target cannot be re-read at all.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$post_id = $this->fields->postIdFromTargetKey( $targetKey );
		clean_post_cache( $post_id );
		$fields = $this->fields->read( $post_id );

		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The content item could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The minimum local state required to reverse a content write.
	 *
	 * Every column a content write can change is captured, and nothing else,
	 * per the design's requirement that a snapshot store the minimum state
	 * required for restoration. The lists are `RESTORABLE_FIELDS` and
	 * `RESTORABLE_ORDER_FIELDS`, so widening one widens the capture and the
	 * restore together rather than one of the two. The order column is recorded
	 * as an integer because that is how the read-back projects it.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function snapshotOf( TargetState $current ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			$snapshot[ $field ] = (string) ( $current->fields[ $field ] ?? '' );
		}

		foreach ( self::RESTORABLE_ORDER_FIELDS as $field ) {
			$snapshot[ $field ] = (int) ( $current->fields[ $field ] ?? 0 );
		}

		foreach ( self::RESTORABLE_TEMPLATE_FIELDS as $field ) {
			$snapshot[ $field ] = (string) ( $current->fields[ $field ] ?? '' );
		}

		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Writes a recorded restore state back to its content item.
	 *
	 * Only the fields the recorded state actually contains are written. A
	 * snapshot captured before a field joined RESTORABLE_FIELDS does not carry
	 * it, and the contract is to restore the state the snapshot recorded — not
	 * to invent a value for a column it never observed.
	 *
	 * Four write mechanisms are used across five field lists, because only
	 * RESTORABLE_FIELDS and RESTORABLE_ORDER_FIELDS hold post columns and they
	 * share a call: one wp_update_post() for whichever of those columns the
	 * state recorded,
	 * set_post_thumbnail() / delete_post_thumbnail() for a recorded
	 * featured-media id, a loop of update_post_meta() calls for a recorded
	 * RESTORABLE_CUSTOM_FIELDS map, and one wp_set_object_terms() call per
	 * taxonomy for a recorded RESTORABLE_TAXONOMY_FIELDS map. A state holding
	 * no column at all issues no wp_update_post() call.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no target, or
	 *                           ErrorCode::ExecutionFailed when a permitted custom
	 *                           field holds a structured value, or more than one
	 *                           value — raised before any write — or when a write
	 *                           itself fails.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restoreFields( array $restoreState ): string {
		$post_id = (int) ( $restoreState['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a content item, so it cannot be restored.',
				'Recover through WordPress revisions instead.'
			);
		}

		// HOISTED ABOVE EVERY WRITE IN THIS METHOD, and read-only so it can be.
		// The refusal it raises says the restore stopped without changing anything,
		// and that sentence has to be true: run from inside the custom-field loop
		// below, wp_update_post() and the featured-media write would already have
		// landed, leaving the item holding snapshot columns beside live meta and
		// telling the operator no recovery was needed. A refusal that claims to
		// have changed nothing while having changed something is worse than the
		// partial write it reports, and captureSnapshot() records columns and meta
		// together, so that combination is the ordinary shape rather than an edge
		// case.
		$custom_fields = [];
		foreach ( self::RESTORABLE_CUSTOM_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_array( $restoreState[ $field ] ) ) {
				$custom_fields = array_merge(
					$custom_fields,
					$this->writable_custom_fields( $post_id, $restoreState[ $field ] )
				);
			}
		}

		$update = [ 'ID' => $post_id ];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) ) {
				$update[ $field ] = (string) $restoreState[ $field ];
			}
		}

		// The same wp_update_post() call, and deliberately so: a position is a
		// post column. is_numeric() for the reason the featured-media loop gives
		// — a recorded 0 means "put this item back at the front of the hand
		// ordering", so a null present under the key must be skipped rather than
		// cast to that instruction.
		foreach ( self::RESTORABLE_ORDER_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] ) ) {
				$update[ $field ] = (int) $restoreState[ $field ];
			}
		}

		// What has actually landed by the time a later step refuses. Every write
		// mechanism below except the first can fail after earlier ones succeeded —
		// the featured-media write after the columns, the custom-field
		// verification after both, the term writes after all three — so each of
		// those refusals carries this list; a bare ExecutionFailed from any of
		// them would tell the operator nothing about which restores to expect.
		// Accumulated as each step succeeds rather than declared up front, so it
		// can never claim a step that was skipped: a meta-only snapshot names no
		// column write, because it issued none.
		$completed = [];

		// Only 'ID' means the recorded state held no post column at all, which is
		// what a featured-media snapshot looks like. Calling wp_update_post() with
		// an ID alone is not a no-op: WordPress re-saves the row, bumping
		// post_modified and firing save_post for a rollback that changed no
		// column. So the column write is skipped rather than issued empty.
		if ( count( $update ) > 1 ) {
			$restored = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $restored ) || 0 === (int) $restored ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded snapshot.',
					'Recover through WordPress revisions instead.'
				);
			}

			$completed[] = 'content columns restored';
		}

		// is_numeric() is not defensive padding: `(int) null` is 0, and 0 is the
		// recorded value that MEANS "restore to no featured image". So a key
		// present with a null value would delete a live featured image and report
		// the rollback verified. Structurally the same as an absent post_status
		// defaulting to '' and resolving to 'draft' — a value nothing observed
		// becoming a destructive instruction. Skipped rather than guessed.
		// The completed entry is appended AFTER the loop rather than inside it, so
		// a second member joining RESTORABLE_MEDIA_FIELDS could not duplicate the
		// step; the flag records that at least one media write landed.
		$media_restored = false;
		foreach ( self::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] ) ) {
				$this->restore_featured_media( $post_id, (int) $restoreState[ $field ], $completed );
				$media_restored = true;
			}
		}

		if ( $media_restored ) {
			$completed[] = 'featured image restored';
		}

		// Already validated and flattened by the hoisted pass above, so this only
		// writes. An empty map issues no call — and records no step, for the same
		// reason the column write only records itself when it was issued.
		$this->restore_custom_fields( $post_id, $custom_fields, $completed );

		if ( [] !== $custom_fields ) {
			$completed[] = 'custom fields restored';
		}

		// is_array() as well as array_key_exists(), for the reason the media loop
		// above uses is_numeric(): a recorded key holding something of the wrong
		// shape is not a value this restore may act on, and casting it would
		// manufacture an instruction the snapshot never gave. A stored snapshot
		// predating these lists holds neither key at all, which is the ordinary
		// case and is why presence is checked rather than assumed.
		foreach ( self::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_array( $restoreState[ $field ] ) ) {
				$this->restore_terms( $post_id, $restoreState[ $field ], $completed );
			}
		}

		// is_string() for the reason the media loop uses is_numeric(): a recorded
		// '' means "this item had no template of its own", which is a deletion
		// and not a skip, so a key present holding null must not be cast into
		// that instruction by accident.
		$template_restored = false;
		foreach ( self::RESTORABLE_TEMPLATE_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_string( $restoreState[ $field ] ) ) {
				$this->restore_template( $post_id, $restoreState[ $field ], $completed );
				$template_restored = true;
			}
		}

		if ( $template_restored ) {
			$completed[] = 'page template restored';
		}

		clean_post_cache( $post_id );

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Restores the recorded page template, verifying by measurement.
	 *
	 * The return value of update_post_meta() is NOT usable as a success signal,
	 * for the reason the featured-media restore gives: it answers false when the
	 * stored value already equals the requested one, which means the recorded
	 * state already holds. So the stored value is re-read and compared instead.
	 *
	 * A recorded '' means the item rendered through the theme's ordinary
	 * template, and restoring that is a deletion of the key rather than a write
	 * of an empty string — a stored '' and an absent key read back the same
	 * through get_post_meta(), but only the deletion leaves the row as the
	 * snapshot found it.
	 *
	 * @param int      $post_id   The post identifier.
	 * @param string   $template  The recorded template, or '' for none.
	 * @param string[] $completed The restore steps that have already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           template does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_template( int $post_id, string $template, array $completed ): void {
		if ( '' === $template ) {
			delete_post_meta( $post_id, ContentFields::TEMPLATE_META_KEY );
		} else {
			update_post_meta( $post_id, ContentFields::TEMPLATE_META_KEY, $template );
		}

		// Read back exactly as the field map does: anything that is not a string
		// is not a template, and treating it as one would report a match that the
		// site does not have.
		$stored = get_post_meta( $post_id, ContentFields::TEMPLATE_META_KEY, true );
		if ( ! is_string( $stored ) || $stored !== $template ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the recorded page template.',
				'Set the template from the WordPress editor instead.',
				$completed
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Restores one recorded featured-media id, verifying by measurement.
	 *
	 * The return values of set_post_thumbnail() and delete_post_thumbnail() are
	 * NOT usable as a success signal. set_post_thumbnail() forwards
	 * update_post_meta(), which returns false when the stored value is already
	 * the requested one, and delete_post_thumbnail() returns false when there
	 * was no thumbnail to delete. Both cases mean the recorded state already
	 * holds — the opposite of a failure. So the stored id is re-read and
	 * compared instead, which is unambiguous.
	 *
	 * A recorded 0 means the post had no featured image, and restoring that is
	 * a deletion, not a skip. get_post_thumbnail_id() answers false when there
	 * is none, which casts to the same 0.
	 *
	 * The refusal carries the steps that already landed, which the caller
	 * accumulates and passes in: this write runs after the column write, so its
	 * failure is reachable with the title and status already back, and an empty
	 * list here would tell the operator nothing was restored — which is false
	 * for the ordinary column-and-media snapshot. The media step itself appears
	 * in no list, because it is the step that failed.
	 *
	 * @param int      $post_id   The post identifier.
	 * @param int      $media_id  The recorded attachment identifier, or 0 for none.
	 * @param string[] $completed The restore steps that have already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           thumbnail does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_featured_media( int $post_id, int $media_id, array $completed ): void {
		if ( 0 === $media_id ) {
			delete_post_thumbnail( $post_id );
		} else {
			set_post_thumbnail( $post_id, $media_id );
		}

		if ( (int) get_post_thumbnail_id( $post_id ) !== $media_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded featured image.',
				'Recover through WordPress revisions instead.',
				$completed
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Restores a recorded map of permitted custom fields, verifying by
	 * measurement.
	 *
	 * The return value of update_post_meta() is NOT a success signal, and core
	 * says so itself: update_metadata()'s docblock reads "false on failure or if the value
	 * passed to the function is the same as the one that is already in the
	 * database". The second half is the ordinary case for a restore — most keys in
	 * a recorded map were never changed by the write being reversed, so most calls
	 * return false while having done exactly what was asked. Judging by the
	 * boolean would fail every rollback that restored an unchanged key. The stored
	 * value is re-read instead, which is unambiguous, and is the same
	 * verify-by-measurement restore_featured_media() uses for the same reason.
	 *
	 * The value is slashed on the way in. update_metadata() calls
	 * wp_unslash( $meta_value ) before storing, exactly as wp_update_post() does
	 * for post columns, so an unslashed value containing a backslash or a quote
	 * loses characters and then fails the comparison below — correctly, but for a
	 * reason the caller could not act on.
	 *
	 * A recorded empty string is written rather than deleted. ContentFields::meta()
	 * projects an absent key and a key stored as '' identically, so the recorded
	 * state cannot distinguish them; writing '' reads back as '' and satisfies the
	 * promise, while deleting would too. Writing is chosen because it is the
	 * smaller claim: it never removes a row the snapshot did not prove was absent.
	 *
	 * The live value having been checked first is what makes this safe, and that
	 * check is NOT here — it is hoisted into restoreFields(), above every write in
	 * the method. See writable_custom_fields() for why.
	 *
	 * The refusal carries the steps that ALREADY LANDED, which the caller
	 * accumulates and passes in — as every restore write that runs after another
	 * one does: restore_featured_media() can fail after the column write, and
	 * restore_terms() after columns, media and meta have all landed. This
	 * method's own pre-write guard is hoisted above everything, but its
	 * verification cannot be, so a bare ExecutionFailed here would tell an
	 * operator that a rollback failed without saying that the columns and the
	 * featured image are already back. The step is 'partially restored' rather
	 * than nothing, because the throw fires mid-loop: an earlier key may already
	 * have been written, and the failing key's own row no longer holds anything
	 * the snapshot describes. The steps name mechanisms, never keys or values.
	 *
	 * @param int                   $post_id   The post identifier.
	 * @param array<string, string> $values    The validated key-to-value map.
	 * @param string[]              $completed The restore steps that have already
	 *                                         succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a stored
	 *                           value does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_custom_fields( int $post_id, array $values, array $completed ): void {
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );

			// Read as a LIST, and require EXACTLY ONE row, for the reason
			// writable_custom_fields() reads one: get_metadata_raw() with
			// $single = true answers `$meta_cache[ $meta_key ][0]` and nothing
			// else, so a single read cannot tell one row from five. Exactly one is
			// the only correct count after this write — update_metadata() rewrites
			// every existing row to the new value, and calls add_metadata() when
			// there were none, so anything other than one means rows appeared
			// between the pre-write guard and here. save_post fires from the
			// wp_update_post() call above and any plugin may add meta on it, which
			// is precisely the window a row-0 comparison cannot see: three rows all
			// holding the new value match on row 0 and would be reported verified.
			$rows = get_post_meta( $post_id, $key, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore a recorded custom field value.',
					'Recover through WordPress revisions instead.',
					array_merge( $completed, [ 'custom fields partially restored' ] )
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The recorded custom fields this restore may write, or a refusal.
	 *
	 * READ-ONLY, which is the entire reason it can be a separate pass. It reads
	 * each live value and refuses a non-scalar one BEFORE any write in
	 * restoreFields() has happened.
	 *
	 * The projection collapse is why the live value is what gets checked, and it
	 * has TWO shapes, both of which this one read answers:
	 *
	 * - A STRUCTURED live value. ContentFields::meta() reports a stored array or
	 *   object as '', so a snapshot records '' for a serialized payload another
	 *   plugin owns under an allowlisted key. Writing that recorded '' would
	 *   replace the payload with an empty string, re-read '', match, and report the
	 *   rollback VERIFIED — data loss reported as success.
	 * - MORE THAN ONE ROW under the key. get_metadata_raw() with $single = true
	 *   returns `maybe_unserialize( $meta_cache[ $meta_key ][0] )` — row 0 alone —
	 *   so three rows are projected, recorded and re-read as one. And the write is
	 *   not confined to row 0: update_metadata() builds
	 *   `$where = array( $column => $object_id, 'meta_key' => $meta_key )` and adds
	 *   `$where['meta_value']` only when a $prev_value was passed, which
	 *   restore_custom_fields() does not pass, so one $wpdb->update() flattens every
	 *   row to the recorded value. Identical failure to the structured case,
	 *   identical undetectability. Core itself declines to reason about this shape:
	 *   its own unchanged-value shortcut is guarded by
	 *   `is_countable( $old_value ) && count( $old_value ) === 1`, under the comment
	 *   "Compare existing value to new value if no prev value given and the key
	 *   exists only once."
	 *
	 * Read from wp-includes/meta.php of the WordPress 6.8.1 install on this
	 * machine, and diffed against the 7.0.2 install beside it: update_metadata(),
	 * get_metadata_raw() and get_metadata_default() differ between the two only in
	 * docblock lines naming the `blog` meta type, so the executable code this
	 * reasoning depends on is identical in both.
	 *
	 * The RECORDED value cannot reveal either shape: a recorded '' is
	 * indistinguishable from a legitimately empty field, and a recorded scalar is
	 * indistinguishable from row 0 of five. Only the live one carries the evidence.
	 *
	 * ZERO rows is not refused and must not be: an allowlisted key holding nothing
	 * is ordinary. get_post_meta( …, false ) answers array() for an absent key —
	 * get_metadata_default() sets `$value = array()` when $single is false — so the
	 * count is 0 and `$rows[0] ?? ''` supplies the scalar for a list with no member.
	 *
	 * The is_array() test is not decoration. get_metadata_raw() returns the
	 * `get_post_metadata` filter's value UNTOUCHED when $single is false, so a
	 * plugin short-circuiting that filter can hand back anything at all, and
	 * count() on a non-array is a TypeError rather than a refusal.
	 *
	 * KNOWN LIMITATION, accepted deliberately. While any permitted key on a post
	 * holds structured data or more than one row, EVERY rollback of that post is
	 * refused — including one
	 * that only restores the title and never touches meta. That is a hard block
	 * rather than a degraded restore, because captureSnapshot() records the whole
	 * meta map alongside the columns, so the unsafe key is present in the recorded
	 * state of every snapshot taken for that post. Refusing is the conservative
	 * direction — nothing is destroyed, and the remediation names the fix — but the
	 * blast radius is the post rather than the key, and a narrower version would
	 * have to drop the unsafe key from the promise rather than refuse the plan.
	 *
	 * A SECOND, unrelated limitation lives in the skip condition below, and it is
	 * recorded here because this is where a reader looks for what this method does
	 * not restore. `! is_string( $key )` skips a key PHP has coerced to an integer,
	 * and PHP coerces every integer-like array key — so an allowlisted key of
	 * '2024', which ContentFields::allowlist()'s /^[A-Za-z0-9_-]+$/ permits and
	 * content-meta-update can therefore write, arrives in the recorded map as the
	 * int 2024 and is silently not written back. It is not silent to the CLIENT:
	 * ContentRollbackApply promises the complete overlaid map including that key, so
	 * the read-back disagrees with the promise and WriteVerifier reports it — a
	 * mixed map comes back as an adjustment naming `meta`, and a numeric key on its
	 * own comes back as not applied. Deliberately left rather than fixed, because
	 * the skip is a decision made elsewhere and pinned by
	 * test_malformed_inner_entries_are_skipped_rather_than_written.
	 *
	 * The refusal names no key and no value. A key is administrator-configured and
	 * a value is site content; neither belongs in an error envelope, and nothing
	 * records the key anywhere, so the correlation id routes an operator to the
	 * ITEM rather than to the key.
	 *
	 * @param int                  $post_id The post identifier.
	 * @param array<string, mixed> $values  One recorded key-to-value map.
	 *
	 * @return array<string, string> The keys safe to write, with string values.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a live value
	 *                           is not scalar.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function writable_custom_fields( int $post_id, array $values ): array {
		$writable = [];
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$rows = get_post_meta( $post_id, $key, false );

			if ( ! is_array( $rows ) || count( $rows ) > 1 || ! is_scalar( $rows[0] ?? '' ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'A permitted custom field on this content item holds a structured value, or more than one value, so this restore stopped without changing anything.',
					'Ask a site administrator to review which custom field keys are permitted, then retry.'
				);
			}

			$writable[ $key ] = (string) $value;
		}

		return $writable;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Restores a recorded map of taxonomy term assignments, verifying by
	 * measurement.
	 *
	 * The return value of wp_set_object_terms() cannot be judged either, in two
	 * separate ways. It returns TERM TAXONOMY IDs, which are a different id space
	 * from the term ids that were passed in and that ContentFields::read()
	 * projects — they coincide on a default install and diverge on any site whose
	 * terms were ever shared across taxonomies. And it SILENTLY DROPS an integer
	 * term id that does not resolve in the named taxonomy; core's own comment
	 * reads "// Skip if a non-existent term ID is passed." followed by
	 * `if ( is_int( $term ) ) { continue; }`. A restore that trusted the return
	 * would report success for a set it did not write.
	 *
	 * So the assignment is re-read through wp_get_object_terms() with
	 * `fields => ids`, which is the same call ContentFields::read() makes, and
	 * compared as a sorted set. Deduplication happens before the write rather than
	 * after: a recorded list holding the same id twice would otherwise never match
	 * a stored set that holds it once.
	 *
	 * An empty recorded list is an instruction, not a skip — it means the post had
	 * no terms in that taxonomy, and restoring that is a removal.
	 * wp_set_object_terms() with an empty array is core's own way to say so.
	 *
	 * Both refusals carry the steps that already landed. This loop runs LAST,
	 * after the columns, the featured image and the custom fields have all been
	 * written, so a bare ExecutionFailed here would hide three completed
	 * restores from the operator.
	 *
	 * The 'taxonomy terms partially restored' marker is EARNED BY A WRITE, not
	 * by entering the loop. A verification failure always carries it — the
	 * write executed and stored something other than the recorded set — and so
	 * does a refusal after an earlier taxonomy in the same map was written. But
	 * a wp_set_object_terms() that answered WP_Error is counted as "did not
	 * write", which core guarantees for an invalid taxonomy — it refuses before
	 * touching the relationship table — so a refused FIRST write reports the
	 * incoming steps unchanged rather than a term step that never happened. A
	 * step list claiming more than happened is the same defect, one arm over,
	 * as the empty list this parameter was added to fix. The boundary is
	 * accepted as an under-claim, and it is wider than one return: read from
	 * wp-includes/taxonomy.php on this machine, wp_set_object_terms() has THREE
	 * WP_Error returns that can fire after it has already written something.
	 *
	 * - `return $term_info` mid-loop, when a term lookup fails, leaving the
	 *   relationships earlier iterations inserted unnamed;
	 * - `return $remove`, when wp_remove_object_terms() fails, after every
	 *   insert for this taxonomy has run;
	 * - `db_insert_error` from the term_order re-write, after the inserts AND
	 *   the deletes.
	 *
	 * All three are counted here as "did not write", so all three under-claim.
	 * That errs toward never claiming an unproven step, the same direction as
	 * the accumulated list itself. Only the FIRST refusal in the map can
	 * under-claim at all: once any taxonomy has been accepted the flag is set
	 * and stays set.
	 *
	 * @param int                  $post_id   The post identifier.
	 * @param array<string, mixed> $map       The recorded taxonomy-to-term-ids map.
	 * @param string[]             $completed The restore steps that have already
	 *                                        succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a taxonomy
	 *                           cannot be written or does not read back.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_terms( int $post_id, array $map, array $completed ): void {
		$terms_written = false;

		foreach ( $map as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! is_array( $ids ) ) {
				continue;
			}

			$wanted = array_values( array_unique( array_map( 'intval', $ids ) ) );
			sort( $wanted, SORT_NUMERIC );

			$written = wp_set_object_terms( $post_id, $wanted, $taxonomy );
			$refused = is_wp_error( $written );

			// A non-error return means core ACCEPTED the call for this taxonomy,
			// which is NOT the same as "a row changed" and must not be read as it.
			// Core `continue`s past its $wpdb->insert when the relationship already
			// exists, and an empty $wanted against an already-empty taxonomy
			// inserts nothing and deletes nothing while still returning the (empty)
			// tt_id list. What the flag records is that a term write was ISSUED
			// here, whatever the read-back below says — which is the property the
			// marker needs: from here on a refusal cannot claim the restore stopped
			// before touching this taxonomy's relationships.
			//
			// One is_wp_error() call, not two. They read the same value and could
			// not disagree, but a second call is a second thing to keep in step.
			if ( ! $refused ) {
				$terms_written = true;
			}

			$stored = $refused
				? $written
				: wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_array( $stored ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded taxonomy terms.',
					'Recover through WordPress revisions instead.',
					$terms_written
						? array_merge( $completed, [ 'taxonomy terms partially restored' ] )
						: $completed
				);
			}

			$actual = array_values( array_unique( array_map( 'intval', $stored ) ) );
			sort( $actual, SORT_NUMERIC );

			if ( $actual !== $wanted ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different set of taxonomy terms than the recorded snapshot held.',
					'Recover through WordPress revisions instead.',
					array_merge( $completed, [ 'taxonomy terms partially restored' ] )
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
