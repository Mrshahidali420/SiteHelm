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
 * Extracted so create, update, and rollback share one implementation rather
 * than three that could drift apart.
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
	 * Only the three fields the content writes can change are captured, per the
	 * design's requirement that a snapshot store the minimum state required for
	 * restoration.
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
			'post_id'      => $this->fields->postIdFromTargetKey( $current->targetKey ),
			'post_title'   => (string) ( $current->fields['post_title'] ?? '' ),
			'post_content' => (string) ( $current->fields['post_content'] ?? '' ),
			'post_excerpt' => (string) ( $current->fields['post_excerpt'] ?? '' ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Writes a recorded restore state back to its content item.
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

		$restored = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_title'   => (string) ( $restoreState['post_title'] ?? '' ),
					'post_content' => (string) ( $restoreState['post_content'] ?? '' ),
					'post_excerpt' => (string) ( $restoreState['post_excerpt'] ?? '' ),
				]
			),
			true
		);

		if ( is_wp_error( $restored ) || 0 === (int) $restored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded snapshot.',
				'Recover through WordPress revisions instead.'
			);
		}

		clean_post_cache( $post_id );

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
