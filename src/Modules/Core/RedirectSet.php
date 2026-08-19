<?php
/**
 * Redirect create-or-update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Change\TargetState;
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
 * REQ-0079: an agency operator points a retired URL at its successor, so the
 * traffic and the ranking the old address earned survive the rename.
 *
 * CREATING AND UPDATING ARE ONE OPERATION. The target is the source PATH, not a
 * row identifier, so pointing `/old-pricing` somewhere is the same instruction
 * whether or not it was already pointed somewhere else. That is what makes this
 * idempotent and its target key stable: sending the same redirect twice leaves
 * one row, and the second request verifies exactly as the first did. A separate
 * `redirect-create` would exist only to refuse the second call.
 *
 * THE WHOLE TABLE IS ONE OPTION, so the snapshot is the WHOLE TABLE and the
 * rollback writes the whole table back — the same reasoning MenuLocationAssign
 * gives for `nav_menu_locations`, and the same price: a rollback reverts any
 * other redirect edited in the interval along with this one. Nothing inside a
 * single stored value can distinguish the two, and reversing this operation's
 * row exactly is the contract chosen over leaving siblings alone.
 *
 * A LOOP IS REFUSED AT WRITE TIME as well as at serve time. Refusing here is
 * what an operator can act on — they get a message naming the mistake while they
 * are making it — and refusing at serve time is what survives the rename that
 * turns a good redirect into a loop months later. Neither one makes the other
 * redundant.
 *
 * @package SiteHelm
 */
final class RedirectSet implements RollbackDelegate {

	/**
	 * The target-key prefix for a redirect-shaped target.
	 */
	public const REDIRECT_PREFIX = 'redirect:';

	/**
	 * The operation's registered definition.
	 *
	 * `target` is declared `[ 'string', 'null' ]` and is REQUIRED, because null is
	 * a value this operation acts on — it is what a 410 means — rather than an
	 * omission. SchemaValidator reads a union type as "no constraint", so
	 * planChange() re-checks the type and the pairing itself: the schema declares
	 * the contract for the client, and the operation enforces it.
	 *
	 * `status` carries no default in the schema. A redirect's status is the whole
	 * difference between "this moved permanently" and "this is gone", and
	 * defaulting it would let a dropped key decide which of those a client's site
	 * tells every crawler.
	 *
	 * @return OperationDefinition The definition registered for redirect-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'redirect-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Point one path on this site at a successor URL, or mark it gone. Creates the redirect or replaces the one already stored for that path. Because WordPress stores the whole redirect table in one value, a rollback of this operation restores the table as it stood at apply — any other redirect changed in the interval is reverted with it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'source'       => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => RedirectStore::MAX_PATH_LENGTH,
						'description' => 'The path on this site to redirect, such as /old-pricing. A query string is ignored, a trailing slash does not matter, and the site\'s home page cannot be a source.',
					],
					'target'       => [
						'type'        => [ 'string', 'null' ],
						'maxLength'   => RedirectStore::MAX_TARGET_LENGTH,
						'description' => 'Where to send the visitor: a path on this site, or an absolute http or https URL on any host. A target may carry its own query string, which wins over the visitor\'s where the two name the same argument. Send null when the status is 410 and the page has no successor.',
					],
					'status'       => [
						'type'        => 'integer',
						'enum'        => RedirectStore::STATUS_CODES,
						'description' => 'The HTTP status to answer with. 301 and 308 are permanent and pass ranking signals on; 302 and 307 are temporary and do not; 410 says the page is gone and takes no target.',
					],
					'forwardQuery' => [
						'type'        => 'boolean',
						'description' => 'Whether to carry the visitor\'s query string over to the target. Defaults to true, so campaign and analytics arguments survive the redirect.',
					],
				],
				'required'             => [ 'source', 'target', 'status' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'manage_options' ],
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
				'operation' => 'redirect-set',
				'arguments' => [
					'source' => '/old-pricing',
					'target' => '/pricing',
					'status' => 301,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param RedirectStore $store The redirect table.
	 */
	public function __construct( private readonly RedirectStore $store ) {
	}

