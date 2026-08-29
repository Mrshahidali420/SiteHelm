<?php
/**
 * SVG upload write operation.
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
 * REQ-0105: SVG upload. A designer's icon set reaches the media library through
 * an AI client, and Elementor's icon and image controls can then point at it.
 *
 * THE ONLY PATH THAT MAY STORE MARKUP. `media-upload` and `media-import` deny
 * `image/svg+xml` and the `svg` extension outright and continue to, because they
 * accept content they only sniff. This operation is the exception, and it earns
 * it by never storing what it was given: SvgSanitizer rebuilds the document from
 * an element allowlist and an attribute rule set, and the bytes that reach disk
 * are the rebuilt ones.
 *
 * IT GATES ON `unfiltered_html` AS WELL AS `upload_files`. An SVG renders in the
 * site's own origin, so storing one is closer to publishing markup than to
 * uploading a photograph, and WordPress already has a capability that means
 * exactly "this person may publish markup". On a single site that is an
 * administrator and an editor; on multisite it is a super admin alone, which is
 * the correct answer there and needs no special case here. The sanitiser is the
 * safety; this is the second lock, so that a mistake in the first one is not the
 * only thing standing between an author account and a stored script.
 *
 * THE SANITISED DOCUMENT IS WHAT IS REVIEWED. `previewDetail` carries the exact
 * bytes that will be stored and a list of everything removed, and the payload's
 * `contentSha256` binds those bytes rather than the submitted ones. An operator
 * approving this preview is approving the file that will exist, not the file
 * that was sent. Every removal is also a warning, so a caller cannot fail to
 * notice that their file was changed on the way in.
 *
 * THE OTHER FOUR PROPERTIES ARE MediaUpload's, unchanged, and for its reasons:
 * all validation is in planChange() and runs at preview and at apply; the plan
 * carries a hash of the bytes rather than the bytes; the bytes ride on a private
 * property and are re-hashed against the plan before anything is written; and
 * the temporary file is removed on every path by MediaSideload's try/finally.
 * MediaUpload's docblock explains each; nothing about SVG changes them.
 *
 * AN UPLOAD CANNOT BE ROLLED BACK, for MediaUpload's reason: the reversal is a
 * deletion, and a deletion wearing a rollback's clothes is not a rollback.
 *
 * @package SiteHelm
 */
final class MediaSvgUpload implements WriteOperation {

	/**
	 * The MIME type this operation, and only this operation, may store.
	 */
	public const MIME_TYPE = 'image/svg+xml';

	/**
	 * The one extension it may store it under.
	 *
	 * `svgz` is deliberately absent: it is a gzipped SVG, and accepting one would
	 * mean either storing bytes the sanitiser never read or inflating attacker
	 * input in memory to read them.
	 */
	public const EXTENSION = 'svg';

	/**
	 * The input property carrying the document.
	 */
	public const INPUT_CONTENT = 'content';

