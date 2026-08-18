<?php
/**
 * REQ-0061: change one user's role on this site.
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
use SiteHelm\Policy\PolicyEngine;

/**
 * Replaces one user's roles with exactly one role.
 *
 * REGISTERED UNDER `content-write` while its read sits under `system-read`, and
 * the split is forced rather than chosen: the eleven dispatchers are a frozen
 * contract with no `system-write` in it, so a write has exactly one general
 * place it can live. `RedirectSet` set the precedent for the same reason. The
 * read stays where it belongs — who holds an account is a fact about the
 * installation, not a piece of its content — and both files say so, because a
 * feature split across two dispatchers is only defensible if the reason is
 * written down at both ends.
 *
 * THIS IS THE HIGHEST-BLAST-RADIUS WRITE IN THE PLUGIN, and it is declared
 * `Risk::High` for that reason alone — the edit itself is one meta value.
 * Granting a role hands over every capability that role carries, and four
 * refusals stand between a caller and a change nobody could undo through this
 * plugin:
 *
 * 1. AN UNREGISTERED ROLE IS REFUSED. WordPress's `set_role()` writes whatever
 *    slug it is handed, so a typo would leave the account holding a role the
 *    site has never registered — which is not a demotion to something lesser but
 *    a removal of every capability, and it looks like a successful write.
 * 2. CHANGING YOUR OWN ROLE IS REFUSED. A self-demotion locks the operator out
 *    of the site mid-session, and a self-promotion is the escalation this gateway
 *    must never be the shortest path to. WordPress's own users screen removes the
 *    dropdown from your own row for the first of those reasons.
 * 3. DEMOTING THE LAST ADMINISTRATOR IS REFUSED. It is the one role change that
 *    cannot be undone from inside WordPress, because after it there is nobody
 *    left who may hand the role back.
 * 4. ON MULTISITE, A SUPER ADMIN IS REFUSED. Their capabilities come from the
 *    network rather than this site's role, so a role change would report success
 *    while altering nothing that governs what they may do — a write whose
 *    envelope lies is worse than one that refuses.
 *
 * PROMOTING SOMEONE TO ADMINISTRATOR IS ALLOWED, and warned about in the
 * preview. Refusing it outright would break the legitimate work — an agency
 * onboarding a client's new administrator — while a caller who holds
 * `promote_users` can already do it from the WordPress screen. The preview says
 * plainly what the grant carries, so the approval is informed rather than
 * incidental.
 *
 * @package SiteHelm
 */
final class UserRoleSet implements RollbackDelegate {

	/**
	 * The one field this operation promises.
	 */
	private const PROMISED_FIELD = 'roles';

