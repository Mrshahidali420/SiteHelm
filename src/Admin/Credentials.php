<?php
/**
 * The application passwords SiteHelm has minted, and revoking one.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use WP_Application_Passwords;

/**
 * A thin seam over WordPress's application-password store.
 *
 * The Connect screen mints credentials; this class is how it later finds them
 * again and takes one back. It only ever looks at passwords carrying SiteHelm's
 * own name ({@see ConnectScreen::PASSWORD_NAME}), so a credential another plugin
 * or a person created in the profile screen is neither listed nor revocable here.
 *
 * The two WordPress calls are injectable so the screen can be tested without the
 * static `WP_Application_Passwords` class, which has no double.
 *
 * @package SiteHelm
 */
final class Credentials {

	/**
	 * Lists one user's application passwords. Signature: (int $user_id): array.
	 *
	 * @var callable
	 */
	private $lister;

	/**
	 * Deletes one application password. Signature: (int $user_id, string $uuid): bool|\WP_Error.
	 *
	 * @var callable
	 */
	private $delete;

	/**
	 * Constructs the seam.
	 *
	 * @param callable|null $lister Lists a user's passwords; null for WordPress's own.
	 * @param callable|null $delete Deletes one; null for WordPress's own.
	 */
	public function __construct( ?callable $lister = null, ?callable $delete = null ) {
		$this->lister = $lister ?? static fn( int $user_id ): array => (array) WP_Application_Passwords::get_user_application_passwords( $user_id );
		$this->delete = $delete ?? static fn( int $user_id, string $uuid ) => WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}

	/**
	 * Every SiteHelm credential held by the given accounts, newest first.
	 *
	 * @param array<int, object> $users The accounts to look at, each with ID and user_login.
	 *
	 * @return array<int, array{user_id: int, login: string, uuid: string, created: int, last_used: int, last_ip: string}>
	 */
	public function for_users( array $users ): array {
		$found = [];

		foreach ( $users as $user ) {
			if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
				continue;
			}

			$user_id = (int) $user->ID;
			$login   = isset( $user->user_login ) ? (string) $user->user_login : '';

			foreach ( ( $this->lister )( $user_id ) as $password ) {
				if ( ! is_array( $password ) || ( $password['name'] ?? '' ) !== ConnectScreen::PASSWORD_NAME ) {
					continue;
				}

				$found[] = [
					'user_id'   => $user_id,
					'login'     => $login,
					'uuid'      => (string) ( $password['uuid'] ?? '' ),
					'created'   => (int) ( $password['created'] ?? 0 ),
					'last_used' => (int) ( $password['last_used'] ?? 0 ),
					'last_ip'   => (string) ( $password['last_ip'] ?? '' ),
				];
			}
		}

		usort( $found, static fn( array $a, array $b ): int => $b['created'] <=> $a['created'] );

		return $found;
	}

	/**
	 * Take one credential back. From now on that client's requests are refused
	 * by WordPress's own authentication, before SiteHelm is reached.
	 *
	 * Only a password carrying SiteHelm's name is revocable through here; a
	 * uuid that names some other application password is refused, so a forged
	 * form cannot use this route to revoke a credential this plugin never made.
	 *
	 * @param int    $user_id The account holding it.
	 * @param string $uuid    The password's uuid.
	 *
	 * @return bool Whether it was revoked.
	 */
	public function revoke( int $user_id, string $uuid ): bool {
		if ( '' === $uuid ) {
			return false;
		}

		$ours = false;

		foreach ( ( $this->lister )( $user_id ) as $password ) {
			if ( is_array( $password ) && ( $password['uuid'] ?? '' ) === $uuid && ( $password['name'] ?? '' ) === ConnectScreen::PASSWORD_NAME ) {
				$ours = true;
				break;
			}
		}

		if ( ! $ours ) {
			return false;
		}

		return true === ( $this->delete )( $user_id, $uuid );
	}
}
