<?php
/**
 * Media metadata update write operation.
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
 * REQ-0024: media metadata update. An agency operator fixes an attachment's
 * title, caption, description, and alternative text so a client library carries
 * correct accessibility metadata.
 *
 * THE WHOLE PAYLOAD IS VALIDATED BEFORE ANY FIELD IS WRITTEN, which is REQ-0015's
 * rule applied to a second operation and the reason this write is a single
 * planned overlay rather than four independent updates. A loop that validated and
 * wrote field by field would leave the attachment half-updated behind a refusal,
 * with no plan token to reverse and no snapshot boundary the operator agreed to.
 *
 * AT LEAST ONE OF THE FOUR must be present, and the check lives here rather than
 * in the schema because the subset of JSON Schema this project uses for input
 * schemas cannot express "at least one of" — there is no anyOf on the input side,
 * and `required` can only demand a fixed set. An id-only payload is therefore
 * refused with invalid_input from planChange(). It is invalid_input rather than
 * forbidden because the request is genuinely malformed: nothing about site
 * configuration or permission could make it meaningful.
 *
 * THE PROMISE NAMES EXACTLY THE FIELDS THE PAYLOAD NAMED, never the others.
 * WriteVerifier compares the promised keys against the read-back projection, so
 * promising a field nobody asked to change would make an unrelated concurrent
 * edit read as this operation's failure.
 *
 * @package SiteHelm
 */
final class MediaMetaUpdate implements WriteOperation {

	/**
	 * The four writable fields, in the order MediaFields::read() projects them.
	 *
	 * Doubles as the field order handed to PlannedChange, so the preview lists
	 * them the way the read path does rather than alphabetically.
	 *
	 * @var string[]
	 */
	private const WRITABLE_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

