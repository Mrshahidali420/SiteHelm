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

	/**
	 * The fingerprint of a known state is pinned to a known value.
	 *
	 * Every other test here compares two fingerprints to each other, which
	 * proves the hash reacts to a change but says nothing about what goes into
	 * it. A golden value pins the whole structure at once: adding a key,
	 * removing one, reordering them, changing the algorithm, or altering the
	 * JSON encoding all move this hash.
	 *
	 * It closes the specific hole a review found — injecting a clock-dependent
	 * value such as time() into the hashed array left every comparison test
	 * passing, because both calls in a sub-second test read the same second.
	 * A fingerprint that drifts with the clock would fail apply with `conflict`
	 * for a target nobody touched, which is the failure mode most likely to
	 * make operators stop trusting the check.
	 *
	 * If a deliberate change to the hashed shape breaks this, recompute the
	 * value — but only after confirming the change was intended, because every
	 * previously issued plan token's stored fingerprint stops matching.
	 */
	public function test_the_fingerprint_of_a_known_state_is_a_known_value(): void {
		$this->assertSame(
			'b45b517426e6dc7c623371f846eaa06e65637367de9c1df3009b801660df76d0',
			$this->fingerprint->compute( $this->makeState(), $this->makeContext() )
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
