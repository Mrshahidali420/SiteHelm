<?php
/**
 * Tests for the system-connection onboarding diagnostic.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Diagnostics\ConnectionCheck;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0004 at the response layer.
 *
 * Only half of REQ-0004's acceptance evidence lives here. The other half — that
 * an invalid credential answers `authentication_failed` — is a GATEWAY fact and
 * is proven in ContextFactoryTest, because the permission callback and
 * ContextFactory both refuse a request with no resolved user before dispatch
 * ever reaches a handler. This operation cannot observe a failed authentication
 * and deliberately contains no logic that pretends to.
 */
final class ConnectionCheckTest extends TestCase {

	/**
	 * A context whose user, site, client and mode are fixed and known.
	 *
	 * `moduleVersions` is empty on purpose: this operation reports the caller and
	 * the transport, and nothing it returns may vary with which plugins happen to
	 * be installed. A handler that grew a dependency on the health map would have
	 * to be given one here before it could be asserted.
	 *
	 * @return OperationContext The context under test.
	 */
	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Makes get_userdata answer with a user carrying these fields.
	 *
	 * @param int    $id The user id the context names.
	 * @param string $login The user_login core would resolve.
	 * @param string $display The display_name core would resolve.
	 */
	private function stubUser( int $id, string $login, string $display ): void {
		$user               = new \stdClass();
		$user->ID           = $id;
		$user->user_login   = $login;
		$user->display_name = $display;

		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	/**
	 * Makes the two WordPress 5.6 application-password functions answer this way.
	 *
	 * EVERY test in this file that reaches the handler must call this, and the
	 * reason is a Brain Monkey property worth stating once: a faked function is
	 * eval'd into the global namespace and stays defined for the rest of the
	 * PHP process, but its behaviour is torn down after each test — so a later
	 * test that does not re-state it finds `function_exists` answering TRUE and
	 * the call itself raising MissingFunctionExpectations. Leaving these unstubbed
	 * therefore does not test the "old core" path; it only makes a test's result
	 * depend on which tests ran before it. The genuinely-absent case is reachable
	 * only in a fresh process, which is what the first test below buys.
	 *
	 * @param bool        $available What wp_is_application_passwords_available() answers.
	 * @param string|null $uuid What rest_get_authenticated_app_password() answers.
	 */
	private function stubApplicationPasswordApi( bool $available, ?string $uuid ): void {
		Functions\when( 'wp_is_application_passwords_available' )->justReturn( $available );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( $uuid );
	}

	/**
	 * Core older than the application-passwords API answers "not available"
	 * rather than fataling on an undefined function.
	 *
	 * DECLARED FIRST, AND IN ITS OWN PROCESS, so that the guard it exists to
	 * prove is actually load-bearing. Brain Monkey defines a faked function into
	 * the global namespace permanently, so once any earlier test in this process
	 * has stubbed `wp_is_application_passwords_available`, `function_exists`
	 * answers true for the rest of the run and deleting the guard would leave
	 * this test green. In a fresh process the functions genuinely do not exist,
	 * and a handler that called them unguarded dies here.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_without_the_application_password_api_reports_it_unavailable(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertFalse( $data['applicationPassword']['available'] );
		$this->assertFalse( $data['applicationPassword']['inUse'] );
	}

	/**
	 * The requirement's deliverable: the caller learns who the site resolved
	 * them as, without reading a server log.
	 */
	public function test_the_report_names_the_authenticated_caller(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( false, null );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( 7, $data['user']['id'] );
		$this->assertSame( 'editor-jane', $data['user']['username'] );
		$this->assertSame( 'Jane', $data['user']['displayName'] );
	}

	/**
	 * The identity reported is the CONTEXT's user, never the ambient current
	 * one. A handler that had called `wp_get_current_user()` would satisfy every
	 * other assertion in this file, because in a unit test the ambient user and
	 * the context user are the same fiction — so the id get_userdata was asked
	 * for is captured and asserted directly.
	 */
	public function test_the_identity_is_resolved_from_the_context_user(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubApplicationPasswordApi( false, null );

		$asked = [];

		Functions\when( 'get_userdata' )->alias(
			static function ( mixed $user_id ) use ( &$asked ): object {
				$asked[]            = $user_id;
				$user               = new \stdClass();
				$user->ID           = 7;
				$user->user_login   = 'editor-jane';
				$user->display_name = 'Jane';

				return $user;
			}
		);

		( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( [ 7 ], $asked );
	}

	/**
	 * The transport facts are the plugin's own constants, never anything
	 * derived from the request. Reflecting a header back to the caller would
	 * put attacker-controlled text in a response.
	 */
	public function test_the_transport_block_is_built_from_the_plugins_own_constants(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( false, null );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( 'sitehelm/v1/mcp', $data['transport']['route'] );
		$this->assertSame( 'json-rpc-2.0', $data['transport']['protocol'] );
		$this->assertSame( 'safe-write', $data['transport']['permissionMode'] );
		$this->assertSame( 'example.com', $data['transport']['siteId'] );
		$this->assertSame( 'client', $data['transport']['clientId'] );
	}

	/**
	 * Whether an application password was used is reportable. WHICH one is a
	 * credential identifier and belongs in no envelope — asserted against the
	 * serialized response, because the point is that the uuid is absent
	 * everywhere, not merely absent from one member.
	 *
	 * The absence is attributable rather than vacuous: `inUse` being true is the
	 * positive control that `rest_get_authenticated_app_password()` really was
	 * called and really did answer with this exact uuid, so the string asserted
	 * absent is one the handler held in its hand and chose not to emit.
	 */
	public function test_the_application_password_uuid_never_reaches_the_response(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( true, 'e5f1c0de-0000-4000-8000-000000000001' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertTrue( $data['applicationPassword']['available'] );
		$this->assertTrue( $data['applicationPassword']['inUse'] );
		$this->assertStringNotContainsString(
			'e5f1c0de-0000-4000-8000-000000000001',
			(string) wp_json_encode( $data )
		);
	}

	/**
	 * A site that offers Application Passwords but a request that did not use
	 * one is a distinct, reportable state — the commonest onboarding failure is
	 * a caller authenticated by a cookie who believes they are on a token.
	 *
	 * Without this, `available` and `inUse` could be one value under two names.
	 */
	public function test_an_available_api_that_this_request_did_not_use_reads_as_not_in_use(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( true, null );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertTrue( $data['applicationPassword']['available'] );
		$this->assertFalse( $data['applicationPassword']['inUse'] );
	}

	/**
	 * A context user the gateway guaranteed to exist but core cannot resolve
	 * is an impossible state, and half a user object is worse than a refusal.
	 *
	 * The code is asserted, not merely the exception class: every refusal in
	 * this codebase is the same class, so a test satisfied by any of them would
	 * pass on the capability refusal instead and prove nothing about this branch.
	 */
	public function test_an_unresolvable_context_user_refuses_rather_than_reporting_half_an_identity(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->stubApplicationPasswordApi( false, null );

		try {
			( new ConnectionCheck() )->handle( [], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );

			return;
		}

		$this->fail( 'An unresolvable context user must be refused with ErrorCode::ExecutionFailed.' );
	}

	/**
	 * The capability check is the first statement in the handler. Delete it and
	 * this test goes red — that deletion is the proof, and it is recorded in the
	 * report.
	 *
	 * Ordering is asserted, not only the refusal: `get_userdata` is wired to a
	 * fake that fails the test if it is ever reached, so a handler that looked
	 * the caller up first and refused afterwards — same refusal, same code,
	 * lookup already performed on an unauthorised caller's behalf — goes red
	 * here rather than passing.
	 */
	public function test_a_caller_without_read_is_refused(): void {
		Functions\when( 'user_can' )->justReturn( false );

		Functions\when( 'get_userdata' )->alias(
			function (): object {
				$this->fail( 'The capability check must run before the user lookup.' );
			}
		);

		try {
			( new ConnectionCheck() )->handle( [], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );

			return;
		}

		$this->fail( 'A caller without read must be refused with ErrorCode::Forbidden.' );
	}

	/**
	 * The capability is re-checked against the CONTEXT's user with the declared
	 * capability, not against the ambient current user.
	 */
	public function test_the_capability_is_checked_against_the_contexts_user(): void {
		$asked = [];

		Functions\when( 'user_can' )->alias(
			static function ( mixed $user, string $capability ) use ( &$asked ): bool {
				$asked[] = [ $user, $capability ];

				return true;
			}
		);

		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( false, null );

		( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( [ [ 7, 'read' ] ], $asked );
	}

	/**
	 * The operation takes no arguments at all, and the schema is what says so.
	 *
	 * `user` is named explicitly because it is the one property whose presence
	 * would turn an onboarding diagnostic into a user-enumeration endpoint held
	 * open by the weakest capability in WordPress. The closed schema is asserted
	 * the way the other modules' definition-invariant tests assert it, on the
	 * registered definition, rather than by calling SchemaValidator here.
	 */
	public function test_the_input_schema_admits_no_arguments_and_no_user_selector(): void {
		$registry = new CapabilityRegistry();
		( new DiagnosticsModule() )->register( $registry );

		$schema = $registry->definition( 'system-connection' )->inputSchema;

		$this->assertSame(
			false,
			$schema['additionalProperties'] ?? null,
			"Operation 'system-connection' must declare inputSchema additionalProperties false. SchemaValidator has no other signal that the argument list is closed."
		);
		$this->assertSame( [], $schema['properties'] );
		$this->assertArrayNotHasKey( 'user', $schema['properties'] );
	}

	/**
	 * Interpretation I6's interim mitigation: nothing validates output at
	 * runtime, so the declared outputSchema is checked against a real payload
	 * here, where drift originates.
	 */
	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$registry = new CapabilityRegistry();
		( new DiagnosticsModule() )->register( $registry );

		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( true, 'e5f1c0de-0000-4000-8000-000000000001' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertConformsToOutputSchema( $data, $registry->definition( 'system-connection' )->outputSchema );
	}

	/**
	 * The whole payload, not one member. The response-envelope prohibition
	 * covers everything this operation returns, and a diagnostic is exactly the
	 * kind of operation that acquires a helpful path or a helpful header.
	 */
	public function test_the_report_leaks_no_path_credential_or_server_detail(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		$this->stubApplicationPasswordApi( true, 'e5f1c0de-0000-4000-8000-000000000001' );

		$json = (string) wp_json_encode( ( new ConnectionCheck() )->handle( [], $this->makeContext() ) );

		$this->assertDoesNotMatchRegularExpression( '/\/var\/|\/home\/|wp-content|[A-Z]:\\\\/', $json );
		$this->assertDoesNotMatchRegularExpression( '/authorization|bearer|secret/i', $json );
		$this->assertStringNotContainsString( PHP_VERSION, $json );
	}
}
