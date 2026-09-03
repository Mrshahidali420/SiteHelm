<?php
/**
 * Shared WordPress stubs for the Auth tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Gives every Auth test the same small WordPress: an options array, a
 * transients array, a $wpdb double, and URL helpers rooted at one site address.
 *
 * The stubs are deliberately literal rather than clever. A test that asserts a
 * URL is only worth anything if the helper that built it behaved the way
 * WordPress does, so `rest_url()` returns the pretty-permalink shape and
 * `add_query_arg()` really appends.
 */
abstract class AuthTestCase extends TestCase {

	protected const SITE = 'https://example.com';

	protected FakeWpdb $wpdb;

	/** @var array<string, mixed> */
	protected array $options = [];

	/** @var array<string, mixed> */
	protected array $transients = [];

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [];
		$this->transients = [];

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, mixed $value, mixed $autoload = null ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );

				return true;
			}
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ): mixed => $this->transients[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value, int $ttl = 0 ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );

				return true;
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component )
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( mixed $value ): string => (string) json_encode( $value )
		);
		Functions\when( 'home_url' )->justReturn( self::SITE );
		Functions\when( 'rest_url' )->alias(
			static fn( string $route = '' ): string => self::SITE . '/wp-json/' . ltrim( $route, '/' )
		);
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => self::SITE . '/wp-admin/' . ltrim( $path, '/' )
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( string $key, string $value, string $url ): string {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . rawurlencode( $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( $value ) );
		Functions\when( 'wp_unslash' )->alias( static fn( mixed $value ): mixed => $value );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}
}