	/**
	 * Resolves the redirect the source path names.
	 *
	 * A source that cannot be a path on this site is InvalidInput rather than
	 * TargetNotFound: the target of this operation is the path itself, and an
	 * absent redirect at a valid path is the ordinary case — it is what creating
	 * one means. So there is no not-found refusal here at all, and `exists` on
	 * the returned state is what tells the engine whether this apply will create
	 * or replace.
	 *
	 * The capability is asked FIRST, before the path is examined, because the
	 * difference between the two refusals would otherwise report which paths this
	 * site already redirects to a caller who may not manage the site at all.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$this->requireCapability( $context );

		$source = $this->requirePath( $input['source'] ?? null );
		$record = $this->store->find( $source );

		return new TargetState(
			self::REDIRECT_PREFIX . $source,
			null !== $record,
			$record ?? [
				'source'       => $source,
				'target'       => null,
				'status'       => 0,
				'forwardQuery' => true,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Validates the redirect and promises the resulting row.
	 *
	 * FOUR REFUSALS LIVE HERE, and each one is a redirect that would be stored
	 * and reported as working while doing something other than what was asked:
	 *
	 *   1. A target paired wrongly with the status. A 410 with a target would
	 *      discard the target silently; a 3xx without one has nowhere to send
	 *      anybody.
	 *   2. A target that is neither a path on this site nor an absolute http or
	 *      https URL. `javascript:` and `data:` URIs are the reason this is a
	 *      whitelist of two schemes rather than a blacklist, and a
	 *      protocol-relative `//host` is refused because it reads as a path and
	 *      behaves as a different origin.
	 *   3. A target equal to the source. The browser reports a redirect loop as
	 *      the site being broken, so this must never be storable by accident.
	 *   4. A table already at capacity, but only when this call would ADD a row.
	 *      Replacing a row in a full table is allowed, because refusing it would
	 *      leave an operator at the bound unable to correct a mistake in a
	 *      redirect they already have.
	 *
	 * `status` is re-read and re-checked against the enum even though
	 * SchemaValidator enforces `enum`, because this method also runs at apply
	 * against the payload recovered from the stored plan, which the validator
	 * never saw.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$source = $this->requirePath( $input['source'] ?? null );
		$status = is_numeric( $input['status'] ?? null ) ? (int) $input['status'] : 0;

		if ( ! in_array( $status, RedirectStore::STATUS_CODES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The status must be one of the redirect status codes this site supports.',
				'Send 301 or 308 for a permanent move, 302 or 307 for a temporary one, or 410 for a page that is gone, then request a fresh preview.'
			);
		}

		if ( ! array_key_exists( 'target', $input ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No target argument was supplied, so it is not clear where this path should send its visitors.',
				'Send a path or an absolute URL to redirect to, or an explicit null target with status 410 for a page that is gone, then request a fresh preview.'
			);
		}

		$target = $this->normalizeTarget( $input['target'], $status, $source );

		if ( ! $current->exists && $this->store->count() >= RedirectStore::MAX_REDIRECTS ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf(
					'This site already stores the greatest number of redirects SiteHelm will hold, which is %d.',
					RedirectStore::MAX_REDIRECTS
				),
				'Delete a redirect that is no longer needed with redirect-delete, then request a fresh preview.'
			);
		}

		$forward = array_key_exists( 'forwardQuery', $input ) ? (bool) $input['forwardQuery'] : true;

		$row = [
			'source'       => $source,
			'target'       => $target,
			'status'       => $status,
			'forwardQuery' => $forward,
		];

		// Every field is promised, because this operation sets every one of them:
		// a create-or-replace writes the whole row, so there is no field here
		// whose value after the write is anybody else's.
		return new PlannedChange( $row, $row, RedirectStore::RECORD_FIELDS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Captures the entire redirect table this write is about to rewrite.
	 *
	 * THE WHOLE TABLE, not this operation's one row, because the table is one
	 * option: a snapshot of one row could only be restored by merging it into a
	 * table read later, and every sibling redirect edited between the write and
	 * the rollback would ride along into a reversal that claims to have touched
	 * one path.
	 *
	 * A path that holds NO redirect yet is recorded by its ABSENCE from the
	 * table, which is exactly how the restore reverses a create: writing the
	 * recorded table back removes the row this write added.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE — it reads the option and the
	 * target key and writes nothing.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target names no path.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return RedirectSnapshot::capture( $this->store, $current->targetKey );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Writes the promised row into the table.
	 *
	 * The table is READ HERE AND MUTATED rather than rebuilt from the snapshot,
	 * so every sibling row survives as stored — including one this operation
	 * would refuse to write itself. A row another version of this plugin left
	 * behind is not this write's to normalise, and correcting it would be an
	 * unrequested change to a client's redirects hidden inside a requested one.
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
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$source = RedirectSnapshot::pathFromKey( $current->targetKey );

		if ( null === $source ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the path this redirect was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$table            = $this->store->all();
		$table[ $source ] = $planned->payload;

		if ( ! $this->store->replace( $table ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the updated redirect table.',
				'Retry the change, or add the redirect through the site\'s own settings instead.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $current->targetKey;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Re-reads the redirect for verification.
	 *
	 * A row that is ABSENT after the write reads back with the same all-null
	 * projection resolveTarget() builds for a path that holds no redirect, rather
	 * than raising: WriteVerifier compares the promised fields, and an absent row
	 * disagrees with every one of them, so the write is reported as not having
	 * taken. Raising VerificationFailed here would report the same fact as an
	 * inability to look, which is a different diagnosis with a different fix.
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
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$source = RedirectSnapshot::pathFromKey( $targetKey );

		if ( null === $source ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The redirect could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		$record = $this->store->find( $source );

		return new TargetState(
			$targetKey,
			null !== $record,
			$record ?? [
				'source'       => $source,
				'target'       => null,
				'status'       => 0,
				'forwardQuery' => true,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Writes the recorded table back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return RedirectSnapshot::restore( $this->store, $restoreState );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses a caller who may not manage this site.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function requireCapability( OperationContext $context ): void {
		if ( ! user_can( $context->userId, 'manage_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s redirects.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The canonical source path, or a refusal.
	 *
	 * `is_string()` rather than a cast: `(string) [ '/x' ]` is a fatal, while a
	 * non-string names no path, which is the truth about it.
	 *
	 * @param mixed $raw The supplied source.
	 *
	 * @return string The canonical path.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function requirePath( mixed $raw ): string {
		$path = is_string( $raw ) ? $this->store->normalizePath( $raw ) : null;

		if ( null === $path ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The source must be a path on this site, and it may not be the site\'s home page.',
				'Send the path alone, such as /old-pricing, without a scheme or a host, then request a fresh preview.'
			);
		}

		return $path;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The stored target for a status, or a refusal.
	 *
	 * @param mixed  $raw    The supplied target.
	 * @param int    $status The validated status.
	 * @param string $source The canonical source path.
	 *
	 * @return string|null The stored target, or null for a 410.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function normalizeTarget( mixed $raw, int $status, string $source ): ?string {
		if ( RedirectStore::STATUS_GONE === $status ) {
			if ( null !== $raw && '' !== $raw ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'A page marked gone has no successor, so it cannot carry a target.',
					'Send a null target with status 410, or choose 301 to send visitors to the successor instead, then request a fresh preview.'
				);
			}

			return null;
		}

		$target = is_string( $raw ) ? trim( $raw ) : '';

		if ( '' === $target ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A redirect needs a target to send its visitors to.',
				'Send a path on this site or an absolute http or https URL, or use status 410 if the page is simply gone, then request a fresh preview.'
			);
		}

		if ( strlen( $target ) > RedirectStore::MAX_TARGET_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The target is longer than the %d characters a redirect target may hold.', RedirectStore::MAX_TARGET_LENGTH ),
				'Shorten the target URL, then request a fresh preview.'
			);
		}

		// An absolute URL is stored exactly as written, query and all. It is
		// checked before the path spellings below because a URL is not a path.
		// Delimited with `~` and not `#`, because the pattern's character class
		// contains a literal `#` — with a `#` delimiter it closes the pattern early
		// and preg_match() raises "Unknown modifier" instead of matching anything.
		if ( 1 === preg_match( '~^https?://[^/?#\s]+~i', $target ) ) {
			return $target;
		}

		// A RELATIVE TARGET KEEPS ITS QUERY STRING. normalizePath() strips one,
		// because a SOURCE is matched against a request line the server has
		// already split — but a target of `/pricing?plan=pro` is an instruction
		// about where to send people, and normalising the query away would store a
		// redirect that works and lands somewhere other than what was asked for.
		// A fragment is dropped rather than kept: it never reaches a server, and
		// carrying one would put the forwarded query after it, which is not a URL.
		[ $bare, $query ] = $this->splitQuery( $target );

		// The home page is the one value normalizePath() refuses that a TARGET
		// legitimately needs. It is refused as a SOURCE because a redirect from the
		// front door captures every visitor including the operator undoing it; as a
		// destination it captures nobody, and "send this retired page to the home
		// page" is the most ordinary redirect an agency writes. Without this the
		// refusal would read "the target must be a path on this site" about the one
		// path every site has.
		if ( 1 === preg_match( '#^/+$#', $bare ) ) {
			return '/' . $query;
		}

		$relative = $this->store->normalizePath( $bare );

		if ( null === $relative ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The target must be a path on this site or an absolute http or https URL.',
				'Send a path such as /pricing, or a full address such as https://example.com/pricing, then request a fresh preview.'
			);
		}

		if ( $relative === $source ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The target is the same path as the source, so this redirect would send visitors back to where they already are.',
				'Send the path visitors should end up at instead, then request a fresh preview.'
			);
		}

		return $relative . $query;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Splits a relative target into its path and its query string.
	 *
	 * The query is returned WITH its leading `?` so a caller can concatenate it
	 * unconditionally, and it is empty when there is none. A fragment is discarded
	 * with whatever follows it.
	 *
	 * @param string $target The supplied relative target.
	 *
	 * @return array{0: string, 1: string} The path, and the query or ''.
	 */
	private function splitQuery( string $target ): array {
		$cut = strcspn( $target, '?#' );

		if ( $cut >= strlen( $target ) || '?' !== $target[ $cut ] ) {
			return [ substr( $target, 0, $cut ), '' ];
		}

		$query = substr( $target, $cut + 1 );
		$hash  = strpos( $query, '#' );

		if ( false !== $hash ) {
			$query = substr( $query, 0, $hash );
		}

		return [ substr( $target, 0, $cut ), '' === $query ? '' : '?' . $query ];
	}

