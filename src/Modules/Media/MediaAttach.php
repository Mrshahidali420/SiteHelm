<?php
/**
 * Media attachment association write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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
 * REQ-0025: media association. An agency operator associates a library asset
 * with the post that uses it, or detaches it.
 *
 * TWO CAPABILITY CHECKS, IN TWO PLACES, AND THE SPLIT IS FORCED. `edit_post` on
 * the ATTACHMENT is declared in the definition and enforced at the gate, because
 * the policy engine resolves exactly one target id and it reads that id from
 * `arguments['id']`. `edit_post` on the DESTINATION post cannot be declared,
 * because the policy engine never sees the payload — so it is checked inside
 * planChange().
 *
 * That placement is only as strong as a gate check BECAUSE planChange() runs in
 * BOTH phases: the change engine re-runs it at apply with the payload recovered
 * from the stored plan, so a caller cannot preview while holding the capability,
 * lose it, and then apply. That property is not this operation's to guarantee —
 * it is pinned by
 * ChangeEngineApplyTest::test_apply_re_runs_plan_change_so_a_refusal_inside_it_stops_the_write
 * — and this operation depends on it. If that test is ever deleted, this
 * operation's second capability check silently becomes preview-only.
 *
 * INTERPRETATION I7: a non-zero parent is validated at PLANNING time, not after
 * the write. wp_update_post() silently drops a post_parent that does not resolve,
 * and WriteVerifier classifies a silently-dropped value as an ADJUSTMENT — so the
 * write would succeed and the operator would be told the platform changed their
 * value rather than that their value was never valid. The same reasoning
 * ContentFeaturedMediaSet records for its attachment id, one field over.
 *
 * DETACHING IS NOT DESTRUCTIVE. `post_parent` is a pointer; clearing it loses no
 * content, and the snapshot records the prior pointer and restores it exactly.
 * `isDestructive` stays false, which keeps the policies at required / required /
 * supported rather than forcing all three to required.
 *
 * @package SiteHelm
 */
