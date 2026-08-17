<?php
/**
 * Tests for user-role-set.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\UserFields;
use SiteHelm\Modules\Core\UserRoleSet;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Tests\Doubles\UserWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The write, driven over a store that actually mutates.
 *
 * THE FOUR REFUSALS ARE THE SUBSTANCE OF THIS OPERATION, not its edit: replacing a
 * role is one call, and every line of judgement in the class is about when not to
 * make it. Each is driven here, and each is driven through planChange() because
 * that is the method the change engine runs in BOTH phases — a guard that only
 * held at preview would be a guard a caller could wait out.
 *
 * THE ROLLBACK TEST IS THE SECOND MOST IMPORTANT ONE. `set_role()` drops every
 * other role a user held, so a rollback that only replayed the first recorded role
 * would undo the write and destroy the rest, leaving nothing behind to say they had
 * existed.
 */
final class UserRoleSetTest extends TestCase {

	use UserWordPressStubs;

	private UserRoleSet $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installUserStubs();
		$this->operation = new UserRoleSet( new PolicyEngine() );
	}

	/**
	 * @param int $userId The acting user.
	 *
	 * @return OperationContext A context resolving to that user.
	 */
	private function context( int $userId = 7 ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: $userId,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Resolves a seeded user the way the change engine would.
	 *
	 * @param int                       $userId The user identifier.
	 * @param array<int|string, string> $roles  The roles held.
	 *
	 * @return TargetState The resolved state.
	 */
	private function resolved( int $userId, array $roles = [ 'subscriber' ] ): TargetState {
		$this->seedUser( $userId, $roles );

		return $this->operation->resolveTarget( [ 'id' => $userId ], $this->context() );
	}

	public function test_the_definition_declares_a_high_risk_reversible_write(): void {
		$definition = UserRoleSet::definition();

		$this->assertSame( 'user-role-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( [ 'promote_users' ], $definition->requiredCapabilities );
		$this->assertSame( false, $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * The target-bound capability is deliberately not declared.
	 *
	 * A meta capability with no target resolves to `do_not_allow`, so declaring
	 * `edit_user` would refuse every caller including administrators while looking
	 * like a tightening.
	 */
	public function test_the_target_bound_capability_is_not_declared(): void {
		$this->assertNotContains( 'edit_user', UserRoleSet::definition()->requiredCapabilities );
	}

	public function test_resolving_an_absent_user_refuses_and_names_the_read(): void {
		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->context() );
			$this->fail( 'An absent user must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringContainsString( 'user-list', (string) $exception->remediation );
		}
	}

	public function test_resolving_projects_the_current_state_under_a_user_key(): void {
		$state = $this->resolved( 12, [ 'editor' ] );

		$this->assertSame( 'user:12', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( [ 'editor' ], $state->fields['roles'] );
	}

	public function test_the_plan_promises_exactly_the_one_role_field(): void {
		$state = $this->resolved( 12, [ 'subscriber' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'editor',
			],
			$this->context()
		);

		$this->assertSame( [ 'roles' => [ 'editor' ] ], $planned->payload );
		$this->assertSame( [ 'roles' => [ 'editor' ] ], $planned->afterFields );
		$this->assertSame( UserFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * An unregistered slug is refused, and the refusal names the live vocabulary.
	 *
	 * `set_role()` writes whatever slug it is handed, so a typo would not demote the
	 * account to something lesser — it would leave it holding a role the site has
	 * never registered, which grants nothing and reads as a successful write.
	 */
	public function test_a_role_the_site_has_not_registered_is_refused(): void {
		$state = $this->resolved( 12, [ 'subscriber' ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 12,
					'role' => 'edtior',
				],
				$this->context()
			);
			$this->fail( 'An unregistered role slug must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'editor', (string) $exception->remediation );
			$this->assertStringContainsString( 'subscriber', (string) $exception->remediation );
		}

		$this->assertSame( [ 'subscriber' ], $this->storedRoles( 12 ) );
	}

	/**
	 * A role a plugin registered is accepted, because the vocabulary is the site's.
	 */
	public function test_a_role_the_site_registered_at_runtime_is_accepted(): void {
		$this->siteRoles = [
			'administrator' => 'Administrator',
			'shop_manager'  => 'Shop manager',
		];

		$state = $this->resolved( 12, [ 'administrator' ] );
		$this->seedUser( 13, [ 'administrator' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'shop_manager',
			],
			$this->context()
		);

		$this->assertSame( [ 'roles' => [ 'shop_manager' ] ], $planned->payload );
	}

	/**
	 * Changing your own role is refused in both directions.
	 *
	 * A self-demotion locks the operator out mid-session; a self-promotion is the
	 * escalation this gateway must never be the shortest path to.
	 */
	public function test_changing_your_own_role_is_refused(): void {
		$state = $this->resolved( 7, [ 'editor' ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 7,
					'role' => 'administrator',
				],
				$this->context( 7 )
			);
			$this->fail( 'The acting account must not change its own role.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [ 'editor' ], $this->storedRoles( 7 ) );
	}

	/**
	 * On multisite a super admin is refused, because the write would change nothing
	 * that governs what they may do while reporting success.
	 */
	public function test_a_network_super_admin_is_refused_on_multisite(): void {
		$this->multisite   = true;
		$this->superAdmins = [ 12 ];

		$state = $this->resolved( 12, [ 'administrator' ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 12,
					'role' => 'subscriber',
				],
				$this->context()
			);
			$this->fail( 'A network super admin must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * On a single site the super admin check does not apply, so the same account is
	 * an ordinary administrator and the last-administrator guard is what answers.
	 */
	public function test_the_super_admin_check_does_not_fire_on_a_single_site(): void {
		$this->superAdmins = [ 12 ];

		$state = $this->resolved( 12, [ 'administrator' ] );
		$this->seedUser( 13, [ 'administrator' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);

		$this->assertSame( [ 'roles' => [ 'subscriber' ] ], $planned->payload );
	}

	/**
	 * Demoting the only administrator is refused.
	 *
	 * It is the one role change nobody could undo from inside WordPress, because
	 * after it there is no account left that may hand the role back.
	 */
	public function test_demoting_the_only_administrator_is_refused(): void {
		$state = $this->resolved( 12, [ 'administrator' ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 12,
					'role' => 'editor',
				],
				$this->context()
			);
			$this->fail( 'The last administrator must not be demoted.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}

		$this->assertSame( [ 'administrator' ], $this->storedRoles( 12 ) );
	}

	public function test_demoting_an_administrator_is_allowed_when_another_one_remains(): void {
		$state = $this->resolved( 12, [ 'administrator' ] );
		$this->seedUser( 13, [ 'administrator' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'editor',
			],
			$this->context()
		);

		$this->assertSame( [ 'roles' => [ 'editor' ] ], $planned->payload );
	}

	/**
	 * The administrator count asks for identifiers only and caps at two rows.
	 *
	 * The question is "is there another one", and reading every administrator on a
	 * site with hundreds to answer it is a cost with no return.
	 */
	public function test_the_administrator_count_asks_for_two_identifiers_at_most(): void {
		$state = $this->resolved( 12, [ 'administrator' ] );
		$this->seedUser( 13, [ 'administrator' ] );

		$this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'editor',
			],
			$this->context()
		);

		$this->assertNotSame( [], $this->userQueries );
		$query = end( $this->userQueries );

		$this->assertSame( 'administrator', $query['role'] );
		$this->assertSame( 'ID', $query['fields'] );
		$this->assertSame( 2, $query['number'] );
	}

	/**
	 * Re-granting administrator to the only administrator is not a demotion.
	 *
	 * The guard fires on losing the role, not on touching an account that holds it,
	 * so an idempotent re-apply must not be refused as a lockout.
	 */
	public function test_re_granting_administrator_to_the_only_administrator_is_allowed(): void {
		$state = $this->resolved( 12, [ 'administrator' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'administrator',
			],
			$this->context()
		);

		$this->assertSame( [ 'roles' => [ 'administrator' ] ], $planned->payload );
	}

	/**
	 * The target-bound capability is re-checked against the specific account.
	 */
	public function test_the_plan_re_checks_the_target_bound_capability(): void {
		$state = $this->resolved( 12, [ 'subscriber' ] );

		$this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'editor',
			],
			$this->context()
		);

		$this->assertContains(
			[
				'user'       => 7,
				'capability' => 'edit_user',
				'target'     => 12,
			],
			$this->capabilityChecks
		);
	}

	public function test_a_caller_who_may_not_edit_this_account_is_refused(): void {
		$this->capabilities['edit_user'] = false;

		$state = $this->resolved( 12, [ 'subscriber' ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 12,
					'role' => 'editor',
				],
				$this->context()
			);
			$this->fail( 'A caller who may not edit this account must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [ 'subscriber' ], $this->storedRoles( 12 ) );
	}

	/**
	 * The preview warns that other roles are about to be replaced.
	 *
	 * A caller sending `role: editor` has not asked for the other roles the account
	 * holds to be removed, and the arguments do not say that they will be.
	 */
	public function test_a_multi_role_account_is_warned_about_before_it_is_collapsed(): void {
		$state = $this->resolved( 12, [ 'editor', 'author' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);

		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'more than one role', $planned->warnings[0] );
		$this->assertStringContainsString( 'editor', $planned->warnings[0] );
		$this->assertStringContainsString( 'author', $planned->warnings[0] );
	}

	public function test_granting_administrator_warns_what_it_carries(): void {
		$state = $this->resolved( 12, [ 'editor' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'administrator',
			],
			$this->context()
		);

		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'full administrative control', $planned->warnings[0] );
	}

	public function test_an_ordinary_single_role_change_carries_no_warnings(): void {
		$state = $this->resolved( 12, [ 'subscriber' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'editor',
			],
			$this->context()
		);

		$this->assertSame( [], $planned->warnings );
	}

	/**
	 * The preview detail names the account and both sides of the change.
	 */
	public function test_the_preview_detail_shows_the_login_and_both_role_sets(): void {
		$state = $this->resolved( 12, [ 'editor', 'author' ] );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);

		$this->assertSame( 'user12', $planned->previewDetail['login'] );
		$this->assertSame( [ 'editor', 'author' ], $planned->previewDetail['from'] );
		$this->assertSame( [ 'subscriber' ], $planned->previewDetail['to'] );
	}

	/**
	 * The snapshot records EVERY role, not the one being replaced.
	 *
	 * `set_role()` drops the others, so a snapshot holding a single role would turn
	 * a rollback into a second act of capability loss.
	 */
	public function test_the_snapshot_records_every_role_the_account_held(): void {
		$state = $this->resolved( 12, [ 'editor', 'author', 'subscriber' ] );

		$snapshot = $this->operation->captureSnapshot( $state, $this->context() );

		$this->assertSame(
			[
				'user_id' => 12,
				'roles'   => [ 'editor', 'author', 'subscriber' ],
			],
			$snapshot
		);
	}

	/**
	 * Snapshotting is side-effect free and answers identically when called twice,
	 * which is what the change engine relies on across its two phases.
	 */
	public function test_snapshotting_twice_answers_the_same_thing_and_writes_nothing(): void {
		$state = $this->resolved( 12, [ 'editor' ] );

		$first  = $this->operation->captureSnapshot( $state, $this->context() );
		$second = $this->operation->captureSnapshot( $state, $this->context() );

		$this->assertSame( $first, $second );
		$this->assertSame( [ 'editor' ], $this->storedRoles( 12 ) );
	}

	public function test_applying_replaces_the_stored_roles_and_returns_the_target_key(): void {
		$state   = $this->resolved( 12, [ 'editor', 'author' ] );
		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);

		$key = $this->operation->applyChange( $state, $planned, $this->context() );

		$this->assertSame( 'user:12', $key );
		$this->assertSame( [ 'subscriber' ], $this->storedRoles( 12 ) );
	}

	/**
	 * An account that vanished between the plan and the apply is reported, not
	 * called into.
	 */
	public function test_applying_to_an_account_that_vanished_reports_execution_failed(): void {
		$state   = $this->resolved( 12, [ 'editor' ] );
		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);

		unset( $this->users[12] );

		try {
			$this->operation->applyChange( $state, $planned, $this->context() );
			$this->fail( 'A vanished account must be reported rather than written to.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertNotSame( [], $exception->completedSteps );
		}
	}

	/**
	 * The read-back clears the user cache first.
	 *
	 * `get_userdata()` reads through the user cache, which still holds the object as
	 * it was before the write on any site with a persistent object cache — so
	 * verifying against it would report a correct write as unapplied.
	 */
	public function test_the_read_back_clears_the_user_cache_before_projecting(): void {
		$this->resolved( 12, [ 'editor' ] );

		$state = $this->operation->readBack( 'user:12', $this->context() );

		$this->assertContains( 12, $this->cacheCleared );
		$this->assertSame( [ 'editor' ], $state->fields['roles'] );
	}

	public function test_a_read_back_that_cannot_find_the_account_names_the_correlation(): void {
		try {
			$this->operation->readBack( 'user:999', $this->context() );
			$this->fail( 'An unreadable account must fail verification.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $exception->remediation );
		}
	}

	/**
	 * The written state satisfies the promise, over the live store.
	 */
	public function test_the_promise_matches_the_state_the_write_actually_leaves(): void {
		$state   = $this->resolved( 12, [ 3 => 'editor' ] );
		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'author',
			],
			$this->context()
		);

		$key = $this->operation->applyChange( $state, $planned, $this->context() );
		$after = $this->operation->readBack( $key, $this->context() );

		foreach ( $planned->afterFields as $field => $promised ) {
			$this->assertSame( $promised, $after->fields[ $field ] );
		}
	}

	/**
	 * A rollback puts every recorded role back, first with set_role and then with
	 * add_role, so a multi-role account is not flattened by its own undo.
	 */
	public function test_a_rollback_restores_every_recorded_role(): void {
		$state    = $this->resolved( 12, [ 'editor', 'author' ] );
		$snapshot = $this->operation->captureSnapshot( $state, $this->context() );

		$planned = $this->operation->planChange(
			$state,
			[
				'id'   => 12,
				'role' => 'subscriber',
			],
			$this->context()
		);
		$this->operation->applyChange( $state, $planned, $this->context() );

		$key = $this->operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( 'user:12', $key );
		$this->assertSame( [ 'editor', 'author' ], array_values( (array) $this->storedRoles( 12 ) ) );

		$calls = $this->users[12]->roleCalls;
		$this->assertSame( 'set_role', $calls[1]['method'] );
		$this->assertSame( 'editor', $calls[1]['role'] );
		$this->assertSame( 'add_role', $calls[2]['method'] );
		$this->assertSame( 'author', $calls[2]['role'] );
	}

	/**
	 * An account that held no role is restored to holding none, which is a state
	 * WordPress supports and this write can genuinely have replaced.
	 */
	public function test_a_rollback_of_an_account_that_held_no_role_clears_the_role(): void {
		$this->seedUser( 12, [ 'editor' ] );

		$this->operation->restore(
			[
				'user_id' => 12,
				'roles'   => [],
			],
			$this->context()
		);

		$this->assertSame( [], $this->storedRoles( 12 ) );
	}

	/**
	 * The rollback re-checks the target-bound capability before writing anything.
	 *
	 * A snapshot holding `administrator` is a stored grant of full control, and a
	 * rollback can arrive in a later request than the write it reverses — so
	 * replaying it without a fresh check would make the ledger an escalation path.
	 */
	public function test_a_rollback_re_checks_the_capability_and_refuses_without_it(): void {
		$this->seedUser( 12, [ 'subscriber' ] );
		$this->capabilities['edit_user'] = false;

		try {
			$this->operation->restore(
				[
					'user_id' => 12,
					'roles'   => [ 'administrator' ],
				],
				$this->context()
			);
			$this->fail( 'A rollback must re-check the target-bound capability.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [ 'subscriber' ], $this->storedRoles( 12 ) );
	}

	/**
	 * @dataProvider unusableSnapshots
	 *
	 * @param array<string, mixed> $snapshot The recorded state under test.
	 */
	public function test_an_unusable_snapshot_reports_rollback_unavailable( array $snapshot ): void {
		try {
			$this->operation->restore( $snapshot, $this->context() );
			$this->fail( 'An unusable snapshot must report that no rollback is available.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}> The unusable snapshots.
	 */
	public static function unusableSnapshots(): array {
		return [
			'no user'          => [ [ 'roles' => [ 'editor' ] ] ],
			'zero user'        => [
				[
					'user_id' => 0,
					'roles'   => [ 'editor' ],
				],
			],
			'no roles member'  => [ [ 'user_id' => 12 ] ],
			'roles not a list' => [
				[
					'user_id' => 12,
					'roles'   => 'editor',
				],
			],
		];
	}

	public function test_a_rollback_for_an_account_that_no_longer_exists_reports_execution_failed(): void {
		try {
			$this->operation->restore(
				[
					'user_id' => 999,
					'roles'   => [ 'editor' ],
				],
				$this->context()
			);
			$this->fail( 'A rollback for a deleted account must be reported.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	/**
	 * A plan whose target key does not name a user refuses rather than defaulting.
	 */
	public function test_a_plan_over_an_unparseable_target_key_refuses(): void {
		$state = new TargetState( 'post:12', true, [ 'roles' => [ 'editor' ] ] );

		try {
			$this->operation->planChange(
				$state,
				[
					'id'   => 12,
					'role' => 'editor',
				],
				$this->context()
			);
			$this->fail( 'A target key that names no user must refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_a_snapshot_over_an_unparseable_target_key_is_null(): void {
		$state = new TargetState( 'post:12', true, [ 'roles' => [ 'editor' ] ] );

		$this->assertNull( $this->operation->captureSnapshot( $state, $this->context() ) );
	}
}
