<?php
/**
 * Content creation write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0013: content creation. An agency operator drafts new client content
 * through an AI client without touching wp-admin.
 *
 * The capability check is split, because a definition cannot express a
 * conditional capability: `edit_posts` is declared and enforced by the policy
 * engine, and `publish_posts` is enforced here whenever the requested status is
 * publish. The contract permits exactly this — the policy engine may add
 * restrictions on top of the declared capabilities, never fewer.
 *
 * @package SiteHelm
 */
final class ContentCreate implements WriteOperation {

	/**
	 * The status whose creation additionally requires publish_posts.
	 */
	private const PUBLISH_STATUS = 'publish';

	/**
	 * The status used when the request names none.
	 */
	private const DEFAULT_STATUS = 'draft';

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
	 * A creation's target does not exist yet, so it resolves to the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Builds the promised new content item.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an
	 *                           unavailable content type, or
	 *                           ErrorCode::Forbidden for an unpermitted publish.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$type = (string) ( $input['type'] ?? '' );
		if ( ! $this->is_creatable( $type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested content type is not available for creation on this site.',
				'Choose a public content type this site registers.'
			);
		}

		$status = (string) ( $input['status'] ?? self::DEFAULT_STATUS );
		if ( self::PUBLISH_STATUS === $status && ! user_can( $context->userId, 'publish_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish content.',
				'Create the item as a draft, or ask a site administrator to grant the publish capability.'
			);
		}

		$promised = [
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => $this->sanitize( 'post_title', (string) ( $input['title'] ?? '' ), $context ),
			'post_content' => $this->sanitize( 'post_content', (string) ( $input['content'] ?? '' ), $context ),
			'post_excerpt' => $this->sanitize( 'post_excerpt', (string) ( $input['excerpt'] ?? '' ), $context ),
		];
		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * The contract's `supported` snapshot policy covers exactly this: creation
	 * style writes proceed without a snapshot, and the result then omits the
	 * rollback reference.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Inserts the promised content item.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$created = wp_insert_post( wp_slash( $planned->payload ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the content item.',
				'Generate a fresh preview and retry; no content item was created.',
				[ 'plan approved' ]
			);
		}

		return $this->fields->targetKey( (int) $created );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the created item for verification.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	/**
	 * A creation cannot be reversed by restoring prior state, because there was
	 * none. Removal is a separate requirement in a later phase.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly created content item has no prior state to restore.',
			'Move the item to the trash in WordPress if it should not exist.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Whether a content type may be created through this operation.
	 *
	 * @param string $type The requested content type.
	 *
	 * @return bool True when the type is registered and public.
	 */
	private function is_creatable( string $type ): bool {
		if ( '' === $type || ! post_type_exists( $type ) ) {
			return false;
		}
		$object = get_post_type_object( $type );

		return is_object( $object ) && isset( $object->public ) && true === $object->public;
	}

	/**
	 * Applies the same sanitizer WordPress applies to this field on save.
	 *
	 * @param string           $field   The normalized field name.
	 * @param string           $value   The requested value.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The value as WordPress will store it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function sanitize( string $field, string $value, OperationContext $context ): string {
		if ( user_can( $context->userId, 'unfiltered_html' ) ) {
			return $value;
		}

		return match ( $field ) {
			'post_title' => (string) wp_kses_data( $value ),
			default      => (string) wp_kses_post( $value ),
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
