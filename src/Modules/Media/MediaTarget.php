<?php
/**
 * Shared target resolution for the media write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The four things every media write does identically: resolve the target,
 * name the pending target, re-read it for verification, and write a recorded
 * restore state back.
 *
 * Extracted for the reason ContentTarget was: metadata update, attachment, and
 * upload would otherwise each carry their own copy, and the copy that drifts is
 * the one deciding which values a rollback silently fails to restore.
 *
 * @package SiteHelm
 */
final class MediaTarget {

	/**
	 * The attachment columns a media snapshot records as TEXT, and the half of a
	 * restore that wp_update_post() writes with a string cast.
	 *
	 * Public because a future media write needs the same list to promise what a
	 * restore will put back, and a second copy of it would drift.
	 *
	 * THREE ENTRIES, AND DELIBERATELY NOT FIVE. `post_status` and `post_name` are
	 * on ContentTarget::RESTORABLE_FIELDS because a content write moves content
	 * between statuses and WordPress renames a trashed slug. No media write in
	 * this phase touches either, so recording them would make every media
	 * rollback promise to rewrite the status and the slug of an attachment nobody
	 * changed — and `post_status` in particular is the exact column that nearly
	 * shipped a live-post unpublish in the core block, because wp_update_post()
	 * resolves an empty status to 'draft'. Adding either here re-opens that.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_TEXT_FIELDS = [ 'title', 'caption', 'description' ];

	/**
	 * The attachment column a media snapshot records as an INTEGER.
	 *
	 * A separate list from RESTORABLE_TEXT_FIELDS even though both ride the same
	 * wp_update_post() call, because the CAST and the PRESENCE GATE differ, and
	 * those are the two things that decide whether a restore is correct.
	 * `(string) null` is '' — harmless for a caption — while `(int) null` is 0,
	 * and 0 is the recorded value that MEANS "restore to detached". A key present
	 * with a null value would therefore detach a live attachment and report the
	 * rollback verified. So this list is gated on is_numeric() as well as on
	 * array_key_exists(), exactly as ContentTarget gates its featured-media list.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_PARENT_FIELDS = [ 'parent' ];

	/**
	 * The restorable media value that is NOT a post column, and therefore cannot
	 * be written by wp_update_post().
	 *
	 * `alt` is stored as the `_wp_attachment_image_alt` post meta, so a restore
	 * has to go through update_post_meta(). A third list rather than a fourth
	 * entry above for the reason ContentTarget keeps RESTORABLE_MEDIA_FIELDS
	 * separate: every loop over a column list hands its value to
	 * wp_update_post(), so a meta key added there would be recorded, promised,
	 * silently ignored on the way in, and then reported as restored.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_META_FIELDS = [ 'alt' ];

	/**
	 * The projection key to post column map for the text fields.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_TEXT_FIELD = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	/**
	 * The projection key to post column map for the integer fields.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_PARENT_FIELD = [ 'parent' => 'post_parent' ];

	/**
	 * Constructs the resolver.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Resolves one existing attachment.
	 *
	 * ONE MESSAGE covers all three refusals — the id names nothing, the id names
	 * a post that is not an attachment, and the caller cannot edit_post it — for
	 * the reason ContentFeaturedMediaSet gives one message for two: distinguishing
	 * them turns the operation into a probe for which post ids exist on the site.
	 * MediaFields::read() answers null for the first two, so only the capability
	 * needs testing here.
	 *
	 * @param int              $attachmentId The attachment identifier.
	 * @param OperationContext $context      The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolve( int $attachmentId, OperationContext $context ): TargetState {
		$fields = $this->fields->read( $attachmentId );

		if ( null === $fields || ! user_can( $context->userId, 'edit_post', $attachmentId ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The requested media item does not exist or is not visible to your WordPress user.',
				'Confirm the media identifier and that your WordPress user may edit that item.'
			);
		}

		return new TargetState( $this->fields->targetKey( $attachmentId ), true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The state of an attachment that does not exist yet.
	 *
	 * @return TargetState The pending state.
	 */
	public function pending(): TargetState {
		return new TargetState( $this->fields->pendingTargetKey(), false, [] );
	}

