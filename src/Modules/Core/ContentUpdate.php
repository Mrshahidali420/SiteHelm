<?php
/**
 * Content update write operation.
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
 * REQ-0014: content update. An agency operator revises existing client content
 * while retaining the prior version for recovery.
 *
 * The promised after-state is the payload passed through the same sanitizers
 * WordPress applies on save, because verification compares the persisted state
 * against the promise. If WordPress applies a further transformation the plan
 * did not anticipate, that is reported as an adjustment rather than a failure:
 * the write succeeds as `verified-with-adjustments`, each adjusted field is
 * named in a warning, and the value WordPress actually stored is disclosed in
 * `data.state`. `verification_failed` is reserved for a promised field still
 * holding its prior value, which means the write did not take. See
 * interpretation I7.
 *
 * @package SiteHelm
 */
final class ContentUpdate implements WriteOperation {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item, keeping the prior revision available.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to revise.',
					],
					'title'   => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Replacement title.',
					],
					'content' => [
						'type'        => 'string',
						'maxLength'   => 500000,
						'description' => 'Replacement body.',
					],
					'excerpt' => [
						'type'        => 'string',
						'maxLength'   => 5000,
						'description' => 'Replacement excerpt.',
					],
				],
				'required'             => [ 'id' ],
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
				'operation' => 'content-update',
				'arguments' => [
					'id'    => 42,
					'title' => 'Revised heading',
				],
			],
		);
	}

	/**
	 * Request property to normalized field name. Status, terms, metadata, and
	 * featured media are deliberately absent: each is its own requirement with
	 * its own operation, and folding them in here would blur that boundary.
	 * Featured media is the first of them to ship, as
	 * `content-featured-media-set`; the rest are still ahead.
	 */
	private const CHANGEABLE = [
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
	];

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
	 * Builds the promised revision.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when nothing
	 *                           changeable was supplied.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$promised = [];

		foreach ( self::CHANGEABLE as $property => $field ) {
			if ( ! array_key_exists( $property, $input ) ) {
				continue;
			}
			$promised[ $field ] = $this->fields->sanitizeForSave(
				$field,
				(string) $input[ $property ],
				$context->userId
			);
		}

		if ( [] === $promised ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Supply at least one of title, content, or excerpt to revise.',
				'Add one changeable property and request a fresh preview.'
			);
		}

		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * The recorded set is `ContentTarget::RESTORABLE_FIELDS`, which is wider than
	 * this operation's own input: it also carries `post_status` and `post_name`
	 * so a rollback restores where the content sat in its workflow and not only
	 * its words.
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
	 * Saves the promised revision.
	 *
	 * The wp_update_post() call expects slashed data and unslashes internally,
	 * so the payload is slashed on the way in; the read-back compares against
	 * the unslashed promise.
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
				'WordPress refused to save the content item.',
				'Generate a fresh preview and retry; the prior revision remains available.',
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
}
