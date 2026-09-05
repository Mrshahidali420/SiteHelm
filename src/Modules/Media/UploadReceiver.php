<?php
/**
 * The route a ticket is spent against.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Admin\WriteModeAction;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Storage\AuditStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Accepts one file's raw bytes against one ticket, and stores it.
 *
 * THE BYTES NEVER TOUCH THE MCP ROUTE. This is a second REST route whose whole
 * purpose is to be somewhere a file can be sent without being an argument: the
 * agent shells out to a plain HTTP POST, the body is the file, and nothing about
 * it passes through the model. `media-upload-ticket` is where the decision was
 * made; this is only where the delivery happens.
 *
 * THE TICKET IS THE CREDENTIAL, WHICH IS WHY THE PERMISSION CALLBACK IS OPEN.
 * There is no Application Password on this request and no bearer token — there
 * is a 64-character secret that was issued moments ago to an authenticated
 * operator, bound to this site, to that operator, to one filename and to one
 * exact byte count, and spendable once. Requiring a second credential as well
 * would mean the agent had to hold the site's password in the shell command it
 * runs, which is the thing this design exists to avoid.
 *
 * IT TRAVELS IN A HEADER OF ITS OWN, never in the URL and never in
 * `Authorization`. Not the URL, because query strings are written to access logs
 * in full and a logged ticket is a spendable ticket. Not `Authorization`,
 * because that header belongs to the bearer authenticator, and a value it does
 * not recognise there is a failed sign-in rather than an upload.
 *
 * WHAT IS RE-CHECKED AT REDEMPTION, and what is deliberately not. The ticket was
 * issued through the ordinary dispatcher, so the permission mode, the module and
 * operation switches, the capability and the licence were all satisfied less
 * than ten minutes ago. Re-running that whole chain here would mean writing it a
 * second time, and two copies of a policy chain are two chances to disagree.
 * What is re-read is the small set that an administrator changes precisely
 * because they want it to take effect immediately: writes being paused, the
 * operator still holding `upload_files`, and `media-upload` still being switched
 * on. A ticket issued before someone hit pause is not honoured after it.
 *
 * THE FILE IS STILL JUDGED BY ITS CONTENT. The ticket says what the filename
 * will be; it says nothing about what the file is. The bytes go through the same
 * MediaMimeGuard sniff, the same extension refusal and the same allowlist as a
 * base64 upload, so a ticket issued for `photo.png` carrying PHP is refused at
 * this door. The zip type only ever enters that allowlist through the add-on's
 * filter, so nothing here decides it either.
 *
 * @package SiteHelm
 */
final class UploadReceiver {

	public const ROUTE_NAMESPACE = 'sitehelm/v1';
	public const ROUTE_PATH      = 'upload';
	public const TICKET_HEADER   = 'x-sitehelm-ticket';
	public const CONTENT_TYPE    = 'application/octet-stream';

	/**
	 * The one refusal every unusable ticket produces.
	 *
	 * ONE MESSAGE FOR FIVE DIFFERENT REASONS, on purpose. Unknown, expired,
	 * already spent, issued for another site, and not a ticket at all are told
	 * apart only by someone holding a ticket they should not have, and telling
	 * them apart is the only help that person needs.
	 */
	private const REFUSED = 'This upload ticket cannot be used. Ask for a new one with media-upload-ticket.';

	/**
	 * The attachment projection, and the source of the pending target key.
	 *
	 * @var MediaFields
	 */
	private readonly MediaFields $fields;

	/**
	 * The ticket store.
	 *
	 * @var UploadTickets
	 */
	private readonly UploadTickets $tickets;

	/**
	 * Builds the payload the sideload stores.
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
	 * The audit trail.
	 *
	 * @var AuditRecorder
	 */
	private readonly AuditRecorder $recorder;