final class MediaAttach implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * MediaFields::read() projects, or verification compares the promise against
	 * nothing.
	 */
	private const PROMISED_FIELD = 'parent';

	/**
	 * The post column the promised field is stored in.
	 */
	private const PARENT_COLUMN = 'post_parent';

	/**
	 * The parent value that means "detach".
	 */
	private const DETACHED = 0;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-attach.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-attach',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Associate one media library item with the content item that uses it, or detach it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item being attached or detached.',
					],
					'parent' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item to attach this media item to. Send 0 to detach it.',
					],
				],
				'required'             => [ 'id', 'parent' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-attach',
				'arguments' => [
					'id'     => 108,
					'parent' => 42,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields $fields  The normalized attachment projection.
	 * @param MediaTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
	) {
	}

	/**
	 * Resolves the media item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ), $context );
	}

	/**
	 * Builds the promised association, validating the destination.
	 *
	 * The presence of `parent` is checked with array_key_exists() rather than
	 * defaulted with `?? 0`, and that is not defensive padding: 0 is not "no
	 * value", it is the instruction to DETACH. A `?? 0` here would turn a payload
	 * that lost its parent argument into a detach and report the write verified —
	 * structurally the same defect as `?? ''` resolving an empty post_status to
	 * 'draft'.
	 *
	 * EXISTENCE IS CHECKED BEFORE CAPABILITY, and the order is deliberate. The
	 * spec requires a dangling parent to refuse `invalid_input` (interpretation
	 * I7), which is only possible if the existence test answers first. That does
	 * mean a caller lacking `edit_post` on an existing post learns it exists,
	 * whereas one naming a non-existent post does not — a narrow existence
	 * oracle. It is accepted rather than closed, because the caller already had
	 * to hold `edit_post` on an attachment to reach this line at all, and the
	 * alternative — capability first — would report `forbidden` for an ordinary
	 * mistyped id and give the operator nothing to act on.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the parent is
	 *                           absent or does not name a content item, or
	 *                           ErrorCode::Forbidden when the caller may not edit
	 *                           it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		if ( ! array_key_exists( self::PROMISED_FIELD, $input ) || ! is_numeric( $input[ self::PROMISED_FIELD ] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No parent identifier was supplied, so there is nothing to attach or detach.',
				'Name the content item to attach this media item to, or send 0 to detach it, then request a fresh preview.'
			);
		}

		$parent = (int) $input[ self::PROMISED_FIELD ];

		if ( self::DETACHED !== $parent ) {
			if ( ! $this->is_attachable_post( $parent ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The requested parent identifier does not name a content item on this site.',
					'Choose the identifier of an existing content item, or send 0 to detach, then request a fresh preview.'
				);
			}

			if ( ! user_can( $context->userId, 'edit_post', $parent ) ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					'Your WordPress user may not edit the requested parent content item.',
					'Ask a site administrator to grant your WordPress user editing rights on that content item.'
				);
			}
		}

		$promised = [ self::PROMISED_FIELD => $parent ];

		return new PlannedChange( $promised, $promised );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the parent the write is about to replace.
	 *
	 * A DETACHED attachment records 0, not null. Null is read by
	 * SnapshotLifecycle as "nothing recoverable", and this operation's snapshot
	 * policy is required, so the plan would be refused with rollback_unavailable
	 * for the ordinary case of an attachment that simply has no parent yet. 0 is
	 * a legal recorded value and restoring it is a detach — which is precisely
	 * why this operation is not destructive.
	 *
	 * ONLY the parent and the identifier are recorded. MediaMetaUpdate's snapshot
	 * shape is not reused, because recording four text values this write never
	 * touches would make a rollback promise to rewrite a title, caption,
	 * description and alt the operator never changed.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads $current->fields and
	 * calls no WordPress function at all.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no identifiable prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $attachment_id,
			self::PROMISED_FIELD => (int) ( $current->fields[ self::PROMISED_FIELD ] ?? self::DETACHED ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the promised parent, and judges the result by measurement.
	 *
	 * THE RE-READ IS THE POINT OF THIS METHOD, and it is not the belt-and-braces
	 * that the same pattern would be on MediaMetaUpdate's columns.
	 * wp_update_post() returning the attachment id proves the row was saved; it
	 * does NOT prove post_parent holds what was sent, because core drops a parent
	 * that does not resolve rather than refusing. A dropped parent is not a
	 * platform ADJUSTMENT — it is a lost instruction — so leaving it to
	 * WriteVerifier would report `adjusted` and success for a write that did
	 * nothing the operator asked for.
	 *
	 * planChange() already refused a dangling parent, but it refused the parent
	 * that existed AT PLANNING TIME, and the plan token round trip is exactly the
	 * window in which another editor can delete that post. This is the guard for
	 * the window.
	 *
	 * No wp_slash() call: the only value written is an integer, and slashing an
	 * integer is a no-op that would misleadingly suggest a string was in play.
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
		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the media item this write was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$parent  = (int) $planned->payload[ self::PROMISED_FIELD ];
		$written = wp_update_post(
			[
				'ID'                => $attachment_id,
				self::PARENT_COLUMN => $parent,
			],
			true
		);

		if ( is_wp_error( $written ) || 0 === (int) $written ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change this media item\'s parent.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		clean_post_cache( $attachment_id );
		$stored = $this->fields->read( $attachment_id );

		if ( null === $stored || (int) ( $stored[ self::PROMISED_FIELD ] ?? -1 ) !== $parent ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the requested parent for this media item.',
				'Confirm the content item still exists, then request a fresh preview.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the media item for verification.
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
	 * Writes the recorded parent back.
	 *
	 * MediaTarget::restoreFields() carries `parent` through
	 * RESTORABLE_PARENT_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and a redeemed rollback reference.
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
		return $this->targets->restoreFields( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Whether an identifier names an existing post that is not an attachment.
	 *
	 * Duck-typed rather than checked against WP_Post, matching
	 * ContentFeaturedMediaSet::is_attachment(), so the operation stays
	 * unit-testable without loading WordPress. Every member is checked before it
	 * is read: a post object that does not expose post_type is not evidence of
	 * anything, and reading it blind would treat a malformed object as valid.
	 *
	 * THE IDENTITY CHECK IS LOAD-BEARING, and it is why there is no separate
	 * `$parentId <= 0` guard here. get_post() answers `$GLOBALS['post']` for any
	 * empty argument, so an identifier that coerces to 0 can come back holding a
	 * real, unrelated post — and comparing the returned id to the one that was
	 * asked for refuses that whatever the argument was, because the global post's
	 * id can never equal 0. A leading `<= 0` return would shadow it. Note that
	 * planChange() short-circuits an explicit 0 before this method is reached and
	 * the schema bounds the value below, so the check is the backstop for the
	 * case, not its only handler.
	 *
	 * An ATTACHMENT is refused as a parent. WordPress uses post_parent on an
	 * attachment to mean "the content item this asset belongs to", and nesting an
	 * attachment under another attachment produces a relationship no media screen
	 * renders and no read path projects meaningfully.
	 *
	 * @param int $parentId The requested parent identifier.
	 *
	 * @return bool True when the identifier names a non-attachment post.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function is_attachable_post( int $parentId ): bool {
		$parent = get_post( $parentId );

		return is_object( $parent )
			&& isset( $parent->ID, $parent->post_type )
			&& (int) $parent->ID === $parentId
			&& MediaFields::ATTACHMENT_TYPE !== (string) $parent->post_type;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
