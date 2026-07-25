<?php
/**
 * Tests for ContextFactory.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Tests\TestCase;

/**
 * Tests ContextFactory.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 */
final class ContextFactoryTest extends TestCase {

	/**
	 * Stubs WordPress functions for context factory tests.
	 *
	 * @param int    $user_id User ID for get_current_user_id.
	 * @param string $mode    Permission mode for get_option.
	 */
	private function stubWordPress( int $user_id, string $mode = 'safe-write' ): void {
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'home_url' )->justReturn( 'https://client-site.example.com' );
		Functions\when( 'get_option' )->justReturn( $mode );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Wrapping wp_parse_url via alias.
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) );
		// phpcs:enable WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * Test that context is built for authenticated users.
	 */
	public function test_builds_context_for_authenticated_user(): void {
		$this->stubWordPress( 7 );
		$context = ( new ContextFactory() )->create(
			[
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			],
			'claude-desktop'
		);

		$this->assertSame( 7, $context->userId );
		$this->assertSame( 'client-site.example.com', $context->siteId );
		$this->assertSame( 'claude-desktop', $context->clientId );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
		$this->assertSame( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $context->correlationId );
		$this->assertGreaterThan( 0, $context->requestTime );
	}

	/**
	 * Test that unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_request_is_rejected(): void {
		$this->stubWordPress( 0 );
		try {
			( new ContextFactory() )->create( [] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::AuthenticationFailed, $e->errorCode );
		}
	}

	/**
	 * Test that invalid stored modes fall back to safe-write.
	 */
	public function test_invalid_stored_mode_falls_back_to_safe_write(): void {
		$this->stubWordPress( 7, 'yolo-mode' );
		$context = ( new ContextFactory() )->create( [] );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
	}

	/**
	 * Test that read-only mode is honored.
	 */
	public function test_read_only_mode_is_honored(): void {
		$this->stubWordPress( 7, 'read-only' );
		$context = ( new ContextFactory() )->create( [] );
		$this->assertSame( PermissionMode::ReadOnly, $context->permissionMode );
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
