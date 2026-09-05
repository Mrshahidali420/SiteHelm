<?php
/**
 * Issues the one-time credential a large file is uploaded with.
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
 * Hands back a short-lived URL and secret that one file may be posted to.
 *
 * WHY THIS OPERATION EXISTS. `media-upload` takes the file as base64 inside an
 * argument, and an argument is part of the request the client assembles — which
 * for an AI client means the file passes through the model. A six megabyte theme
 * zip is eight megabytes of base64 and something close to two million tokens.
 * That is not a slow upload; it is an upload that cannot happen. So the
 * permission to upload and the bytes take separate roads: this operation is the
 * permission, small enough to travel as an ordinary argument, and the bytes go
 * straight from the agent's disk to a route of their own.
 *
 * IT IS A PREVIEW-THEN-APPLY WRITE LIKE ANY OTHER, and the preview earns its
 * place rather than satisfying a rule. It reports the filename after
 * sanitisation, so a name WordPress would change is seen before a ticket is
 * spent on it; it reports the declared length against this site's actual
 * ceiling, so a file too large for the server is refused before it is sent
 * rather than after; and it refuses an extension this site will not store at
 * all. A caller who has approved the plan knows the upload will be accepted.
 *
 * WHAT IT PROMISES: `filename` and `byteLength`. Both are decided here, from the
 * arguments alone, and both are stable across the two runs of planChange().
 *
 * THE TICKET ITSELF IS DELIBERATELY NOT PROMISED, and that is a security
 * property rather than an oversight. A promised field is copied into the
 * permanent audit row; an unpromised one reaches the caller's response and stops
 * there. The ticket is a bearer credential, so it belongs in exactly one place —
 * the hands of the operator who asked for it. `expiresAt` and `uploadUrl` travel
 * the same way for the simpler reason that they are not knowable until the row
 * is written, and a promise made before the fact would be verified against it.
 *
 * A TICKET IS NOT A FILE. Nothing is stored, nothing is fetched, and no network
 * call is made. If the ticket is never spent it expires and the site is exactly
 * as it was, which is why there is nothing to roll back.
 *
 * @package SiteHelm
 */
final class MediaUploadTicket implements WriteOperation {

	/**
	 * The stable target key a ticket is planned against.
	 *
	 * A LITERAL, LIKE `attachment:new`, AND FOR THE SAME REASON: the thing being
	 * created has no identifier until it exists, and PlanAdmission compares the
	 * previewed target key with the one resolved at apply on a string compare.
	 */
	public const PENDING_KEY = 'upload-ticket:new';

	/**
	 * The ticket applyChange() minted, waiting to be reported by readBack().
	 *
	 * NOT READONLY AND NOT IN THE PAYLOAD. It is the one value in this operation
	 * that must never be written down anywhere, so it lives on the instance for
	 * the width of a single request and is cleared as soon as it is reported.
	 *
	 * @var array{ticket: string, expiresAt: int}|null
	 */
	private ?array $minted = null;

