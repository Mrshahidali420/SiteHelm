<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Policy;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Tests\TestCase;

/**
 * @package SiteHelm
 */
final class PolicyEngineTest extends TestCase {

	private PolicyEngine $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new PolicyEngine();
	}

	private function makeContext( PermissionMode $mode ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: $mode,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	private function makeDefinition( Mode $mode, array $capabilities ): OperationDefinition {
		$is_read = Mode::Read === $mode;
		return new OperationDefinition(
			id: $is_read ? 'content-list' : 'content-update',
			domain: Domain::Content,
			mode: $mode,
			description: 'Test operation.',
			inputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			outputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			schemaVersion: 1,
			requiredCapabilities: $capabilities,
			risk: Risk::Low,
			isReadOnly: $is_read,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: $is_read ? PreviewPolicy::NotApplicable : PreviewPolicy::Required,
			snapshotPolicy: $is_read ? SnapshotPolicy::NotApplicable : SnapshotPolicy::Required,
			rollbackPolicy: $is_read ? RollbackPolicy::NotApplicable : RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [ 'operation' => 'content-list', 'arguments' => [] ],
		);
	}

	public function test_read_operation_allowed_in_read_only_mode(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'edit_posts' ] ),
			$this->makeContext( PermissionMode::ReadOnly )
		);
		$this->addToAssertionCount( 1 ); // no exception thrown
	}

	public function test_write_operation_forbidden_in_read_only_mode(): void {
		Functions\when( 'user_can' )->justReturn( true );
		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_posts' ] ),
				$this->makeContext( PermissionMode::ReadOnly )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_missing_capability_is_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->expectException( OperationException::class );
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'edit_posts' ] ),
			$this->makeContext( PermissionMode::SafeWrite )
		);
	}

	public function test_meta_capability_receives_target_id(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];
				return true;
			}
		);
		$this->policy->authorize(
			$this->makeDefinition( Mode::Write, [ 'edit_post' ] ),
			$this->makeContext( PermissionMode::SafeWrite ),
			42
		);
		$this->assertSame( [ [ 'edit_post', [ 42 ] ] ], $received );
	}

	public function test_capability_without_target_omits_target_argument(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];
				return true;
			}
		);
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'read' ] ),
			$this->makeContext( PermissionMode::SafeWrite )
		);
		$this->assertSame( [ [ 'read', [] ] ], $received );
	}
}
