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

	/**
	 * REQ-0076: a retired domain that still resolves lets a forgotten connector go
	 * on writing to a site its operator believes it left. The request is not
	 * malformed and the credentials are real, so nothing else in the gate stops it.
	 */
	public function test_write_from_a_retired_host_is_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		$_SERVER['HTTP_HOST'] = 'old-agency-site.com';

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_posts' ] ),
				$this->makeContext( PermissionMode::SafeWrite )
			);
			$this->fail( 'A write arriving on a retired host must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringContainsString( 'no longer answers as', $e->getMessage() );
		} finally {
			unset( $_SERVER['HTTP_HOST'] );
		}
	}

	/**
	 * Reads stay available on purpose. An operator whose connector is pointed at
	 * the wrong domain needs the diagnostics that say so, and a read cannot change
	 * the site it reached by mistake.
	 */
	public function test_read_from_a_retired_host_is_still_allowed(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$_SERVER['HTTP_HOST'] = 'old-agency-site.com';

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Read, [ 'edit_posts' ] ),
				$this->makeContext( PermissionMode::SafeWrite )
			);
			$this->addToAssertionCount( 1 ); // no exception thrown
		} finally {
			unset( $_SERVER['HTTP_HOST'] );
		}
	}

	public function test_write_from_the_site_own_host_is_allowed(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$_SERVER['HTTP_HOST'] = 'example.com';

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_posts' ] ),
				$this->makeContext( PermissionMode::SafeWrite )
			);
			$this->addToAssertionCount( 1 ); // no exception thrown
		} finally {
			unset( $_SERVER['HTTP_HOST'] );
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

	/**
	 * `assign_terms` is taxonomy-scoped, not post-scoped: WordPress resolves it
	 * through get_taxonomy( $tax )->cap->assign_terms and never against a post
	 * id. It is still a member of OperationDefinition::ALLOWED_CAPABILITIES, so
	 * a future operation may still declare it — and when one does, the gate must
	 * ask WordPress for the primitive rather than substituting a post-scoped
	 * check against a target id that means nothing here.
	 */
	public function test_declaring_assign_terms_asks_for_the_primitive_not_a_post_scoped_check(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];

				return 'assign_terms' !== $capability;
			}
		);

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'assign_terms' ] ),
				$this->makeContext( PermissionMode::SafeWrite ),
				42
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [ [ 'assign_terms', [] ] ], $received );
	}

	/**
	 * The sharp edge of the removed map row. A caller holding edit_posts and
	 * nothing else must NOT be granted assign_terms by substitution. Restoring
	 * the map row makes this authorize() call succeed, so this is the test that
	 * pins the removal rather than merely observing a refusal.
	 */
	public function test_edit_posts_does_not_substitute_for_assign_terms(): void {
		// Captured so the refusal is pinned to the capability actually checked.
		// Without this the test passes for ANY capability other than edit_posts,
		// and the message assertion below pins only the declared string, not the
		// checked one — so a future substitution to some third capability would
		// still be refused here and still be wrong.
		$received = [];

		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = $capability;

				return 'edit_posts' === $capability;
			}
		);

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'assign_terms' ] ),
				$this->makeContext( PermissionMode::SafeWrite )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringContainsString( 'assign_terms', $e->getMessage() );
			$this->assertSame( [ 'assign_terms' ], $received );
		}
	}

	/**
	 * An identifier nothing answers to must not come back as a permissions
	 * failure. WordPress maps a meta-capability against a missing post to
	 * do_not_allow, so the precise check refuses an administrator over a typo —
	 * and `content-style-check` on a live site did exactly that, telling the
	 * operator to ask for access to a post that had never existed, while
	 * `content-get`, which declares the primitive, said target_not_found for the
	 * same class of mistake.
	 *
	 * The gate now steps aside and lets the handler answer. Both capabilities
	 * are asked, in order, so the fallback cannot be reached without the precise
	 * check failing first.
	 */
	public function test_a_missing_target_falls_through_to_the_handler_rather_than_being_refused(): void {
		$received = [];

		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];

				return 'edit_posts' === $capability;
			}
		);

		$this->policy->authorize(
			$this->makeDefinition( Mode::Write, [ 'edit_post' ] ),
			$this->makeContext( PermissionMode::SafeWrite ),
			2170
		);

		$this->assertSame( [ [ 'edit_post', [ 2170 ] ], [ 'edit_posts', [] ] ], $received );
	}

	/**
	 * The fallback is scoped to a target that is not there. A post that exists
	 * and belongs to somebody else is the case the precise check is for, and it
	 * still refuses — otherwise this would have widened every post-scoped
	 * operation to anyone holding the site-wide primitive.
	 */
	public function test_a_post_someone_else_owns_is_still_refused(): void {
		Functions\when( 'get_post' )->justReturn( (object) [ 'ID' => 2170 ] );
		Functions\when( 'user_can' )->alias(
			static fn ( int $user, string $capability, ...$args ): bool => 'edit_posts' === $capability
		);

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_post' ] ),
				$this->makeContext( PermissionMode::SafeWrite ),
				2170
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * A caller who holds neither is refused whether or not the target exists, so
	 * a missing identifier cannot be used to get further into an operation than
	 * a present one would.
	 */
	public function test_a_missing_target_does_not_admit_a_caller_without_the_primitive(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_post' ] ),
				$this->makeContext( PermissionMode::SafeWrite ),
				2170
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}
}
