<?php
/**
 * Media upload write operation.
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
 * REQ-0023: media upload. An agency operator adds a client-approved asset to the
 * library through an AI client.
 *
 * The high-risk operation of the phase, and the only one in the codebase that
 * writes a file. Four properties make it safe, and each is pinned by a test:
 *
 * 1. ALL VALIDATION IS IN planChange(), IN MEMORY. planChange() runs at preview
 *    AND at apply, so every guard runs twice and a caller cannot preview one
 *    payload and apply another. Nothing touches disk until applyChange().
 * 2. THE PLANNED PAYLOAD CARRIES A HASH OF THE BYTES, NEVER THE BYTES.
 *    PayloadNormalizer::canonicalJson() is `(string) wp_json_encode( ... )`, and
 *    wp_json_encode returns false for a string that is not valid UTF-8 — which
 *    every JPEG and PNG is not. Raw bytes in the payload would make every upload
 *    fingerprint to sha256(''), and ChangeEngine::apply()'s payload check would
 *    then accept ANY upload against ANY upload plan. The state fingerprint would
 *    not catch it either: a create-shaped target has no fields and a constant
 *    key. So the payload carries `contentSha256`, the fingerprint is real, and
 *    it binds the exact bytes.
 * 3. THE BYTES RIDE ON A PRIVATE PROPERTY between planChange() and applyChange(),
 *    which is safe because the engine re-runs planChange() at apply immediately
 *    before applyChange(), and is VERIFIED rather than assumed: applyChange()
 *    re-hashes what it holds and refuses if it disagrees with the plan.
 * 4. THE TEMP FILE IS REMOVED ON EVERY PATH, via try/finally. A failed sideload
 *    leaves no bytes behind.
 *
 * NO SUPERGLOBAL IS READ. `$_FILES` is never consulted; the payload arrives as
 * base64 through the ordinary argument channel and is validated as such.
 *
 * NOTHING HERE FETCHES A REMOTE URL. REQ-0052, media import from a URL, is
 * deliberately absent: it is a server-side request forgery surface and is
 * blocked pending an independent review.
 *
 * WHAT IT PROMISES: `mimeType`, `title`, `alt`, `caption`, `description`.
 *
 * `filename` IS DELIBERATELY NOT PROMISED. WordPress uniquifies on collision —
 * `photo.png` becomes `photo-1.png` — which is correct behaviour, but a promised
 * filename would make WriteVerifier classify every collision as an ADJUSTMENT and
 * emit a warning on a completely routine event. The stored filename is disclosed
 * in `data.state`, which readBack() populates. The rule: promise what the caller
 * specified or what the content determines; report what WordPress may adjust for
 * uniqueness.
 *
 * `parent` IS ALSO NOT PROMISED, and is passed to wp_insert_attachment as given.
 * The spec does not ask this operation to validate it — REQ-0025 `media-attach`
 * is the operation for associating an asset with a post, and it does validate.
 *
 * AN UPLOAD CANNOT BE ROLLED BACK. That is the designed outcome, not a gap. The
 * alternative is a restore path that deletes an attachment and its files from
 * disk, which is a destructive operation wearing a rollback's clothes and would
 * force isDestructive true and all three policies to required. An operator who
 * wants an uploaded asset gone deletes it in WordPress, where the confirmation
 * and the trash exist.
 *
 * @package SiteHelm
 */
final class MediaUpload implements WriteOperation {

	/**
	 * The presentation order of the promised fields.
	 *
	 * Local to this operation rather than on MediaFields, because it is the
	 * order of what an UPLOAD promises, which is a subset of the projection and
	 * not the projection's own order.
	 */
	private const FIELD_ORDER = [ 'mimeType', 'title', 'alt', 'caption', 'description' ];

	/**
	 * The optional text fields a caller may name, mapped to the projection keys
	 * they are promised and verified under.
	 */
	private const TEXT_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

