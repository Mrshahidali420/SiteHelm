<?php
/**
 * Media import write operation.
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
 * REQ-0052: media import. An agency operator adds a client-approved asset to the
 * library by naming the public URL it is published at, rather than by pasting
 * megabytes of base64 through the argument channel.
 *
 * It is `media-upload` with a different delivery route for the bytes. Everything
 * from "we have validated bytes" onward — the promise, the payload, the hash
 * binding, the temp file, the attachment — is the same code, in MediaAssetPlan
 * and MediaSideload. What is new is only how the bytes arrive, and that is the
 * whole of the risk.
 *
 * THE FETCH IS INSIDE planChange(), DELIBERATELY, AND THAT IS THE DESIGN
 * (spec §2). The change engine runs planChange() at preview AND again at apply,
 * and PlanAdmission::assertPayloadMatches() refuses when the second payload
 * differs from the approved one. Because the payload carries `contentSha256` of
 * the bytes THIS run fetched, a remote whose content changed between the two
 * phases produces a different payload and the plan is refused as stale. Fetching
 * once, at preview, and re-using the bytes at apply would mean approving one
 * asset and storing whatever the remote served the first time; fetching only at
 * apply would mean a preview that reviewed nothing. Two requests per import is
 * the price of a preview that means something, and the operation's description
 * says so to the caller.
 *
 * THE BYTES GET NO TRUST FROM HAVING BEEN FETCHED. A 200 response from a host
 * that passed MediaUrlGuard is a delivery, not a warrant. The response is handed
 * to MediaMimeGuard::inspectBytes() exactly as a base64 argument would be, and
 * the declared `Content-Type` is never consulted at any point: the type is
 * sniffed from the content, the extension must agree with the sniffed type, and
 * the extension deny list runs before either. A PHP script served as
 * `image/png` is refused for what it is, not for what it claimed.
 *
 * THE FILENAME IS NEVER INVENTED. It is the caller's `filename` when supplied,
 * otherwise the basename of the URL's path — and when that yields nothing usable
 * the import is refused rather than guessed at. A guessed extension would be a
 * claim competing with the content, and MediaMimeGuard's deny list keys off the
 * extension, so an invented one is a guard supplied with its own input.
 *
 * The three properties that make `media-upload` safe hold here unchanged and are
 * pinned by their own tests: all validation runs in memory inside planChange()
 * so nothing touches disk during a preview; the planned payload carries a HASH
 * of the bytes and never the bytes, because raw bytes would collapse every
 * payload fingerprint to sha256( '' ); and the bytes ride on a private property
 * between planChange() and applyChange(), which applyChange() re-hashes and
 * refuses to write if it disagrees with the plan it was handed.
 *
 * WHAT IT PROMISES: `mimeType`, `title`, `alt`, `caption`, `description`.
 * `filename` is deliberately not promised — WordPress uniquifies on collision,
 * and a promised filename would make every collision read as an adjustment. The
 * stored filename is disclosed in `data.state`, which readBack() populates.
 * `sourceUrl` is not promised either: it is in the PAYLOAD, where it binds the
 * plan to the source that was reviewed. It is not an attachment field, so there
 * is nothing to read back and verify it against.
 *
 * AN IMPORT CANNOT BE ROLLED BACK, for the same reason an upload cannot. The
 * reversal that would exist instead — deleting an attachment and its files from
 * disk — is a destructive operation wearing a rollback's clothes, and would
 * force isDestructive true and all three policies to required. An operator who
 * wants an imported asset gone deletes it in WordPress, where the confirmation
 * and the trash exist.
 *
 * NO REFUSAL FROM THIS OPERATION DISCLOSES AN ADDRESS. Not a resolved IP, not a
 * redirect target, not a response header, not a transport error string — those
 * are the four things an attacker harvests from a blind SSRF probe, and leaking
 * them turns a refused fetch into an internal port scanner. The detail goes to
 * the server log under the correlation id, in MediaUrlGuard and MediaFetch.
 *
 * @package SiteHelm
 */
final class MediaImport implements WriteOperation {

