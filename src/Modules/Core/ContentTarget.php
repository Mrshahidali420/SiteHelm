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
	 * Two write mechanisms are used, because RESTORABLE_MEDIA_FIELDS holds post
	 * meta rather than post columns: one wp_update_post() call for whichever
	 * RESTORABLE_FIELDS columns the state recorded, and set_post_thumbnail() /
	 * delete_post_thumbnail() for a recorded featured-media id. A state holding
	 * no column at all issues no wp_update_post() call.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no target, or
	 *                           ErrorCode::ExecutionFailed when the write fails.
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
}
