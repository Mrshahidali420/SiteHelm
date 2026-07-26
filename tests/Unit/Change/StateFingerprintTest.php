<?php
/**
 * Tests for StateFingerprint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Tests\TestCase;

/**
 * Tests that the fingerprint covers the target state and the module versions.
 */
final class StateFingerprintTest extends TestCase {

	private StateFingerprint $fingerprint;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->fingerprint = new StateFingerprint( new PayloadNormalizer() );
	}

	private function makeContext( ?string $core_version = '6.8.1' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => $core_version,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function makeState( string $title = 'Original title' ): TargetState {
		return new TargetState(
			'post:42',
			true,
			[
				'post_title'        => $title,
				'post_modified_gmt' => '2026-07-26 10:00:00',
				'terms'             => [ 'category' => [ 3, 5 ] ],
			]
		);
	}

	public function test_fingerprint_is_64_hex_characters(): void {
		$value = $this->fingerprint->compute( $this->makeState(), $this->makeContext() );

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $value ) );
	}

	public function test_same_state_and_versions_yield_the_same_fingerprint(): void {
		$this->assertSame(
			$this->fingerprint->compute( $this->makeState(), $this->makeContext() ),
			$this->fingerprint->compute( $this->makeState(), $this->makeContext() )
		);
	}

	public function test_a_changed_field_changes_the_fingerprint(): void {
		$this->assertNotSame(
			$this->fingerprint->compute( $this->makeState( 'Original title' ), $this->makeContext() ),
			$this->fingerprint->compute( $this->makeState( 'Edited title' ), $this->makeContext() )
		);
	}

	public function test_a_changed_core_module_version_changes_the_fingerprint(): void {
		$this->assertNotSame(
			$this->fingerprint->compute( $this->makeState(), $this->makeContext( '6.8.1' ) ),
			$this->fingerprint->compute( $this->makeState(), $this->makeContext( '6.9.0' ) )
		);
	}

	public function test_a_missing_core_version_entry_is_tolerated(): void {
		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);

		$this->assertSame(
			1,
			preg_match( '/^[0-9a-f]{64}$/', $this->fingerprint->compute( $this->makeState(), $context ) )
		);
	}

	public function test_the_target_key_and_existence_are_part_of_the_fingerprint(): void {
		$existing = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );
		$pending  = new TargetState( 'post:new', false, [ 'post_title' => 'x' ] );

		$this->assertNotSame(
			$this->fingerprint->compute( $existing, $this->makeContext() ),
			$this->fingerprint->compute( $pending, $this->makeContext() )
		);
	}

	/**
	 * The target key and existence flag vary together above, so that test alone
	 * would still pass if the fingerprint dropped one of the two and kept the
	 * other. These isolate each guarantee: only the target key differs here.
	 */
	public function test_the_target_key_alone_changes_the_fingerprint(): void {
		$post = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );
		$page = new TargetState( 'post:43', true, [ 'post_title' => 'x' ] );

		$this->assertNotSame(
			$this->fingerprint->compute( $post, $this->makeContext() ),
			$this->fingerprint->compute( $page, $this->makeContext() )
		);
	}

	/**
	 * Only the existence flag differs here, with the target key held fixed.
	 */
	public function test_existence_alone_changes_the_fingerprint(): void {
		$existing = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );
		$pending  = new TargetState( 'post:42', false, [ 'post_title' => 'x' ] );

		$this->assertNotSame(
			$this->fingerprint->compute( $existing, $this->makeContext() ),
			$this->fingerprint->compute( $pending, $this->makeContext() )
		);
	}
}
