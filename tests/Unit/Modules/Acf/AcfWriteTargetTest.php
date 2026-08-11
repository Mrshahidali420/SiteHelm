<?php
/**
 * Tests for AcfWriteTarget, the guard order every ACF write runs through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Acf\AcfWriteTarget;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * One resolver, four refusals, and an order that no single-condition test can see.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason the sibling suites record:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and a suite that installed ACF once would have nothing left to run the
 * absent-plugin refusal against.
 *
 * THE ORDERING IS HELD BY THREE CROSSING TESTS, NOT BY THREE SINGLE-CONDITION
 * ONES. Task 3 shipped an ordering no test could see: every ACF-absent case named
 * a post that existed and every missing-post case ran on a site with ACF, so the
 * two conditions never met and swapping the blocks passed the whole suite. Each
 * ordering below is therefore proved by a case where BOTH conditions hold at once
 * and only the order decides which error code comes back.
 *
 * A CALL COUNT ALONE CANNOT SEE THE CAPABILITY/PRESENCE SWAP, because AcfApi
 * re-gates on presence internally and the recorded ACF call count is zero under
 * either ordering. The ERROR CODE is the evidence there. The post lookup is
 * different: `get_post` is a real function the double replaces, so `$postCalls`
 * turns "no post was looked up" into an assertable fact.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfWriteTargetTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * The post every test targets.
	 */
	private const TARGET = 42;

	/**
	 * Whether the doubled WordPress user may edit the target post.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question the resolver asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every identifier the doubled post lookup was asked for, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->acfCalls         = [];
		$this->postCalls        = [];
		$this->capabilityChecks = [];
		$this->posts            = [ self::TARGET => $this->acfPost( self::TARGET ) ];

		$this->stubAcfWordPress();
		$this->stubAcfPosts();
	}

	/**
	 * The resolver, wired the way the module wires it.
	 *
	 * @return AcfWriteTarget The resolver under test.
	 */
	private function target(): AcfWriteTarget {
		$presence = new AcfPresence();
		$api      = new AcfApi( $presence );

		return new AcfWriteTarget( $presence, $api, new AcfFieldIndex( $api ) );
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'acf' => [
					'version' => '6.2.7',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Resolves one input against the doubled site.
	 *
	 * @param array<string, mixed> $input The operation input.
	 *
	 * @return array<string, mixed> The resolved target.
	 */
	private function resolve( array $input = [ 'post' => self::TARGET ] ): array {
		return $this->target()->resolve( $input, $this->context() );
	}

	/**
	 * One field group in the shape ACF stores it.
	 *
	 * @param string $key The group key.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function group( string $key ): array {
		return [
			'key'   => $key,
			'title' => 'Page settings',
		];
	}

	/**
	 * A site with one group carrying two fields.
	 */
	private function installTwoFields(): void {
		$this->installAcf(
			[ $this->group( 'group_page' ) ],
			[
				'group_page' => [
					[
						'key'      => 'field_subtitle',
						'name'     => 'subtitle',
						'label'    => 'Subtitle',
						'type'     => 'text',
						'required' => 0,
					],
					[
						'key'      => 'field_rows',
						'name'     => 'rows',
						'label'    => 'Rows',
						'type'     => 'repeater',
						'required' => 0,
					],
				],
			]
		);
	}

	// ---------------------------------------------------------------- happy path

	public function test_a_resolvable_target_answers_the_post_the_index_and_the_resolved_fields(): void {
		$this->installTwoFields();

		$resolved = $this->resolve();

		$this->assertSame( [ 'post', 'index', 'resolved' ], array_keys( $resolved ) );
		$this->assertSame( self::TARGET, $resolved['post'] );
		$this->assertSame( [ 'fields', 'skippedGroups' ], array_keys( $resolved['index'] ) );
		$this->assertSame( [], $resolved['index']['skippedGroups'] );
	}

	/**
	 * THE `resolved` CHANNEL IS THE `fields` LIST, ALREADY UNWRAPPED. Handing a
	 * caller the two-key index and expecting it to reach in is the documented trap
	 * in this module: `AcfFieldIndex::find()` takes the fields list, `$index` type
	 * checks fine where `$index['fields']` was meant, and the result is null for
	 * every field on the post rather than an error.
	 */
	public function test_the_resolved_channel_is_the_fields_list_a_lookup_can_be_run_against(): void {
		$this->installTwoFields();

		$resolved = $this->resolve();

		$this->assertSame( $resolved['index']['fields'], $resolved['resolved'] );
		$this->assertSame(
			[ 'field_subtitle', 'field_rows' ],
			array_column( $resolved['resolved'], 'key' )
		);
	}

	/**
	 * A post with no applicable fields RESOLVES. It is a target that exists and
	 * carries nothing, which is a refusal the input validation makes about the
	 * fields the caller named — not a refusal about the post.
	 */
	public function test_a_post_with_no_applicable_fields_resolves_with_an_empty_field_list(): void {
		$this->installAcf( [] );

		$resolved = $this->resolve();

		$this->assertSame( [], $resolved['resolved'] );
		$this->assertSame( self::TARGET, $resolved['post'] );
	}

	/**
	 * A skipped group survives into the caller's hands rather than being silently
	 * dropped, because the operation above has to warn about it: a shorter field
	 * list reported as complete is how an operator concludes a field does not apply
	 * to a post when its whole group simply could not be read.
	 */
	public function test_a_group_whose_fields_cannot_be_read_is_reported_as_skipped(): void {
		$this->installAcf(
			[ $this->group( 'group_page' ) ],
			[ 'group_page' => 'not a list of fields' ]
		);

		$this->assertSame( [ 'group_page' ], $this->resolve()['index']['skippedGroups'] );
	}

	// ------------------------------------------------------------------ the input

	/**
	 * NEVER A CAST. `(int)` on an array is 1, so a resolver that cast would build
	 * the field index of post 1 — a post most sites have — and hand a write a
	 * target nobody named. The refusal names the argument and never its value.
	 */
	public function test_an_identifier_that_is_not_an_integer_is_refused_as_invalid_input(): void {
		$this->installTwoFields();

		try {
			$this->resolve( [ 'post' => [ self::TARGET ] ] );
			$this->fail( 'A non-integer identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( '42', $e->getMessage(), 'A refusal names the argument, never its value.' );
		}

		$this->assertSame( [], $this->postCalls );
		$this->assertSame( [], $this->capabilityChecks, 'Nothing is asked about a target that was never established.' );
	}

	public function test_a_missing_identifier_is_refused_as_invalid_input(): void {
		$this->installTwoFields();

		$this->expectException( OperationException::class );

		$this->resolve( [] );
	}

	// ------------------------------------------------------------------ guard one

	/**
	 * THE CROSSING PROOF FOR CAPABILITY BEFORE PRESENCE AND BEFORE THE LOOKUP.
	 *
	 * ACF is deliberately NOT installed in this process and the named post does
	 * NOT exist, so all three refusal conditions hold at once and only the order
	 * decides which is raised. Move the presence check above the capability check
	 * and the error code becomes IntegrationUnavailable; move the lookup above it
	 * and the code becomes TargetNotFound. Either mutation tells a caller with no
	 * rights over a post whether that post exists and whether this site runs ACF —
	 * site configuration they are not entitled to.
	 *
	 * The call-count assertions are kept BESIDE the error code rather than instead
	 * of it, because a count cannot see the capability/presence swap at all:
	 * AcfApi re-gates on presence internally, so the recorded ACF call count is
	 * zero under either ordering.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_and_before_the_lookup(): void {
		$this->mayEdit = false;

		try {
			$this->resolve( [ 'post' => 999 ] );
			$this->fail( 'A caller without edit_post on the target must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame(
				ErrorCode::Forbidden,
				$e->errorCode,
				'The capability is asked first, so a denied caller learns nothing about the plugin or the post.'
			);
		}

		$this->assertSame( [], $this->postCalls, 'The capability check must run BEFORE the post is looked up.' );
		$this->assertSame( 0, $this->acfCallCount(), 'A denied caller must cause no ACF read at all.' );
	}

	/**
	 * The capability is asked about THIS post and not about the site. A site-wide
	 * `edit_posts` would let a contributor who may edit their own drafts resolve —
	 * and then write — a page they may not touch.
	 */
	public function test_the_capability_is_asked_about_the_target_post(): void {
		$this->installTwoFields();

		$this->resolve();

		$this->assertSame( [ [ 7, 'edit_post', self::TARGET ] ], $this->capabilityChecks );
	}

	// ------------------------------------------------------------------ guard two

	public function test_a_site_without_acf_refuses_with_integration_unavailable(): void {
		try {
			$this->resolve();
			$this->fail( 'A site without ACF must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}
	}

	/**
	 * THE CROSSING PROOF FOR PRESENCE BEFORE THE LOOKUP — no ACF AND no such post,
	 * so exactly one code is possible per ordering. Presence must win: "this site
	 * does not run ACF" is the fact that makes the whole write meaningless, and
	 * answering TargetNotFound first would send an operator hunting for a post
	 * identifier when no identifier could have worked.
	 */
	public function test_a_site_without_acf_refuses_for_the_plugin_before_it_refuses_for_the_target(): void {
		try {
			$this->resolve( [ 'post' => 999 ] );
			$this->fail( 'A site without ACF must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'No post is looked up on a site that cannot answer the question at all.' );
	}

	/**
	 * A HALF-LOADED ACF IS BLAMED ON THE PLUGIN. The ACF functions exist here but
	 * ACF_VERSION does not. Delete the presence guard and the resolver still
	 * refuses — the index answers null — but with ExecutionFailed, sending the
	 * operator to retry a call that can never succeed.
	 */
	public function test_a_half_loaded_acf_is_refused_as_a_missing_plugin_not_a_failed_read(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], null );

		try {
			$this->resolve();
			$this->fail( 'A site whose ACF does not declare a version must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->assertSame( 0, $this->acfCallCount(), 'The presence gate must stop the read.' );
	}

	// ---------------------------------------------------------------- guard three

	public function test_a_post_that_does_not_exist_refuses_as_a_missing_target(): void {
		$this->installTwoFields();

		try {
			$this->resolve( [ 'post' => 999 ] );
			$this->fail( 'A post that does not exist must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [ 999 ], $this->postCalls );
		$this->assertSame( 0, $this->acfCallCount(), 'The target is confirmed before any ACF read is done for it.' );
	}

	// ----------------------------------------------------------------- guard four

	/**
	 * AN INDEX THAT COULD NOT BE BUILT REFUSES rather than resolving to zero
	 * fields. Resolving would send the write on to input validation, which would
	 * refuse every field the caller named as "not applying to this post" — an
	 * InvalidInput blaming the operator for a read that failed on the site.
	 */
	public function test_an_index_that_cannot_be_built_refuses_rather_than_resolving_to_no_fields(): void {
		$this->installAcf( 'not a list of groups' );

		try {
			$this->resolve();
			$this->fail( 'An index that could not be built must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}
	}
}
