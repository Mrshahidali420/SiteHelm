<?php
/**
 * Shared target resolution for the comment write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The three things both comment writes do identically: resolve the target,
 * re-read it for verification, and put a recorded status back.
 *
 * Extracted for the reason ContentTarget was — one implementation rather than
 * two that can drift — and kept separate from it because a comment is a
 * different row in a different table with a different cache group, and the one
 * thing the two resolvers must never share is the target key.
 *
 * @package SiteHelm
 */
final class CommentTarget {

	/**
	 * Resolves one existing comment.
	 *
	 * @param int $comment_id The comment identifier.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when absent.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolve( int $comment_id ): TargetState {
		$comment = $comment_id > 0 ? get_comment( $comment_id ) : null;

		if ( null === $comment ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No comment on this site matches the requested identifier.',
				'Call comment-list to see the comments this site holds, and confirm the identifier you named.'
			);
		}

		return new TargetState(
			CommentFields::targetKey( $comment_id ),
			true,
			CommentFields::project( $comment )
		);
	}

	/**
	 * Re-reads a comment after a write so the engine can verify it.
	 *
	 * The comment cache is cleared first, and it has to be: `wp_set_comment_status()`
	 * updates the row through $wpdb and then primes the cache itself, but the
	 * parent post's comment count and the projected post title are read through
	 * caches of their own. Verifying against a stale read would report a correct
	 * write as unapplied, which is the failure mode most likely to send an
	 * operator looking for a bug in the site rather than in the read.
	 *
	 * @param string $targetKey     The written target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                           comment cannot be re-read at all.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$comment_id = CommentFields::commentIdFromKey( $targetKey );

		if ( null !== $comment_id ) {
			clean_comment_cache( $comment_id );
		}

		$comment = null === $comment_id ? null : get_comment( $comment_id );

		if ( null === $comment ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The comment could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, CommentFields::project( $comment ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The minimum local state required to reverse a comment status change.
	 *
	 * Two members: which comment, and what its status was. Nothing else a status
	 * change touches is lost by it — the author, the body and the date are
	 * untouched columns, and recording them would be state the restore could not
	 * meaningfully put back anyway.
	 *
	 * The status recorded is the REPORTED one rather than the stored column
	 * value, so a snapshot is readable and a restore goes back through the same
	 * vocabulary the write came in through.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the key
	 *                                   cannot be parsed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function snapshotOf( TargetState $current ): ?array {
		$comment_id = CommentFields::commentIdFromKey( $current->targetKey );

		if ( null === $comment_id ) {
			return null;
		}

		return [
			'comment_id' => $comment_id,
			'status'     => (string) ( $current->fields['status'] ?? CommentFields::STATUS_PENDING ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes a recorded status back.
	 *
	 * A snapshot recording `post-trashed` is refused rather than replayed. That
	 * status belongs to the parent post's lifecycle — WordPress sets it on every
	 * comment when a post is trashed and restores the prior status when the post
	 * returns — so writing it directly would leave the comment holding a value
	 * the post's own untrash then overwrites. The remedy is to restore the post,
	 * and the refusal says so.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot is unusable, or ErrorCode::ExecutionFailed
	 *                           when WordPress refuses the write.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restoreStatus( array $restoreState ): string {
		$comment_id = (int) ( $restoreState['comment_id'] ?? 0 );
		$status     = (string) ( $restoreState['status'] ?? '' );

		if ( $comment_id < 1 || '' === $status ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not identify a comment and a status to restore.',
				'Set the comment back to its previous status in the WordPress comments screen.'
			);
		}

		if ( ! array_key_exists( $status, CommentFields::SET_ARGUMENT_BY_STATUS ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded status is one WordPress assigns from the parent post rather than one this plugin can set.',
				'Restore the parent post from the trash; WordPress returns its comments to the status they held.'
			);
		}

		$restored = wp_set_comment_status(
			$comment_id,
			CommentFields::SET_ARGUMENT_BY_STATUS[ $status ],
			true
		);

		if ( is_wp_error( $restored ) || false === $restored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the comment to its recorded status.',
				'Set the comment back to that status in the WordPress comments screen.'
			);
		}

		return CommentFields::targetKey( $comment_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
