<?php
/**
 * The one WordPress double set the admin console suites share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;

/**
 * The WordPress functions the four console screens call, doubled once.
 *
 * ONE COPY, BECAUSE AN UNFAITHFUL DOUBLE IS THIS BRANCH'S RECURRING DEFECT. A
 * second copy of a stub set is a place a fidelity fix can fail to reach, and
 * that failure has already produced more than a dozen incidents on this
 * codebase. Every admin suite extends this file rather than starting its own.
 *
 * THE ESCAPING DOUBLES ARE REAL. `esc_html()` here actually escapes, so a test
 * that asserts an injected value does not reach the page is testing the screen's
 * escaping rather than a stub that returns its argument unchanged. A pass-through
 * escape double would make every escaping assertion vacuous.
 *
 * @package SiteHelm
 */
final class AdminWordPressStubs {

	/**
	 * The capability answer every screen's gate reads.
	 */
	public static bool $canManage = true;

	/**
	 * Whether the doubled site is served over HTTPS.
	 */
	public static bool $isSsl = true;

	/**
	 * What wp_remote_post() answers the connection probe with. The default is
	 * the answer of a server that passes the Authorization header through.
	 *
	 * @var mixed
	 */
	public static mixed $probeResponse = [
		'response' => [ 'code' => 401 ],
		'body'     => '{"code":"invalid_username","message":"Unknown username.","data":{"status":401}}',
	];

	/**
	 * Options the doubled `get_option()` returns, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	public static array $options = [];

	/**
	 * Transients the doubled store holds, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	public static array $transients = [];

	/**
	 * Transient names passed to the doubled `delete_transient()`, in order.
	 *
	 * @var string[]
	 */
	public static array $deletedTransients = [];

	/**
	 * How long each transient was asked to live, keyed by name, in seconds.
	 *
	 * @var array<string, int>
	 */
	public static array $transientExpirations = [];

	/**
	 * The signed-in user's identifier.
	 */
	public static int $currentUserId = 7;

	/**
	 * The signed-in user's login name.
	 */
	public static string $currentUserLogin = 'agency';

	/**
	 * Whether the site allows application passwords.
	 */
	public static bool $applicationPasswords = true;

	/**
	 * Other accounts on the doubled site, as id to login and first role.
	 *
	 * @var array<int, array{login: string, role: string}>
	 */
	public static array $users = [];

	/**
	 * Account ids `current_user_can( 'edit_user', … )` answers true for.
	 *
	 * Separate from {@see self::$users} on purpose: the screen is supposed to
	 * offer only the accounts this person may act for, and a double that let
	 * every listed account through could not tell a screen that filters from one
	 * that does not.
	 *
	 * @var int[]
	 */
	public static array $editableUsers = [];

	/**
	 * User meta the doubled store holds, as account id to name to value.
	 *
	 * Written through as well as read, because the connect dialog's whole
	 * dismissal contract is that the flag survives the redirect: a double that
	 * only answered reads would let a handler that stored nothing pass.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $userMeta = [];

	/**
	 * What the doubled `wp_get_referer()` answers, or false for none.
	 *
	 * @var string|false
	 */
	public static $referer = false;

	/**
	 * Nonce actions passed to the doubled `check_admin_referer()`, in order.
	 *
	 * Recorded rather than merely allowed, because a handler that never verified
	 * its nonce would pass against a double that only returned true.
	 *
	 * @var string[]
	 */
	public static array $refererChecks = [];

	/**
	 * Installs the double set and clears state from the previous test.
	 */
	public static function install(): void {
		self::$refererChecks = [];
		self::$canManage            = true;
		self::$isSsl                = true;
		self::$probeResponse        = [
			'response' => [ 'code' => 401 ],
			'body'     => '{"code":"invalid_username","message":"Unknown username.","data":{"status":401}}',
		];
		self::$options              = [];
		self::$transients           = [];
		self::$deletedTransients    = [];
		self::$transientExpirations = [];
		self::$currentUserId        = 7;
		self::$currentUserLogin     = 'agency';
		self::$applicationPasswords = true;
		self::$users                = [];
		self::$editableUsers        = [];
		self::$userMeta             = [];
		self::$referer              = false;

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		/*
		 * `_n()` chooses between two DIFFERENT literals. A double that returned the
		 * singular unconditionally would let a screen print "1 modules are not
		 * active" while every assertion on the singular form still passed, so the
		 * choice is made here exactly as WordPress makes it for English.
		 */
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural
		);

