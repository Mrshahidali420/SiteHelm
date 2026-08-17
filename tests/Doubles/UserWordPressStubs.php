<?php
/**
 * The WordPress surface the user operations actually touch.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use WP_User;

/**
 * A user store that holds accounts and mutates them.
 *
 * THE STORE IS THE POINT, for the reason the comment stubs give: `set_role()` here
 * mutates the stored object and `get_userdata()` hands the same object back, so the
 * write and the verification read that follows it meet over one piece of state. A
 * double that answered a canned "after" state would make every role write verify by
 * construction, and the promise-versus-projection comparison would be pinned by
 * nothing.
 *
 * THE CAPABILITY ANSWERS ARE PER CAPABILITY, not one boolean. The whole point of
 * this feature's permission model is that `list_users`, `promote_users` and the
 * target-bound `edit_user` are three different questions; a single flag would make
 * every test that thinks it is checking one of them actually check all three at
 * once, and the narrowing this feature depends on would be untested.
 */
trait UserWordPressStubs {

	/**
	 * User identifier => the stored account.
	 *
	 * @var array<int, WP_User>
	 */
	private array $users = [];

	/**
	 * Capability => whether the doubled user holds it.
	 *
	 * @var array<string, bool>
	 */
	private array $capabilities = [
		'list_users'    => true,
		'promote_users' => true,
		'edit_user'     => true,
	];

	/**
	 * Every capability question asked, in order.
	 *
	 * @var array<int, array{user: int, capability: string, target: ?int}>
	 */
	private array $capabilityChecks = [];

	/**
	 * The user identifiers whose cache was cleared, in order.
	 *
	 * @var int[]
	 */
	private array $cacheCleared = [];

	/**
	 * Every `get_users()` argument set, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $userQueries = [];

	/**
	 * The roles this site has registered, slug => display name.
	 *
	 * @var array<string, string>
	 */
	private array $siteRoles = [
		'administrator' => 'Administrator',
		'editor'        => 'Editor',
		'author'        => 'Author',
		'subscriber'    => 'Subscriber',
	];

	/**
	 * Whether the doubled site is a multisite network.
	 */
	private bool $multisite = false;

	/**
	 * The user identifiers the doubled network treats as super admins.
	 *
	 * @var int[]
	 */
	private array $superAdmins = [];

	/**
	 * Installs the whole surface.
	 */
	private function installUserStubs(): void {
		Functions\when( 'user_can' )->alias(
			function ( $user, $capability, $target = null ) {
				$this->capabilityChecks[] = [
					'user'       => (int) $user,
					'capability' => (string) $capability,
					'target'     => null === $target ? null : (int) $target,
				];

				return $this->capabilities[ (string) $capability ] ?? false;
			}
		);

		Functions\when( 'get_userdata' )->alias(
			fn( $user_id ) => $this->users[ (int) $user_id ] ?? false
		);

		Functions\when( 'wp_roles' )->alias(
			fn() => new FakeWpRoles( $this->siteRoles )
		);

		Functions\when( 'clean_user_cache' )->alias(
			function ( $user_id ): void {
				$this->cacheCleared[] = (int) $user_id;
			}
		);

		Functions\when( 'is_multisite' )->alias(
			fn(): bool => $this->multisite
		);

		Functions\when( 'is_super_admin' )->alias(
			fn( $user_id = false ): bool => in_array( (int) $user_id, $this->superAdmins, true )
		);

		// Answers identifiers because that is the only field the administrator count
		// asks for, and it honours `number` so the two-row cap the count relies on is
		// exercised rather than assumed.
		Functions\when( 'get_users' )->alias(
			function ( $args = [] ) {
				$args                = is_array( $args ) ? $args : [];
				$this->userQueries[] = $args;

				$role  = (string) ( $args['role'] ?? '' );
				$found = [];

				foreach ( $this->users as $id => $user ) {
					if ( '' !== $role && ! in_array( $role, (array) $user->roles, true ) ) {
						continue;
					}

					$found[] = $id;
				}

				$number = (int) ( $args['number'] ?? 0 );

				return $number > 0 ? array_slice( $found, 0, $number ) : $found;
			}
		);
	}

	/**
	 * Seeds one account.
	 *
	 * `$roles` is passed through untouched so a test can hand in the key-preserving
	 * shape WordPress's own `array_filter()` produces.
	 *
	 * @param int                       $user_id The user identifier.
	 * @param array<int|string, string> $roles   The roles held, keys included.
	 * @param array<string, mixed>      $fields  Any other members to override.
	 *
	 * @return WP_User The seeded account.
	 */
	private function seedUser( int $user_id, array $roles = [ 'subscriber' ], array $fields = [] ): WP_User {
		$user = new WP_User(
			array_merge(
				[
					'ID'              => $user_id,
					'user_login'      => 'user' . $user_id,
					'display_name'    => 'User ' . $user_id,
					'user_email'      => 'user' . $user_id . '@example.com',
					'user_registered' => '2026-08-01 10:00:00',
					'roles'           => $roles,
				],
				$fields
			)
		);

		$this->users[ $user_id ] = $user;

		return $user;
	}

	/**
	 * The roles one stored account holds right now.
	 *
	 * @param int $user_id The user identifier.
	 *
	 * @return array<int|string, string>|null The stored roles, or null when absent.
	 */
	private function storedRoles( int $user_id ): ?array {
		return isset( $this->users[ $user_id ] ) ? (array) $this->users[ $user_id ]->roles : null;
	}
}
