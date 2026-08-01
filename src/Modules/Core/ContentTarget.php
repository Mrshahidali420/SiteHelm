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
	 * required for restoration. The list is `RESTORABLE_FIELDS`, so widening it
	 * widens the capture and the restore together rather than one of the two.
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
	 * Four write mechanisms are used, one per field list, because only
	 * RESTORABLE_FIELDS holds post columns: one wp_update_post() call for
	 * whichever RESTORABLE_FIELDS columns the state recorded,
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
	 *                           field holds a structured value — raised before any
	 *                           write — or when a write itself fails.
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
		}

		// is_numeric() is not defensive padding: `(int) null` is 0, and 0 is the
		// recorded value that MEANS "restore to no featured image". So a key
		// present with a null value would delete a live featured image and report
		// the rollback verified. Structurally the same as an absent post_status
		// defaulting to '' and resolving to 'draft' — a value nothing observed
		// becoming a destructive instruction. Skipped rather than guessed.
		foreach ( self::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] ) ) {
				$this->restore_featured_media( $post_id, (int) $restoreState[ $field ] );
			}
		}

		// Already validated and flattened by the hoisted pass above, so this only
		// writes. An empty map issues no call.
		$this->restore_custom_fields( $post_id, $custom_fields );

		// is_array() as well as array_key_exists(), for the reason the media loop
		// above uses is_numeric(): a recorded key holding something of the wrong
		// shape is not a value this restore may act on, and casting it would
		// manufacture an instruction the snapshot never gave. A stored snapshot
		// predating these lists holds neither key at all, which is the ordinary
		// case and is why presence is checked rather than assumed.
		foreach ( self::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_array( $restoreState[ $field ] ) ) {
				$this->restore_terms( $post_id, $restoreState[ $field ] );
			}
		}

		clean_post_cache( $post_id );

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
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
	 * @param int $post_id  The post identifier.
	 * @param int $media_id The recorded attachment identifier, or 0 for none.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           thumbnail does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_featured_media( int $post_id, int $media_id ): void {
		if ( 0 === $media_id ) {
			delete_post_thumbnail( $post_id );
		} else {
			set_post_thumbnail( $post_id, $media_id );
		}

		if ( (int) get_post_thumbnail_id( $post_id ) !== $media_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded featured image.',
				'Recover through WordPress revisions instead.'
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
	 * @param int                   $post_id The post identifier.
	 * @param array<string, string> $values  The validated key-to-value map.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a stored
	 *                           value does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_custom_fields( int $post_id, array $values ): void {
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );

			$stored = get_post_meta( $post_id, $key, true );
			if ( ! is_scalar( $stored ) || (string) $stored !== $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore a recorded custom field value.',
					'Recover through WordPress revisions instead.'
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
	 * The projection collapse is why the live value is what gets checked:
	 * ContentFields::meta() reports a stored array or object as '', so a snapshot
	 * records '' for a serialized payload another plugin owns under an allowlisted
	 * key. Writing that recorded '' would replace the payload with an empty string,
	 * re-read '', match, and report the rollback VERIFIED — data loss reported as
	 * success. The RECORDED value cannot reveal it: a recorded '' is
	 * indistinguishable from a legitimately empty field. Only the live one carries
	 * the evidence.
	 *
	 * KNOWN LIMITATION, accepted deliberately. While any permitted key on a post
	 * holds structured data, EVERY rollback of that post is refused — including one
	 * that only restores the title and never touches meta. That is a hard block
	 * rather than a degraded restore, because captureSnapshot() records the whole
	 * meta map alongside the columns, so the unsafe key is present in the recorded
	 * state of every snapshot taken for that post. Refusing is the conservative
	 * direction — nothing is destroyed, and the remediation names the fix — but the
	 * blast radius is the post rather than the key, and a narrower version would
	 * have to drop the unsafe key from the promise rather than refuse the plan.
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

			if ( ! is_scalar( get_post_meta( $post_id, $key, true ) ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'A permitted custom field on this content item holds a structured value, so this restore stopped without changing anything.',
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
	 * @param int                  $post_id The post identifier.
	 * @param array<string, mixed> $map     The recorded taxonomy-to-term-ids map.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a taxonomy
	 *                           cannot be written or does not read back.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_terms( int $post_id, array $map ): void {
		foreach ( $map as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! is_array( $ids ) ) {
				continue;
			}

			$wanted = array_values( array_unique( array_map( 'intval', $ids ) ) );
			sort( $wanted, SORT_NUMERIC );

			$written = wp_set_object_terms( $post_id, $wanted, $taxonomy );
			$stored  = is_wp_error( $written )
				? $written
				: wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_array( $stored ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded taxonomy terms.',
					'Recover through WordPress revisions instead.'
				);
			}

			$actual = array_values( array_unique( array_map( 'intval', $stored ) ) );
			sort( $actual, SORT_NUMERIC );

			if ( $actual !== $wanted ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different set of taxonomy terms than the recorded snapshot held.',
					'Recover through WordPress revisions instead.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
