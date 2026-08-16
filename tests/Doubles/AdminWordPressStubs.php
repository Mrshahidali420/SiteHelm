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
	 * Installs the double set and clears state from the previous test.
	 */
	public static function install(): void {
		self::$canManage            = true;
		self::$isSsl                = true;
		self::$options              = [];
		self::$transients           = [];
		self::$deletedTransients    = [];
		self::$currentUserId        = 7;
		self::$currentUserLogin     = 'agency';
		self::$applicationPasswords = true;

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

		Functions\when( 'current_user_can' )->alias( static fn(): bool => self::$canManage );
		Functions\when( 'is_ssl' )->alias( static fn(): bool => self::$isSsl );
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
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = false ) => self::$options[ $name ] ?? $default_value
		);
		Functions\when( 'get_transient' )->alias(
			static fn( string $key ) => self::$transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ): bool {
				self::$transients[ $key ] = $value;

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
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_is_application_passwords_available' )->alias(
			static fn(): bool => self::$applicationPasswords
		);
		Functions\when( 'get_current_user_id' )->alias( static fn(): int => self::$currentUserId );
		Functions\when( 'wp_get_current_user' )->alias(
			static function (): object {
				$user             = new \stdClass();
				$user->ID         = self::$currentUserId;
				$user->user_login = self::$currentUserLogin;

				return $user;
			}
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
}
