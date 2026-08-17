<?php
/**
 * Oversized-image reduction write operation.
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
 * REQ-0072: bring an oversized asset within the sizes the theme actually
 * renders. A camera original at 6000x4000 is served in full to every visitor
 * whose browser asks for the full size, and no amount of correct markup makes
 * those bytes smaller. This reduces the served image to a bound the operator
 * names, which `image-size-list` (REQ-0022) reports for this site.
 *
 * THE ORIGINAL FILE IS NEVER OVERWRITTEN AND NEVER DELETED, and every other
 * decision here follows from that. The reduced image is written to a NEW file
 * beside the original, the attachment is re-pointed at it, and the original is
 * recorded in the attachment metadata under `original_image` — which is
 * WordPress core's own vocabulary for exactly this state, produced by the
 * big-image threshold since 5.3, and read by wp_get_original_image_path(). So
 * the media library, the editor, and any plugin that already understands a
 * `-scaled` file keep working, and a rollback has a real file to point back at.
 * REQ-0056 excludes irreversible deletion from this plugin permanently; an
 * operation that reduced an image by destroying its only copy would be that
 * exclusion in all but name.
 *
 * WHICH ORIGINAL. wp_get_original_image_path() is asked for the source bytes,
 * never get_attached_file(). On an attachment core has already scaled, or one
 * this operation has already reduced, the attached file is a derivative — and
 * reducing a derivative loses detail that reducing the original would not.
 * Recording the original's basename forward is the other half of that: a second
 * reduction must not re-point `original_image` at the first reduction's output,
 * or the true original becomes unreachable while every read still reports
 * success.
 *
 * THE DESTINATION NAME IS MADE UNIQUE BEFORE THE SAVE. WP_Image_Editor's
 * generate_filename() does not consult the directory, so a second reduction to
 * the same bound would compute the same name and save() would overwrite the file
 * a live snapshot points at. wp_unique_filename() is what stops a rollback from
 * finding a different image than the one it recorded.
 *
 * NOT DESTRUCTIVE, AND DELIBERATELY HIGH RISK. Nothing is removed, and the
 * snapshot restores the attachment to the file and metadata it had, so
 * `isDestructive` stays false. Risk is high anyway, because the change is
 * visible on every page that renders the asset and the operator will not see it
 * until they look at the front end.
 *
 * IDEMPOTENT BY REFUSAL. An image already within the requested bound is refused
 * rather than re-saved, so a retried request cannot reduce twice and compound
 * the loss. That is what `isIdempotent` protects against here: not that the
 * second call succeeds, but that it cannot change anything.
 *
 * @package SiteHelm
 */
final class MediaResize implements WriteOperation {

	/**
	 * The operation identifier, used in this class's own server-log lines.
	 */
	public const OPERATION_ID = 'media-resize';

	/**
	 * The input naming the widest the served image may be.
	 */
	public const INPUT_MAX_WIDTH = 'maxWidth';

	/**
	 * The input naming the tallest the served image may be.
	 */
	public const INPUT_MAX_HEIGHT = 'maxHeight';

	/**
	 * The largest bound a caller may name on either axis.
	 *
	 * Not a limit on the image — it is a limit on the ARGUMENT. A bound larger
	 * than any real photograph can only ever be a no-op, and a no-op that costs
	 * a full image decode is worth refusing at the schema.
	 */
	public const MAX_BOUND = 10000;

	/**
	 * The two fields this operation promises. Both must be keys
	 * MediaFields::read() projects, or verification compares the promise against
	 * nothing.
	 *
	 * @var string[]
	 */
	private const PROMISED_FIELDS = [ 'width', 'height' ];

	/**
	 * The attachment metadata key holding the untouched original's basename.
	 *
	 * Core's own key, not one this plugin invented, which is why
	 * wp_get_original_image_path() reads what this operation writes.
	 */
	private const ORIGINAL_IMAGE_KEY = 'original_image';

	/**
	 * The snapshot member holding the attached-file path this write replaced.
	 */
	private const SNAPSHOT_FILE = 'attached_file';

	/**
	 * The snapshot member holding the attachment metadata this write replaced.
	 */
	private const SNAPSHOT_METADATA = 'metadata';

