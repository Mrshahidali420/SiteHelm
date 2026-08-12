<?php
/**
 * Tests for MetaboxFieldList (REQ-0049).
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
use SiteHelm\Modules\Metabox\MetaboxFieldIndex;
use SiteHelm\Modules\Metabox\MetaboxFieldList;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0049: which Meta Box fields actually apply to ONE post.
 *
 * THE REQUIREMENT'S OPERATIVE WORD IS "ONLY", so the exclusions are asserted
 * directly. A field belonging to a group whose assignment rules do not match the
 * target appearing in this response is a requirement failure and not a cosmetic
 * one: this is the call an operator plans a write from, and a field listed here
 * that Meta Box does not attach to the post is a write that stores a row no
 * editing screen shows.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; neither can be removed from a process, so a
 * suite that installed Meta Box once would have nothing left to run the
 * absent-plugin and too-old refusals against.
 *
 * THE GUARD-ORDER PROOFS ARE THE TWO TESTS THAT ARRANGE TWO REFUSALS AT ONCE, and
 * which tests those are matters more than their number. An error code cannot show
 * ordering when only one refusal condition holds — every ordering passes that.
 * test_a_denied_caller_is_refused_before_the_target_is_looked_up arranges a
 * nonexistent post AND no capability, so `Forbidden` rather than `TargetNotFound`
 * is the ordering; test_a_denied_caller_is_refused_before_the_presence_gate_is_
 * consulted arranges no capability on a site with no Meta Box, and works precisely
 * because Meta Box is NOT installed in its process.
 *
 * THE ABSENT `warnings` MEMBER IS ASSERTED, NOT ASSUMED. OperationResult::toArray()
 * emits a top-level `warnings` for every read, so a `warnings` inside `data` would
 * sit one level below an identically named empty envelope member and a client
 * honouring the envelope contract would report none for a degraded listing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldListTest extends TestCase {

	use MetaboxWordPressStubs;

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
	 * Every doubled Meta Box call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every post identifier looked up, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->metaboxCalls     = [];
		$this->capabilityChecks = [];
		$this->posts            = [];
		$this->postCalls        = [];

		$this->stubMetaboxWordPress();
		$this->stubMetaboxPosts();
	}

	/**
	 * The operation, wired the way the module wires it.
	 *
	 * One presence gate shared by the operation and the API wrapper, so that "does
	 * this site run Meta Box" is answered by one object within a request.
	 *
	 * @return MetaboxFieldList The handler under test.
	 */
	private function operation(): MetaboxFieldList {
		$presence = new MetaboxPresence();

		return new MetaboxFieldList( $presence, new MetaboxFieldIndex( new MetaboxApi( $presence ) ) );
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
	private function handle( array $input = [ 'post' => 42 ] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * One group, carrying the `fields` setting every real Meta Box group carries.
	 *
	 * THE DEFAULT IS `[]` AND NOT ABSENCE. A group answering `false` for `fields` is
	 * one whose field list was mangled after registration, which MetaboxApi correctly
	 * reports as unreadable — so a fixture that omitted the setting to mean "no
	 * fields" would be describing a DEGRADED group and every assertion about the
	 * notices channel would be reading a notice the site never earned.
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
	 * `id` IS THE META KEY AND `name` IS THE LABEL. The helper spells them apart so a
	 * fixture cannot quietly agree with an implementation that swapped them.
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
	 * Puts post 42 on the doubled site, of a chosen type.
	 *
	 * @param string $post_type The post type.
	 */
	private function givenTarget( string $post_type = 'post' ): void {
		$this->posts[42] = $this->metaboxPost( 42, $post_type );
	}

	// ------------------------------------------------------------ the guards

	/**
	 * THE CAPABILITY-BEFORE-TARGET ORDERING PROOF. Both refusal conditions hold at
	 * once — post 999 is not on this site and the caller may not edit it — so only the
	 * ordering decides which is raised. Run the lookup first and the caller learns
	 * from the difference between two refusals whether a post they may not edit exists
	 * at all, which is site content they are not entitled to.
	 */
	public function test_a_denied_caller_is_refused_before_the_target_is_looked_up(): void {
		$this->mayEdit = false;
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'No post may be looked up for a caller who was refused.' );
	}

	/**
	 * THE CAPABILITY-BEFORE-PRESENCE ORDERING PROOF, AND IT WORKS BECAUSE META BOX IS
	 * NOT INSTALLED IN THIS PROCESS. Both refusals are available; capability is the
	 * one that must win, or an unprivileged caller learns which plugins this site
	 * runs.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted(): void {
		$this->mayEdit = false;
		$this->givenTarget();

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	/**
	 * THE CAPABILITY IS TARGET-SCOPED, AND THE RECORDED ARGUMENT IS THE ONLY PLACE
	 * THAT IS VISIBLE. A site-wide `edit_posts` answers identically for every
	 * administrator, so an operation that asked the wrong question would pass every
	 * assertion on its payload while handing a contributor the field set of a page
	 * they may not touch.
	 */
	public function test_the_capability_is_asked_about_this_post_and_not_about_the_site(): void {
		$this->givenTarget();
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$this->handle();

		$this->assertSame( [ [ 7, 'edit_post', 42 ] ], $this->capabilityChecks );
	}

	public function test_a_site_without_metabox_is_refused_as_unavailable(): void {
		$this->givenTarget();

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}
	}

	/**
	 * "TOO OLD" IS A DIFFERENT ANSWER FROM "ABSENT" AND SENDS THE OPERATOR TO A
	 * DIFFERENT REMEDY. Nothing upstream draws the distinction — the dispatcher blocks
	 * on a health state no module reports — so an unsupported Meta Box reaches this
	 * handler unchallenged and must be refused here.
	 */
	public function test_a_metabox_below_the_floor_is_refused_as_unsupported(): void {
		$this->givenTarget();
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.2.9' );

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $exception->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'The version gate runs before the target lookup.' );
	}

	public function test_a_post_that_does_not_exist_is_refused_as_not_found(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * THE IDENTIFIER IS GUARDED ON SHAPE AND NEVER CAST. `(int)` on an array is 1, so
	 * a cast would turn a malformed argument into a listing for post 1 — a post most
	 * sites have — and answer a question nobody asked.
	 *
	 * @dataProvider unusableIdentifiers
	 *
	 * @param mixed $post The caller's argument.
	 */
	public function test_an_unusable_identifier_is_refused_as_invalid_input( mixed $post ): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		try {
			$this->handle( [ 'post' => $post ] );
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @return array<string, array{0: mixed}> The arguments no post identifier can be read from.
	 */
	public function unusableIdentifiers(): array {
		return [
			'an array'         => [ [ 42 ] ],
			'a numeric string' => [ '42' ],
			'zero'             => [ 0 ],
			'a negative'       => [ -1 ],
			'null'             => [ null ],
			'a float'          => [ 42.0 ],
		];
	}

	// ------------------------------------------------------------ the answer

	public function test_the_listing_reports_the_fields_of_a_matching_group(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'      => 'Post settings',
							'post_types' => [ 'post' ],
							'fields'     => [ $this->field( 'byline', 'Byline', 'text', [ 'required' => true ] ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( 42, $payload['target'] );
		$this->assertSame(
			[
				[
					'id'         => 'byline',
					'name'       => 'Byline',
					'type'       => 'text',
					'required'   => true,
					'groupId'    => 'group-post',
					'groupTitle' => 'Post settings',
				],
			],
			$payload['fields']
		);
		$this->assertSame( [], $payload['fieldListingNotices'] );
	}

	/**
	 * THE "ONLY" IN THE REQUIREMENT, ASSERTED AS AN EXCLUSION AT THE OPERATION
	 * BOUNDARY. A group scoped to `page` must contribute nothing to a post, and this
	 * is the response an operator plans a write from.
	 */
	public function test_the_listing_omits_the_fields_of_a_group_scoped_to_another_post_type(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-page',
						[
							'title'      => 'Page settings',
							'post_types' => [ 'page' ],
							'fields'     => [ $this->field( 'subtitle', 'Subtitle' ) ],
						]
					),
					$this->group(
						'group-post',
						[
							'title'      => 'Post settings',
							'post_types' => [ 'post' ],
							'fields'     => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [ 'byline' ], array_column( $payload['fields'], 'id' ) );
	}

	/**
	 * A NON-POST GROUP NEVER APPEARS, whatever it declares. V1 serves post-object
	 * groups only (spec §3), and a user group's values are stored against a user id.
	 */
	public function test_the_listing_omits_a_group_of_another_object_type(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-user',
						[
							'title'  => 'Profile fields',
							'fields' => [ $this->field( 'twitter', 'Twitter' ) ],
						],
						'user'
					),
				]
			)
		);

		$this->assertSame( [], $this->handle()['fields'] );
	}

	/**
	 * THE RESPONSE SAYS WHICH VOCABULARY ITS IDENTIFIERS BELONG TO. This dispatcher
	 * also serves Advanced Custom Fields, whose keys look nothing like Meta Box meta
	 * keys, so a client holding a field list has to be told which plugin's it is.
	 */
	public function test_the_response_names_metabox_as_its_provider(): void {
		$this->givenTarget();
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$this->assertSame( MetaboxSchemaFormat::PROVIDER, $this->handle()['provider'] );
		$this->assertSame( 'metabox', $this->handle()['provider'] );
	}

	/**
	 * A FIELD'S `id` IS THE META KEY AND ITS `name` IS THE LABEL, and the schema says
	 * so in words rather than leaving a client to infer it. This is the single mistake
	 * this module is most likely to make, and a response whose two members were
	 * swapped would look entirely plausible.
	 */
	public function test_the_schema_says_which_member_is_the_meta_key(): void {
		$items = MetaboxFieldList::definition()->outputSchema['properties']['fields']['items'];

		$this->assertStringContainsStringIgnoringCase( 'meta key', $items['properties']['id']['description'] );
		$this->assertStringContainsStringIgnoringCase( 'label', $items['properties']['name']['description'] );
	}

	/**
	 * NO STORED VALUE LEAVES BY ANY ROUTE. The index carries each field's raw
	 * definition so the later operations can consult it, and a definition carries
	 * `std` — the field's default VALUE. A response that promises to report which
	 * fields apply rather than what they hold must project it away.
	 */
	public function test_the_reported_field_carries_no_definition_and_no_default_value(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'byline', 'Byline', 'text', [ 'std' => 'A default nobody asked for' ] ) ],
						]
					),
				]
			)
		);

		$reported = $this->handle()['fields'][0];

		$this->assertSame(
			[ 'id', 'name', 'type', 'required', 'groupId', 'groupTitle' ],
			array_keys( $reported )
		);
		$this->assertArrayNotHasKey( 'definition', $reported );
		$this->assertArrayNotHasKey( 'std', $reported );
	}

	public function test_a_post_no_group_applies_to_lists_no_fields_without_refusing(): void {
		$this->givenTarget( 'product' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-page',
						[
							'title'      => 'Page settings',
							'post_types' => [ 'page' ],
							'fields'     => [ $this->field( 'subtitle', 'Subtitle' ) ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [], $payload['fields'] );
		$this->assertSame( [], $payload['fieldListingNotices'] );
	}

	// ----------------------------------------------------------- the notices

	/**
	 * A GROUP WHOSE FIELDS COULD NOT ALL BE READ IS REPORTED RATHER THAN QUIETLY
	 * SHORTENING THE LIST. A shorter list presented as complete reads exactly like
	 * fields having been deleted, and this response is what a write is planned
	 * against.
	 */
	public function test_a_group_whose_definitions_could_not_be_read_earns_a_notice(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-broken',
						[
							'title'  => 'Broken',
							'fields' => [ $this->field( 'kept', 'Kept' ), 'not a definition' ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertSame( [ 'kept' ], array_column( $payload['fields'], 'id' ) );
		$this->assertCount( 1, $payload['fieldListingNotices'] );
		$this->assertStringContainsString( 'group-broken', $payload['fieldListingNotices'][0] );
	}

	/**
	 * A NOTICE COUNTS AND NAMES GROUPS AND CARRIES NO VALUE. A notice is text a client
	 * may display, and a stored value quoted into one is how a message becomes an
	 * injection surface.
	 */
	public function test_a_notice_carries_no_field_value(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-broken',
						[
							'title'  => 'Broken',
							'fields' => [ $this->field( 'kept', 'Kept', 'text', [ 'std' => 'SENTINEL-VALUE' ] ), 42 ],
						]
					),
				]
			)
		);

		$this->assertStringNotContainsString( 'SENTINEL-VALUE', $this->handle()['fieldListingNotices'][0] );
	}

	/**
	 * A DEGRADED GROUP THAT DOES NOT APPLY EARNS NO NOTICE. The notices describe this
	 * listing, and a group whose rules do not match this post was never going to
	 * contribute a field to it — reporting it would tell an operator something is
	 * missing from a response nothing was missing from.
	 */
	public function test_a_degraded_group_that_does_not_apply_earns_no_notice(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-page',
						[
							'title'      => 'Page settings',
							'post_types' => [ 'page' ],
							'fields'     => [ 'not a definition' ],
						]
					),
				]
			)
		);

		$this->assertSame( [], $this->handle()['fieldListingNotices'] );
	}

	/**
	 * A GROUP WITH NO READABLE ID IS COUNTED RATHER THAN PRINTED AS AN EMPTY NAME.
	 * Saying nothing at all would be the silence the channel exists to prevent.
	 */
	public function test_a_degraded_group_with_no_readable_id_is_counted_not_named(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'',
						[
							'title'  => 'Nameless',
							'fields' => [ 'not a definition' ],
						]
					),
				]
			)
		);

		$notices = $this->handle()['fieldListingNotices'];

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( '1 field group', $notices[0] );
	}

	/**
	 * THE ENVELOPE OWNS THE NAME `warnings`, AND THIS RESPONSE MUST NOT SHADOW IT.
	 * Dispatcher builds every read's OperationResult with `warnings: []`, so a
	 * `warnings` inside `data` would sit one level below an identically named empty
	 * envelope member and a client honouring the envelope contract would report no
	 * warnings for a degraded listing. Two modules in this plugin have shipped that
	 * bug.
	 */
	public function test_the_response_carries_no_warnings_member_of_its_own(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-broken',
						[
							'title'  => 'Broken',
							'fields' => [ 'not a definition' ],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertArrayNotHasKey( 'warnings', $payload );
		$this->assertArrayHasKey( 'fieldListingNotices', $payload );
		$this->assertSame(
			[ 'provider', 'target', 'fields', 'fieldListingNotices' ],
			array_keys( $payload )
		);
	}

	public function test_the_output_schema_declares_no_warnings_member(): void {
		$schema = MetaboxFieldList::definition()->outputSchema;

		$this->assertArrayNotHasKey( 'warnings', $schema['properties'] );
		$this->assertArrayHasKey( 'fieldListingNotices', $schema['properties'] );
		$this->assertSame(
			[ 'provider', 'target', 'fields', 'fieldListingNotices' ],
			$schema['required']
		);
		$this->assertFalse( $schema['additionalProperties'] );
	}
}