	/**
	 * The three writable fields that are post columns, mapped to their column.
	 *
	 * `alt` is absent by design: it is the `_wp_attachment_image_alt` post meta,
	 * not a column, so a fourth entry here would be recorded, promised, silently
	 * ignored by wp_update_post(), and then reported as written.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_FIELD = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	/**
	 * The longest value this operation will accept for one field.
	 *
	 * Every one of these lands in a LONGTEXT column or in post meta, so this is
	 * not a storage limit; it is a blast-radius limit on a write an AI client
	 * issues unattended. The schema is the ONLY place it is enforced — there is
	 * no second check in this class for it to drift against.
	 */
	private const MAX_VALUE_LENGTH = 65535;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             media-meta-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-meta-update',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Update the title, alternative text, caption, or description of one existing media library item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'          => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item whose details are being updated.',
					],
					'title'       => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The media item\'s title. Must not be blank.',
					],
					'alt'         => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'Alternative text describing the image for assistive technology. Send an empty string to clear it.',
					],
					'caption'     => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The caption shown beneath the media item. Send an empty string to clear it.',
					],
					'description' => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The long description of the media item. Send an empty string to clear it.',
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
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-meta-update',
				'arguments' => [
					'id'  => 108,
					'alt' => 'A tabby cat sitting on a dry stone wall',
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
	 * Builds the promised details, refusing the whole payload if any named field
	 * is invalid.
	 *
	 * The order is load-bearing and each step has a test:
	 *
	 * 1. Collect only the fields the payload actually NAMED, using
	 *    array_key_exists() rather than `??` or isset(), because '' is a legal
	 *    value that means "clear this field" and both of the shorter forms would
	 *    silently drop it.
	 * 2. Refuse a payload naming none of the four. This is the "at least one of"
	 *    the input schema cannot express.
	 * 3. Refuse a blank title — one that is empty or holds only whitespace. The
	 *    schema carries the upper bound on length; this is the lower one, and it
	 *    cannot be written as `minLength` because a title of "   " satisfies
	 *    minLength 1 while naming nothing an operator can find again: `media-list`
	 *    matches its `search` argument against exactly this field. The other three
	 *    fields are deliberately NOT blank-checked, because clearing a caption,
	 *    a description, or an alt is a legitimate instruction.
	 * 4. Only then build the promise.
	 *
	 * Steps 2 and 3 both run BEFORE anything is written, and that ordering is the
	 * operation's whole safety property: a payload naming a valid caption and a
	 * blank title writes neither.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) && is_scalar( $input[ $field ] ) ) {
				$requested[ $field ] = (string) $input[ $field ];
			}
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No media details were supplied, so there is nothing to write.',
				'Name at least one of the title, alternative text, caption, or description, then request a fresh preview.'
			);
		}

		if ( array_key_exists( 'title', $requested ) && '' === trim( $requested['title'] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A media title cannot be blank, so none of the requested details were written.',
				'Send a title with at least one non-whitespace character, then request a fresh preview.'
			);
		}

		return new PlannedChange( $requested, $requested, self::WRITABLE_FIELDS );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures all four current values the write is about to replace.
	 *
	 * ALL FOUR are recorded, not only the ones being written, because a rollback
	 * that restored a subset would leave the attachment holding a mixture of the
	 * pre-write and post-write states — and because recording the complete set is
	 * what makes the record comparable to MediaFields::read()'s projection.
	 *
	 * `alt` IS RECORDED EVEN WHEN UNSET, as ''. That is the absent-versus-empty
	 * axis this operation's restore path turns on: a recorded '' instructs
	 * MediaTarget::restoreFields() to write '' back, and an absent key instructs
	 * it to leave the meta alone. Omitting the key for an unset alt would mean a
	 * rollback silently kept whatever alt this write had just set.
	 *
	 * NEITHER post_status NOR post_name is recorded, and their absence is the
	 * point rather than an omission — MediaTarget::RESTORABLE_TEXT_FIELDS carries
	 * only the three mapped columns, so a rollback of a metadata change cannot
	 * touch an attachment's status or slug.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads $current->fields and
	 * nothing else, calling no WordPress function at all. The engine calls it
	 * once at preview for snapshot eligibility and again at apply for real.
	 *
	 * The key order is sorted, matching every other snapshot in the codebase: the
	 * restore state is stored as canonical JSON, so a stable order keeps the
	 * stored row identical for identical state.
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

		$snapshot = [ 'post_id' => $attachment_id ];
		foreach ( self::WRITABLE_FIELDS as $field ) {
			$snapshot[ $field ] = (string) ( $current->fields[ $field ] ?? '' );
		}
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the promised details.
	 *
	 * TWO MECHANISMS, and they are judged differently ON PURPOSE.
	 *
	 * The column write is judged by wp_update_post()'s ERROR return — WP_Error or
	 * 0 — and NOT by re-reading each column's value. That is not an exception to
	 * the project's verify-by-measurement rule; it is the rule applied correctly.
	 * wp_update_post() does not silently drop a post_title, so a stored value that
	 * differs from the requested one means the platform ADJUSTED it — kses
	 * stripping a tag from a description, say — and an adjustment is exactly what
	 * WriteVerifier exists to detect and report against the promised fields. A
	 * re-read here would convert every legitimate adjustment into an
	 * execution_failed and make this operation structurally unable to report one.
	 *
	 * The alt write is judged by RE-READING, because update_post_meta()'s return
	 * is documented-useless: update_metadata() returns false "if the value passed
	 * to the function is the same as the one that is already in the database",
	 * which is the ordinary idempotent apply — the second half of a preview/apply
	 * pair, or a client retrying after a timeout.
	 *
	 * The re-read asks for the LIST and requires EXACTLY ONE row rather than a
	 * matching row 0. get_metadata_raw() with `$single = true` answers row 0 alone,
	 * and update_metadata() rewrites EVERY row under the key because its WHERE
	 * carries no meta_value unless a $prev_value was passed. So a plugin that has
	 * added a second row under this key has its row flattened by this write, and a
	 * row-0 comparison would see the value it just wrote and pass. Rows destroyed,
	 * reported verified.
	 *
	 * The value is slashed on the way in for both mechanisms, because
	 * wp_update_post() and update_metadata() both unslash before storing, so an
	 * unslashed value holding a backslash or a quote is stored short of a
	 * character.
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
		// Accumulated as each step succeeds rather than declared up front, so a
		// refusal on the alternative text can never claim nothing was written
		// when the column write for a title-and-alt payload has already landed.
		$completed = [ 'plan approved', 'snapshot captured' ];

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the media item this write was planned against.',
				'Request a fresh preview and retry.',
				$completed
			);
		}

		$update = [ 'ID' => $attachment_id ];
		foreach ( self::COLUMN_FOR_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $planned->payload ) ) {
				$update[ $column ] = (string) $planned->payload[ $field ];
			}
		}

		// Only 'ID' means the payload named `alt` alone. Calling wp_update_post()
		// with an ID alone is not a no-op: WordPress re-saves the row, bumping
		// post_modified and firing save_post for a write that changed no column.
		if ( count( $update ) > 1 ) {
			$written = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to update the media item\'s details.',
					'Request a fresh preview and retry.',
					$completed
				);
			}

			$completed[] = 'media details written';
		}

		if ( array_key_exists( 'alt', $planned->payload ) ) {
			$alt = (string) $planned->payload['alt'];
			update_post_meta( $attachment_id, MediaFields::ALT_META_KEY, wp_slash( $alt ) );

			$rows = get_post_meta( $attachment_id, MediaFields::ALT_META_KEY, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== $alt ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'The alternative text did not read back as exactly the one value this write stored.',
					'Request a fresh preview and retry; if it is refused again, ask a site administrator to review the site\'s plugins.',
					$completed
				);
			}
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
	 * Writes the recorded details back.
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
}
