<?php
/**
 * Content status change write operation.
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
 * REQ-0018: content status change. An agency operator moves client content
 * through draft, review and publish states on request.
 *
 * The publish capability is checked in planChange(), not in the definition,
 * because it depends on WHAT is being written. PolicyEngine::authorize()
 * receives the definition, the context and one integer target id — never the
 * payload — and Dispatcher calls it once, up front, so a capability required
 * only for some values of `status` cannot be expressed there, and widening the
 * gate to accept a payload would put operation logic behind the single
 * chokepoint that guards every operation in the plugin. ContentCreate
 * established the alternative.
 *
 * That is as strong as a gate check for one reason: ChangeEngine calls
 * planChange() in BOTH phases, at preview and again at apply, so a caller
 * cannot preview while holding publish_posts, lose it, and then apply. The
 * property is pinned by ChangeEngineApplyTest, because it is the assumption
 * this whole approach rests on.
 *
 * The capability split matches WordPress core's own. Its REST posts controller
 * routes `draft` and `pending` through handle_status_param() with no check at
 * all, and requires `$post_type->cap->publish_posts` for `private`, `publish`
 * and `future` alike.
 *
 * A custom post type registered with its own capability_type maps publish to a
 * distinct capability name — `publish_products`, say — so the type's own name is
 * read rather than the generic `publish_posts` primitive being substituted for
 * it. When that name cannot be read at all the transition is refused rather
 * than allowed through a generic fallback, which would let a caller publish a
 * type they hold no capability for. A post whose type is no longer registered
 * — the plugin that declared it having been deactivated — is the ordinary way
 * that happens, and get_post_type_object() answers null for it.
 *
 * `future` and `trash` are absent from the settable set on purpose. WordPress
 * converts a publish on a future-dated post to `future` itself, and the engine
 * reports that as verified-with-adjustments with the stored value disclosed;
 * declaring it settable would promise a transition the operator never asked
 * for. The trash is REQ-0019, a separate operation with its own required
 * rollback.
 *
 * @package SiteHelm
 */
final class ContentStatusSet implements WriteOperation {

	/**
	 * The statuses this operation can set.
	 *
	 * The input schema's `enum` is rendered from this constant so there is one
	 * list rather than two that can drift, and the golden schema fixture pins
	 * the rendered result independently.
	 *
	 * This is NOT the capability split. ContentFields::DRAFT_LIKE_STATUSES
	 * decides which of these need publish_posts. And it is unrelated to
	 * ContentList::DEFAULT_STATUSES, which happens to hold the same four
	 * strings but is a read filter's default.
	 *
	 * @var string[]
	 */
	private const SETTABLE_STATUSES = [ 'draft', 'pending', 'private', 'publish' ];

