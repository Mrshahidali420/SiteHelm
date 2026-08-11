<?php
/**
 * Tests for MetaboxGroupList (REQ-0048).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxGroupList;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0048: what Meta Box field groups does this site define, and what is in them.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; both are permanent for the life of a process, so
 * a suite that installed Meta Box once would have nothing left to run the
 * absent-plugin refusals against — and those refusals are half of what this
 * operation does. It is also what makes the version boundary testable on both
 * sides: 5.2.9 and 5.3.0 cannot both be `RWMB_VER` in one process.
 *
 * THE GUARD-ORDER PROOF IS ONE TEST AND ONLY ONE, and which test that is matters
 * more than the count. Capability runs before presence (spec §6), and the error code
 * alone cannot show it: on a site where an unprivileged caller meets an absent
 * plugin BOTH refusal conditions hold at once and only the ordering decides which is
 * raised. test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted is
 * that test, and it works precisely because Meta Box is NOT installed in its
 * process. test_a_denied_caller_causes_no_metabox_read_at_all is deliberately not an
 * ordering proof — with Meta Box installed, a swap of the two blocks leaves it
 * passing — and its docblock says so, because the later tasks in this module will
 * copy this file and copying the wrong test would leave the ordering unprotected
 * while looking as though it were covered.
 *
 * THE ABSENT `warnings` MEMBER IS ASSERTED, NOT ASSUMED. OperationResult::toArray()
 * emits a top-level `warnings` for every read, so a `warnings` inside `data` would
 * sit one level below an identically named empty envelope member and a client
 * honouring the envelope would report none for a degraded listing. Two modules have
 * now shipped that wire bug, so the absence has a test of its own rather than a
 * comment.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxGroupListTest extends TestCase {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Declared here because the shared trait's contract requires it; PHP 8.1 has no
	 * trait constants and a trait property would collide.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question the operation asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled Meta Box call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->metaboxCalls     = [];
		$this->capabilityChecks = [];

		$this->stubMetaboxWordPress();
	}

	/**
	 * The operation, wired the way the module wires it.
	 *
	 * One presence gate shared by the operation and the API wrapper, so that "does
	 * this site run Meta Box" is answered by one object within a request.
	 *
	 * @return MetaboxGroupList The handler under test.
	 */
	private function operation(): MetaboxGroupList {
		$presence = new MetaboxPresence();

		return new MetaboxGroupList( $presence, new MetaboxApi( $presence ), new MetaboxSchemaFormat() );
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
				'metabox' => [
					'version' => '5.9.4',
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
	private function handle( array $input = [] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * One group, carrying the `fields` setting every real Meta Box group carries.
	 *
	 * THE DEFAULT IS `[]` AND NOT ABSENCE, WHICH IS A FIDELITY POINT RATHER THAN A
	 * CONVENIENCE. `RW_Meta_Box::__get()` answers `false` for a setting the group does
	 * not hold, and Meta Box normalizes `fields` to an array when it registers a
	 * group — so a group answering `false` there is one whose field list was mangled
	 * after registration, which MetaboxApi correctly reports as unreadable. A fixture
	 * that omitted the setting to mean "this group has no fields" would therefore be
	 * describing a DEGRADED group, and every assertion about the notices channel would
	 * be reading a notice the site never earned.
	 *
	 * @param string               $id          The group id.
	 * @param array<string, mixed> $settings    The group's settings.
	 * @param string               $object_type The object type the group attaches to.
	 *
	 * @return object The group.
	 */
	private function group( string $id, array $settings = [], string $object_type = 'post' ): object {
		return $this->metaboxGroup( $id, array_merge( [ 'fields' => [] ], $settings ), $object_type );
	}

	/**
	 * One field definition, in the shape Meta Box stores it.
	 *
	 * `id` IS THE META KEY AND `name` IS THE LABEL. The helper spells them apart so
	 * that a fixture cannot quietly agree with an implementation that swapped them.
	 *
	 * @param string               $id        The meta key.
	 * @param string               $name      The human label.
	 * @param string               $type      The Meta Box field type.
	 * @param array<string, mixed> $overrides Members to add.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function field( string $id, string $name, string $type = 'text', array $overrides = [] ): array {
		return array_merge(
			[
				'id'   => $id,
				'name' => $name,
				'type' => $type,
			],
			$overrides
		);
	}

	/**
	 * A chain of nested group fields, the outermost at depth 0.
	 *
	 * @param int $levels How many fields the chain holds.
	 *
	 * @return array<string, mixed> The outermost field.
	 */
	private function chain( int $levels ): array {
		$field = $this->field( 'leaf', 'Leaf' );

		for ( $depth = $levels - 2; $depth >= 0; $depth-- ) {
			$field = $this->field(
				'level_' . $depth,
				'Level ' . $depth,
				'group',
				[ 'fields' => [ $field ] ]
			);
		}

		return $field;
	}

	// ---------------------------------------------------------------- happy path

	public function test_a_site_with_two_groups_reports_each_with_its_object_type_rules_and_fields(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-page',
						[
							'title'      => 'Page settings',
							'post_types' => [ 'page' ],
							'fields'     => [
								$this->field( 'subtitle', 'Subtitle', 'text', [ 'required' => 1 ] ),
								$this->field( 'hero', 'Hero image', 'image_advanced' ),
							],
						]
					),
					$this->group(
						'group-post',
						[
							'title'      => 'Post settings',
							'post_types' => [ 'post', 'page' ],
							'fields'     => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [ 'provider', 'groups', 'truncated', 'groupListingNotices' ], array_keys( $payload ) );
		$this->assertFalse( $payload['truncated'] );
		$this->assertSame( [], $payload['groupListingNotices'] );
		$this->assertCount( 2, $payload['groups'] );

		$page = $payload['groups'][0];

		$this->assertSame(
			[ 'id', 'title', 'objectType', 'supported', 'postTypes', 'appliesToAllPostTypes', 'fields' ],
			array_keys( $page )
		);
		$this->assertSame( 'group-page', $page['id'] );
		$this->assertSame( 'Page settings', $page['title'] );
		$this->assertSame( 'post', $page['objectType'] );
		$this->assertSame( [ 'page' ], $page['postTypes'] );
		$this->assertFalse( $page['appliesToAllPostTypes'] );

		// THE REQUIREMENT IS FIELD IDS *AND* TYPES, so both are asserted, and the id
		// is asserted to be the meta key rather than the label.
		$this->assertSame( 'subtitle', $page['fields'][0]['id'] );
		$this->assertSame( 'Subtitle', $page['fields'][0]['name'] );
		$this->assertSame( 'text', $page['fields'][0]['type'] );
		$this->assertTrue( $page['fields'][0]['required'] );
		$this->assertSame( 'image_advanced', $page['fields'][1]['type'] );

		$this->assertSame( [ 'post', 'page' ], $payload['groups'][1]['postTypes'] );
	}

	/**
	 * AN UNRESTRICTED GROUP APPLIES EVERYWHERE, AND `[]` DOES NOT SAY THAT. Meta Box
	 * reads an absent `post_types` as "every post type"; a response carrying only an
	 * empty list would be read by a client as "no post type", the exact opposite
	 * claim and the broader of the two in consequence.
	 */
	public function test_a_group_with_no_post_type_restriction_is_reported_as_applying_to_all(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-everywhere',
						[
							'title'  => 'Everywhere',
							'fields' => [ $this->field( 'note', 'Note' ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [], $payload['groups'][0]['postTypes'] );
		$this->assertTrue( $payload['groups'][0]['appliesToAllPostTypes'] );
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-page',
						[
							'title'      => 'Page settings',
							'post_types' => [ 'page' ],
							'fields'     => [
								$this->field(
									'slides',
									'Slides',
									'group',
									[
										'clone'  => true,
										'fields' => [ $this->field( 'caption', 'Caption' ) ],
									]
								),
								// A choices-bearing field, with the numeric keys that
								// were the actual wire failure in the sibling module:
								// keyed by value, this member re-types itself into a
								// JSON array and breaks the object a schema published.
								$this->field(
									'layout',
									'Layout',
									'select',
									[
										'options' => [
											1 => 'Wide',
											2 => 'Narrow',
										],
									]
								),
							],
						]
					),
					$this->group( 'group-user', [ 'title' => 'Profile' ], 'user' ),
				]
			)
		);

		$payload = $this->handle();

		// A conformance check passes just as happily over a member that was never
		// emitted, so the nested and mapped members are asserted present first.
		$this->assertSame( 'caption', $payload['groups'][0]['fields'][0]['subFields'][0]['id'] );
		$this->assertTrue( $payload['groups'][0]['fields'][0]['clonable'] );
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
			$payload['groups'][0]['fields'][1]['options']
		);

		// The recursive `$ref` into `#/$defs/metaboxFieldSchema` resolves only against
		// the schema document, so this is also the proof that the declared `$id` and
		// `$defs` are wired to each other.
		$this->assertConformsToOutputSchema( $payload, MetaboxGroupList::definition()->outputSchema );
	}

	public function test_a_site_with_metabox_and_no_groups_reports_zero_groups_without_refusing(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$payload = $this->handle();

		$this->assertSame( [], $payload['groups'] );
		$this->assertFalse( $payload['truncated'] );
		$this->assertSame( [], $payload['groupListingNotices'] );
	}

	// ------------------------------------------------------------ the provider

	/**
	 * THE MODULE IS ONE OF TWO ANSWERING THE SAME DISPATCHER. `subtitle` is a meta
	 * key to Meta Box and nothing at all to ACF, whose keys look like
	 * `field_5f3a1b2c`, so a response that did not say whose vocabulary its
	 * identifiers belong to would be a set of identifiers a client cannot use.
	 */
	public function test_the_response_identifies_the_provider_as_metabox(): void {
		$this->installMetabox( $this->metaboxRegistry( [ $this->group( 'group-page', [ 'title' => 'Page' ] ) ] ) );

		$payload = $this->handle();

		$this->assertSame( 'metabox', $payload['provider'] );
		$this->assertSame( MetaboxSchemaFormat::PROVIDER, $payload['provider'] );
	}

	/**
	 * THE WIRE BUG TWO MODULES HAVE NOW SHIPPED, ASSERTED RATHER THAN COMMENTED.
	 * Dispatcher builds every read's OperationResult with `warnings: []` and
	 * OperationResult::toArray() emits it at the top level. A `warnings` member
	 * inside `data` would therefore sit one level below an identically named empty
	 * envelope member, and a client honouring the envelope contract would report no
	 * warnings for a listing that was degraded. The channel is `groupListingNotices`,
	 * and this test fails the moment anything renames it back.
	 */
	public function test_the_response_carries_no_warnings_member_of_its_own(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					// A group that WILL produce a notice, so the assertion runs against
					// a response that actually had something to report rather than
					// against an empty one where any channel name would pass.
					$this->group(
						'group-broken',
						[
							'title'  => 'Broken',
							'fields' => [ $this->field( 'ok', 'Ok' ), 'not a definition' ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertArrayNotHasKey( 'warnings', $payload, 'The envelope owns that name; data must not shadow it.' );
		$this->assertNotSame( [], $payload['groupListingNotices'], 'The notice channel must be the one carrying it.' );
	}

	// ------------------------------------------------------------- object types

	/**
	 * EVERY OBJECT TYPE IS LISTED AND ONLY POST GROUPS ARE SUPPORTED. V1 addresses
	 * post-object groups only (spec §3), but a discovery read that hid the others
	 * would leave an operator unable to tell "this site has no such group" from
	 * "SiteHelm does not serve it", and those call for different actions.
	 */
	public function test_a_non_post_object_group_is_reported_unsupported_and_a_post_group_supported(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group( 'group-post', [ 'title' => 'Post settings' ], 'post' ),
					$this->group( 'group-user', [ 'title' => 'Profile fields' ], 'user' ),
					$this->group( 'group-term', [ 'title' => 'Term fields' ], 'term' ),
				]
			)
		);

		$payload = $this->handle();

		$this->assertCount( 3, $payload['groups'], 'Every object type is listed; only support differs.' );

		$this->assertSame( 'post', $payload['groups'][0]['objectType'] );
		$this->assertTrue( $payload['groups'][0]['supported'] );

		$this->assertSame( 'user', $payload['groups'][1]['objectType'] );
		$this->assertFalse( $payload['groups'][1]['supported'] );

		$this->assertSame( 'term', $payload['groups'][2]['objectType'] );
		$this->assertFalse( $payload['groups'][2]['supported'] );
	}

	// -------------------------------------------------------------- guard order

	/**
	 * THE ORDERING PROOF, AND THE ONLY TEST IN THIS FILE THAT DETECTS A SWAP OF THE
	 * CAPABILITY AND PRESENCE BLOCKS. Meta Box is deliberately NOT installed in this
	 * process, so the caller trips BOTH guards at once and only the order decides
	 * which refusal is raised. Move the presence check above the capability check and
	 * this test reports IntegrationUnavailable — which would tell an unprivileged
	 * caller whether this site runs Meta Box, a fact about the site's configuration
	 * they have no right to, and would walk them through fixing a request that was
	 * never going to run.
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
	 * NOT AN ORDERING PROOF — a no-work proof, and the distinction is why this
	 * docblock exists. Here Meta Box IS installed, so a refusal for either reason
	 * leaves the same evidence: swap the capability and presence blocks and presence
	 * simply passes, the capability guard still throws Forbidden, and zero Meta Box
	 * calls are still recorded. This test cannot see that mutation and must not be
	 * described as though it can. MetaboxPresence::isLoaded() calls only defined()
	 * and function_exists(), neither of which a double can observe.
	 *
	 * What it does prove is worth its own test: a caller who may not have the answer
	 * causes no work to be done for them at all.
	 */
	public function test_a_denied_caller_causes_no_metabox_read_at_all(): void {
		$this->installMetabox( $this->metaboxRegistry( [ $this->group( 'group-page', [ 'title' => 'Page' ] ) ] ) );
		$this->mayEdit = false;

		try {
			$this->handle();
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( 0, $this->metaboxCallCount(), 'The capability check must run BEFORE any Meta Box read.' );
		$this->assertSame( [ 7, 'edit_posts' ], $this->capabilityChecks[0], 'The question is site-wide; this operation names no target.' );
	}

	// ----------------------------------------------------------- Meta Box absent

	public function test_a_site_without_metabox_refuses_with_integration_unavailable(): void {
		try {
			$this->handle();
			$this->fail( 'A site without Meta Box must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation, 'The remedy is to install a plugin, and the refusal must say so.' );
		}
	}

	/**
	 * A HALF-LOADED META BOX IS BLAMED ON THE PLUGIN. The Meta Box functions are
	 * installed here but RWMB_VER is not, which is the shape of a fork, a disturbed
	 * load order, or another plugin's compatibility shim. Presence must refuse it as
	 * an unavailable integration rather than letting it reach a read that would
	 * answer with an empty registry and report the site as defining no groups.
	 */
	public function test_a_half_loaded_metabox_is_refused_as_a_missing_plugin(): void {
		$this->installMetabox( $this->metaboxRegistry( [ $this->group( 'group-page', [ 'title' => 'Page' ] ) ] ), null );

		try {
			$this->handle();
			$this->fail( 'A site whose Meta Box declares no version must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->assertSame( 0, $this->metaboxCallCount( 'registry' ), 'Presence must stop the read.' );
	}

	// ------------------------------------------------------------- the version

	/**
	 * BELOW THE FLOOR IS A DIFFERENT ANSWER FROM ABSENT, and the caller acts on the
	 * difference: one is "install Meta Box", the other is "update it". Nothing else
	 * in the stack makes this distinction — no module reports VersionBlocked health,
	 * so the dispatcher's version gate never fires for this module — which is why the
	 * operation asks for itself rather than assuming an upstream check ran.
	 */
	public function test_a_metabox_below_the_supported_floor_refuses_as_an_unsupported_version(): void {
		$this->installMetabox( $this->metaboxRegistry( [ $this->group( 'group-page', [ 'title' => 'Page' ] ) ] ), '5.2.9' );

		try {
			$this->handle();
			$this->fail( 'A Meta Box below the floor must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $e->errorCode );
			$this->assertNotSame(
				ErrorCode::IntegrationUnavailable,
				$e->errorCode,
				'Meta Box IS installed here; telling the operator to install it would send them to the wrong remedy.'
			);
		}

		$this->assertSame( 0, $this->metaboxCallCount( 'registry' ), 'An unsupported version must stop before the read.' );
	}

	/**
	 * THE OTHER SIDE OF THE SAME BOUNDARY. Without this, a guard written with `>`
	 * instead of `>=` would refuse every site running exactly the version this module
	 * advertises as its floor, and the test above would pass throughout.
	 */
	public function test_the_supported_floor_itself_is_served(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [ $this->group( 'group-page', [ 'title' => 'Page' ] ) ] ),
			MetaboxPresence::MIN_VERSION
		);

		$payload = $this->handle();

		$this->assertCount( 1, $payload['groups'] );
	}

	// ---------------------------------------------------------------- notices

	/**
	 * A GROUP WHOSE DEFINITIONS COULD NOT BE READ IS STILL LISTED, and the fact that
	 * something was lost is reported rather than left to be inferred from a short
	 * field list. `rwmb_meta_boxes` is a public filter, so a group carrying something
	 * that is not a definition is an ordinary outcome; losing the whole call over it
	 * would be the worse answer, and reporting the group with no fields and no notice
	 * would be indistinguishable from a group that genuinely defines none.
	 */
	public function test_a_group_whose_definitions_cannot_be_read_is_reported_in_the_notices_without_failing(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-broken',
						[
							'title'  => 'Broken',
							'fields' => [ $this->field( 'ok', 'Ok' ), 'not a definition' ],
						]
					),
					$this->group(
						'group-fine',
						[
							'title'  => 'Fine',
							'fields' => [ $this->field( 'kept', 'Kept' ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertCount( 2, $payload['groups'], 'The call must not fail over one unreadable group.' );
		$this->assertCount( 1, $payload['groupListingNotices'] );
		$this->assertStringNotContainsString(
			'group-broken',
			$payload['groupListingNotices'][0],
			'A notice counts; it never echoes an identifier read off the site.'
		);
		$this->assertSame( 'kept', $payload['groups'][1]['fields'][0]['id'] );
	}

	/**
	 * A TREE CUT AT THE DEPTH CAP SAYS SO. The cut sits on a field ten levels inside
	 * the response, and a client walking for it is a client that will not; the notice
	 * is the one place an operator sees that the group holds more than was shown.
	 */
	public function test_a_field_tree_deeper_than_the_cap_is_reported_in_the_notices(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-deep',
						[
							'title'  => 'Deep',
							'fields' => [ $this->chain( MetaboxSchemaFormat::MAX_DEPTH + 3 ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertCount( 1, $payload['groupListingNotices'] );
		$this->assertStringContainsString(
			(string) MetaboxSchemaFormat::MAX_DEPTH,
			$payload['groupListingNotices'][0]
		);
	}

	/**
	 * The mirror of the test above, and the reason it can fail. A group whose fields
	 * fit inside the cap raises no notice at all; without this, a listing that
	 * reported truncation unconditionally would pass.
	 */
	public function test_a_field_tree_inside_the_cap_raises_no_notice(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-shallow',
						[
							'title'  => 'Shallow',
							'fields' => [ $this->chain( MetaboxSchemaFormat::MAX_DEPTH ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [], $payload['groupListingNotices'] );
	}

	// -------------------------------------------------------------- the ceiling

	/**
	 * Over the ceiling the listing STOPS AND SAYS SO. Ending silently at the cap
	 * would answer a different question than the one asked and answer it invisibly: a
	 * caller cannot tell "this site has 200 groups" from "this site has 900 and you
	 * were shown the first 200". The count in the notice is a count, never content.
	 */
	public function test_more_groups_than_the_ceiling_truncates_and_warns(): void {
		$groups = [];

		for ( $index = 0; $index < MetaboxSchemaFormat::MAX_GROUPS + 5; $index++ ) {
			$groups[] = $this->group( 'group-' . $index, [ 'title' => 'Group ' . $index ] );
		}

		$this->installMetabox( $this->metaboxRegistry( $groups ) );

		$payload = $this->handle();

		$this->assertCount( MetaboxSchemaFormat::MAX_GROUPS, $payload['groups'] );
		$this->assertTrue( $payload['truncated'] );
		$this->assertCount( 1, $payload['groupListingNotices'] );
		$this->assertStringContainsString(
			(string) MetaboxSchemaFormat::MAX_GROUPS,
			$payload['groupListingNotices'][0]
		);
	}
}
