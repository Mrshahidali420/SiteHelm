<?php
/**
 * One-time tickets that let a file arrive without passing through an argument.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Storage\PlanStore;

/**
 * Issues, resolves, and spends the credential a raw upload is admitted by.
 *
 * WHY A TICKET EXISTS AT ALL. Every argument an operation takes travels inside
 * the request body the client assembles, which for an AI client means it also
 * travels through the model. A six megabyte zip is eight megabytes of base64 and
 * something near two million tokens, so `contentBase64` is not a slow way to
 * move a package — it is structurally incapable of moving one. The ticket
 * separates the permission to upload, which is small and belongs in a normal
 * operation, from the bytes, which are large and belong on a route of their own.
 *
 * THE PLANS TABLE IS THE STORE, AND NO MIGRATION WAS NEEDED, because a ticket
 * and a plan token are the same object with different words on it: a secret
 * issued to one caller, bound to one site and one operation, valid for a bounded
 * window, and spendable exactly once. PlanStore::consume() is a conditional
 * UPDATE that reports how many rows it changed, so of two requests presenting
 * the same ticket the winner sees one row and the loser sees none. That is the
 * single-use guarantee, and it is atomic. A get-then-delete pair would not be.
 *
 * THE STORED OPERATION ID IS NOT AN OPERATION ID. Rows are written under
 * `media-upload-ticket:redeem`, which is deliberately not the id of anything the
 * dispatcher can run. Plan admission matches a token's row against the operation
 * being called, so a ticket presented as a planToken matches nothing and is
 * refused — the two credentials share a table without ever being mistaken for
 * one another.
 *
 * WHAT IS STORED IS A DIGEST. The ticket itself exists in one response and in
 * the client's hands; the row holds sha256 of it, so a reader of the database
 * cannot spend one.
 *
 * @package SiteHelm
 */
final class UploadTickets {

	/**
	 * The row marker for a ticket, chosen so no operation can ever share it.
	 */
	public const TICKET_OPERATION = 'media-upload-ticket:redeem';

	/**
	 * How long a ticket stays spendable.
	 *
	 * SHORTER THAN A PLAN TOKEN, and it does not follow the site's plan TTL. A
	 * plan's window covers a person reading a diff and deciding; a ticket's
	 * covers a file already chosen leaving a disk the agent is standing on. Ten
	 * minutes is generous for the latter on a slow connection and stingy for
	 * anything else.
	 */
	public const TTL_SECONDS = 600;

	/**
	 * The row store tickets live in.
	 *
	 * @var PlanStore
	 */
	private readonly PlanStore $plans;

	/**
	 * Constructs the ticket store over the shared plan storage.
	 *
	 * @param PlanStore|null $plans The row store, or null for the plugin's own.
	 */
	public function __construct( ?PlanStore $plans = null ) {
		$this->plans = $plans ?? new PlanStore();
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The media module's vocabulary is camelCase across every class.
	/**
	 * Mints one ticket and records what it may be spent on.
	 *
	 * THE DECLARED FACTS ARE PART OF THE CREDENTIAL, not advice. A ticket names
	 * the filename, the exact byte length, and optionally the content hash, and
	 * the receiver refuses a body that disagrees with any of them. So a ticket
	 * issued for a 4 MiB theme cannot be spent on a 40 MiB one, and a stolen
	 * ticket cannot be redirected at different content.
	 *
	 * @param string      $siteId    The host the ticket is bound to.
	 * @param int         $userId    The operator the upload will be attributed to.
	 * @param string      $filename  The sanitized filename the asset will take.
	 * @param int         $byteLength The exact length the body must have.
	 * @param string|null $sha256    The content hash the body must have, or null.
	 * @param int         $now       The server-side request time.
	 *
	 * @return array{ticket: string, expiresAt: int}|null The secret and its
	 *         expiry, or null when the row could not be written.
	 */
	public function issue( string $siteId, int $userId, string $filename, int $byteLength, ?string $sha256, int $now ): ?array {
		$ticket = PlanStore::issueToken();
		$expiry = $now + self::TTL_SECONDS;

		$stored = $this->plans->store(
			[
				'token_hash'        => PlanStore::digest( $ticket ),
				'site_id'           => $siteId,
				'user_id'           => $userId,
				'operation_id'      => self::TICKET_OPERATION,
				'schema_version'    => 1,
				'target_key'        => 'attachment:new',
				// The declared facts ride in the columns a plan would use for the
				// same job: what was asked for, and a fingerprint of it.
				'payload_hash'      => (string) $sha256,
				'state_fingerprint' => hash( 'sha256', $filename . '|' . $byteLength ),
				'plan_body'         => (string) wp_json_encode(
					[
						'filename'   => $filename,
						'byteLength' => $byteLength,
						'sha256'     => $sha256,
					]
				),
				'created_at'        => $now,
				'expires_at'        => $expiry,
			]
		);

		if ( ! $stored ) {
			return null;
		}

		return [
			'ticket'    => $ticket,
			'expiresAt' => $expiry,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The media module's vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The media module's vocabulary is camelCase across every class.
	/**
	 * Resolves a presented ticket to what it was issued for.
	 *
	 * EVERY REASON TO REFUSE RETURNS NULL, and the caller reports one message for
	 * all of them. An unknown ticket, one issued for a different site, one that
	 * expired, one already spent, and a row that is not a ticket at all are the
	 * same answer to the only party who could learn something from telling them
	 * apart.
	 *
	 * This does NOT spend the ticket. Resolving and spending are separate so the
	 * caller can finish checking the body against the declaration before
	 * committing the one use it gets.
	 *
	 * @param string $ticket The secret the client presented.
	 * @param string $siteId The host this request arrived on.
	 * @param int    $now    The server-side request time.
	 *
	 * @return array{digest: string, userId: int, filename: string, byteLength: int, sha256: string|null}|null
	 *         What the ticket was issued for, or null when it may not be spent.
	 */
	public function find( string $ticket, string $siteId, int $now ): ?array {
		if ( '' === $ticket ) {
			return null;
		}

		$digest = PlanStore::digest( $ticket );
		$row    = $this->plans->find( $digest );

		if ( null === $row
			|| self::TICKET_OPERATION !== (string) ( $row['operation_id'] ?? '' )
			|| (string) ( $row['site_id'] ?? '' ) !== $siteId
			|| null !== ( $row['consumed_at'] ?? null )
			|| (int) ( $row['expires_at'] ?? 0 ) < $now ) {
			return null;
		}

		$declared = json_decode( (string) ( $row['plan_body'] ?? '' ), true );
		if ( ! is_array( $declared ) ) {
			return null;
		}

		$sha256 = isset( $declared['sha256'] ) && is_string( $declared['sha256'] ) && '' !== $declared['sha256']
			? $declared['sha256']
			: null;

		return [
			'digest'     => $digest,
			'userId'     => (int) ( $row['user_id'] ?? 0 ),
			'filename'   => (string) ( $declared['filename'] ?? '' ),
			'byteLength' => (int) ( $declared['byteLength'] ?? 0 ),
			'sha256'     => $sha256,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Claims a resolved ticket for its one use.
	 *
	 * Called after the body has been checked against the declaration and before
	 * anything is stored, so two requests racing the same ticket produce one
	 * upload rather than two.
	 *
	 * @param string $digest The digest find() returned.
	 * @param int    $now    The server-side request time.
	 *
	 * @return bool True when this call is the one that claimed the ticket.
	 */
	public function spend( string $digest, int $now ): bool {
		return $this->plans->consume( $digest, $now );
	}
}