		/*
		 * `current_user_can()` is asked TWO different questions here, and a double
		 * that answered both from one flag could not tell a screen that checks
		 * `edit_user` per account apart from one that never checks it at all. The
		 * meta capability is answered from its own list, against the object id the
		 * caller passed.
		 */
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability, ...$args ): bool {
				if ( 'edit_user' !== $capability ) {
					return self::$canManage;
				}

				return in_array( (int) ( $args[0] ?? 0 ), self::$editableUsers, true );
			}
		);
		Functions\when( 'get_users' )->alias(
			static function ( array $query = [] ): array {
				$exclude = array_map( 'intval', (array) ( $query['exclude'] ?? [] ) );
				$found   = [];

				foreach ( self::$users as $id => $user ) {
					if ( ! in_array( (int) $id, $exclude, true ) ) {
						$found[] = self::user( (int) $id );
					}
				}

				return $found;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ) => isset( self::$users[ $id ] ) ? self::user( $id ) : false
		);
		Functions\when( 'is_ssl' )->alias( static fn(): bool => self::$isSsl );
		Functions\when( 'wp_remote_post' )->alias( static fn(): mixed => self::$probeResponse );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $thing ): bool => $thing instanceof \Throwable );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( mixed $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( mixed $response ): string => (string) ( $response['body'] ?? '' )
		);

		Functions\when( 'wp_die' )->alias(
			static function ( $message = '' ): void {
				throw new AdminDied( is_string( $message ) ? $message : '' );
			}
		);

		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'rest_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test/wp-json/' . $path
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test' . $path
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000000' );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = false ) => self::$options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ): bool {
				self::$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static fn( string $key ) => self::$transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, $expiration = 0 ): bool {
				self::$transients[ $key ]           = $value;
				self::$transientExpirations[ $key ] = (int) $expiration;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ): bool {
				self::$deletedTransients[] = $key;
				unset( self::$transients[ $key ] );

				return true;
			}
		);

		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, ?int $timestamp = null ): string => gmdate( $format, (int) $timestamp )
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, int $flags = 0 ): string => (string) json_encode( $data, $flags )
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) )
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( $value ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $value ): string => trim( $value ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->alias( static fn( string $url, $action = -1 ): string => $url . '&_wpnonce=' . $action );
		Functions\when( 'check_admin_referer' )->alias(
			static function ( string $action ): bool {
				self::$refererChecks[] = $action;

				return true;
			}
		);
		Functions\when( 'wp_is_application_passwords_available' )->alias(
			static fn(): bool => self::$applicationPasswords
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key = '', bool $single = false ) {
				$value = self::$userMeta[ $user_id ][ $key ] ?? '';

				return $single ? $value : ( '' === $value ? [] : [ $value ] );
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ): bool {
				self::$userMeta[ $user_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_get_referer' )->alias( static fn() => self::$referer );
		Functions\when( 'get_current_user_id' )->alias( static fn(): int => self::$currentUserId );
		Functions\when( 'wp_get_current_user' )->alias(
			static fn(): object => self::user( self::$currentUserId )
		);
		/*
		 * Real `add_query_arg()` takes EITHER an array of pairs plus a URL, or a
		 * single key, a value and a URL. A double that accepts only the array form
		 * would throw on the three-argument call `ConnectScreen::go_back()` makes,
		 * and a double that accepted it but ignored the value would let a screen
		 * drop a query argument silently. Both forms are honoured here.
		 */
		Functions\when( 'add_query_arg' )->alias(
			static function ( $first, $second = null, $third = null ): string {
				$args = is_array( $first ) ? $first : [ (string) $first => $second ];
				$url  = is_array( $first ) ? (string) $second : (string) $third;

				$separator = str_contains( $url, '?' ) ? '&' : '?';

				return $url . $separator . http_build_query( $args );
			}
		);
	}

	/**
	 * One account, shaped the way WordPress shapes one.
	 *
	 * THE ROLE LIST IS NOT ZERO-INDEXED, DELIBERATELY. `WP_User::$roles` is built
	 * by filtering the capability list, and `array_filter()` preserves keys, so a
	 * real account's roles routinely start at an index other than zero. A double
	 * that handed back a tidy `[ 0 => 'editor' ]` would let a screen read
	 * `$roles[0]` and pass here while printing nothing on a live site.
	 *
	 * The current user falls back to an administrator, because that is who reaches
	 * a screen gated on `manage_options`.
	 *
	 * @param int $id The account identifier.
	 */
	private static function user( int $id ): object {
		$known = self::$users[ $id ] ?? null;

		$login = is_array( $known ) ? (string) $known['login'] : self::$currentUserLogin;
		$role  = is_array( $known ) ? (string) $known['role'] : 'administrator';

		$user               = new \stdClass();
		$user->ID           = $id;
		$user->user_login   = $login;
		$user->display_name = $login;
		$user->roles        = '' === $role ? [] : [ 3 => $role ];

		return $user;
	}
}
