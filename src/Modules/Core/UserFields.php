<?php
/**
 * The vocabulary the user operations read, write, and address by.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use WP_User;

/**
 * User field names, role vocabulary, and target addressing.
 *
 * TWO CAPABILITIES, NOT ONE, and neither is `manage_options`. Listing users is
 * `list_users`; changing what a user may do is `promote_users`. WordPress
 * separates them because they are different powers — a client who may see who
 * has access is not thereby someone who may hand access out — and collapsing
 * both into the administrator's catch-all capability would make the read
 * unavailable to exactly the roles a site grants it to.
 *
 * THE ROLE VOCABULARY IS THE SITE'S, NOT THIS PLUGIN'S. Roles are registered at
 * runtime: a site with WooCommerce has `customer` and `shop_manager`, a
 * membership plugin adds its own, and a site can rename or remove the defaults.
 * So there is no enum of role slugs here, and the write validates against
 * whatever the site has registered at the moment it runs, naming the live list
 * in its refusal. An enum baked into a schema would refuse legitimate roles on
 * three sites out of four.
 *
 * NOTHING SENSITIVE IS PROJECTED. The user row also holds the password hash, the
 * password-reset activation key, and the session tokens in user meta — every one
 * of which is a credential rather than a fact about a person. None of them
 * appears in FIELD_ORDER, and none is reachable through these operations at all.
 *
 * @package SiteHelm
 */
final class UserFields {

	/**
	 * The capability the user read requires.
	 *
	 * A site-wide primitive, so it needs no target and is absent from
	 * PolicyEngine::META_CAPABILITY_MAP. That matches the capability: WordPress
	 * grants the users screen over the whole site or not at all.
	 */
	public const READ_CAPABILITY = 'list_users';

	/**
	 * The capability the role write requires.
	 *
	 * `promote_users` is what WordPress's own user-edit screen gates the role
	 * dropdown on — not `edit_users`, which governs editing a profile. It is the
	 * narrower and more accurate of the two: an operator who may correct a display
	 * name has not thereby been granted the power to make someone an
	 * administrator.
	 *
	 * It is a site-wide primitive, so the target-bound half of WordPress's rule —
	 * `edit_user` against the specific account — cannot be expressed as a
	 * declared capability and is re-checked inside the operation, where the target
	 * id is actually known.
	 */
	public const WRITE_CAPABILITY = 'promote_users';

	/**
	 * The target-bound capability the write re-checks for itself.
	 *
	 * Declared here rather than inline so the operation and its tests name the
	 * same string. It is deliberately NOT in the operation's
	 * `requiredCapabilities`: a meta capability with no target resolves to
	 * `do_not_allow`, which would refuse every caller including administrators.
	 */
	public const TARGET_CAPABILITY = 'edit_user';

	/**
	 * Every field a read reports, in the order it reports them.
	 *
	 * @var string[]
	 */
	public const FIELD_ORDER = [
		'id',
		'login',
		'displayName',
		'email',
		'roles',
		'registeredGmt',
	];

	/**
	 * The prefix every user target key carries.
	 *
	 * A user is not a post and not a comment, and the change ledger has to be
	 * able to tell `user:42` from `post:42` — otherwise a role change and a post
	 * edit look like two writes to one target.
	 */
	public const TARGET_PREFIX = 'user:';

	/**
	 * The largest page a caller may request, matching every other list.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * The longest role slug a caller may send.
	 *
	 * WordPress stores role slugs as array keys inside one serialised meta value
	 * with no length limit of its own, so this bounds the input rather than the
	 * storage. Anything longer than this is not a slug a site registered.
	 */
	public const ROLE_MAX_LENGTH = 64;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * Builds the target key for a user.
	 *
	 * @param int $user_id The user identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $user_id ): string {
		return self::TARGET_PREFIX . $user_id;
	}

	/**
	 * Reads the user identifier back out of a target key.
	 *
	 * Answers null rather than 0 for anything this class did not build, for the
	 * reason every other resolver does: 0 is not inert in the user functions, and
	 * a key that failed to parse must refuse rather than address a default.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The user identifier, or null when the key is not one of ours.
	 */
	public static function userIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$digits = substr( $key, strlen( self::TARGET_PREFIX ) );

		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $digits ) ) {
			return null;
		}

		return (int) $digits;
	}

	/**
	 * The role slugs registered on this site right now.
	 *
	 * Read through `wp_roles()` rather than the `wp_user_roles` option, because a
	 * plugin that registers a role with `add_role()` at runtime does not write it
	 * to that option on every request, and a validator that missed those roles
	 * would refuse slugs the site's own dropdown offers.
	 *
	 * @return string[] The registered role slugs, in registration order.
	 */
	public static function registeredRoles(): array {
		$roles = wp_roles();

		return array_values( array_keys( $roles->get_names() ) );
	}

	/**
	 * The roles one user holds, as a list.
	 *
	 * `WP_User::$roles` is built with `array_filter()` over the capability keys,
	 * and array_filter PRESERVES KEYS — so a user whose stored capability array
	 * happens to carry a non-role key first answers `[ 1 => 'editor' ]` rather
	 * than `[ 'editor' ]`. Every projection goes through here so a promised
	 * `[ 'editor' ]` cannot fail verification against a read-back that means the
	 * same thing with different keys.
	 *
	 * @param WP_User $user The user to read.
	 *
	 * @return string[] The role slugs, re-indexed from zero.
	 */
	public static function rolesOf( WP_User $user ): array {
		return array_values( array_map( 'strval', (array) $user->roles ) );
	}

	/**
	 * Projects one user into the reported field map.
	 *
	 * The email address is reported because it is the field an operator uses to
	 * tell two accounts apart, and it is already on the users screen the same
	 * capability unlocks. The password hash, the activation key and the session
	 * tokens are not reported, are not addressable, and have no member in
	 * FIELD_ORDER to put them in.
	 *
	 * The registration date is the stored `user_registered` value, which
	 * WordPress writes in GMT — named `registeredGmt` so nobody reads it as site
	 * time and computes an offset twice.
	 *
	 * @param WP_User $user The user to project.
	 *
	 * @return array<string, mixed> The reported fields, in FIELD_ORDER.
	 */
	public static function project( WP_User $user ): array {
		return [
			'id'            => (int) $user->ID,
			'login'         => (string) $user->user_login,
			'displayName'   => (string) $user->display_name,
			'email'         => (string) $user->user_email,
			'roles'         => self::rolesOf( $user ),
			'registeredGmt' => (string) $user->user_registered,
		];
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
