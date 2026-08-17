<?php
/**
 * REQ-0060: reply to a comment as the acting WordPress user.
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
 * Posts an approved reply beneath an existing comment.
 *
 * THE REPLY IS AUTHORED BY THE ACTING USER ACCOUNT, not by the plugin and not
 * by an anonymous name the caller supplies. A moderator's reply carries the
 * site's voice, and an operation that let a caller choose the display name would
 * be a way to put words under any name on a public page. The name, email and site
 * URL all come from the resolved user account, and the reply is linked to it by
 * `user_id` so WordPress marks it as the post author's or an administrator's
 * exactly as it does for a reply typed into the admin screen.
 *
 * THE REPLY IS APPROVED ON CREATION. The capability this operation requires is
 * the one WordPress uses to decide who may approve comments, so a moderator's own
 * reply landing in the moderation queue would be a queue entry only they could
 * clear. `comment_approved` is therefore set explicitly, which WordPress honours:
 * `wp_new_comment()` consults the spam and moderation rules only when that member
 * is absent.
 *
 * A REPLY TO A SPAM OR TRASHED PARENT IS REFUSED RATHER THAN REPARENTED. This is
 * not a matter of taste: `wp_new_comment()` silently resets `comment_parent` to 0
 * for any parent that is not approved or pending, so the alternative to refusing
 * is posting a top-level comment that the caller believes is a threaded reply.
 * A pending parent is allowed — WordPress threads under it — and the plan carries
 * a warning saying the reply will be invisible to readers until the parent is
 * approved.
 *
 * IT CANNOT BE ROLLED BACK, and says so. `captureSnapshot()` runs before
 * `applyChange()`, so there is no moment at which the created comment's
 * identifier can be recorded as restore state; this is the same shape
 * `content-create` has. The refusal names `comment-status-set` as the way to
 * withdraw a reply, which trashes it reversibly rather than destroying it.
 *
 * @package SiteHelm
 */
final class CommentReply implements WriteOperation {