	/**
	 * The one field this operation promises.
	 */
	private const PROMISED_FIELD = 'post_status';

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-status-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-status-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Move one existing content item to a different publication status, for example from draft to publish.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose status is being changed.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => self::SETTABLE_STATUSES,
						'description' => 'Target status. Anything other than draft or pending additionally requires the content type\'s publish capability.',
					],
				],
				'required'             => [ 'id', 'status' ],
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
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-status-set',
				'arguments' => [
					'id'     => 42,
					'status' => 'publish',
				],
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
	 * Builds the promised transition, checking the conditional capability.
	 *
	 * The status is re-validated against SETTABLE_STATUSES even though the input
	 * schema declares the same `enum`. Interpretation I7's discipline is that a
	 * write validates its own payload rather than assuming a caller reached it
	 * through the one path that validated it: WriteVerifier classifies a value
	 * WordPress silently normalises as an ADJUSTMENT, so an unknown status that
	 * reached wp_update_post() would be quietly rewritten and the write would
	 * report success.
	 *
	 * No ksort: the promise holds exactly one key, so there is no order for a
	 * sort to make deterministic. ContentUpdate and ContentCreate ksort because
	 * they build up to three and five keys respectively.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a status this
	 *                           operation cannot set, or ErrorCode::Forbidden
	 *                           for an unpermitted publish.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$status = (string) ( $input['status'] ?? '' );

		if ( ! in_array( $status, self::SETTABLE_STATUSES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested status is not one this operation can set.',
				'Choose draft, pending, private, or publish and request a fresh preview.'
			);
		}

		if ( ! in_array( $status, ContentFields::DRAFT_LIKE_STATUSES, true ) ) {
			$this->assert_may_publish(
				(string) ( $current->fields['post_type'] ?? '' ),
				$context->userId
			);
		}

		$promised = [ self::PROMISED_FIELD => $status ];

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * The recorded set is ContentTarget::RESTORABLE_FIELDS, which includes
	 * post_status — the column this write changes — and post_name, because
	 * WordPress regenerates an empty slug on a status change and a rollback that
	 * restored the status but not the slug would leave the item at a different
	 * address. Core does that in wp_insert_post(): a stored post_name that is
	 * empty is filled from the title through wp_unique_post_slug() for any
	 * status that is not draft, pending or auto-draft.
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
	 * Saves the promised transition.
	 *
	 * The wp_update_post() call expects slashed data and unslashes internally,
	 * matching ContentUpdate. It answers 0 or a WP_Error when the row cannot be
	 * read or the insert fails, and the post id otherwise, so both failure shapes
	 * are tested rather than the truthiness of the return alone.
	 *
	 * WordPress may store a status other than the promised one — a publish on a
	 * future-dated post becomes `future` — and that is reported as
	 * verified-with-adjustments with the stored value disclosed in data.state,
	 * not as a failure.
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
		$updated = wp_update_post(
			wp_slash( array_merge( [ 'ID' => $post_id ], $planned->payload ) ),
			true
		);

		if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change the status of the content item.',
				'Generate a fresh preview and retry; the prior status remains recorded for rollback.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( (int) $updated );
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
	 * Writes a recorded snapshot back.
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
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses unless the acting user holds the content type's OWN publish
	 * capability.
	 *
	 * The type's `cap->publish_posts` is a capability NAME, which a custom post
	 * type registered with its own capability_type maps to something like
	 * `publish_products`. Substituting the generic `publish_posts` primitive when
	 * that name cannot be read would let a caller publish a type they hold no
	 * capability for at all, so an unreadable name refuses instead. Every member
	 * is checked before it is read.
	 *
	 * The guard below holds FIVE conditions, and they are not equally load
	 * bearing. Deleting any of these three changes the answer, and each has a
	 * test that fails when it does:
	 *
	 * - `! isset( $object->cap )`                  — the type exposes no cap object
	 * - `! isset( $object->cap->publish_posts )`   — cap declares no publish name
	 * - `! is_string( $object->cap->publish_posts )` — the name is not a name
	 *
	 * The other two — `! is_object( $object )` and `! is_object( $object->cap )`
	 * — are DEFENCE IN DEPTH and cannot currently change the answer, because
	 * isset() on a property of null or of a scalar is already false, so the next
	 * condition catches those shapes anyway. They are kept deliberately: they are
	 * free, they state the intended shape, and an edit that reordered or narrowed
	 * the isset() conditions would make them reachable again. Do not "clean them
	 * up" on the strength of a surviving mutation — the redundancy is the point,
	 * and it is recorded here so a future reader deletes neither by mistake nor a
	 * load-bearing one by confusion.
	 *
	 * There is no separate `'' === $type` guard, and its absence is deliberate
	 * rather than an omission: get_post_type_object() answers null for an empty
	 * string exactly as it does for any other unregistered type, so a short
	 * circuit could not change the answer for any input this method can be
	 * handed, and a test for it would only be asserting against the test double.
	 *
	 * The refusal is Forbidden in both branches. An unreadable capability name is
	 * a failure to establish permission, and failing closed on authorization is
	 * the only safe answer; it is not invalid input, because the caller supplied
	 * a valid status and never named the type at all.
	 *
	 * @param string $type   The target's content type.
	 * @param int    $userId The acting WordPress user.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_may_publish( string $type, int $userId ): void {
		$object = get_post_type_object( $type );

		if ( ! is_object( $object )
			|| ! isset( $object->cap ) || ! is_object( $object->cap )
			|| ! isset( $object->cap->publish_posts ) || ! is_string( $object->cap->publish_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your permission to publish this content type could not be established.',
				'Set the item to draft or pending instead, or ask a site administrator to review how this content type is registered.'
			);
		}

		if ( ! user_can( $userId, $object->cap->publish_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish this content type.',
				'Set the item to draft or pending instead, or ask a site administrator to grant the publish capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