	/**
	 * The steps that have completed by the time the editor is opened.
	 *
	 * @var string[]
	 */
	private const STEPS_BEFORE_WRITE = [ 'plan approved', 'snapshot captured' ];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-resize.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Reduce an oversized image so the size this site serves fits within a width and height you name, keeping the original file.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'                   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library image to bring down.',
					],
					self::INPUT_MAX_WIDTH  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_BOUND,
						'description' => 'The widest the served image may be, in pixels. Omit to bound by height alone.',
					],
					self::INPUT_MAX_HEIGHT => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_BOUND,
						'description' => 'The tallest the served image may be, in pixels. Omit to bound by width alone.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post', 'upload_files' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'id'                   => 108,
					self::INPUT_MAX_WIDTH  => 1568,
					self::INPUT_MAX_HEIGHT => 1568,
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->fields and $current->exists are the TargetState contract's own property names.
	/**
	 * Computes the reduced dimensions and promises them.
	 *
	 * FOUR REFUSALS, AND EACH ONE IS A DIFFERENT THING THE OPERATOR CAN ACT ON:
	 * no bound was named, the item is not an image this site measured, the bound
	 * would not bring it down, and the source file cannot be found. Collapsing
	 * them into one message would leave an operator staring at a photograph and a
	 * refusal that does not say which of the four it is.
	 *
	 * THE ALREADY-SMALL CASE REFUSES rather than writing a no-op. A write that
	 * changes nothing still re-saves the file, still regenerates every rendition,
	 * and still records a rollback reference for a change that was never made.
	 * Refusing is what makes a repeated request harmless, which is the whole of
	 * this operation's idempotence claim.
	 *
	 * wp_constrain_dimensions() does the arithmetic, because it is what core uses
	 * everywhere else: it preserves the aspect ratio, and it never upscales — an
	 * unbounded axis is passed as 0, which that function reads as "no limit".
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no bound was
	 *                           named or the item is not a measured image, or
	 *                           ErrorCode::Conflict when the image is already
	 *                           within the bound or its source file is missing.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $context );

		$max_width  = $this->bound( $input, self::INPUT_MAX_WIDTH );
		$max_height = $this->bound( $input, self::INPUT_MAX_HEIGHT );

		if ( 0 === $max_width && 0 === $max_height ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No maximum width or height was supplied, so there is no size to bring this image within.',
				'Name a maximum width, a maximum height, or both. Call image-size-list to see the sizes this site renders.'
			);
		}

		$width  = $this->measured( $current->fields, 'width' );
		$height = $this->measured( $current->fields, 'height' );

		if ( null === $width || null === $height ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This media item is not an image whose dimensions this site has recorded, so it cannot be brought down.',
				'Choose an image from the media library, or ask a site administrator to regenerate its metadata.'
			);
		}

		$reduced = wp_constrain_dimensions( $width, $height, $max_width, $max_height );
		$reduced = [ (int) ( $reduced[0] ?? 0 ), (int) ( $reduced[1] ?? 0 ) ];

		if ( $reduced[0] >= $width && $reduced[1] >= $height ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'This image already fits within the requested maximum, so there is nothing to bring down.',
				'Name a smaller maximum width or height, or leave this image as it is.'
			);
		}

		$source = $this->source_path( $current );

		$payload = [
			self::INPUT_MAX_WIDTH  => $max_width,
			self::INPUT_MAX_HEIGHT => $max_height,
			'width'                => $reduced[0],
			'height'               => $reduced[1],
			'sourceBasename'       => wp_basename( $source ),
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			[
				'width'  => $reduced[0],
				'height' => $reduced[1],
			],
			self::PROMISED_FIELDS,
			[],
			[
				'from' => [
					'width'  => $width,
					'height' => $height,
				],
				'to'   => [
					'width'  => $reduced[0],
					'height' => $reduced[1],
				],
			]
		);
	}

	/**
	 * Captures the attached file and metadata this write is about to replace.
	 *
	 * BOTH, NOT EITHER. Re-pointing `_wp_attached_file` without putting the
	 * metadata back would leave the original file serving under the reduced
	 * image's recorded width and height — every size a template asks for computed
	 * from numbers that no longer describe the file. Restoring the metadata
	 * without the file is the same wrong state seen from the other side.
	 *
	 * The metadata is recorded whole rather than field by field, because it is
	 * the input wp_update_attachment_metadata() takes, and a rebuilt subset is a
	 * rebuilt subset: `image_meta`, `filesize`, and every rendition a plugin
	 * registered would silently not come back.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads only, and the engine
	 * calls it in both phases.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no identifiable prior state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $context );

		if ( ! $current->exists ) {
			return null;
		}

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$snapshot = [
			'post_id'               => $attachment_id,
			self::SNAPSHOT_FILE     => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			self::SNAPSHOT_METADATA => is_array( $metadata ) ? $metadata : [],
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}

	/**
	 * Writes the reduced image, re-points the attachment, and measures the result.
	 *
	 * THE SOURCE IS RE-DERIVED HERE rather than taken from the payload's
	 * `sourceBasename`. The basename is in the payload so that a plan whose source
	 * changed between preview and apply is refused by the plan token's own
	 * argument binding; it is not a path, and treating a client-visible string as
	 * one would be a path-traversal surface for no gain.
	 *
	 * ORDER MATTERS ON THE WAY OUT: the file is saved first, then the attachment
	 * is re-pointed, then the metadata is rebuilt. A failure at any step leaves
	 * the steps before it recorded on the refusal, and leaves the original file
	 * exactly where it was — which is what makes the engine's compensation path
	 * able to put the attachment back.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the media item this write was planned against.',
				'Request a fresh preview and retry.',
				self::STEPS_BEFORE_WRITE
			);
		}

		$this->load_admin_image_apis();

		$source = $this->source_path( $current );
		$width  = (int) $planned->payload['width'];
		$height = (int) $planned->payload['height'];

		$saved = $this->save_reduced( $source, $width, $height, $context );

		$original = $this->original_basename( $attachment_id, $source );

		update_attached_file( $attachment_id, $saved );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $saved );
		if ( ! is_array( $metadata ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site stored the reduced image but could not describe it, so the media library was left unchanged.',
				'Ask a site administrator to regenerate this item\'s media metadata, then request a fresh preview.',
				array_merge( self::STEPS_BEFORE_WRITE, [ 'reduced image stored', 'media item re-pointed' ] )
			);
		}

		$metadata[ self::ORIGINAL_IMAGE_KEY ] = $original;
		wp_update_attachment_metadata( $attachment_id, $metadata );

		clean_post_cache( $attachment_id );
		$this->assert_reduced( $attachment_id, $width, $height );

		return $this->fields->targetKey( $attachment_id );
	}

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
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Points the attachment back at the file and metadata it had.
	 *
	 * MediaTarget::restoreFields() is deliberately not called: it writes post
	 * columns and alternative text, and this write touched neither. What it must
	 * put back is the attached-file pointer and the metadata array, and both are
	 * written here and then measured, because a restore has no WriteVerifier
	 * downstream to notice that it did not land.
	 *
	 * The reduced file is left on disk. A rollback that deleted it would make the
	 * change un-redoable and would be the irreversible deletion this plugin
	 * excludes; the cost is one orphaned file per rolled-back reduction, which an
	 * operator can remove from the media library by hand.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no item or no file, or
	 *                           ErrorCode::ExecutionFailed when the restore does
	 *                           not read back.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $context );

		$attachment_id = (int) ( $restoreState['post_id'] ?? 0 );
		$file          = (string) ( $restoreState[ self::SNAPSHOT_FILE ] ?? '' );

		if ( $attachment_id <= 0 || '' === $file ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify the image file to restore, so this reduction cannot be undone.',
				'Re-upload the image you want served, or restore it from a site backup.'
			);
		}

		$metadata = $restoreState[ self::SNAPSHOT_METADATA ] ?? null;
		if ( ! is_array( $metadata ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not describe the image it would restore, so this reduction cannot be undone.',
				'Re-upload the image you want served, or restore it from a site backup.'
			);
		}

		update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( $file ) );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		clean_post_cache( $attachment_id );
		$this->assert_restored( $attachment_id, $file );

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One bound from the input, or 0 when the caller named none on that axis.
	 *
	 * Zero is what wp_constrain_dimensions() reads as "no limit", so an omitted
	 * axis and an explicit zero would mean the same thing to it — which is why the
	 * schema's `minimum: 1` refuses the explicit zero before this is reached, and
	 * why this method never manufactures one from a non-numeric value.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 * @param string               $key   The bound to read.
	 *
	 * @return int The bound, or 0 when unbounded on this axis.
	 */
	private function bound( array $input, string $key ): int {
		if ( ! array_key_exists( $key, $input ) || ! is_numeric( $input[ $key ] ) ) {
			return 0;
		}

		$bound = (int) $input[ $key ];

		return $bound > 0 ? $bound : 0;
	}

	/**
	 * One recorded dimension, or null when the site never measured it.
	 *
	 * A recorded 0 is treated as "not measured" rather than as a dimension. Zero
	 * is not a width any image has, and passing it on would let
	 * wp_constrain_dimensions() report a reduction for a file nothing measured.
	 *
	 * @param array<string, mixed> $fields The projected attachment fields.
	 * @param string               $key    The dimension to read.
	 *
	 * @return int|null The dimension, or null.
	 */
	private function measured( array $fields, string $key ): ?int {
		if ( ! isset( $fields[ $key ] ) || ! is_numeric( $fields[ $key ] ) ) {
			return null;
		}

		$value = (int) $fields[ $key ];

		return $value > 0 ? $value : null;
	}

	/**
	 * The absolute path of the untouched original this reduction reads from.
	 *
	 * The source is wp_get_original_image_path() rather than get_attached_file(),
	 * so a second
	 * reduction reads the camera original rather than the first reduction's
	 * output. Reducing a derivative would throw away detail the original still
	 * holds, and would do it invisibly.
	 *
	 * A missing file is a Conflict rather than an InvalidInput: the request was
	 * well formed, and the site's own state is what stopped it. The message names
	 * no path, because a refusal that echoed one would report the site's directory
	 * layout to a caller who only supplied an identifier.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return string The absolute path.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 */
	private function source_path( TargetState $current ): string {
		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		$path          = null === $attachment_id ? '' : (string) wp_get_original_image_path( $attachment_id );

		if ( '' === $path || ! file_exists( $path ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The original file for this media item is not on this site, so there is nothing to bring down.',
				'Re-upload the image, then request a fresh preview.'
			);
		}

		return $path;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Editor failure detail goes to the server log precisely so it never reaches the envelope.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Writes the reduced image to a file that did not exist a moment ago.
	 *
	 * THE UNIQUE NAME IS THE POINT OF THIS METHOD. generate_filename() composes a
	 * name from the source and a suffix and never looks at the directory, so two
	 * reductions to the same bound compute the same name and the second save()
	 * silently overwrites the first — including when the first is the file a live
	 * snapshot recorded. wp_unique_filename() is the only thing standing between
	 * that and a rollback that restores a pointer to bytes that are no longer the
	 * bytes it recorded.
	 *
	 * The editor's own error strings are logged rather than reported. They name
	 * paths and library versions, and neither belongs in an envelope.
	 *
	 * @param string           $source  The absolute source path.
	 * @param int              $width   The reduced width.
	 * @param int              $height  The reduced height.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The absolute path of the reduced file.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function save_reduced( string $source, int $width, int $height, OperationContext $context ): string {
		$editor = wp_get_image_editor( $source );

		if ( is_wp_error( $editor ) ) {
			error_log(
				sprintf(
					'SiteHelm %s editor unavailable [%s]: %s',
					self::OPERATION_ID,
					$context->correlationId,
					$editor->get_error_message()
				)
			);

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not open the image for editing, so nothing was changed.',
				'Ask a site administrator to confirm this site has an image library installed, then request a fresh preview.',
				self::STEPS_BEFORE_WRITE
			);
		}

		$resized = $editor->resize( $width, $height, false );

		if ( is_wp_error( $resized ) ) {
			error_log(
				sprintf(
					'SiteHelm %s resize failed [%s]: %s',
					self::OPERATION_ID,
					$context->correlationId,
					$resized->get_error_message()
				)
			);

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not reduce the image, so nothing was changed.',
				'Ask a site administrator to check the site\'s image library and available memory, then request a fresh preview.',
				self::STEPS_BEFORE_WRITE
			);
		}

		$directory   = dirname( $source );
		$intended    = (string) $editor->generate_filename( $width . 'x' . $height, $directory );
		$destination = trailingslashit( $directory ) . wp_unique_filename( $directory, wp_basename( $intended ) );

		$saved = $editor->save( $destination );

		if ( is_wp_error( $saved ) || ! is_array( $saved ) || ! isset( $saved['path'] ) ) {
			error_log(
				sprintf(
					'SiteHelm %s save failed [%s]: %s',
					self::OPERATION_ID,
					$context->correlationId,
					is_wp_error( $saved ) ? $saved->get_error_message() : 'no path returned'
				)
			);

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not store the reduced image, so nothing was changed.',
				'Ask a site administrator to check the media directory\'s permissions and available disk space, then request a fresh preview.',
				self::STEPS_BEFORE_WRITE
			);
		}

		return (string) $saved['path'];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The basename this attachment's metadata must keep pointing at.
	 *
	 * An attachment core already scaled, or this operation already reduced,
	 * carries the TRUE original under `original_image`. Recomputing it from the
	 * file being read would re-point it at the previous derivative on the second
	 * reduction, and the camera original would become unreachable through
	 * wp_get_original_image_path() while every read still reported success. So a
	 * recorded value wins over the file in hand, always.
	 *
	 * @param int    $attachment_id The attachment identifier.
	 * @param string $source        The absolute path just read from.
	 *
	 * @return string The basename to record.
	 */
	private function original_basename( int $attachment_id, string $source ): string {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $metadata ) && isset( $metadata[ self::ORIGINAL_IMAGE_KEY ] )
			&& is_string( $metadata[ self::ORIGINAL_IMAGE_KEY ] )
			&& '' !== $metadata[ self::ORIGINAL_IMAGE_KEY ] ) {
			return $metadata[ self::ORIGINAL_IMAGE_KEY ];
		}

		return (string) wp_basename( $source );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	/**
	 * Refuses unless the media library now reports the reduced dimensions.
	 *
	 * Note that wp_update_attachment_metadata() returns false when the stored value is
	 * already identical, so its return is not a success signal — the same reason
	 * MediaTarget judges a restored alt by re-reading it rather than by the
	 * boolean. Measuring is the only thing that distinguishes "already correct"
	 * from "not written".
	 *
	 * @param int $attachment_id The attachment identifier.
	 * @param int $width         The promised width.
	 * @param int $height        The promised height.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_reduced( int $attachment_id, int $width, int $height ): void {
		$stored = $this->fields->read( $attachment_id );

		if ( null === $stored || (int) ( $stored['width'] ?? 0 ) !== $width
			|| (int) ( $stored['height'] ?? 0 ) !== $height ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site stored the reduced image but the media library still reports the previous size.',
				'Ask a site administrator to regenerate this item\'s media metadata, then request a fresh preview.',
				array_merge( self::STEPS_BEFORE_WRITE, [ 'reduced image stored', 'media item re-pointed' ] )
			);
		}
	}

	/**
	 * Refuses unless the attachment points back at the recorded file.
	 *
	 * The pointer is what a restore actually writes, so the pointer is what it
	 * measures. Comparing the projected width instead would pass on a site whose
	 * original and reduction happen to share a dimension.
	 *
	 * @param int    $attachment_id The attachment identifier.
	 * @param string $file          The recorded attached-file value.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_restored( int $attachment_id, string $file ): void {
		$stored = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( $stored !== $file ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site did not point the media item back at the image the snapshot recorded.',
				'Restore this image from a site backup, or re-upload it.',
				[ 'snapshot read' ]
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Loads the administration-side image APIs when the request has not.
	 *
	 * The function wp_generate_attachment_metadata() lives in wp-admin includes, which a REST
	 * request does not load. As in MediaSideload, the `require_once` body is the
	 * one statement unit tests cannot enter: Brain Monkey defines the function, so
	 * the guard is always satisfied.
	 */
	private function load_admin_image_apis(): void {
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