	/**
	 * The role whose grant is warned about and whose loss is guarded.
	 */
	private const ADMINISTRATOR = 'administrator';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for user-role-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'user-role-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Replace one user\'s roles with a single role registered on this site. Refuses your own account and the last administrator.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the user whose role is being changed.',
					],
					'role' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => UserFields::ROLE_MAX_LENGTH,
						'description' => 'Role slug to grant. Must be one of the slugs user-list reports as siteRoles; roles are registered by the site, so there is no fixed list.',
					],
				],
				'required'             => [ 'id', 'role' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ UserFields::WRITE_CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'user-role-set',
				'arguments' => [
					'id'   => 12,
					'role' => 'editor',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param PolicyEngine $policy The gate used for the target-bound re-check.
	 */
	public function __construct( private readonly PolicyEngine $policy ) {
	}

	/**
	 * Resolves the user the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$user_id = (int) ( $input['id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof \WP_User ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No user on this site matches the requested identifier.',
				'Call user-list to see the accounts this site holds, and confirm the identifier you named.'
			);
		}

		return new TargetState(
			UserFields::targetKey( $user_id ),
			true,
			UserFields::project( $user )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Validates the role and every refusal, then builds the promised change.
	 *
	 * Every guard below runs in BOTH phases, because planChange() does, and it is
	 * evaluated again at apply. That is load-bearing rather than incidental: the administrator
	 * count, the caller's own capability over this account, and the site's
	 * registered roles can all change between a preview and its approval, and each
	 * of the four refusals exists precisely because the state it reads can move.
	 *
	 * The target-bound `edit_user` re-check is made here rather than declared,
	 * because a meta capability with no target resolves to `do_not_allow` and would
	 * refuse every caller including administrators. `promote_users` is the declared
	 * half; this is the half WordPress's own screen applies per row.
	 *
	 * No ksort: the promise holds exactly one key.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a role the site
	 *                           has not registered, ErrorCode::Forbidden for the
	 *                           caller's own account or an account they may not
	 *                           edit, or ErrorCode::Conflict for the last
	 *                           administrator and for a network super admin.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$user_id = UserFields::userIdFromKey( $current->targetKey );
		$role    = trim( (string) ( $input['role'] ?? '' ) );

		if ( null === $user_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name a user account.',
				'Request a fresh preview for the account you meant to change.'
			);
		}

		$registered = UserFields::registeredRoles();

		if ( ! in_array( $role, $registered, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That role slug is not registered on this site.',
				sprintf(
					'Choose one of the roles this site has registered: %s.',
					implode( ', ', $registered )
				)
			);
		}

		if ( $user_id === $context->userId ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This operation will not change the role of the account it is running as.',
				'Ask another administrator to change your role, or change it in the WordPress users screen where the consequences of locking yourself out are visible.'
			);
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'That account is a network super admin, so its capabilities come from the network rather than this site\'s role.',
				'Change the account\'s network privileges in the network administration screens; a role change here would not alter what it may do.'
			);
		}

		$this->policy->authorizeTargetCapability(
			UserFields::TARGET_CAPABILITY,
			$user_id,
			'user-role-set',
			$context
		);

		$held = array_map( 'strval', (array) ( $current->fields[ self::PROMISED_FIELD ] ?? [] ) );

		if ( in_array( self::ADMINISTRATOR, $held, true )
			&& self::ADMINISTRATOR !== $role
			&& $this->administratorCount() < 2 ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'That account is the only administrator on this site, so changing its role would leave nobody able to administer it.',
				'Promote another account to administrator first, then change this one.'
			);
		}

		$promised = [ self::PROMISED_FIELD => [ $role ] ];

		return new PlannedChange(
			$promised,
			$promised,
			UserFields::FIELD_ORDER,
			$this->warnings( $held, $role ),
			[
				'login' => (string) ( $current->fields['login'] ?? '' ),
				'from'  => $held,
				'to'    => [ $role ],
			]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Captures every role to go back to.
	 *
	 * ALL of them, not the one being replaced. WordPress supports a user holding
	 * several roles even though its own screen shows one dropdown, and `set_role()`
	 * silently drops the others. A snapshot recording a single role would turn a
	 * rollback into a second act of capability loss — the write would be undone and
	 * the extra roles would still be gone, with nothing in the ledger saying they
	 * had ever existed.
	 *
	 * Read from the resolved state rather than the database, so it is side-effect
	 * free and answers identically when called twice.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$user_id = UserFields::userIdFromKey( $current->targetKey );

		if ( null === $user_id ) {
			return null;
		}

		return [
			'user_id' => $user_id,
			'roles'   => array_map( 'strval', (array) ( $current->fields[ self::PROMISED_FIELD ] ?? [] ) ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the promised role.
	 *
	 * `WP_User::set_role()` returns nothing, so there is no return value to test —
	 * which is exactly why this operation declares `PreviewPolicy::Required` and
	 * leans on the read-back. The only failure this method can detect for itself is
	 * an account that has disappeared between the plan and the apply, and it
	 * reports that rather than calling a method on false.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$user_id = UserFields::userIdFromKey( $current->targetKey );
		$roles   = (array) ( $planned->payload[ self::PROMISED_FIELD ] ?? [] );
		$role    = (string) ( $roles[0] ?? '' );
		$user    = null === $user_id ? false : get_userdata( $user_id );

		if ( null === $user_id || '' === $role || ! $user instanceof \WP_User ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The account named by the approved plan could not be loaded, so no role was changed.',
				'Confirm the account still exists with user-list and request a fresh preview.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$user->set_role( $role );

		return UserFields::targetKey( $user_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the user for verification.
	 *
	 * The user cache is cleared first. `set_role()` writes the capability meta
	 * value and updates the object it was called on, but the projection is built
	 * from a fresh `get_userdata()` — and that reads through the user cache, which
	 * still holds the object as it was before the write on any site with a
	 * persistent object cache. Verifying against that read would report a correct
	 * write as unapplied.
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
		$user_id = UserFields::userIdFromKey( $targetKey );

		if ( null !== $user_id ) {
			clean_user_cache( $user_id );
		}

		$user = null === $user_id ? false : get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The user account could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, UserFields::project( $user ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Puts every recorded role back.
	 *
	 * The target-bound capability is re-checked before anything is written, for
	 * the reason ContentRollbackApply gives: a rollback can arrive in a later
	 * request than the write it reverses, and the account that may undo a change
	 * is not automatically the one that made it. Here the stake is higher than
	 * elsewhere — a snapshot holding `administrator` is a stored grant of full
	 * control, and replaying it without a fresh check would make the ledger itself
	 * an escalation path.
	 *
	 * An empty recorded list is restored as no role at all, which is a state
	 * WordPress genuinely supports and this operation can genuinely have replaced.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$user_id = (int) ( $restoreState['user_id'] ?? 0 );

		if ( $user_id < 1 || ! isset( $restoreState['roles'] ) || ! is_array( $restoreState['roles'] ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not identify a user account and the roles it held.',
				'Set the account back to its previous role in the WordPress users screen.'
			);
		}

		$this->policy->authorizeTargetCapability(
			UserFields::TARGET_CAPABILITY,
			$user_id,
			'user-role-set',
			$context
		);

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The account whose roles were recorded no longer exists, so they cannot be restored.',
				'No further action is available through this plugin; the account is gone.'
			);
		}

		$roles = array_values( array_map( 'strval', $restoreState['roles'] ) );

		$user->set_role( (string) ( $roles[0] ?? '' ) );

		foreach ( array_slice( $roles, 1 ) as $additional ) {
			$user->add_role( $additional );
		}

		return UserFields::targetKey( $user_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The warnings a preview carries.
	 *
	 * Both describe something the write does that the arguments do not say. A
	 * caller sending `role: editor` has not asked for the other three roles the
	 * account holds to be removed, and a caller sending `role: administrator` has
	 * not necessarily read what that grants.
	 *
	 * @param string[] $held The roles the account holds now.
	 * @param string   $role The role being granted.
	 *
	 * @return string[] The warnings, or an empty list.
	 */
	private function warnings( array $held, string $role ): array {
		$warnings = [];

		if ( count( $held ) > 1 ) {
			$warnings[] = sprintf(
				'This account currently holds more than one role (%s). A role change replaces all of them with the single role requested; the others are recorded in the snapshot and returned by a rollback.',
				implode( ', ', $held )
			);
		}

		if ( self::ADMINISTRATOR === $role && ! in_array( self::ADMINISTRATOR, $held, true ) ) {
			$warnings[] = 'This grants full administrative control of the site, including the ability to change or remove every other account.';
		}

		return $warnings;
	}

	/**
	 * How many administrators this site has.
	 *
	 * Counted with a role-filtered query asking for identifiers only and capped at
	 * two rows, because the only question is "is there another one". Counting the
	 * whole set would read every administrator on a site that has hundreds to
	 * answer a yes-or-no question.
	 *
	 * @return int The number of administrators found, up to two.
	 */
	private function administratorCount(): int {
		$found = get_users(
			[
				'role'   => self::ADMINISTRATOR,
				'fields' => 'ID',
				'number' => 2,
			]
		);

		return is_array( $found ) ? count( $found ) : 0;
	}

	/**
	 * Resolves one recorded account for a rollback, and re-checks that this
	 * caller may change that account's role.
	 *
	 * BOTH capabilities are asked, and neither is implied by the rollback
	 * operation's own front gate: `content-rollback-apply` declares `edit_post`,
	 * so without this re-check a caller who may edit a post could reverse a role
	 * change through the rollback path that `user-role-set` itself refuses them.
	 * `promote_users` is the site-wide gate and `edit_user` is the target-bound
	 * one, and the second is asked against the account rather than declared
	 * anywhere — a meta capability with no target resolves to `do_not_allow`.
	 *
	 * @param string           $targetKey The recorded target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The account's current state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the key
	 *                           names no account, or ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		$user_id = UserFields::userIdFromKey( $targetKey );

		if ( null === $user_id ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No user on this site matches the requested identifier.',
				'Call user-list to see the accounts this site holds, and confirm the identifier you named.'
			);
		}

		$this->policy->authorizeTargetCapability(
			UserFields::WRITE_CAPABILITY,
			$user_id,
			'user-role-set',
			$context
		);
		$this->policy->authorizeTargetCapability(
			UserFields::TARGET_CAPABILITY,
			$user_id,
			'user-role-set',
			$context
		);

		return $this->resolveTarget( [ 'id' => $user_id ], $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The roles a rollback of this snapshot would put back.
	 *
	 * THE THREE LOCKOUT REFUSALS planChange() RAISES ARE RAISED HERE TOO, because
	 * a rollback is a role change like any other and every one of them describes a
	 * way to lock a site out of its own administration. Reversing a promotion is
	 * the case that makes this concrete: the account being demoted may since have
	 * become the only administrator, and the forward operation refuses exactly
	 * that. A rollback path that skipped them would be the way around them.
	 *
	 * The role-registration check is a REFUSAL rather than a filter. `set_role()`
	 * would store an unregistered slug, but WP_User::$roles is built by filtering
	 * against the registered roles, so the account would read back holding fewer
	 * roles than the rollback promised — restore a subset, verify against the
	 * narrowed promise, report success. Saying the recorded state can no longer be
	 * reproduced is the honest answer.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised roles, or an empty map.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		$user_id  = UserFields::userIdFromKey( $current->targetKey );
		$recorded = $restoreState['roles'] ?? null;

		if ( null === $user_id || ! is_array( $recorded ) || [] === $recorded ) {
			return [];
		}

		$roles      = array_values( array_map( 'strval', $recorded ) );
		$registered = UserFields::registeredRoles();

		foreach ( $roles as $role ) {
			if ( ! in_array( $role, $registered, true ) ) {
				return [];
			}
		}

		$this->assertRollbackIsSafe( $user_id, $roles, $current, $context );

		return [ self::PROMISED_FIELD => $roles ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Refuses a rollback that would lock this site out of its own administration.
	 *
	 * The three refusals are planChange()'s, in its order and with its messages.
	 * They are not shared with it as one method because the two differ in what
	 * they compare the site against: planChange() measures a role being SET, this
	 * measures a set of roles being RESTORED, and collapsing them would make the
	 * forward operation's refusal depend on a shape only a rollback has.
	 *
	 * @param int                $user_id The account being restored.
	 * @param array<int, string> $roles   The roles it would be restored to.
	 * @param TargetState        $current The resolved current state.
	 * @param OperationContext   $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertRollbackIsSafe( int $user_id, array $roles, TargetState $current, OperationContext $context ): void {
		if ( $user_id === $context->userId ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This operation will not change the role of the account it is running as.',
				'Ask another administrator to change your role, or change it in the WordPress users screen where the consequences of locking yourself out are visible.'
			);
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'That account is a network super admin, so its capabilities come from the network rather than this site\'s role.',
				'Change the account\'s network privileges in the network administration screens; a role change here would not alter what it may do.'
			);
		}

		$held = array_map( 'strval', (array) ( $current->fields[ self::PROMISED_FIELD ] ?? [] ) );

		if ( in_array( self::ADMINISTRATOR, $held, true )
			&& ! in_array( self::ADMINISTRATOR, $roles, true )
			&& $this->administratorCount() < 2 ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'That account is the only administrator on this site, so changing its role would leave nobody able to administer it.',
				'Promote another account to administrator first, then change this one.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
