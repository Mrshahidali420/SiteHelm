<?php
/**
 * Tests for AcfFieldList (REQ-0045).
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
use SiteHelm\Modules\Acf\AcfFieldList;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0045: which custom fields actually apply to one post.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason AcfGroupListTest records:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and a suite that installed ACF once would have nothing left to run the
 * absent-plugin refusal against.
 *
 * THE GUARD ORDER IS THE SAME ORDER EVERY ACF OPERATION USES, with one difference
 * from acf-group-list: the capability is target-scoped. `edit_post` on THIS post
 * runs first, before the presence gate and before the post is looked up.
 *
 * Two mutations are held here, and by two different kinds of evidence, because
 * neither kind can see both:
 *
 *  - CAPABILITY BEFORE PRESENCE is held only by the error code, in
 *    test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted, which
 *    runs in a process where ACF is genuinely absent so both refusal conditions
 *    hold at once and only the order decides. A call count cannot see this
 *    mutation: AcfPresence::isLoaded() calls defined() and function_exists(), and
 *    AcfApi re-gates on presence internally, so the recorded ACF call count is
 *    zero whichever way the two blocks are ordered.
 *  - CAPABILITY BEFORE THE POST LOOKUP is held by a count, in the same test,
 *    because `get_post` IS a real function a double replaces. Moving the lookup
 *    above the capability check records one call and fails there.
 *
 * NULL AND [] ARE DIFFERENT ANSWERS. An index that could not be built refuses; an
 * index that was built and holds nothing answers zero fields. A post reported as
 * carrying no custom fields when nothing was read at all is a lie an operator acts
 * on by rebuilding what is already there.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldListTest extends TestCase {

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
	 * Every capability question the operation asked, in order.
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
	 * The operation, wired the way the module wires it.
	 *
	 * One presence gate shared by the operation and the API wrapper, so that "does
	 * this site run ACF" is answered by one object within a request.
	 *
	 * @return AcfFieldList The handler under test.
	 */
	private function operation(): AcfFieldList {
		$presence = new AcfPresence();

		return new AcfFieldList( $presence, new AcfFieldIndex( new AcfApi( $presence ) ) );
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
	 * Runs the operation and returns the payload.
	 *
	 * @param array<string, mixed> $input The operation input.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function handle( array $input = [ 'post' => self::TARGET ] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * One field group in the shape ACF stores it.
	 *
	 * @param string $key   The group key.
	 * @param string $title The group title.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function group( string $key, string $title ): array {
		return [
			'key'   => $key,
			'title' => $title,
		];
	}

	/**
	 * One field definition in the shape ACF stores it.
	 *
	 * @param string $key  The field key.
	 * @param string $name The field name.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function field( string $key, string $name ): array {
		return [
			'key'      => $key,
			'name'     => $name,
			'label'    => ucfirst( $name ),
			'type'     => 'text',
			'required' => 1,
		];
	}

	// ---------------------------------------------------------------- happy path

	public function test_a_post_with_applicable_fields_reports_them_with_their_groups(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[ 'group_page' => [ $this->field( 'field_subtitle', 'subtitle' ) ] ]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'target', 'fields', 'fieldListingNotices' ], array_keys( $payload ) );
		$this->assertSame( self::TARGET, $payload['target'] );
		$this->assertSame( [], $payload['fieldListingNotices'] );
		$this->assertCount( 1, $payload['fields'] );

		$entry = $payload['fields'][0];

		$this->assertSame(
			[ 'key', 'name', 'label', 'type', 'required', 'groupKey', 'groupTitle' ],
			array_keys( $entry ),
			'This operation reports which fields apply, not what they hold: no value and no nested definition.'
		);
		$this->assertSame( 'field_subtitle', $entry['key'] );
		$this->assertSame( 'subtitle', $entry['name'] );
		$this->assertSame( 'Subtitle', $entry['label'] );
		$this->assertSame( 'text', $entry['type'] );
		$this->assertTrue( $entry['required'] );
		$this->assertSame( 'group_page', $entry['groupKey'] );
		$this->assertSame( 'Page settings', $entry['groupTitle'] );

		// The index carries the whole ACF definition; the response must not. A
		// nested definition here would put a field's default VALUE in a response
		// that promises none.
		$this->assertArrayNotHasKey( 'definition', $entry );

		$this->assertSame(
			[ [ 'post_id' => self::TARGET ] ],
			$this->acfCallArguments( 'groups' ),
			'ACF does the location matching, so the target has to reach it.'
		);
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[ 'group_page' => [ $this->field( 'field_subtitle', 'subtitle' ) ] ]
		);

		$payload = $this->handle();

		// A conformance check passes just as happily over a member that was never
		// emitted, so the field list is asserted non-empty before it is validated.
		$this->assertCount( 1, $payload['fields'] );

		$this->assertConformsToOutputSchema( $payload, AcfFieldList::definition()->outputSchema );
	}

	// -------------------------------------------------------------- guard order

	/**
	 * THE ORDERING PROOF, AND IT HOLDS TWO ORDERINGS BY TWO KINDS OF EVIDENCE.
	 *
	 * ACF is deliberately NOT installed in this process, so a denied caller trips
	 * both refusal conditions at once and only the order decides which is raised.
	 * Move the presence check above the capability check and the assertion on the
	 * error code fails — and the mutation it catches is real: an unprivileged
	 * caller would learn from the refusal whether this site runs ACF, which is site
	 * configuration they have no right to.
	 *
	 * The post-lookup half is held by a count instead, because `get_post` is a real
	 * function the double replaces and the presence gate is not. Move the lookup
	 * above the capability check and $postCalls records one entry: a caller who may
	 * not edit a post would then learn from the difference between TargetNotFound
	 * and Forbidden whether that post exists.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted(): void {
		$this->mayEdit = false;

		try {
			$this->handle();
			$this->fail( 'A caller without edit_post on the target must be refused.' );
		} catch ( OperationException $e ) {
			// Both refusal conditions hold here, so only the guard order decides which
			// one answers; presence answering first is the mutation this catches.
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'The capability check must run BEFORE the post is looked up.' );
		$this->assertSame( 0, $this->acfCallCount(), 'A denied caller must cause no ACF read at all.' );
	}

	/**
	 * The capability is asked about THIS post and not about the site.
	 *
	 * A site-wide `edit_posts` here would let a contributor who may edit their own
	 * drafts enumerate the field set of a page they may not touch. The double
	 * records what it was asked, so a two-argument call fails by name rather than
	 * by a downstream symptom.
	 */
	public function test_the_capability_is_asked_about_the_target_post(): void {
		$this->installAcf( [] );

		$this->handle();

		$this->assertSame(
			[ [ 7, 'edit_post', self::TARGET ] ],
			$this->capabilityChecks,
			'The capability must be target-scoped: user_can( $userId, \'edit_post\', $post_id ).'
		);
	}

	/**
	 * A HALF-LOADED ACF IS BLAMED ON THE PLUGIN, NOT ON THE READ. The ACF functions
	 * are installed here but ACF_VERSION is not — a fork, a disturbed load order,
	 * or another plugin's shim. Delete the presence guard and the operation still
	 * refuses, because AcfApi re-gates on presence and the index answers null, but
	 * it refuses with ExecutionFailed and sends the operator to retry a call that
	 * can never succeed. That substitution is what this asserts.
	 */
	public function test_a_half_loaded_acf_is_refused_as_a_missing_plugin_not_a_failed_read(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ], [], null );

		try {
			$this->handle();
			$this->fail( 'A site whose ACF does not declare a version must refuse.' );
		} catch ( OperationException $e ) {
			// Without the presence guard the read fails instead, and the operator is
			// told to retry a call that cannot succeed.
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	// ------------------------------------------------------------- ACF is absent

	public function test_a_site_without_acf_refuses_with_integration_unavailable(): void {
		try {
			$this->handle();
			$this->fail( 'A site without ACF must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation, 'The remedy is to install a plugin, and the refusal must say so.' );
		}
	}

	/**
	 * THE ONLY TEST THAT PROVES PRESENCE RUNS BEFORE THE TARGET LOOKUP.
	 *
	 * Every other guard test satisfies exactly one guard, so none of them can see
	 * the order of two: the ACF-absent case names a post that EXISTS, and the
	 * missing-post case runs on a site where ACF IS installed. Swap the two blocks
	 * in handle() and both still pass, which makes the documented ordering a claim
	 * with nothing behind it.
	 *
	 * This crosses them — no ACF AND no such post — so exactly one error code is
	 * possible per ordering, and the assertion picks the right one. Presence must
	 * win: "this site does not run ACF" is the fact that makes the whole call
	 * meaningless, and answering TargetNotFound first would send an operator
	 * hunting for a post identifier when no ordering of correct identifiers could
	 * have worked.
	 */
	public function test_a_site_without_acf_refuses_for_the_plugin_before_it_refuses_for_the_target(): void {
		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'A site without ACF must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame(
				ErrorCode::IntegrationUnavailable,
				$e->errorCode,
				'Presence is asked before the target is looked up, so an absent plugin outranks a missing post.'
			);
		}

		$this->assertSame( [], $this->postCalls, 'No post is looked up on a site that cannot answer the question at all.' );
	}

	// -------------------------------------------------------------- the target

	public function test_a_post_that_does_not_exist_refuses_as_a_missing_target(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ] );

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'A post that does not exist must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [ 999 ], $this->postCalls );
		$this->assertSame( 0, $this->acfCallCount(), 'The target is confirmed before any ACF read is done for it.' );
	}

	/**
	 * An identifier that is not an integer is refused rather than cast. `(int)` on
	 * an array is 1 — a lookup for post 1, which on most sites exists, and a field
	 * list for a post nobody asked about.
	 */
	public function test_an_identifier_that_is_not_an_integer_is_refused_as_invalid_input(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ] );

		try {
			$this->handle( [ 'post' => [ self::TARGET ] ] );
			$this->fail( 'A non-integer identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( '42', $e->getMessage(), 'A refusal names the field, never its value.' );
		}

		$this->assertSame( [], $this->postCalls );
	}

	// ------------------------------------------------- null and [] are different

	/**
	 * THE DECISION 5 GUARD. `acf/load_field_groups` is public, so a site really can
	 * answer with something that is not a list of groups; the index turns that into
	 * null and null must REFUSE. Coalescing it into an empty list would produce a
	 * well-formed response reporting a post as carrying no custom fields when
	 * nothing was read at all — and that response is what an operator plans a write
	 * against.
	 */
	public function test_an_index_that_cannot_be_built_refuses_rather_than_reporting_zero_fields(): void {
		$this->installAcf( 'not a list of groups' );

		try {
			$this->handle();
			$this->fail( 'An index that could not be built must refuse.' );
		} catch ( OperationException $e ) {
			// ACF is installed here; the plugin is not what is missing.
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * The mirror, and the reason the test above can fail. A post no field group
	 * applies to is an ordinary post and answers zero fields without refusing.
	 */
	public function test_a_post_no_group_applies_to_reports_zero_fields_without_refusing(): void {
		$this->installAcf( [] );

		$payload = $this->handle();

		$this->assertSame( [], $payload['fields'] );
		$this->assertSame( [], $payload['fieldListingNotices'] );
		$this->assertSame( self::TARGET, $payload['target'] );
	}

	// ------------------------------------------------------------- the warnings

	/**
	 * A GROUP THAT COULD NOT BE READ IS NAMED, AND THE OTHER GROUP'S FIELDS SURVIVE.
	 *
	 * Zero warnings here would report a shorter field list as though it were
	 * complete, and an operator comparing it against the editor would conclude the
	 * fields had been deleted. The warning names the GROUP KEY — an identifier ACF
	 * assigned, which the caller needs in order to go and look — and carries no
	 * field's value, because a warning is text a client may display and a value
	 * quoted into it is how a message becomes an injection surface.
	 */
	public function test_a_group_whose_fields_cannot_be_read_warns_and_names_the_group(): void {
		$this->installAcf(
			[
				$this->group( 'group_broken', 'Broken' ),
				$this->group( 'group_page', 'Page settings' ),
			],
			[
				'group_broken' => 'not a list of fields',
				'group_page'   => [ $this->field( 'field_subtitle', 'subtitle' ) ],
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertCount( 1, $payload['fieldListingNotices'] );
		$this->assertStringNotContainsString( 'subtitle', $payload['fieldListingNotices'][0], 'A warning names groups, never a field or its value.' );

		// The WHOLE string, not a substring of it. A substring assertion passes over
		// a warning that names the group and then trails off, or that says '1 field
		// group' while listing none — the two defects most easily introduced here.
		$this->assertSame(
			'The field definitions of 1 field group that applies to this post could not be read, '
				. 'so any field in it is not listed here: group_broken.',
			$payload['fieldListingNotices'][0]
		);
	}

	/**
	 * A group ACF answered with that is not an array has no key to name, and the
	 * warning counts it instead of printing an empty identifier. Saying nothing at
	 * all would be the silence the second channel exists to prevent.
	 *
	 * ASSERTED WHOLE, AND ASSERTED TO CARRY NO COLON. Drop the filter that removes
	 * unnameable keys and the warning ends '… not listed here: .' — a count and a
	 * substring assertion both still pass over that, which is why neither is used.
	 */
	public function test_a_group_with_no_readable_key_is_counted_rather_than_named(): void {
		$this->installAcf(
			[
				'not a group',
				$this->group( 'group_page', 'Page settings' ),
			],
			[ 'group_page' => [ $this->field( 'field_subtitle', 'subtitle' ) ] ]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertCount( 1, $payload['fieldListingNotices'] );
		$this->assertStringNotContainsString( ':', $payload['fieldListingNotices'][0], 'Nothing can be named here, so nothing is introduced.' );
		$this->assertSame(
			'The field definitions of 1 field group that applies to this post could not be read, '
				. 'so any field in it is not listed here.',
			$payload['fieldListingNotices'][0]
		);
	}

	/**
	 * THE MIXED CASE: two groups fail, one of them can be named. Counting both while
	 * naming one reads as though the single key were the whole list, so the size of
	 * the named subset is stated. Delete that clause and this warning claims two
	 * groups and shows one with nothing to say the list is partial.
	 */
	public function test_a_partly_nameable_set_of_skipped_groups_says_how_many_it_named(): void {
		$this->installAcf(
			[
				'not a group',
				$this->group( 'group_broken', 'Broken' ),
				$this->group( 'group_page', 'Page settings' ),
			],
			[
				'group_broken' => 'not a list of fields',
				'group_page'   => [ $this->field( 'field_subtitle', 'subtitle' ) ],
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertCount( 1, $payload['fieldListingNotices'] );
		$this->assertSame(
			'The field definitions of 2 field groups that apply to this post could not be read, '
				. 'so any field in them is not listed here. 1 of them could be identified: group_broken.',
			$payload['fieldListingNotices'][0]
		);
	}
}
