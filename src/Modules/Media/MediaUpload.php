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
 *    it binds the exact bytes. MediaAssetPlan builds it.
 * 3. THE BYTES RIDE ON A PRIVATE PROPERTY between planChange() and applyChange(),
 *    which is safe because the engine re-runs planChange() at apply immediately
 *    before applyChange(), and is VERIFIED rather than assumed: applyChange()
 *    re-hashes what it holds and refuses if it disagrees with the plan.
 * 4. THE TEMP FILE IS REMOVED ON EVERY PATH, by MediaSideload's try/finally. A
 *    failed sideload leaves no bytes behind.
 *
 * NO SUPERGLOBAL IS READ. `$_FILES` is never consulted; the payload arrives as
 * base64 through the ordinary argument channel and is validated as such.
 *
 * NOTHING HERE FETCHES A REMOTE URL. Remote fetching lives in REQ-0052
 * `media-import`, behind MediaUrlGuard; this operation still touches no network.
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
	 * Builds the planned payload this operation and media-import both promise.
	 *
	 * @var MediaAssetPlan
	 */
	private readonly MediaAssetPlan $planner;

	/**
	 * Writes the bytes and creates the attachment.
	 *
	 * @var MediaSideload
	 */
	private readonly MediaSideload $sideload;

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
	 * The two shared collaborators are optional and default to their own plain
	 * construction. Both are stateless value-shaped services with no
	 * alternative implementation, so a caller that does not care gets the only
	 * correct pair, and MediaModule still injects them explicitly so the wiring
	 * stays visible in one place.
	 *
	 * @param MediaFields         $fields   The attachment projection.
	 * @param MediaTarget         $targets  Shared target resolution.
	 * @param MediaMimeGuard      $guard    Upload byte validation.
	 * @param MediaAssetPlan|null $planner  Shared payload construction.
	 * @param MediaSideload|null  $sideload Shared attachment creation.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
		private readonly MediaMimeGuard $guard,
		?MediaAssetPlan $planner = null,
		?MediaSideload $sideload = null,
	) {
		$this->planner  = $planner ?? new MediaAssetPlan();
		$this->sideload = $sideload ?? new MediaSideload( $fields );
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

		return $this->planner->plan( $inspected, $input );
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
	/**
	 * Hands the validated bytes to MediaSideload, having first re-checked them.
	 *
	 * The re-check belongs here rather than in MediaSideload: it is this
	 * operation asserting that the bytes IT holds are the bytes ITS OWN plan
	 * described. MediaSideload removes the temp file on every path, so a
	 * sideload that fails — or a core function that throws — leaves nothing on
	 * disk. That is pinned by test_a_failed_sideload_leaves_no_bytes_behind.
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

		try {
			return $this->sideload->store( $bytes, $planned->payload, $context, 'media-upload' );
		} finally {
			$this->pending_bytes = null;
		}
	}
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
}