	/**
	 * The ticket store.
	 *
	 * @var UploadTickets
	 */
	private readonly UploadTickets $tickets;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-upload-ticket.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-upload-ticket',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Get a short-lived, single-use URL to upload one large file to, for content too big to send as an argument. POST the raw bytes to the returned uploadUrl with the returned ticket in an X-SiteHelm-Ticket header and a Content-Type of application/octet-stream.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'filename'      => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Filename the uploaded file will take, including its extension. The file type is determined from the content when it arrives, never from this name.',
					],
					'byteLength'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Exact size of the file in bytes. A body of any other length is refused, so this must be the real size and not an estimate.',
					],
					'contentSha256' => [
						'type'        => 'string',
						'minLength'   => 64,
						'maxLength'   => 64,
						'pattern'     => '^[a-f0-9]{64}$',
						'description' => 'Optional lowercase hex sha256 of the file. When given, a body that does not hash to it is refused.',
					],
				],
				'required'             => [ 'filename', 'byteLength' ],
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
				'operation' => 'media-upload-ticket',
				'arguments' => [
					'filename'   => 'my-theme.zip',
					'byteLength' => 4194304,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaMimeGuard     $guard   Filename and byte validation.
	 * @param UploadTickets|null $tickets The ticket store, or null for the default.
	 */
	public function __construct(
		private readonly MediaMimeGuard $guard,
		?UploadTickets $tickets = null,
	) {
		$this->tickets = $tickets ?? new UploadTickets();
	}

	/**
	 * A ticket has no prior existence, so it resolves to the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return new TargetState( self::PENDING_KEY, false, [] );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	/**
	 * Checks everything that can be checked before the file exists here.
	 *
	 * EVERY GUARD RUNS TWICE, at preview and again at apply, because the engine
	 * re-runs this method immediately before applyChange(). Nothing here touches
	 * the database, the network, or the clock: the answer depends on the
	 * arguments alone, so the plan a caller approves and the plan that is applied
	 * cannot differ.
	 *
	 * WHAT IS NOT CHECKED HERE IS THE CONTENT, because there is none yet. The
	 * file type is decided by sniffing the bytes when they arrive, exactly as it
	 * is for `media-upload`. A ticket for `photo.png` carrying a PHP file is
	 * refused at the door, not here.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no upload of
	 *                           this shape could succeed.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$inspected = $this->guard->inspectFilename( (string) ( $input['filename'] ?? '' ) );
		$length    = (int) ( $input['byteLength'] ?? 0 );
		$cap       = MediaMimeGuard::ticketByteCap();

		if ( $length < 1 || $length > $cap ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					/* translators: 1: requested size in bytes, 2: the site's ceiling in bytes. */
					'This site accepts uploads up to %2$s bytes, and %1$s bytes were declared.',
					number_format_i18n( $length ),
					number_format_i18n( $cap )
				),
				'Send a smaller file, or raise the upload limit in the hosting account first.'
			);
		}

		$sha = isset( $input['contentSha256'] ) ? strtolower( (string) $input['contentSha256'] ) : null;

		return new PlannedChange(
			payload: [
				'filename'      => $inspected['filename'],
				'byteLength'    => $length,
				'contentSha256' => $sha,
			],
			afterFields: [
				'filename'   => $inspected['filename'],
				'byteLength' => $length,
			],
			fieldOrder: [ 'filename', 'byteLength' ],
			previewDetail: [
				'uploadUrl'   => $this->uploadUrl(),
				'ttlSeconds'  => UploadTickets::TTL_SECONDS,
				'contentHash' => null === $sha ? 'not checked' : 'checked',
			],
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A ticket has no prior state, so there is nothing to capture.
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
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext's own property names.
	/**
	 * Writes the ticket row and holds the secret for readBack().
	 *
	 * The row is bound to this site, this operator and these declared facts, so
	 * the receiver can admit the upload without a second credential and without
	 * trusting anything the uploading request says about itself.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The ticket's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$minted = $this->tickets->issue(
			$context->siteId,
			$context->userId,
			(string) $planned->payload['filename'],
			(int) $planned->payload['byteLength'],
			null === $planned->payload['contentSha256'] ? null : (string) $planned->payload['contentSha256'],
			time()
		);

		if ( null === $minted ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The upload ticket could not be recorded, so no upload was authorised.',
				'Try again; if it keeps failing, check the database is writable on the SiteHelm status screen.'
			);
		}

		$this->minted = $minted;

		return self::PENDING_KEY;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext's own property names.
	/**
	 * Reports the issued ticket, once.
	 *
	 * THE TWO PROMISED FIELDS ARE READ BACK FROM THE ROW rather than echoed from
	 * the plan, so verification measures what was stored. The three unpromised
	 * ones are what the caller actually needs to perform the upload, and being
	 * unpromised is what keeps the secret out of the audit trail.
	 *
	 * @param string           $targetKey The ticket's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The issued ticket.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$minted       = $this->minted;
		$this->minted = null;

		if ( null === $minted ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The upload ticket was recorded but could not be read back to report it.',
				'Ask for a new ticket; the unreported one expires on its own and cannot be used.'
			);
		}

		$found = $this->tickets->find( $minted['ticket'], $context->siteId, time() );

		if ( null === $found ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The upload ticket was not readable immediately after it was recorded.',
				'Ask for a new ticket; nothing was uploaded.'
			);
		}

		return new TargetState(
			$targetKey,
			true,
			[
				'filename'   => $found['filename'],
				'byteLength' => $found['byteLength'],
				'ticket'     => $minted['ticket'],
				'uploadUrl'  => $this->uploadUrl(),
				'expiresAt'  => gmdate( 'c', $minted['expiresAt'] ),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The method never returns; it always throws.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * There is nothing to undo, because a ticket that is never spent expires.
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
			'An upload ticket changes nothing until it is used, so there is nothing to restore.',
			'Ignore the ticket and it expires by itself. If a file was already uploaded with it, delete that asset in the media library.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	/**
	 * The route a ticket is spent against.
	 *
	 * @return string The absolute upload URL.
	 */
	private function uploadUrl(): string {
		return rest_url( UploadReceiver::ROUTE_NAMESPACE . '/' . UploadReceiver::ROUTE_PATH );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