	/**
	 * Re-reads a target after a write so the engine can verify it.
	 *
	 * The post cache is invalidated first, which is both correct for
	 * verification and the module's declared cache-cleanup obligation.
	 *
	 * @param string $targetKey     The concrete target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$attachment_id = $this->fields->attachmentIdFromKey( $targetKey );
		$fields        = null;

		if ( null !== $attachment_id ) {
			clean_post_cache( $attachment_id );
			$fields = $this->fields->read( $attachment_id );
		}

		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The media item could not be re-read after the write, so the result cannot be verified.',
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
	 * Writes a recorded restore state back to its attachment.
	 *
	 * ONLY THE FIELDS THE RECORDED STATE ACTUALLY CONTAINS ARE WRITTEN, and the
	 * gate is array_key_exists(), never `??`. The difference is the whole point
	 * of this method: a recorded '' means "set it back to empty" and an absent key
	 * means "do not touch", and `?? ''` collapses those two into one. That
	 * collapse is what nearly shipped in the core block, where an absent
	 * post_status became '' and wp_update_post() resolved '' to 'draft',
	 * unpublishing a live post while reporting the rollback verified.
	 *
	 * Two write mechanisms, because only the first two lists hold post columns:
	 * one wp_update_post() call for whichever columns the state recorded, and one
	 * update_post_meta() call for a recorded alt. A state holding no column at all
	 * issues no wp_update_post() call, because calling it with an ID alone is not
	 * a no-op — WordPress re-saves the row, bumping post_modified and firing
	 * save_post for a rollback that changed nothing.
	 *
	 * EVERY RESTORED VALUE IS RE-READ, which is the one place this method is
	 * stricter than the write operations that call it. A write's promised fields
	 * are compared by WriteVerifier after applyChange() returns; a restore has no
	 * such downstream reader, so if this method does not measure what it stored,
	 * nothing does.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no target, or
	 *                           ErrorCode::ExecutionFailed when a write fails or
	 *                           does not read back.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function restoreFields( array $restoreState, OperationContext $context ): string {
		$attachment_id = (int) ( $restoreState['post_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a media item, so it cannot be restored.',
				'Restore the media item\'s details in the WordPress media library instead.'
			);
		}

		$update = [ 'ID' => $attachment_id ];

		foreach ( self::COLUMN_FOR_TEXT_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $restoreState ) && is_scalar( $restoreState[ $field ] ) ) {
				$update[ $column ] = (string) $restoreState[ $field ];
			}
		}

		foreach ( self::COLUMN_FOR_PARENT_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] ) ) {
				$update[ $column ] = (int) $restoreState[ $field ];
			}
		}

		// Accumulated as each step succeeds rather than declared up front, so a
		// later refusal can never claim a step that was skipped: an alt-only
		// snapshot names no column write, because it issued none.
		$completed = [];

		if ( count( $update ) > 1 ) {
			$written = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded media metadata.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}

			$completed[] = 'media columns restored';
		}

		$this->restore_alternative_text( $attachment_id, $restoreState, $completed );

		clean_post_cache( $attachment_id );
		$this->assert_restored( $attachment_id, $restoreState, $completed );

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Writes a recorded alternative text back, judging it by measurement.
	 *
	 * The return value of update_post_meta() is NOT a success signal, and core's
	 * own docblock for update_metadata() says why: it returns false "if the value
	 * passed to the function is the same as the one that is already in the
	 * database". Most keys in a recorded state were never changed by the write
	 * being reversed, so judging the boolean would fail every rollback that
	 * restored an unchanged value.
	 *
	 * The re-read asks for the LIST and requires EXACTLY ONE row rather than a
	 * matching row 0. get_metadata_raw() with `$single = true` answers row 0 and
	 * nothing else, so a single read cannot tell one row from five — and the
	 * write is not confined to row 0 either, because update_metadata() builds its
	 * WHERE from object id and meta key alone unless a $prev_value was passed,
	 * which this does not pass. One $wpdb->update() then flattens every row to
	 * the recorded value, and a row-0 comparison would see what it just wrote and
	 * pass. Rows destroyed, reported restored.
	 *
	 * Exactly one is the only correct count after this write, and it refuses no
	 * legitimate case: update_metadata() falls through to add_metadata() only when
	 * there are no rows, so a key holding zero or one row must hold exactly one
	 * afterwards.
	 *
	 * The value is slashed on the way in, because update_metadata() calls
	 * wp_unslash() before storing, so an unslashed value holding a backslash or a
	 * quote is stored short of a character and then fails the comparison below.
	 *
	 * @param int                  $attachment_id The attachment identifier.
	 * @param array<string, mixed> $restoreState  The recorded restore state.
	 * @param string[]             $completed     The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_alternative_text( int $attachment_id, array $restoreState, array $completed ): void {
		foreach ( self::RESTORABLE_META_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $restoreState ) || ! is_scalar( $restoreState[ $field ] ) ) {
				continue;
			}

			$value = (string) $restoreState[ $field ];
			update_post_meta( $attachment_id, MediaFields::ALT_META_KEY, wp_slash( $value ) );

			$rows = get_post_meta( $attachment_id, MediaFields::ALT_META_KEY, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress did not store the recorded alternative text as the only value under its key.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the attachment and refuses unless every recorded value landed.
	 *
	 * The alt row was already measured by restore_alternative_text(), so this
	 * pass covers the columns — the values whose write reported only an id.
	 * wp_update_post() returning the attachment id proves the row was saved, not
	 * that post_excerpt holds what was sent: a wp_insert_post_data filter can
	 * rewrite it, and on a restore path there is no WriteVerifier downstream to
	 * notice.
	 *
	 * The comparison is by string for the text fields and by integer for the
	 * parent, matching the casts the write used, so a restored 0 does not fail
	 * against a projected int 0.
	 *
	 * @param int                  $attachment_id The attachment identifier.
	 * @param array<string, mixed> $restoreState  The recorded restore state.
	 * @param string[]             $completed     The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_restored( int $attachment_id, array $restoreState, array $completed ): void {
		$stored = $this->fields->read( $attachment_id );

		if ( null === $stored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The media item could not be re-read after the restore, so the restore cannot be confirmed.',
				'Restore the media item\'s details in the WordPress media library instead.',
				$completed
			);
		}

		foreach ( self::RESTORABLE_TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_scalar( $restoreState[ $field ] )
				&& (string) ( $stored[ $field ] ?? '' ) !== (string) $restoreState[ $field ] ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different value than the recorded snapshot held.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}

		foreach ( self::RESTORABLE_PARENT_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] )
				&& (int) ( $stored[ $field ] ?? -1 ) !== (int) $restoreState[ $field ] ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different value than the recorded snapshot held.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
