<?php
/**
 * Tests for AcfGroupList (REQ-0044).
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
use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfGroupList;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Acf\AcfSchemaFormat;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0044: what field groups does this site define, and what is in them.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `ACF_VERSION` is a constant and a Brain
 * Monkey alias is a real global function; both are permanent for the life of a
 * process, so a suite that installed ACF once would have nothing left to run the
 * absent-plugin refusals against, and those refusals are half of what this
 * operation does. The cost is process startup per test; the alternative is a
 * suite in which the most important cases cannot exist.
 *
 * THE GUARD-ORDER PROOF IS THE POINT OF THIS FILE. Capability runs before
 * presence, presence before the read, and none of that is visible in the error
 * code alone: on a site where an unprivileged caller meets an absent plugin, BOTH
 * refusal conditions are true at once and only the ordering decides which is
 * raised. Each ordering claim is held by EXACTLY ONE test, and each of those was
 * verified by performing the mutation it claims to catch:
 *
 *  - capability before presence, by
 *    test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted, which
 *    runs in a process where ACF is genuinely absent so that only the order can
 *    decide the answer;
 *  - presence before the read, by
 *    test_a_half_loaded_acf_is_refused_as_a_missing_plugin_not_a_failed_read,
 *    which installs the ACF functions WITHOUT ACF_VERSION and asserts WHICH
 *    refusal is raised. Removing the guard does not let the read through —
 *    AcfApi gates itself as well — it changes the refusal into ExecutionFailed,
 *    which sends the operator to retry a call that cannot succeed. A call count
 *    cannot see that, because AcfApi's own gate keeps it at zero either way; the
 *    count is asserted in AcfApiTest, over the gate that produces it.
 *
 * test_a_denied_caller_causes_no_acf_read_at_all is deliberately NOT one of them.
 * It proves no work is done for a denied caller, which is worth proving, but it
 * survives a swap of the two guards by construction — see its docblock. The
 * distinction is recorded because the later tasks in this module will copy this
 * file, and copying the wrong test would leave the ordering unprotected while
 * looking as though it were covered.
 *
 * NULL AND [] ARE DIFFERENT ANSWERS, and the pair of tests holding that apart is
 * the second reason this file exists. A group list that could not be read is a
 * refusal; a group list that was read and holds nothing is a normal answer with
 * zero groups. Coalescing the first into the second reports a site as having no
 * field groups when nothing was checked at all, and an operator acts on that by
 * rebuilding what was already there.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfGroupListTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Declared here because the shared trait's contract requires it; PHP 8.1 has
	 * no trait constants and a trait property would collide.
	 */
	private bool $mayEdit = true;

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit  = true;
		$this->acfCalls = [];

		$this->stubAcfWordPress();
	}

	/**
	 * The operation, wired the way the module wires it.
	 *
	 * One presence gate shared by the operation and the API wrapper, so that
	 * "does this site run ACF" is answered by one object within a request.
	 *
	 * @return AcfGroupList The handler under test.
	 */
	private function operation(): AcfGroupList {
		$presence = new AcfPresence();

		return new AcfGroupList( $presence, new AcfApi( $presence ), new AcfSchemaFormat() );
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
	 * One field group in the shape ACF stores it.
	 *
	 * @param string               $key       The group key.
	 * @param string               $title     The group title.
	 * @param array<string, mixed> $overrides Members to add or replace.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function group( string $key, string $title, array $overrides = [] ): array {
		return array_merge(
			[
				'key'      => $key,
				'title'    => $title,
				'location' => [
					[
						[
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'page',
						],
					],
				],
			],
			$overrides
		);
	}

	/**
	 * Runs the operation and returns the payload.
	 *
	 * @param array<string, mixed> $input The operation input.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function handle( array $input = [] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	// ---------------------------------------------------------------- happy path

	public function test_a_site_with_two_groups_reports_both_with_their_rules_and_fields(): void {
		$this->installAcf(
			[
				$this->group( 'group_page', 'Page settings' ),
				$this->group( 'group_post', 'Post settings' ),
			],
			[
				'group_page' => [
					[
						'key'      => 'field_subtitle',
						'name'     => 'subtitle',
						'label'    => 'Subtitle',
						'type'     => 'text',
						'required' => 1,
					],
				],
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'groups', 'truncated', 'warnings' ], array_keys( $payload ) );
		$this->assertFalse( $payload['truncated'] );
		$this->assertSame( [], $payload['warnings'] );
		$this->assertCount( 2, $payload['groups'] );

		$page = $payload['groups'][0];

		$this->assertSame( [ 'key', 'title', 'locationRules', 'fields' ], array_keys( $page ) );
		$this->assertSame( 'group_page', $page['key'] );
		$this->assertSame( 'Page settings', $page['title'] );
		$this->assertSame( 'post_type', $page['locationRules'][0][0]['param'] );
		$this->assertSame( 'field_subtitle', $page['fields'][0]['key'] );
		$this->assertTrue( $page['fields'][0]['required'] );

		// A group ACF reports no fields for is reported with none, which is a
		// different fact from the whole read failing and is why the fields call is
		// made per group rather than once.
		$this->assertSame( [], $payload['groups'][1]['fields'] );
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[
				'group_page' => [
					[
						'key'        => 'field_slides',
						'name'       => 'slides',
						'label'      => 'Slides',
						'type'       => 'repeater',
						'sub_fields' => [
							[
								'key'   => 'field_caption',
								'name'  => 'caption',
								'label' => 'Caption',
								'type'  => 'text',
							],
						],
					],
					// A choices-bearing field, with the numeric keys that were the
					// actual failure mode: keyed by value, this member re-typed
					// itself into a JSON array and broke the object the schema
					// published. Its presence here is what makes the conformance
					// check exercise the emitter and the schema against each other
					// rather than only member by member.
					[
						'key'     => 'field_layout',
						'name'    => 'layout',
						'label'   => 'Layout',
						'type'    => 'select',
						'choices' => [
							1 => 'Wide',
							2 => 'Narrow',
						],
					],
				],
			]
		);

		$payload = $this->handle();

		// A conformance check passes just as happily over a member that was never
		// emitted, so the choices list is asserted present before it is validated.
		$this->assertSame(
			[
				[
					'value' => '1',
					'label' => 'Wide',
				],
				[
					'value' => '2',
					'label' => 'Narrow',
				],
			],
			$payload['groups'][0]['fields'][1]['choices']
		);

		// The recursive `$ref` into `#/$defs/acfFieldSchema` only resolves against
		// the schema document, so this assertion is also the proof that the
		// declared `$id` and `$defs` are actually wired to each other.
		$this->assertConformsToOutputSchema( $payload, AcfGroupList::definition()->outputSchema );
	}

	// -------------------------------------------------------------- guard order

	/**
	 * THE ORDERING PROOF. THIS IS THE ONLY TEST IN THE FILE THAT DETECTS A SWAP OF
	 * THE CAPABILITY AND PRESENCE BLOCKS, and the reason is worth stating so the
	 * later tasks in this module copy the right test rather than the impressive
	 * looking one. ACF is deliberately NOT installed in this process, so the
	 * caller trips BOTH guards at once and only the order decides which refusal is
	 * raised. Move the presence check above the capability check and this test
	 * reports IntegrationUnavailable — which would tell an unprivileged caller
	 * whether this site runs ACF, a fact about the site's configuration they have
	 * no right to, and would walk them through fixing a request that was never
	 * going to run.
	 *
	 * VERIFIED BY MUTATION, NOT BY ARGUMENT. Swapping the two blocks in
	 * AcfGroupList::handle() and running this file fails here with
	 * "Failed asserting that two variables reference the same object" —
	 * ErrorCode Enum (Forbidden) versus ErrorCode Enum (IntegrationUnavailable) —
	 * and, once ACF is absent, nothing else in the file notices. The companion
	 * test below survives that mutation by construction; see its docblock.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted(): void {
		$this->mayEdit = false;

		try {
			$this->handle();
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertNotSame(
				ErrorCode::IntegrationUnavailable,
				$e->errorCode,
				'Both refusal conditions hold here, so only the guard order decides; presence must not answer first.'
			);
		}
	}

	/**
	 * NOT AN ORDERING PROOF — a no-work proof, and the distinction is the whole
	 * point of this docblock. Here ACF IS installed, so a refusal for either
	 * reason leaves the same evidence: swap the capability and presence blocks and
	 * presence simply passes, the capability guard still throws Forbidden, and
	 * zero ACF calls are still recorded. This test cannot see that mutation and
	 * must not be described as though it can. AcfPresence::isLoaded() calls only
	 * defined() and function_exists(), neither of which a double can observe, so
	 * no amount of recording makes the presence gate itself countable.
	 *
	 * What it does prove is worth a test of its own: a caller who may not have the
	 * answer causes no work to be done for them at all — no field-group read, no
	 * per-group field read, nothing an expensive site would feel.
	 */
	public function test_a_denied_caller_causes_no_acf_read_at_all(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ] );
		$this->mayEdit = false;

		try {
			$this->handle();
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( 0, $this->acfCallCount(), 'The capability check must run BEFORE any ACF read.' );
	}

	/**
	 * A HALF-LOADED ACF IS BLAMED ON THE PLUGIN, NOT ON THE READ. The ACF
	 * functions are installed here but ACF_VERSION is not, which is the shape of a
	 * fork, a disturbed load order, or another plugin's compatibility shim. The
	 * presence gate must refuse it as an unavailable integration.
	 *
	 * Delete the presence guard from handle() and the operation still refuses —
	 * AcfApi::groups() re-checks presence for itself, so the read answers null —
	 * but it refuses with ExecutionFailed, telling an operator to retry a call
	 * that will never succeed and to go looking at a filter instead of at the
	 * plugin. That substitution is what this test detects, and it is why the
	 * assertion is on WHICH refusal rather than on a call count: a count of zero
	 * is guaranteed by AcfApi's own gate whether handle() checks or not, so
	 * asserting it here would be asserting something that cannot fail. The place
	 * where zero recorded calls IS load-bearing is AcfApiTest, over the gate that
	 * actually produces it.
	 */
	public function test_a_half_loaded_acf_is_refused_as_a_missing_plugin_not_a_failed_read(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ], [], null );

		try {
			$this->handle();
			$this->fail( 'A site whose ACF does not declare a version must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotSame(
				ErrorCode::ExecutionFailed,
				$e->errorCode,
				'Without the presence guard the read would fail instead, and the operator would be told to retry a call that cannot succeed.'
			);
		}
	}

	/**
	 * Input is validated AFTER presence, so a caller on a site without ACF is told
	 * the plugin is missing rather than being walked through correcting a request
	 * that could not have run either way.
	 */
	public function test_a_malformed_filter_on_a_site_without_acf_reports_the_missing_plugin_first(): void {
		try {
			$this->handle( [ 'group' => [ 'group_page' ] ] );
			$this->fail( 'The call must be refused.' );
		} catch ( OperationException $e ) {
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

	// ------------------------------------------------- null and [] are different

	/**
	 * THE DECISION 5 GUARD. `acf/load_field_groups` is a public filter, so a site
	 * really can answer with something that is not a list of groups. AcfApi turns
	 * that into null, and null must REFUSE. Coalescing it into `[]` would produce
	 * a well-formed response reporting a site as defining no field groups when
	 * nothing was read at all.
	 */
	public function test_an_unreadable_group_list_refuses_rather_than_reporting_zero_groups(): void {
		$this->installAcf( 'not a list of groups' );

		try {
			$this->handle();
			$this->fail( 'An unreadable group list must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame(
				ErrorCode::IntegrationUnavailable,
				$e->errorCode,
				'ACF is installed here; the plugin is not what is missing.'
			);
		}
	}

	/**
	 * The mirror, and the reason the test above can fail. A site with ACF and no
	 * field groups is an ordinary site, not a failure, and it answers zero groups.
	 */
	public function test_a_site_with_acf_and_no_groups_reports_zero_groups_without_refusing(): void {
		$this->installAcf( [] );

		$payload = $this->handle();

		$this->assertSame( [], $payload['groups'] );
		$this->assertFalse( $payload['truncated'] );
		$this->assertSame( [], $payload['warnings'] );
	}

	// ------------------------------------------------------------------ the filter

	public function test_the_group_filter_narrows_the_listing_to_one_group(): void {
		$this->installAcf(
			[
				$this->group( 'group_page', 'Page settings' ),
				$this->group( 'group_post', 'Post settings' ),
			]
		);

		$payload = $this->handle( [ 'group' => 'group_post' ] );

		$this->assertCount( 1, $payload['groups'] );
		$this->assertSame( 'group_post', $payload['groups'][0]['key'] );
		$this->assertSame(
			1,
			$this->acfCallCount( 'fields' ),
			'The filter must narrow before the per-group field reads, not after.'
		);
	}

	/**
	 * A filter matching nothing answers zero groups AND says so.
	 *
	 * Zero groups alone would read as "that group defines no fields", which is a
	 * claim about a group that does not exist. The warning names the input field
	 * and never echoes the value the caller sent.
	 */
	public function test_a_filter_matching_no_group_warns_rather_than_answering_silently(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ] );

		$payload = $this->handle( [ 'group' => 'group_missing' ] );

		$this->assertSame( [], $payload['groups'] );
		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringContainsString( 'group', $payload['warnings'][0] );
		$this->assertStringNotContainsString( 'group_missing', $payload['warnings'][0], 'A warning names the field, never its value.' );
	}

	public function test_a_filter_that_is_not_a_string_is_refused_as_invalid_input(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ] );

		try {
			$this->handle( [ 'group' => [ 'group_page' ] ] );
			$this->fail( 'A non-string filter must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}

		$this->assertSame( 0, $this->acfCallCount( 'fields' ), 'Input is validated before any per-group read.' );
	}

	// ------------------------------------------------------------ malformed data

	/**
	 * A location rule member that is not a rule is dropped, not crashed on.
	 *
	 * `acf/load_field_group` is public, and ACF itself has stored a bare string
	 * in the position across versions. Reading the whole group would be lost over
	 * one unreadable rule, and a TypeError inside a read is the worst of the
	 * available outcomes.
	 */
	public function test_a_malformed_location_rule_member_is_dropped_rather_than_crashing(): void {
		$this->installAcf(
			[
				$this->group(
					'group_page',
					'Page settings',
					[
						'location' => [
							[
								'not a rule',
								[ 'operator' => '==' ],
								[
									'param'    => 'post_type',
									'operator' => '==',
									'value'    => 'page',
								],
							],
							'not a rule group',
						],
					]
				),
			]
		);

		$payload = $this->handle();
		$rules   = $payload['groups'][0]['locationRules'];

		$this->assertCount( 1, $rules, 'A rule group that is not an array is dropped.' );
		$this->assertCount( 1, $rules[0], 'A member that is not an array, and one carrying no scalar param, are both dropped.' );
		$this->assertSame( 'post_type', $rules[0][0]['param'] );
	}

	/**
	 * An AND with no conditions in it matches everything, and saying nothing about
	 * it would report the group as narrower than the site declared it. A rule
	 * group that arrived with rules and lost every one of them to the shape guards
	 * is the opposite case and is dropped, because emitting `[]` there would turn
	 * "these conditions could not be read" into "there are no conditions".
	 */
	public function test_an_empty_rule_group_is_kept_but_one_emptied_by_dropping_is_not(): void {
		$this->installAcf(
			[
				$this->group( 'group_everywhere', 'Everywhere', [ 'location' => [ [] ] ] ),
				$this->group( 'group_unreadable', 'Unreadable', [ 'location' => [ [ 'not a rule' ] ] ] ),
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ [] ], $payload['groups'][0]['locationRules'] );
		$this->assertSame( [], $payload['groups'][1]['locationRules'] );
	}

	public function test_a_group_that_is_not_an_array_is_dropped_and_warned_about(): void {
		$this->installAcf( [ 'not a group', $this->group( 'group_page', 'Page settings' ) ] );

		$payload = $this->handle();

		$this->assertCount( 1, $payload['groups'] );
		$this->assertSame( 'group_page', $payload['groups'][0]['key'] );
		$this->assertNotSame( [], $payload['warnings'], 'A dropped group is a fact the caller has to be told.' );
	}

	/**
	 * A group whose fields could not be read is reported, and the failure is
	 * warned about rather than reported as a group with no fields.
	 */
	public function test_a_group_whose_fields_cannot_be_read_is_warned_about(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[ 'group_page' => 'not a list of fields' ]
		);

		$payload = $this->handle();

		$this->assertSame( [], $payload['groups'][0]['fields'] );
		$this->assertNotSame( [], $payload['warnings'] );
	}

	// -------------------------------------------------------------- the ceiling

	/**
	 * Over the ceiling the listing STOPS AND SAYS SO.
	 *
	 * Ending silently at 200 would answer a different question than the one asked
	 * and answer it invisibly: a caller cannot tell "this site has 200 groups"
	 * from "this site has 900 and you were shown the first 200". The count in the
	 * warning is a count, not any group's content.
	 */
	public function test_more_groups_than_the_ceiling_truncates_and_warns(): void {
		$groups = [];

		for ( $index = 0; $index < AcfFields::MAX_GROUPS + 5; $index++ ) {
			$groups[] = $this->group( 'group_' . $index, 'Group ' . $index );
		}

		$this->installAcf( $groups );

		$payload = $this->handle();

		$this->assertCount( AcfFields::MAX_GROUPS, $payload['groups'] );
		$this->assertTrue( $payload['truncated'] );
		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringContainsString( (string) AcfFields::MAX_GROUPS, $payload['warnings'][0] );

		// The ceiling has to bound the WORK, not just the response: reading the
		// fields of every group and then discarding most of them would leave the
		// unbounded cost the ceiling exists to remove.
		$this->assertSame( AcfFields::MAX_GROUPS, $this->acfCallCount( 'fields' ) );
	}
}