	/**
	 * The validated bytes of the asset planChange() last fetched and inspected.
	 *
	 * Deliberately NOT readonly and deliberately NOT in the planned payload; see
	 * the class docblock. Cleared in applyChange() on every path, success and
	 * refusal alike, so a request never holds an image longer than the write that
	 * needs it.
	 *
	 * @var string|null
	 */
	private ?string $pending_bytes = null;

	/**
	 * Builds the planned payload this operation and media-upload both promise.
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
	 * @return OperationDefinition The definition registered for media-import.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-import',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Add one image to the media library by fetching it from a public http or https address, with optional title, alternative text, caption, and description. The address is fetched once while the preview is prepared and fetched again when the approved plan is applied, and an address whose content changed between the two is refused rather than stored.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'url'         => [
						'type'        => 'string',
						'format'      => 'uri',
						'maxLength'   => 2048,
						'description' => 'Public http or https address of the image to import. The address must resolve to a publicly reachable host.',
					],
					'filename'    => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Filename for the new library asset, including its extension. Defaults to the last path segment of the address. WordPress may adjust it for uniqueness.',
					],
					'title'       => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Title of the new library asset.',
					],
					'alt'         => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Alternative text of the new library asset.',
					],
					'caption'     => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Caption of the new library asset.',
					],
					'description' => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Description of the new library asset.',
					],
					'parent'      => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item this asset belongs to, or 0 for none.',
					],
				],
				'required'             => [ 'url' ],
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
				'operation' => 'media-import',
				'arguments' => [
					'url'   => 'https://cdn.example.com/photos/holiday.png',
					'title' => 'Holiday photo',
					'alt'   => 'A beach at sunset',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * The two shared collaborators are optional and default to their own plain
	 * construction, matching MediaUpload: both are stateless value-shaped
	 * services with no alternative implementation, so a caller that does not care
	 * gets the only correct pair, and MediaModule still injects them explicitly
	 * so the wiring stays visible in one place.
	 *
	 * @param MediaFields         $fields   The attachment projection, used only to
	 *                                      construct the default sideload.
	 * @param MediaTarget         $targets  Shared target resolution.
	 * @param MediaMimeGuard      $guard    Content byte validation.
	 * @param MediaUrlGuard       $urls     The address policy every URL passes.
	 * @param MediaFetch          $fetch    The bounded, pinned remote fetch.
	 * @param MediaAssetPlan|null $planner  Shared payload construction.
	 * @param MediaSideload|null  $sideload Shared attachment creation.
	 */
	public function __construct(
		MediaFields $fields,
		private readonly MediaTarget $targets,
		private readonly MediaMimeGuard $guard,
		private readonly MediaUrlGuard $urls,
		private readonly MediaFetch $fetch,
		?MediaAssetPlan $planner = null,
		?MediaSideload $sideload = null,
	) {
		$this->planner  = $planner ?? new MediaAssetPlan();
		$this->sideload = $sideload ?? new MediaSideload( $fields );
	}

