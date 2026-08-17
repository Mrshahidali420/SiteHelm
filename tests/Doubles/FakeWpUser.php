<?php
/**
 * The WordPress user row, as WordPress actually hands it over.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase -- The member names are WordPress's own and cannot be renamed.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

/**
 * A stand-in for WP_User, aliased to that name by tests/bootstrap.php.
 *
 * `$roles` IS DELIBERATELY UNTYPED AND MAY CARRY PRESERVED KEYS. WordPress builds
 * it with `array_filter()` over the capability keys, and array_filter preserves
 * keys, so a real user can answer `[ 1 => 'editor' ]`. A double that always
 * answered a zero-indexed list would make `UserFields::rolesOf()`'s re-indexing
 * look like decoration, and the promise/read-back mismatch it prevents would only
 * appear on a live site. Tests set the awkward shape on purpose.
 *
 * `set_role()` and `add_role()` mutate this object and record what they were
 * asked to do, because they are what the write and the rollback call and neither
 * returns anything: recording the calls is the only way to assert that a rollback
 * put a multi-role user back rather than flattening it.
 */
final class FakeWpUser {

	public int $ID = 0;

	public string $user_login = '';

	public string $display_name = '';

	public string $user_email = '';

	public string $user_registered = '';

	/** @var array<int|string, string> The roles held, keys as WordPress leaves them. */
	public array $roles = [];

	/** @var array<int, array{method: string, role: string}> Every role mutation, in order. */
	public array $roleCalls = [];

	/**
	 * Builds a user from the members a test cares to name.
	 *
	 * @param array<string, mixed> $row The member values to override.
	 */
	public function __construct( array $row = [] ) {
		foreach ( $row as $member => $value ) {
			if ( 'roles' === $member ) {
				$this->roles = (array) $value;
				continue;
			}

			if ( 'ID' === $member ) {
				$this->ID = (int) $value;
				continue;
			}

			if ( property_exists( $this, (string) $member ) && ! is_array( $value ) ) {
				$this->{$member} = (string) $value;
			}
		}
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The method names are WordPress's own.

	/**
	 * Replaces every role with one, as WordPress does.
	 *
	 * An empty slug clears the roles rather than adding an empty one, which is the
	 * state `restore()` puts back for a user who held none.
	 *
	 * @param string $role The role slug to hold.
	 */
	public function set_role( string $role ): void {
		$this->roleCalls[] = [
			'method' => 'set_role',
			'role'   => $role,
		];

		$this->roles = '' === $role ? [] : [ $role ];
	}

	/**
	 * Adds one role alongside those already held.
	 *
	 * @param string $role The role slug to add.
	 */
	public function add_role( string $role ): void {
		$this->roleCalls[] = [
			'method' => 'add_role',
			'role'   => $role,
		];

		if ( ! in_array( $role, $this->roles, true ) ) {
			$this->roles[] = $role;
		}
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