	/**
	 * The validated bytes of the payload planChange() last inspected.
	 *
	 * Deliberately NOT readonly and deliberately NOT in the planned payload; see
	 * points 2 and 3 in the class docblock. Cleared in applyChange()'s finally
	 * block so a request never holds an image longer than the write that needs
	 * it.
	 *
	 * @var string|null
	 */
	private ?string $pending_bytes = null;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-upload.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-upload',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Add one base64-encoded image to the media library, with optional title, alternative text, caption, and description.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'filename'      => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Filename for the new library asset, including its extension. WordPress may adjust it for uniqueness.',
					],
					'contentBase64' => [
						'type'        => 'string',
						'maxLength'   => MediaMimeGuard::MAX_BASE64_LENGTH,
						'description' => 'The file content, base64 encoded. The file type is determined from the content, never from a declared type.',
					],
					'title'         => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Title of the new library asset.',
					],
					'alt'           => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Alternative text of the new library asset.',
					],
					'caption'       => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Caption of the new library asset.',
					],
					'description'   => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Description of the new library asset.',
					],
					'parent'        => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item this asset belongs to, or 0 for none.',
					],
				],
				'required'             => [ 'filename', 'contentBase64' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-upload',
				'arguments' => [
					'filename'      => 'holiday.png',
					'contentBase64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
					'title'         => 'Holiday photo',
					'alt'           => 'A beach at sunset',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields    $fields  The attachment projection.
	 * @param MediaTarget    $targets Shared target resolution.
	 * @param MediaMimeGuard $guard   Upload byte validation.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
		private readonly MediaMimeGuard $guard,
	) {
	}

	/**
	 * An upload's target does not exist yet, so it resolves to the pending key.
	 *
	 * The literal `attachment:new` is stable across preview and apply, which is
	 * what lets PlanAdmission::assertTargetMatches() pass on a string compare
	 * without needing an id that does not exist yet.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	/**
	 * Validates the payload entirely in memory and builds the promise.
	 *
	 * NOTHING HERE TOUCHES DISK. This method runs at preview, and a preview that
	 * writes a file is not a preview.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the payload
	 *                           cannot become a file on this site.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$inspected = $this->guard->inspect(
			(string) ( $input['filename'] ?? '' ),
			(string) ( $input['contentBase64'] ?? '' )
		);

		$this->pending_bytes = $inspected['bytes'];

		$promised = [ 'mimeType' => $inspected['mimeType'] ];
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$promised[ $field ] = $this->sanitize_field( $field, (string) $input[ $field ] );
			}
		}

		// The bytes are represented by their hash, never by themselves. See
		// point 2 in the class docblock: raw bytes here would collapse every
		// upload's payload fingerprint to the same value.
		$payload = $promised + [
			'contentSha256' => hash( 'sha256', $inspected['bytes'] ),
			'byteLength'    => strlen( $inspected['bytes'] ),
			'filename'      => $inspected['filename'],
			'extension'     => $inspected['extension'],
			'parent'        => (int) ( $input['parent'] ?? 0 ),
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $promised, self::FIELD_ORDER );
	}

	/**
	 * An upload has no prior state, so there is nothing to capture.
	 *
	 * The contract's `supported` snapshot policy covers exactly this: creation
	 * style writes proceed without a snapshot, and the result then omits the
	 * rollback reference.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp_handle_sideload() requires a real temporary file, which WP_Filesystem cannot produce for it.
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failure detail goes to the server log precisely so it never reaches the envelope.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Writes the validated bytes and creates the attachment.
	 *
	 * The temp file is removed in a `finally` block, so a sideload that fails —
	 * or a core function that throws — leaves nothing on disk. That is pinned by
	 * test_a_failed_sideload_leaves_no_bytes_behind.
	 *
	 * Every failure reports execution_failed with a message that names nothing:
	 * no path, no directory, no core error string. The detail goes to error_log,
	 * correlated by the request's correlation id.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created attachment's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$bytes = (string) $this->pending_bytes;

		// The bytes this instance holds must be the bytes the approved plan
		// describes. planChange() re-runs immediately before this method, so a
		// mismatch means the coupling between them has been broken by an edit,
		// and writing anything at that point would write an unreviewed file.
		if ( '' === $bytes || hash( 'sha256', $bytes ) !== (string) ( $planned->payload['contentSha256'] ?? '' ) ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved upload could not be matched to its reviewed content.',
				'Request a fresh preview and approve it again; nothing was uploaded.',
				[ 'plan approved' ]
			);
		}

		$this->load_admin_upload_apis();

		$temp = wp_tempnam( (string) $planned->payload['filename'] );
		if ( ! is_string( $temp ) || '' === $temp ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not prepare temporary storage for the upload.',
				'Ask a site administrator to check the site\'s temporary directory, then request a fresh preview.',
				[ 'plan approved' ]
			);
		}

		try {
			// One comparison, not two. file_put_contents() returns int|false and
			// strlen() returns int, so `false !== strlen( $bytes )` is always
			// true: a separate `false === $written` clause would short-circuit
			// this one and could never change the outcome. Testing the byte count
			// covers the outright failure and the partial write together.
			$written = file_put_contents( $temp, $bytes );
			if ( strlen( $bytes ) !== $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'This site could not write the uploaded content to temporary storage.',
					'Ask a site administrator to check the site\'s available disk space, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$sideload = wp_handle_sideload(
				[
					'name'     => (string) $planned->payload['filename'],
					'type'     => (string) $planned->payload['mimeType'],
					'tmp_name' => $temp,
					'error'    => 0,
					'size'     => (int) $planned->payload['byteLength'],
				],
				[ 'test_form' => false ]
			);

			if ( ! is_array( $sideload ) || isset( $sideload['error'] ) || ! isset( $sideload['file'] ) ) {
				error_log(
					sprintf(
						'SiteHelm media-upload sideload failed [%s]: %s',
						$context->correlationId,
						is_array( $sideload ) ? (string) ( $sideload['error'] ?? 'no file returned' ) : 'no result'
					)
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to store the uploaded content.',
					'Ask a site administrator to check the media library settings, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$attachment_id = wp_insert_attachment(
				wp_slash(
					[
						'post_mime_type' => (string) $sideload['type'],
						'post_title'     => (string) ( $planned->payload['title'] ?? $planned->payload['filename'] ),
						'post_excerpt'   => (string) ( $planned->payload['caption'] ?? '' ),
						'post_content'   => (string) ( $planned->payload['description'] ?? '' ),
						'post_status'    => 'inherit',
						'post_parent'    => (int) $planned->payload['parent'],
					]
				),
				(string) $sideload['file'],
				(int) $planned->payload['parent'],
				true
			);

			if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
				error_log(
					sprintf( 'SiteHelm media-upload insert failed [%s].', $context->correlationId )
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored the uploaded content but refused to add it to the media library.',
					'Ask a site administrator to check the media library, then request a fresh preview.',
					[ 'plan approved', 'content stored' ]
				);
			}

			$attachment_id = (int) $attachment_id;

			$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $sideload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			if ( array_key_exists( 'alt', $planned->payload ) ) {
				update_post_meta(
					$attachment_id,
					MediaFields::ALT_META_KEY,
					wp_slash( (string) $planned->payload['alt'] )
				);
			}

			return $this->fields->targetKey( $attachment_id );
		} finally {
			$this->pending_bytes = null;
			wp_delete_file( $temp );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Re-reads the created attachment for verification.
	 *
	 * This is what discloses the STORED filename in `data.state`, including a
	 * uniquified one, without the operation having promised it.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The method never returns; it always throws.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * An upload cannot be reversed by restoring prior state, because there was
	 * none, and the reversal that would exist instead — deleting an attachment
	 * and its files from disk — is destruction, not rollback.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly uploaded library asset has no prior state to restore.',
			'Delete the asset in the WordPress media library if it should not exist.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Sanitizes one optional text field the way it will be stored.
	 *
	 * The promise must equal what comes back out, or WriteVerifier reports a
	 * routine sanitization as an adjustment. Title and alternative text are
	 * plain text; caption and description carry the post-content HTML rules,
	 * because that is the column each lands in.
	 *
	 * @param string $field The field name.
	 * @param string $value The requested value.
	 *
	 * @return string The value as it will be stored.
	 */
	private function sanitize_field( string $field, string $value ): string {
		return in_array( $field, [ 'title', 'alt' ], true )
			? (string) sanitize_text_field( $value )
			: (string) wp_kses_post( $value );
	}

	/**
	 * Loads the administration-side upload APIs when the request has not.
	 *
	 * Both wp_handle_sideload() and wp_generate_attachment_metadata() live in
	 * wp-admin includes, which a REST or front-end request does not load. The
	 * `require_once` body below is the only part of this class that unit tests
	 * cannot cover: Brain Monkey defines both functions, so the guard is always
	 * satisfied and the body is never entered. It is the single uncovered
	 * statement this class contributes, counted and declared in this task's
	 * coverage report rather than hidden.
	 */
	private function load_admin_upload_apis(): void {
		if ( function_exists( 'wp_handle_sideload' ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
