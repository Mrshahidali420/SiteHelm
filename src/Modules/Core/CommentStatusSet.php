<?php
/**
 * REQ-0060: approve, unapprove, spam, or trash one comment.
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
 * Moves one comment between approved, pending, spam, and trash.
 *
 * ALL FOUR TRANSITIONS ARE REVERSIBLE, which is why this operation declares
 * itself not destructive even though two of its four destinations sound final.
 * Spam and trash are both statuses on a row that stays where it is; WordPress
 * empties them on its own schedule, and nothing here shortens that. Permanent
 * deletion is REQ-0056, permanently excluded, and the map this operation writes
 * through does not carry the value that would perform one.
 *
 * A COMMENT WHOSE PARENT POST IS TRASHED IS REFUSED. WordPress parks every
 * comment on a trashed post at the `post-trashed` status and restores the prior
 * status when the post comes back — so a status written here would survive only
 * until the post is untrashed, and then be silently replaced. Refusing with a
 * remedy that names the real fix is more honest than performing a write whose
 * result has an expiry date the caller was never told about.
 *
 * THE SPAM TRANSITION IS NOT JUST A STATUS. `wp_set_comment_status()` routes it
 * through `wp_spam_comment()`, which records the prior status in comment meta so
 * WordPress's own unspam restores it, and fires the hooks Akismet and its peers
 * listen on to learn from the decision. Writing the column directly would skip
 * both, so this operation goes through the same function the moderation screen
 * does, for every destination.
 *
 * @package SiteHelm
 */
final class CommentStatusSet implements WriteOperation {

	/**
	 * The one field this operation promises.
	 */
	private const PROMISED_FIELD = 'status';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for comment-status-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'comment-status-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Approve, unapprove, mark as spam, or trash one comment. Every transition is reversible; nothing is deleted.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the comment whose status is being changed.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => CommentFields::SETTABLE_STATUSES,
						'description' => 'Target status. Trash and spam are reversible; neither deletes the comment.',
					],
				],
				'required'             => [ 'id', 'status' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ CommentFields::CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'comment-status-set',
				'arguments' => [
					'id'     => 118,
					'status' => CommentFields::STATUS_APPROVED,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param CommentTarget $targets Shared comment target resolution.
	 */
	public function __construct( private readonly CommentTarget $targets ) {
	}

	/**
	 * Resolves the comment the input names.
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
	 * Builds the promised transition.
	 *
	 * The status is re-validated against SETTABLE_STATUSES even though the input
	 * schema declares the same `enum`, for the reason ContentStatusSet gives: a
	 * write validates its own payload rather than assuming a caller reached it
	 * through the one path that validated it. Here the consequence is sharper
	 * than a silent normalisation — an unrecognised status reaching
	 * `wp_set_comment_status()` is passed through to the column as-is, so a typo
	 * would park the comment at a status nothing in WordPress displays.
	 *
	 * planChange() runs in BOTH phases, so the parent-post check below is made
	 * again at apply. A post trashed between preview and apply is caught rather
	 * than written over.
	 *
	 * No ksort: the promise holds exactly one key.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a status this
	 *                           operation cannot set, or ErrorCode::Conflict when
	 *                           the parent post is in the trash.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$status = (string) ( $input['status'] ?? '' );

		if ( ! in_array( $status, CommentFields::SETTABLE_STATUSES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested status is not one this operation can set.',
				'Choose approved, pending, spam, or trash and request a fresh preview.'
			);
		}

		if ( CommentFields::STATUS_POST_TRASHED === ( $current->fields['status'] ?? '' ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The post this comment belongs to is in the trash, so WordPress owns the comment\'s status until the post is restored.',
				'Restore the parent post first; its comments return to the status they held, and this operation can then change them.'
			);
		}

		$promised = [ self::PROMISED_FIELD => $status ];

		return new PlannedChange( $promised, $promised, CommentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the status to go back to.
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
	 * Writes the promised status.
	 *
	 * `wp_set_comment_status()` answers a WP_Error only when handed the `$wp_error`
	 * flag, which is why it is passed; without it a refused write returns a bare
	 * false that is indistinguishable from a write of the value `false`. Both
	 * failure shapes are tested.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$comment_id = CommentFields::commentIdFromKey( $current->targetKey );
		$status     = (string) ( $planned->payload[ self::PROMISED_FIELD ] ?? '' );

		if ( null === $comment_id || ! isset( CommentFields::SET_ARGUMENT_BY_STATUS[ $status ] ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name a comment and a status this operation can write.',
				'Request a fresh preview for the comment you meant to change.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$written = wp_set_comment_status(
			$comment_id,
			CommentFields::SET_ARGUMENT_BY_STATUS[ $status ],
			true
		);

		if ( is_wp_error( $written ) || false === $written ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change the status of the comment.',
				'Generate a fresh preview and retry; the prior status remains recorded for rollback.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return CommentFields::targetKey( $comment_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the comment for verification.
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
	 * Writes a recorded status back.
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
		return $this->targets->restoreStatus( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
