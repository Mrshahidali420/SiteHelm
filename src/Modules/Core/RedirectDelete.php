<?php
/**
 * Redirect removal write operation.
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
 * REQ-0079: an operator retires a redirect that is no longer needed.
 *
 * DESTRUCTIVE, AND MARKED SO. WordPress has no trash for an option row: once the
 * redirect is gone the path it was serving stops being served, and the only thing
 * standing between an operator and a silently broken inbound link is the snapshot
 * this operation captures. Declaring it destructive is what puts it behind the
 * policy engine's confirmation rather than behind an ordinary preview.
 *
 * A PATH HOLDING NO REDIRECT IS TargetNotFound, not a quiet success. Deleting is
 * the one place in this trio where "nothing was there" is worth saying out loud:
 * an operator deleting a redirect they believe exists has either the wrong path
 * or a wrong belief about their own site, and both are worth knowing before they
 * conclude the redirect is gone.
 *
 * @package SiteHelm
 */
final class RedirectDelete implements RollbackDelegate {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for redirect-delete.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'redirect-delete',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Remove the redirect stored for one path, so that path is served normally again. There is no trash for a redirect: rollback restores it from the snapshot, and because WordPress stores the whole redirect table in one value, that rollback restores the table as it stood at apply.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'source' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => RedirectStore::MAX_PATH_LENGTH,
						'description' => 'The path whose redirect should be removed, as redirect-list reports it.',
					],
				],
				'required'             => [ 'source' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'manage_options' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: true,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			// Required, not Supported: the invariants hold a destructive
				// operation to all three policies, and rightly — the snapshot is the
				// only thing standing between an operator and a silently broken
				// inbound link.
				rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'redirect-delete',
				'arguments' => [
					'source' => '/old-pricing',
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
	 * The capability is asked FIRST, before the path is looked up, because the
	 * difference between Forbidden and TargetNotFound would otherwise tell a
	 * caller who may not manage this site which of its paths are redirected.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::InvalidInput or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, 'manage_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s redirects.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}

		$raw    = $input['source'] ?? null;
		$source = is_string( $raw ) ? $this->store->normalizePath( $raw ) : null;

		if ( null === $source ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The source must be a path on this site, and it may not be the site\'s home page.',
				'Send the path alone, such as /old-pricing, without a scheme or a host, then request a fresh preview.'
			);
		}

		$record = $this->store->find( $source );

		if ( null === $record ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'This site stores no redirect for the requested path.',
				'Call redirect-list to see the redirects this site serves, with the exact paths they are stored under.'
			);
		}

		return new TargetState( RedirectSnapshot::PREFIX . $source, true, $record );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Promises the absence this write produces.
	 *
	 * THE PROMISED AFTER-STATE IS `exists: false`, AND NOTHING ELSE. A removal has
	 * no field values afterwards, so promising `status` or `target` would ask
	 * WriteVerifier to compare values against a row that is meant to be gone —
	 * and the comparison would pass most convincingly in exactly the case where
	 * the delete silently did not happen.
	 *
	 * The payload carries the row being removed rather than only the path,
	 * because the payload is what apply recovers from the stored plan: an
	 * operator approving this preview approved removing THIS redirect, and a
	 * payload holding only a path would let the row change between the two phases
	 * without the plan noticing.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		return new PlannedChange(
			[
				'source'       => (string) ( $current->fields['source'] ?? '' ),
				'target'       => $current->fields['target'] ?? null,
				'status'       => (int) ( $current->fields['status'] ?? 0 ),
				'forwardQuery' => (bool) ( $current->fields['forwardQuery'] ?? true ),
			],
			[ 'exists' => false ],
			[ 'exists' ]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Captures the whole redirect table.
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
	 * Removes the row from the table.
	 *
	 * The table is read here and the one key unset, rather than rebuilt, so every
	 * sibling row survives as stored.
	 *
	 * A path that is ALREADY ABSENT is written anyway rather than short-circuited.
	 * The state after this apply is the state that was asked for either way, and a
	 * short circuit would skip the store write that a concurrent edit may have
	 * made necessary for the siblings this table holds.
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
				'The change engine could not identify the path this removal was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$table = $this->store->all();
		unset( $table[ $source ] );

		if ( ! $this->store->replace( $table ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the updated redirect table.',
				'Retry the change, or remove the redirect through the site\'s own settings instead.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $current->targetKey;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Re-reads the path for verification.
	 *
	 * `exists` is the field promised and it is the field reported. A row still
	 * present after the write reads back as existing, which is exactly the
	 * disagreement WriteVerifier is looking for.
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

		$exists = null !== $this->store->find( $source );

		return new TargetState( $targetKey, $exists, [ 'exists' => $exists ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Writes the recorded table back, restoring the removed redirect.
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
	 * Resolves one recorded path for a rollback, and re-checks site authority.
	 *
	 * This operation's own resolveTarget() is not reused, and here it could not
	 * be: it refuses a path that holds no redirect, which is precisely the state
	 * this operation leaves behind and the one a rollback of it starts from.
	 *
	 * @param string           $targetKey The recorded target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The path's current state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, 'manage_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s redirects.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}

		$source = RedirectSnapshot::pathFromKey( $targetKey );

		if ( null === $source ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		$exists = null !== $this->store->find( $source );

		return new TargetState( $targetKey, $exists, [ 'exists' => $exists ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether a rollback of this snapshot would leave the path holding a redirect.
	 *
	 * Promised in this operation's OWN read vocabulary — `exists`, the single key
	 * readBack() projects — rather than in `redirect-set`'s four-field row, even
	 * though the two share a recorded state. A shared snapshot does not make a
	 * shared promise: the verifier compares the promise against whichever
	 * operation's read-back ran, and that is this one's.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised presence, or an empty map.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		$source    = $restoreState['source'] ?? null;
		$redirects = $restoreState['redirects'] ?? null;

		if ( ! is_string( $source ) || '' === $source || ! is_array( $redirects ) ) {
			return [];
		}

		return [ 'exists' => array_key_exists( $source, $redirects ) ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