	/**
	 * An import's target does not exist yet, so it resolves to the pending key.
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	/**
	 * Validates the address, fetches it, validates the bytes, and builds the
	 * promise.
	 *
	 * THE ORDER IS THE DESIGN. The address is refused before a single packet
	 * leaves this machine, so a URL this site will not fetch never becomes a
	 * request at all — a guard that ran after the fetch would already have
	 * performed the probe it exists to prevent. Then the bytes are inspected as
	 * untrusted content, and only then does anything become a plan.
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
	 * @throws OperationException With ErrorCode::InvalidInput when the address or
	 *                           the content is one this site will not import, and
	 *                           ErrorCode::ExecutionFailed when the fetch itself
	 *                           fails.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$validated = $this->urls->validate( (string) ( $input['url'] ?? '' ), $context->correlationId );

		// Read from the address the GUARD RETURNED rather than from the raw
		// argument, so the name and the checks that approved it are taken from one
		// string. The two cannot be made to disagree today — core's
		// wp_http_validate_url() refuses outright any address whose normalisation
		// altered more than the case of its scheme, so no input produces two
		// different basenames — which is why no test distinguishes them. The
		// guarantee this line actually buys is forward-looking: a guard that one
		// day rewrites more of the address does not leave the filename behind on a
		// spelling nothing examined.
		$filename = $this->filename_for( $validated['url'], $input );

		$bytes = $this->fetch->fetch( $validated, $context->correlationId );

		// The bytes held between the phases are THE GUARD'S, not the fetched ones.
		// inspectBytes() hands back its own argument unchanged today, which is why
		// no test can tell this assignment from `= $bytes`; the premise is pinned
		// by test_the_content_guard_hands_back_the_bytes_it_was_given, so a guard
		// that starts normalising what it validates cannot quietly leave this
		// operation holding the unnormalised copy.
		$inspected           = $this->guard->inspectBytes( $filename, $bytes );
		$this->pending_bytes = $inspected['bytes'];

		return $this->planner->plan( $inspected, $input, $validated['url'] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * An import has no prior state, so there is nothing to capture.
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
	 * Hands the fetched bytes to MediaSideload, having first re-checked them.
	 *
	 * The re-check belongs here rather than in MediaSideload: it is this
	 * operation asserting that the bytes IT holds are the bytes ITS OWN plan
	 * described. planChange() has just re-run — and therefore just re-fetched —
	 * immediately before this method, so a mismatch means the coupling between
	 * them has been broken by an edit, and writing anything at that point would
	 * write an unreviewed file.
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

		// The empty clause cannot fire through ChangeEngine today: apply() re-runs
		// planChange() and hands applyChange() THAT plan, MediaFetch refuses an
		// empty body, and MediaAssetPlan digests only what the guard inspected, so
		// no genuine plan carries hash('sha256',''). It is kept because nothing in
		// the type system stops a future caller from constructing a PlannedChange
		// directly, and matches the same clause in MediaUpload::applyChange(). The
		// forged case is pinned at unit level by
		// test_apply_refuses_a_plan_that_fingerprints_no_bytes_at_all.
		if ( '' === $bytes || hash( 'sha256', $bytes ) !== (string) ( $planned->payload['contentSha256'] ?? '' ) ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved import could not be matched to its reviewed content.',
				'Request a fresh preview and approve it again; nothing was imported.',
				[ 'plan approved' ]
			);
		}

		try {
			return $this->sideload->store( $bytes, $planned->payload, $context, 'media-import' );
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
	 * An import cannot be reversed by restoring prior state, because there was
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
			'A newly imported library asset has no prior state to restore.',
			'Delete the asset in the WordPress media library if it should not exist.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	/**
	 * The name the asset will be stored under.
	 *
	 * An explicit `filename` wins outright, and is not merged with anything the
	 * address suggests: the caller naming the file is the caller deciding, and
	 * MediaMimeGuard will still refuse it if its extension disagrees with the
	 * content.
	 *
	 * Otherwise the name is the basename of the URL's PATH. The path component
	 * specifically, so a query string can never become part of a filename —
	 * `photo.png?v=2` is the same asset as `photo.png` and must not be stored as
	 * a file whose name carries a cache-buster.
	 *
	 * A path that yields no basename, or a basename with no extension, is
	 * REFUSED rather than repaired — by ONE check, because a name that is empty
	 * has no extension either, so a separate empty-name clause would be a branch
	 * no address could reach. Inventing an extension would be inventing
	 * the very value MediaMimeGuard's deny list and its extension-versus-content
	 * agreement check both key off, which is a guard being fed its own answer.
	 * The refusal names the argument that fixes it and nothing else: not the
	 * address, not the host, not any address this site resolved.
	 *
	 * @param string               $url   The validated, normalised address.
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The filename to store the asset under.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no usable name
	 *                            can be taken from the address.
	 */
	private function filename_for( string $url, array $input ): string {
		if ( array_key_exists( 'filename', $input ) ) {
			return (string) $input['filename'];
		}

		$basename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( '' === (string) pathinfo( $basename, PATHINFO_EXTENSION ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The supplied address does not end in a file name with an extension.',
				'Supply the filename argument, including the file extension the content requires, and request a fresh preview.'
			);
		}

		return $basename;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