	/**
	 * Resolves one recorded path for a rollback, and re-checks site authority.
	 *
	 * This operation's own resolveTarget() is not reused, only because it takes a
	 * caller-supplied `source` through normalizePath(); a recorded target key
	 * already holds the canonical path, and re-normalising a stored value would
	 * let a change in the normaliser silently point a rollback at a different
	 * path.
	 *
	 * @param string           $targetKey The recorded target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The redirect's current state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		$this->requireCapability( $context );

		$source = RedirectSnapshot::pathFromKey( $targetKey );

		if ( null === $source ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		$record = $this->store->find( $source );

		return new TargetState(
			$targetKey,
			null !== $record,
			$record ?? [
				'source'       => $source,
				'target'       => null,
				'status'       => 0,
				'forwardQuery' => true,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The redirect row a rollback of this snapshot would put back.
	 *
	 * The recorded state holds the WHOLE table, so the promise is the one row the
	 * snapshot names — promising the table would compare a site-wide value against
	 * a read-back that projects a single redirect. A path ABSENT from the recorded
	 * table is promised as the absent projection this operation's own reads use,
	 * which is how the reversal of a create is expressed: the row goes away.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised row, or an empty map.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		$source    = $restoreState['source'] ?? null;
		$redirects = $restoreState['redirects'] ?? null;

		if ( ! is_string( $source ) || '' === $source || ! is_array( $redirects ) ) {
			return [];
		}

		$row = $redirects[ $source ] ?? null;

		if ( ! is_array( $row ) ) {
			return [
				'source'       => $source,
				'target'       => null,
				'status'       => 0,
				'forwardQuery' => true,
			];
		}

		return [
			'source'       => $source,
			'target'       => is_string( $row['target'] ?? null ) ? $row['target'] : null,
			'status'       => (int) ( $row['status'] ?? 0 ),
			'forwardQuery' => (bool) ( $row['forwardQuery'] ?? true ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