	/**
	 * The sanitised bytes the last plan approved.
	 *
	 * Deliberately not readonly and deliberately not in the planned payload, for
	 * the reasons MediaUpload's docblock sets out. Cleared in applyChange()'s
	 * finally block.
	 *
	 * @var string|null
	 */
	private ?string $pending_bytes = null;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-svg-upload.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-svg-upload',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Add one SVG image to the media library. The document is rebuilt from a safe subset before it is stored, and everything removed is reported.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'filename'          => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Filename for the new library asset, ending in .svg. WordPress may adjust it for uniqueness.',
					],
					self::INPUT_CONTENT => [
						'type'        => 'string',
						'maxLength'   => SvgSanitizer::MAX_BYTES,
						'description' => 'The SVG document itself, as text. Scripts, event handlers, embedded HTML, external references, and stylesheets are removed before it is stored.',
					],
					'title'             => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Title of the new library asset.',
					],
					'alt'               => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Alternative text of the new library asset.',
					],
					'caption'           => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Caption of the new library asset.',
					],
					'description'       => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Description of the new library asset.',
					],
					'parent'            => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item this asset belongs to, or 0 for none.',
					],
				],
				'required'             => [ 'filename', self::INPUT_CONTENT ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files', 'unfiltered_html' ],
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
				'operation' => 'media-svg-upload',
				'arguments' => [
					'filename'          => 'check.svg',
					self::INPUT_CONTENT => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 12l6 6L20 6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
					'title'             => 'Check mark',
					'alt'               => 'A check mark',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaTarget    $targets   Shared target resolution.
	 * @param SvgSanitizer   $sanitizer The document rebuilder.
	 * @param MediaAssetPlan $planner   Shared payload construction.
	 * @param MediaSideload  $sideload  Shared attachment creation.
	 */
	public function __construct(
		private readonly MediaTarget $targets,
		private readonly SvgSanitizer $sanitizer,
		private readonly MediaAssetPlan $planner,
		private readonly MediaSideload $sideload,
	) {
	}

	/**
	 * An upload's target does not exist yet, so it resolves to the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $plan->afterFields is the PlannedChange contract's own property name.
	/**
	 * Rebuilds the document in memory and promises what will be stored.
	 *
	 * NOTHING HERE TOUCHES DISK. The filename is checked first, because a caller
	 * who named a `.png` should learn that before a parser has read their file.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the document
	 *                            cannot become a file on this site.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$filename = $this->filename( (string) ( $input['filename'] ?? '' ) );
		$cleaned  = $this->sanitizer->sanitize( (string) ( $input[ self::INPUT_CONTENT ] ?? '' ) );

		$this->pending_bytes = $cleaned['svg'];

		$plan = $this->planner->plan(
			[
				'bytes'     => $cleaned['svg'],
				'filename'  => $filename,
				'mimeType'  => self::MIME_TYPE,
				'extension' => self::EXTENSION,
			],
			$input
		);

		return new PlannedChange(
			$plan->payload,
			$plan->afterFields,
			MediaAssetPlan::FIELD_ORDER,
			$cleaned['warnings'],
			[
				'storedDocument'    => $cleaned['svg'],
				'storedByteLength'  => strlen( $cleaned['svg'] ),
				'removedElements'   => $cleaned['removedElements'],
				'removedAttributes' => $cleaned['removedAttributes'],
			]
		);
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The sanitized filename, which must carry the one permitted extension.
	 *
	 * The extension is checked AFTER sanitization, so `icon.svg.php` — whose
	 * pathinfo extension is `php` — is refused here rather than being cleaned
	 * into something that looks acceptable.
	 *
	 * @param string $requested The client-supplied filename.
	 *
	 * @return string The sanitized filename.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function filename( string $requested ): string {
		$safe = (string) sanitize_file_name( $requested );

		if ( '' === $safe ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested filename contains no characters this site can store.',
				'Choose a filename made of letters, numbers, dots, hyphens, or underscores, and request a fresh preview.'
			);
		}

		if ( self::EXTENSION !== strtolower( (string) pathinfo( $safe, PATHINFO_EXTENSION ) ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This operation stores SVG images only, and the requested filename does not end in .svg.',
				'Give the file the .svg extension, or use media-upload for an image of another kind, and request a fresh preview.'
			);
		}

		return $safe;
	}

	/**
	 * An upload has no prior state, so there is nothing to capture.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	/**
	 * Hands the sanitised bytes to MediaSideload, having first re-checked them.
	 *
	 * The re-check is this operation asserting that the bytes IT holds are the
	 * bytes ITS OWN plan described — which for this operation means the rebuilt
	 * document, never the submitted one.
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

		if ( '' === $bytes || hash( 'sha256', $bytes ) !== (string) ( $planned->payload['contentSha256'] ?? '' ) ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved image could not be matched to its reviewed content.',
				'Request a fresh preview and approve it again; nothing was uploaded.',
				[ 'plan approved' ]
			);
		}

		try {
			// The one call in the codebase that passes an explicit type map to
			// the sideload. WordPress does not permit SVG uploads by default and
			// SiteHelm does not ask it to: widening the site's own upload
			// permissions would change what every OTHER upload path accepts,
			// including the WordPress media screen. The permission is granted
			// here, for this call, for the one type this operation has just
			// rebuilt from an allowlist.
			return $this->sideload->store(
				$bytes,
				$planned->payload,
				$context,
				'media-svg-upload',
				[ self::EXTENSION => self::MIME_TYPE ]
			);
		} finally {
			$this->pending_bytes = null;
		}
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Re-reads the created attachment for verification.
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
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * A newly uploaded asset has no prior state to restore.
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
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
