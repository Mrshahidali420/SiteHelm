<?php
/**
 * Tests for MetaboxFieldIndex, the applicable-field index behind REQ-0049.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxFieldIndex;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Which Meta Box fields apply to ONE post.
 *
 * THE WORD THE REQUIREMENT TURNS ON IS "ONLY" — REQ-0049 is "returned only the Meta
 * Box fields whose assignment rules match the requested post ID" — so the exclusions
 * are asserted directly rather than inferred from a count. A test that only checked
 * that a matching group's fields ARE present would pass for an index that returned
 * every field on the site, which is the single way this class can be wrong that
 * matters.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; both are permanent for the life of a process, and
 * the index is only exercisable against a site that has Meta Box installed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldIndexTest extends TestCase {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Declared because the shared trait's contract requires it, though this suite
	 * never asks a capability question: the index makes no policy decision at all.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order.
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
	 * The index, wired the way the module wires it.
	 *
	 * @return MetaboxFieldIndex The index under test.
	 */
	private function index(): MetaboxFieldIndex {
		return new MetaboxFieldIndex( new MetaboxApi( new MetaboxPresence() ) );
	}

	/**
	 * One group, carrying the `fields` setting every real Meta Box group carries.
	 *
	 * THE DEFAULT IS `[]` AND NOT ABSENCE. `RW_Meta_Box::__get()` answers `false` for
	 * a setting the group does not hold, and Meta Box normalizes `fields` to an array
	 * when it registers a group, so a group answering `false` there is one whose field
	 * list was mangled after registration — a DEGRADED group rather than an empty one.
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
	 * Puts one post of a chosen type on the doubled site.
	 *
	 * @param int    $id        The post identifier.
	 * @param string $post_type The post type.
	 */
	private function givenPost( int $id, string $post_type ): void {
		$this->posts[ $id ] = $this->metaboxPost( $id, $post_type );
	}

	// ------------------------------------------------------------ the match

	public function test_a_group_scoped_to_the_posts_own_type_contributes_its_fields(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
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

		$fields = $this->index()->fieldsForPost( 42 );

		$this->assertSame( [ 'byline' ], array_keys( $fields ) );
		$this->assertSame( 'byline', $fields['byline']['id'] );
		$this->assertSame( 'Byline', $fields['byline']['name'] );
		$this->assertSame( 'text', $fields['byline']['type'] );
		$this->assertSame( 'group-post', $fields['byline']['groupId'] );
		$this->assertSame( 'Post settings', $fields['byline']['groupTitle'] );
	}

	/**
	 * THE "ONLY" IN THE REQUIREMENT, ASSERTED AS AN EXCLUSION. A group scoped to
	 * `page` must contribute NOTHING to a post of type `post`. Delete the post-type
	 * filter and this is the test that fails; every other test in this file would go
	 * on passing, because they all assert the presence of something rather than the
	 * absence of it.
	 */
	public function test_a_group_scoped_to_another_post_type_contributes_nothing(): void {
		$this->givenPost( 42, 'post' );
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

		$this->assertSame(
			[],
			$this->index()->fieldsForPost( 42 ),
			'A group whose assignment rules do not match the post must contribute no field at all.'
		);
	}

	/**
	 * AN ABSENT `post_types` IS META BOX'S "EVERY POST TYPE", not "no post type". A
	 * filter that treated an empty restriction list as a failed match would report
	 * every unrestricted group as inapplicable, which is most sites' commonest group.
	 */
	public function test_a_group_with_no_post_type_restriction_applies_to_every_post_type(): void {
		$this->givenPost( 42, 'product' );
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

		$this->assertSame( [ 'note' ], array_keys( $this->index()->fieldsForPost( 42 ) ) );
	}

	/**
	 * V1 SERVES POST-OBJECT GROUPS ONLY (spec §3), and the boundary is enforced by
	 * Meta Box's own registry filter rather than by a comparison made here. A user
	 * group's fields are stored against a user id; offering one for a post would
	 * propose a write that lands nowhere.
	 */
	public function test_a_non_post_object_group_never_appears(): void {
		$this->givenPost( 42, 'post' );
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
					$this->group(
						'group-term',
						[
							'title'  => 'Term fields',
							'fields' => [ $this->field( 'colour', 'Colour' ) ],
						],
						'term'
					),
				]
			)
		);

		$this->assertSame( [], $this->index()->fieldsForPost( 42 ) );
	}

	// ------------------------------------------------------------ the merge

	public function test_two_applicable_groups_merge_without_one_clobbering_the_other(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-one',
						[
							'title'      => 'One',
							'post_types' => [ 'post' ],
							'fields'     => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
					$this->group(
						'group-two',
						[
							'title'  => 'Two',
							'fields' => [ $this->field( 'note', 'Note' ), $this->field( 'colour', 'Colour' ) ],
						]
					),
				]
			)
		);

		$fields = $this->index()->fieldsForPost( 42 );

		$this->assertSame( [ 'byline', 'note', 'colour' ], array_keys( $fields ) );
		$this->assertSame( 'group-one', $fields['byline']['groupId'] );
		$this->assertSame( 'group-two', $fields['note']['groupId'] );
	}

	/**
	 * FIRST SEEN WINS ON A DUPLICATE META KEY, and the rule is asserted rather than
	 * left to whichever order the merge happens to run in. Two groups may both declare
	 * a field with the same `id`, but that id IS the meta key, so exactly one row
	 * exists on the post and `rwmb_meta()` answers exactly one value for it. Reporting
	 * the entry twice would offer a choice Meta Box does not have, and letting the last
	 * declaration win would make this index disagree with metabox-group-list about
	 * which group the operator should go and look at.
	 */
	public function test_a_duplicate_field_id_across_two_groups_resolves_to_the_first_group(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-first',
						[
							'title'  => 'First',
							'fields' => [ $this->field( 'subtitle', 'Subtitle as first declared', 'text' ) ],
						]
					),
					$this->group(
						'group-second',
						[
							'title'  => 'Second',
							'fields' => [ $this->field( 'subtitle', 'Subtitle as redeclared', 'textarea' ) ],
						]
					),
				]
			)
		);

		$fields = $this->index()->fieldsForPost( 42 );

		$this->assertCount( 1, $fields, 'One meta key is one entry, whatever declared it.' );
		$this->assertSame( 'group-first', $fields['subtitle']['groupId'] );
		$this->assertSame( 'Subtitle as first declared', $fields['subtitle']['name'] );
		$this->assertSame( 'text', $fields['subtitle']['type'] );
	}

	/**
	 * THE INDEX IS KEYED BY THE META KEY, AND META BOX'S NAMING IS INVERTED. A field's
	 * `id` is the meta key and its `name` is the human label (spec §5); an index keyed
	 * by the label would answer for a caller who addressed the wrong thing, and every
	 * later operation takes its identifier from this key.
	 */
	public function test_the_index_is_keyed_by_the_meta_key_and_not_by_the_label(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'subtitle', 'Subtitle' ) ],
						]
					),
				]
			)
		);

		$fields = $this->index()->fieldsForPost( 42 );

		$this->assertArrayHasKey( 'subtitle', $fields );
		$this->assertArrayNotHasKey( 'Subtitle', $fields );
	}

	// ------------------------------------------------------------- field()

	public function test_field_answers_the_entry_for_an_applicable_field(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$entry = $this->index()->field( 'byline', 42 );

		$this->assertIsArray( $entry );
		$this->assertSame( 'byline', $entry['id'] );
	}

	/**
	 * THE REFUSAL SIDE OF THE SAME QUESTION, and the one the write path depends on. A
	 * field declared by a group that does not apply to this post is not addressable
	 * here, and `null` is how Tasks 4 and 5 learn to refuse rather than to write a meta
	 * row Meta Box will never render.
	 */
	public function test_field_answers_null_for_a_field_of_a_non_applicable_group(): void {
		$this->givenPost( 42, 'post' );
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

		$this->assertNull( $this->index()->field( 'subtitle', 42 ) );
	}

	public function test_field_answers_null_for_an_identifier_no_group_declares(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$this->assertNull( $this->index()->field( 'nothing-declares-this', 42 ) );
	}

	// -------------------------------------------------------- degraded input

	/**
	 * A DEFINITION WITH NO READABLE `id` IS NOT ADDRESSABLE AND IS DROPPED. It cannot
	 * be read, written or deleted, because every one of those addresses the meta key;
	 * an entry keyed on the empty string would collide with every other unkeyed entry
	 * and would be offered to an operator as a field they could name.
	 */
	public function test_a_definition_carrying_no_usable_identifier_is_dropped(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [
								$this->field( '', 'No key at all' ),
								[
									'name' => 'No id member',
									'type' => 'text',
								],
								[
									'id'   => [ 'not', 'scalar' ],
									'name' => 'Unreadable id',
								],
								$this->field( 'kept', 'Kept' ),
							],
						]
					),
				]
			)
		);

		$this->assertSame( [ 'kept' ], array_keys( $this->index()->fieldsForPost( 42 ) ) );
	}

	/**
	 * A POST WHOSE TYPE CANNOT BE READ MATCHES ONLY THE UNRESTRICTED GROUPS, which is
	 * the conservative side of the requirement's "only". A restricted group cannot be
	 * shown to match a post type nobody could read, so it is excluded; the alternative
	 * — treating an unreadable type as matching everything — would offer a page-only
	 * field on a post and is exactly the failure the word "only" names.
	 */
	public function test_a_post_whose_type_cannot_be_read_matches_only_unrestricted_groups(): void {
		$this->posts[42] = new stdClass();
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
						'group-everywhere',
						[
							'title'  => 'Everywhere',
							'fields' => [ $this->field( 'note', 'Note' ) ],
						]
					),
				]
			)
		);

		$this->assertSame( [ 'note' ], array_keys( $this->index()->fieldsForPost( 42 ) ) );
	}

	public function test_a_post_that_does_not_exist_matches_only_unrestricted_groups(): void {
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

		$this->assertSame( [], $this->index()->fieldsForPost( 999 ) );
	}

	// ------------------------------------------------- the degradation channel

	/**
	 * THE APPLICABLE GROUPS ARE REACHABLE ON THEIR OWN, because the field map alone
	 * cannot say what was lost. A group whose whole field list was mangled contributes
	 * no entry, so an operation reading only the map would report a shorter field list
	 * as complete — the silence the notices channel exists to prevent.
	 */
	public function test_the_applicable_groups_carry_the_readable_flag_of_a_degraded_group(): void {
		$this->givenPost( 42, 'post' );
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

		$groups = $this->index()->applicableGroups( 42 );

		$this->assertCount( 1, $groups, 'The page-only group is not applicable and must not be counted.' );
		$this->assertSame( 'group-broken', $groups[0]['id'] );
		$this->assertFalse( $groups[0]['readable'] );
	}

	public function test_the_applicable_groups_of_a_healthy_site_are_all_readable(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$groups = $this->index()->applicableGroups( 42 );

		$this->assertCount( 1, $groups );
		$this->assertTrue( $groups[0]['readable'] );
	}

	/**
	 * THE FIELD MAP IS THE FLATTENING OF THOSE SAME GROUPS, so an operation can count
	 * the degraded ones and list the fields from one registry pass rather than two.
	 */
	public function test_the_field_map_is_the_flattening_of_the_applicable_groups(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'group-post',
						[
							'title'  => 'Post settings',
							'fields' => [ $this->field( 'byline', 'Byline' ) ],
						]
					),
				]
			)
		);

		$index = $this->index();

		$this->assertSame(
			$index->fieldsForPost( 42 ),
			$index->fieldsInGroups( $index->applicableGroups( 42 ) )
		);
	}

	// ------------------------------------------------------------ containment

	/**
	 * A SITE WITHOUT META BOX ANSWERS AN EMPTY INDEX RATHER THAN FATALLING. The
	 * operations refuse on presence before they reach here, so this is not the answer
	 * an operator sees; it is what makes the index safe to construct unconditionally,
	 * which is how the module registers on a site with no Meta Box at all.
	 */
	public function test_a_site_without_metabox_yields_an_empty_index(): void {
		$this->givenPost( 42, 'post' );

		$this->assertSame( [], $this->index()->fieldsForPost( 42 ) );
		$this->assertNull( $this->index()->field( 'byline', 42 ) );
	}

	/**
	 * THE OBJECT TYPE IS ASKED FOR EXPLICITLY, and the argument is the assertion. Meta
	 * Box's registry answers `get_by()` against whatever it is given, so an index that
	 * omitted the object type — or named the wrong one — would answer with real groups
	 * from the wrong storage, which no assertion on the returned fields can catch on a
	 * site whose groups are all post groups anyway.
	 */
	public function test_the_registry_is_asked_for_the_meta_box_registry_only(): void {
		$this->givenPost( 42, 'post' );
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$this->index()->fieldsForPost( 42 );

		$this->assertSame( [ 'meta_box' ], $this->metaboxCallArguments( 'registry' ) );
	}
}