	/**
	 * Constructs the receiver.
	 *
	 * @param MediaFields         $fields   The attachment projection.
	 * @param MediaMimeGuard      $guard    Byte validation.
	 * @param OperationSwitches   $switches The operator's per-operation switches.
	 * @param UploadTickets|null  $tickets  The ticket store, or null for the default.
	 * @param MediaAssetPlan|null $planner  Payload construction, or null for the default.
	 * @param MediaSideload|null  $sideload Attachment creation, or null for the default.
	 * @param AuditRecorder|null  $recorder The audit trail, or null for the default.
	 */
	public function __construct(
		MediaFields $fields,
		private readonly MediaMimeGuard $guard,
		private readonly OperationSwitches $switches,
		?UploadTickets $tickets = null,
		?MediaAssetPlan $planner = null,
		?MediaSideload $sideload = null,
		?AuditRecorder $recorder = null,
	) {
		$this->fields   = $fields;
		$this->tickets  = $tickets ?? new UploadTickets();
		$this->planner  = $planner ?? new MediaAssetPlan();
		$this->sideload = $sideload ?? new MediaSideload( $fields );
		$this->recorder = $recorder ?? new AuditRecorder( new AuditStore(), new AuditRedactor() );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	/**
	 * Registers the upload route.
	 *
	 * @return void
	 */
	public function registerRoute(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . self::ROUTE_PATH,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleRequest' ],
				// The ticket is the credential; see the class docblock.
				'permission_callback' => '__return_true',
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext's own property names.
	/**
	 * Admits one upload, in the order that refuses soonest.
	 *
	 * The checks run cheapest first and, more importantly, in the order that
	 * spends the ticket last: everything about the request is settled before the
	 * one use is claimed, so a body that was going to be refused leaves the
	 * ticket still valid for the retry.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response The upload result, or a refusal.
	 */
	public function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$type = strtolower( trim( explode( ';', (string) $request->get_header( 'content-type' ) )[0] ) );

		if ( self::CONTENT_TYPE !== $type ) {
			return $this->refuse(
				400,
				'An upload must be sent as raw bytes.',
				'Set the Content-Type header to ' . self::CONTENT_TYPE . ' and put the file itself in the request body.'
			);
		}

		$site   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$now    = time();
		$ticket = trim( (string) $request->get_header( self::TICKET_HEADER ) );
		$issued = $this->tickets->find( $ticket, $site, $now );

		if ( null === $issued ) {
			return $this->refuse( 401, self::REFUSED, 'Tickets last ' . UploadTickets::TTL_SECONDS . ' seconds and work once.' );
		}

		$body = (string) $request->get_body();

		if ( strlen( $body ) !== $issued['byteLength'] ) {
			return $this->refuse(
				400,
				'The uploaded file is not the size the ticket was issued for.',
				sprintf(
					/* translators: 1: declared size, 2: received size. */
					'The ticket declared %1$s bytes and %2$s arrived. Ask for a new ticket with the real size.',
					number_format_i18n( $issued['byteLength'] ),
					number_format_i18n( strlen( $body ) )
				)
			);
		}

		if ( null !== $issued['sha256'] && ! hash_equals( $issued['sha256'], hash( 'sha256', $body ) ) ) {
			return $this->refuse(
				400,
				'The uploaded file is not the file the ticket was issued for.',
				'Send the file the hash was taken from, or ask for a ticket without a hash.'
			);
		}

		$refusal = $this->policyRefusal( $issued['userId'] );

		if ( null !== $refusal ) {
			return $refusal;
		}

		// Claimed only now, with everything else settled. Losing this race means
		// another request is already storing this exact file.
		if ( ! $this->tickets->spend( $issued['digest'], $now ) ) {
			return $this->refuse( 409, self::REFUSED, 'This ticket has already been used.' );
		}

		return $this->store( $body, $issued, $site, $now );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	/**
	 * The live checks an administrator expects to take effect at once.
	 *
	 * @param int $userId The operator the ticket was issued to.
	 *
	 * @return WP_REST_Response|null A refusal, or null when the upload may proceed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function policyRefusal( int $userId ): ?WP_REST_Response {
		if ( WriteModeAction::is_paused() ) {
			return $this->refuse(
				403,
				'This site is not accepting changes right now.',
				'Resume writes on the SiteHelm status screen, then ask for a new ticket.'
			);
		}

		if ( ! $this->switches->isEnabled( 'media-upload' ) ) {
			return $this->refuse(
				403,
				'Uploading files is switched off for connected apps on this site.',
				'Switch media-upload back on from the SiteHelm operations screen.'
			);
		}

		if ( ! user_can( $userId, 'upload_files' ) ) {
			return $this->refuse(
				403,
				'This account is not allowed to add files to the media library.',
				'Ask a site administrator to grant the account permission, then try again.'
			);
		}

		return null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext's own property names.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * Inspects the bytes and stores them, recording the result either way.
	 *
	 * THE AUDIT ROW SAYS `media-upload`, because that is what happened. The
	 * transport differs and the outcome does not: a file the operator chose is
	 * now in the media library. Filing it under a name of its own would leave the
	 * activity log with a category the console has never heard of, and would let
	 * a reader believe uploads-by-ticket are something other than uploads.
	 *
	 * @param string $bytes  The uploaded file.
	 * @param array  $issued What the ticket was for, as UploadTickets::find() reports it.
	 * @param string $site   This site's host.
	 * @param int    $now    The request time.
	 *
	 * @return WP_REST_Response The result.
	 */
	private function store( string $bytes, array $issued, string $site, int $now ): WP_REST_Response {
		$context = new OperationContext(
			siteId: $site,
			userId: $issued['userId'],
			clientId: 'upload-ticket',
			correlationId: wp_generate_uuid4(),
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: $now,
		);

		try {
			$inspected = $this->guard->inspectBytes( $issued['filename'], $bytes, MediaMimeGuard::ticketByteCap() );
		} catch ( OperationException $refusal ) {
			return $this->refuse( 400, $refusal->getMessage(), $refusal->remediation );
		}

		$planned = $this->planner->plan( $inspected, [] );

		$audit = $this->recorder->start(
			MediaUpload::definition(),
			$context,
			$this->fields->pendingTargetKey(),
			hash( 'sha256', (string) wp_json_encode( $planned->payload ) ),
			null,
			null
		);

		try {
			$targetKey = $this->sideload->store( $bytes, $planned->payload, $context, 'media-upload' );
		} catch ( OperationException $failure ) {
			$this->recorder->finish(
				$audit,
				AuditRecorder::OUTCOME_EXECUTION_FAILED,
				null,
				null,
				$this->fields->pendingTargetKey(),
				[],
				[]
			);

			return $this->refuse( 500, $failure->getMessage(), $failure->remediation );
		}

		$this->recorder->finish(
			$audit,
			AuditRecorder::OUTCOME_APPLIED,
			null,
			null,
			$targetKey,
			[],
			$planned->afterFields
		);

		return new WP_REST_Response(
			[
				'ok'         => true,
				'targetKey'  => $targetKey,
				'filename'   => $planned->payload['filename'],
				'mimeType'   => $planned->payload['mimeType'],
				'byteLength' => $planned->payload['byteLength'],
			],
			201
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One refusal shape, so a client can read every failure the same way.
	 *
	 * @param int    $status      The HTTP status.
	 * @param string $message     What went wrong.
	 * @param string $remediation What to do about it.
	 *
	 * @return WP_REST_Response The refusal.
	 */
	private function refuse( int $status, string $message, string $remediation ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'ok'          => false,
				'message'     => $message,
				'remediation' => $remediation,
			],
			$status
		);
	}
}