	/**
	 * The statuses a parent may hold for a reply to thread beneath it.
	 *
	 * Mirrors the pair `wp_new_comment()` accepts. Widening it would not widen
	 * what WordPress threads; it would only stop this operation from noticing.
	 *
	 * @var string[]
	 */
	private const REPLYABLE_PARENT_STATUSES = [
		CommentFields::STATUS_APPROVED,
		CommentFields::STATUS_PENDING,
	];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for comment-reply.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'comment-reply',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Post an approved reply beneath an existing comment, authored by the acting WordPress user.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the comment being replied to.',
					],
					'content' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => CommentFields::CONTENT_MAX_LENGTH,
						'description' => 'The reply body, as the acting user would type it.',
					],
				],
				'required'             => [ 'id', 'content' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ CommentFields::CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'comment-reply',
				'arguments' => [
					'id'      => 118,
					'content' => 'Thanks for flagging this — the broken link is fixed now.',
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
	 * Resolves the comment being replied to.
	 *
	 * The target is the PARENT, because that is the row that must exist before the
	 * plan can be judged. `applyChange()` returns the created reply's key instead,
	 * so verification reads the new comment rather than the one it hangs from.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved parent state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised reply.
	 *
	 * THE BODY IS NOT PROMISED, and its absence from the after-state is the one
	 * judgement in this method worth reading twice. WordPress runs every comment
	 * body through `preprocess_comment` and the site's own kses rules on the way
	 * in, so a site that strips a tag or converts an entity would store something
	 * that is not character-for-character what was sent. Promising the body would
	 * make each of those legitimate filters look like a write that failed to
	 * apply. What IS promised is what this operation controls exactly: the post
	 * and parent it threads under, and the status it lands in. The body the
	 * operator approves is shown in the preview detail instead, where a difference
	 * is informative rather than fatal.
	 *
	 * planChange() runs in both phases, so a parent that is spammed between
	 * preview and apply is caught at apply rather than quietly reparented.
	 *
	 * @param TargetState          $current The resolved parent state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an empty or
	 *                           oversized body, ErrorCode::Conflict for a parent
	 *                           WordPress will not thread under, or
	 *                           ErrorCode::Forbidden when the acting user cannot
	 *                           be resolved to an account.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$content = trim( (string) ( $input['content'] ?? '' ) );

		if ( '' === $content ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A reply needs a body.',
				'Send the text of the reply in the content argument.'
			);
		}

		if ( strlen( $content ) > CommentFields::CONTENT_MAX_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'The reply body is longer than the %d characters this operation accepts.',
					CommentFields::CONTENT_MAX_LENGTH
				),
				'Shorten the reply, or publish the longer text as content and link to it.'
			);
		}

		$parent_status = (string) ( $current->fields['status'] ?? '' );

		if ( ! in_array( $parent_status, self::REPLYABLE_PARENT_STATUSES, true ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'WordPress does not thread replies beneath a comment in this status, so the reply would be posted as a top-level comment instead.',
				'Restore the comment with comment-status-set first, then reply to it.'
			);
		}

		$user = get_userdata( $context->userId );

		if ( false === $user ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'The acting WordPress user could not be resolved to an account, so the reply has no author.',
				'Ask a site administrator to confirm the WordPress user this client is bound to still exists.'
			);
		}

		$parent_id = (int) ( $current->fields['id'] ?? 0 );
		$post_id   = (int) ( $current->fields['postId'] ?? 0 );

		$warnings = [];

		if ( CommentFields::STATUS_PENDING === $parent_status ) {
			$warnings[] = 'The comment being replied to is awaiting moderation, so the reply will not be visible to readers until the parent is approved.';
		}

		return new PlannedChange(
			[
				'content'  => $content,
				'parentId' => $parent_id,
				'postId'   => $post_id,
				'author'   => (string) $user->display_name,
			],
			[
				'parentId' => $parent_id,
				'postId'   => $post_id,
				'status'   => CommentFields::STATUS_APPROVED,
			],
			CommentFields::FIELD_ORDER,
			$warnings,
			[
				'author'  => (string) $user->display_name,
				'content' => $content,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures nothing, because there is nothing yet to capture.
	 *
	 * The reply does not exist when this runs, and the parent is not modified by
	 * creating one. Returning null is honest; the operation declares
	 * SnapshotPolicy::Supported rather than Required so a null capture refuses
	 * nothing.
	 *
	 * @param TargetState      $current The resolved parent state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	/**
	 * Creates the reply.
	 *
	 * `wp_new_comment()` is used rather than `wp_insert_comment()` because it is
	 * the function the admin reply box goes through: it fires `preprocess_comment`
	 * and the comment notification hooks, so subscribers are told and the site's
	 * own filters run. The `$wp_error` flag is passed, and a bare false is treated
	 * as a failure too, because the function answers both shapes.
	 *
	 * @param TargetState      $current The resolved parent state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created reply's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$user = get_userdata( $context->userId );

		if ( false === $user ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The acting WordPress user could not be resolved to an account, so the reply has no author.',
				'Ask a site administrator to confirm the WordPress user this client is bound to still exists.',
				[ 'plan approved' ]
			);
		}

		$created = wp_new_comment(
			[
				'comment_post_ID'      => (int) ( $planned->payload['postId'] ?? 0 ),
				'comment_parent'       => (int) ( $planned->payload['parentId'] ?? 0 ),
				'comment_content'      => (string) ( $planned->payload['content'] ?? '' ),
				'comment_author'       => (string) $user->display_name,
				'comment_author_email' => (string) $user->user_email,
				'comment_author_url'   => (string) $user->user_url,
				'comment_type'         => 'comment',
				'comment_approved'     => 1,
				'user_id'              => $context->userId,
			],
			true
		);

		if ( is_wp_error( $created ) || ! is_int( $created ) || $created < 1 ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to store the reply.',
				'Generate a fresh preview and retry; nothing was posted.',
				[ 'plan approved' ]
			);
		}

		return CommentFields::targetKey( $created );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the created reply for verification.
	 *
	 * @param string           $targetKey The created reply's target key.
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

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	/**
	 * Refuses, because a reply has no prior state to return to.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException Always, with ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly posted reply has no prior state to restore.',
			'Withdraw the reply by moving it to the trash with comment-status-set; nothing is deleted by doing so.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
