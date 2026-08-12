<?php
/**
 * Tests for MetaboxApi, the module's one wrapper around Meta Box's functions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The wrapper's two jobs: ask Meta Box the right question, and never guess an
 * answer.
 *
 * THE THREE TRAPS THIS FILE EXISTS TO HOLD (spec §5), one test each at least:
 *
 * 1. `rwmb_set_meta()` RETURNS void, so writeValue() must not claim success. The
 *    dropped-write test below writes a field Meta Box resolves to nothing and
 *    asserts the wrapper says nothing about it — the caller finds out by reading
 *    the value back, and there is no return value that could have told it.
 *
 * 2. `rwmb_meta()` CANNOT TELL ABSENT FROM EMPTY, so hasStoredRow() must answer
 *    from `metadata_exists()`. Tested in BOTH directions on purpose: a field
 *    storing the empty string with a row present must answer true, and a field
 *    with no row must answer false. Either direction alone passes for an
 *    implementation comparing the value against `''` — the first passes it by
 *    accident only if the value is non-empty, and the second passes it always.
 *    Only the pair kills that mutation.
 *
 * 3. A FIELD'S `id` IS THE META KEY, so every call is asserted on its recorded
 *    arguments and not merely on its count. A wrapper addressing a field's `name`
 *    reads and writes nothing at all and raises no error doing it.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. Installing Meta Box defines `RWMB_VER` and
 * real global functions, neither of which can be removed from a PHP process, so a
 * shared process would leave the absent-plugin cases asserting against a site that
 * has Meta Box installed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxApiTest extends TestCase {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Unused by the wrapper, which asks no capability question, but required by the
	 * shared trait's contract.
	 */
	private bool $mayEdit = true;

	/**
	 * Every doubled capability question, in the order it was asked.
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
		$this->capabilityChecks = [];
		$this->metaboxCalls     = [];

		$this->stubMetaboxWordPress();
	}

	/**
	 * @return MetaboxApi The wrapper under test, over a real presence gate.
	 */
	private function api(): MetaboxApi {
		return new MetaboxApi( new MetaboxPresence() );
	}

	/**
	 * The two groups every discovery test in this file reads.
	 *
	 * One post-object group carrying two fields and a post-type restriction, and one
	 * term-object group. Two object types, because a filter that ignored its argument
	 * and answered with everything would pass against a fixture holding only one.
	 *
	 * @return array<int, object> The groups.
	 */
	private function fixtureGroups(): array {
		return [
			$this->metaboxGroup(
				'page-details',
				[
					'title'      => 'Page details',
					'post_types' => [ 'page' ],
					'fields'     => [
						[
							'id'   => 'subtitle',
							'name' => 'Subtitle',
							'type' => 'text',
						],
						[
							'id'   => 'reading_time',
							'name' => 'Reading time',
							'type' => 'number',
						],
					],
				]
			),
			$this->metaboxGroup(
				'category-extras',
				[
					'title'  => 'Category extras',
					'fields' => [
						[
							'id'   => 'accent',
							'name' => 'Accent',
							'type' => 'color',
						],
					],
				],
				'term'
			),
		];
	}

	public function test_a_site_without_metabox_reads_no_groups_and_never_asks_for_the_registry(): void {
		// Nothing is installed in this process, so the presence gate is the only thing
		// standing between the wrapper and an undefined function. The call-count
		// assertion is what proves the gate ran FIRST rather than the wrapper having
		// caught something afterwards.
		$this->assertSame( [], $this->api()->fieldGroups() );
		$this->assertSame( [], $this->api()->fieldGroupsForObjectType( 'post' ) );
		$this->assertSame( 0, $this->metaboxCallCount() );
	}

	public function test_a_site_without_metabox_refuses_every_value_call_without_fataling(): void {
		$api = $this->api();

		$this->assertNull( $api->readValue( 'subtitle', 12 ) );
		$this->assertFalse( $api->hasStoredRow( 'subtitle', 12 ) );

		// void methods: the assertion is that neither reached an undefined function
		// and neither touched the site.
		$api->writeValue( 'subtitle', 12, 'x' );
		$api->deleteValue( 'subtitle', 12 );

		$this->assertSame( 0, $this->metaboxCallCount() );
	}

	/**
	 * A site with the Meta Box functions and no RWMB_VER is not a Meta Box site.
	 *
	 * The functions all exist here, so nothing would have fataled had the wrapper
	 * called them — which is exactly why the recorded call count is the assertion.
	 * It shows the presence gate stopped the read rather than the read having
	 * happened and answered emptily.
	 */
	public function test_a_half_loaded_metabox_is_refused_even_though_the_functions_exist(): void {
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups() ), null );

		$api = $this->api();

		$this->assertSame( [], $api->fieldGroups() );
		$this->assertNull( $api->readValue( 'subtitle', 12 ) );
		$this->assertFalse( $api->hasStoredRow( 'subtitle', 12 ) );
		$api->writeValue( 'subtitle', 12, 'x' );
		$api->deleteValue( 'subtitle', 12 );

		$this->assertSame( 0, $this->metaboxCallCount() );
	}

	public function test_the_group_registry_is_asked_for_the_meta_box_registry_by_name(): void {
		// Meta Box keeps several registries behind one accessor. Asking for the wrong
		// one answers a real object with a real all() listing the wrong things, which
		// no assertion on the returned shape could catch.
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups() ) );

		$this->api()->fieldGroups();

		$this->assertSame( [ 'meta_box' ], $this->metaboxCallArguments( 'registry' ) );
	}

	public function test_every_registered_group_is_normalized_to_the_documented_shape(): void {
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups() ) );

		$groups = $this->api()->fieldGroups();

		$this->assertCount( 2, $groups );
		$this->assertSame(
			[
				'id'         => 'page-details',
				'title'      => 'Page details',
				'objectType' => 'post',
				'postTypes'  => [ 'page' ],
				'fields'     => [
					[
						'id'   => 'subtitle',
						'name' => 'Subtitle',
						'type' => 'text',
					],
					[
						'id'   => 'reading_time',
						'name' => 'Reading time',
						'type' => 'number',
					],
				],
				'readable'   => true,
			],
			$groups[0]
		);

		// THE LIST IS RE-INDEXED. Meta Box's registry keys its answer by group id;
		// a caller iterating this must not inherit an ordering PHP gives an
		// associative array rather than one Meta Box promises.
		$this->assertSame( [ 0, 1 ], array_keys( $groups ) );
		$this->assertSame( 'term', $groups[1]['objectType'] );
	}

	/**
	 * A setting the group does not carry arrives as `false`, not null.
	 *
	 * This is Meta Box's own magic accessor, and it is the reason nothing in the
	 * wrapper uses `??`: a missing `post_types` coalesces to nothing, so `??` would
	 * pass `false` straight through into a member this module has declared to be a
	 * list of strings.
	 */
	public function test_a_group_missing_its_settings_answers_empty_values_rather_than_false(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [ $this->metaboxGroup( 'bare', [ 'fields' => [] ] ) ] )
		);

		$groups = $this->api()->fieldGroups();

		$this->assertSame( '', $groups[0]['title'] );
		$this->assertSame( [], $groups[0]['postTypes'] );
		$this->assertSame( [], $groups[0]['fields'] );
		$this->assertTrue( $groups[0]['readable'] );
	}

	/**
	 * A single post type declared as a string is one restriction, not none.
	 *
	 * Meta Box accepts `'post_types' => 'page'` and normalizes it itself; a group
	 * declared that way on a site whose normalization has not run is still scoped to
	 * one post type. Reading it as `[]` would report it as unscoped, which is the
	 * broader claim.
	 */
	public function test_a_single_post_type_string_is_read_as_a_one_entry_list(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [ $this->metaboxGroup( 'one', [ 'post_types' => 'page' ] ) ] )
		);

		$this->assertSame( [ 'page' ], $this->api()->fieldGroups()[0]['postTypes'] );
	}

	/**
	 * A group whose class predates get_object_type() reads as a post-object group.
	 *
	 * That is Meta Box's own default for the property, and the guard is why an older
	 * group class is a normalized group rather than a fatal.
	 */
	public function test_a_group_without_the_object_type_accessor_defaults_to_post(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [ $this->metaboxGroup( 'old', [], 'term', false ) ] )
		);

		$this->assertSame( 'post', $this->api()->fieldGroups()[0]['objectType'] );
	}

	/**
	 * A group whose field list is not a list degrades rather than disappearing.
	 *
	 * `readable` false is what lets a caller report the group in a notices channel.
	 * A group silently listed with no fields is indistinguishable from a group that
	 * really has none, and the two need different answers.
	 */
	public function test_an_unreadable_field_list_marks_the_group_unreadable(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [ $this->metaboxGroup( 'broken', [ 'fields' => 'not a list' ] ) ] )
		);

		$group = $this->api()->fieldGroups()[0];

		$this->assertFalse( $group['readable'] );
		$this->assertSame( [], $group['fields'] );
	}

	public function test_one_unreadable_field_marks_the_group_unreadable_and_keeps_the_rest(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->metaboxGroup(
						'mixed',
						[
							'fields' => [
								[ 'id' => 'subtitle' ],
								'not a field',
							],
						]
					),
				]
			)
		);

		$group = $this->api()->fieldGroups()[0];

		$this->assertFalse(
			$group['readable'],
			'A dropped field must degrade the group; a shorter list looks like a shorter group.'
		);
		$this->assertSame( [ [ 'id' => 'subtitle' ] ], $group['fields'] );
	}

	public function test_a_registry_that_is_not_an_object_reads_as_no_groups(): void {
		$this->installMetabox( 'not a registry' );

		$this->assertSame( [], $this->api()->fieldGroups() );
	}

	/**
	 * A registry without all() answers no groups instead of fataling.
	 *
	 * The presence gate proves `rwmb_get_registry` is DEFINED, which is a claim about
	 * the plugin and not about the object it hands back. A fork, a filtered registry
	 * class, or a Meta Box below this module's floor can all satisfy the gate and
	 * answer with an object missing this method.
	 */
	public function test_a_registry_without_the_listing_method_reads_as_no_groups(): void {
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups(), false ) );

		$this->assertSame( [], $this->api()->fieldGroups() );
		$this->assertSame( 1, $this->metaboxCallCount( 'registry' ) );
	}

	public function test_the_object_type_filter_is_delegated_to_the_registry(): void {
		// The filter is Meta Box's own rather than a comparison made in the wrapper,
		// because the object type lives in a protected property no caller can read as
		// a member — and a reimplementation here would disagree with the registry the
		// moment Meta Box changed how it resolves one.
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups() ) );

		$groups = $this->api()->fieldGroupsForObjectType( 'term' );

		$this->assertCount( 1, $groups );
		$this->assertSame( 'category-extras', $groups[0]['id'] );
	}

	/**
	 * A registry without get_by() answers nothing, and NEVER falls back to all().
	 *
	 * `all()` is present on this site, so a fallback would succeed — and would answer
	 * a question nobody asked: every group of every object type, handed to a caller
	 * that asked for one type. The caller most likely to be affected is the write.
	 */
	public function test_a_registry_without_the_filter_method_does_not_fall_back_to_the_full_listing(): void {
		$this->installMetabox( $this->metaboxRegistry( $this->fixtureGroups(), true, false ) );

		$this->assertSame( [], $this->api()->fieldGroupsForObjectType( 'post' ) );
	}

	public function test_a_value_read_addresses_the_field_id_against_the_post_object_type(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => 'A subtitle' ]
		);

		$this->assertSame( 'A subtitle', $this->api()->readValue( 'subtitle', 12 ) );

		// THE OBJECT TYPE IS PASSED EXPLICITLY. Omitted, Meta Box reads from whichever
		// object type the current screen has defaulted to — a legitimate value from the
		// wrong storage, which no assertion on the returned value could see.
		$this->assertSame(
			[ [ 'subtitle', [ 'object_type' => 'post' ], 12 ] ],
			$this->metaboxCallArguments( 'value' )
		);
	}

	public function test_a_value_read_on_a_site_without_the_read_function_answers_null(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4', [], false );

		$this->assertNull( $this->api()->readValue( 'subtitle', 12 ) );
	}

	/**
	 * The write flips the argument order Meta Box takes.
	 *
	 * `rwmb_set_meta()` takes the object id first; every method on the wrapper takes
	 * the field id first. A transposed pair here is two ordinary integers to PHP and
	 * nothing else would catch it.
	 */
	public function test_a_write_passes_the_post_id_first_and_the_field_id_second(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$this->api()->writeValue( 'subtitle', 12, 'A subtitle' );

		$this->assertSame(
			[ [ 12, 'subtitle', 'A subtitle', [ 'object_type' => 'post' ] ] ],
			$this->metaboxCallArguments( 'write' )
		);
	}

	/**
	 * A write Meta Box drops is reported by nothing, which is the contract.
	 *
	 * `rwmb_set_meta()` returns void: on a field whose definition it cannot resolve it
	 * stores nothing and says nothing. writeValue() therefore returns void as well, so
	 * that no caller can mistake silence for success — the re-read is the only thing
	 * that separates this site from an ordinary one.
	 */
	public function test_a_dropped_write_leaves_the_value_unchanged_and_the_wrapper_silent(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => 'Before' ],
			true,
			true,
			[],
			true,
			true,
			[ 'subtitle' ]
		);

		$api = $this->api();

		$api->writeValue( 'subtitle', 12, 'After' );

		// THE SILENCE IS NOT ASSERTED HERE, BECAUSE IT CANNOT BE. writeValue() is
		// declared `void`, so a call to it evaluates to null whatever it does and an
		// assertion on that answer passes for every implementation there is. What proves
		// the contract is the return TYPE, which the runtime enforces, and the re-read
		// below, which is the only thing that can see a dropped write.
		$this->assertSame( 1, $this->metaboxCallCount( 'write' ) );
		$this->assertSame(
			'Before',
			$api->readValue( 'subtitle', 12 ),
			'The wrapper must not report a dropped write; only the re-read can see it.'
		);
	}

	public function test_a_write_on_a_site_without_the_write_function_changes_nothing(): void {
		// `rwmb_set_meta()` arrives only at Meta Box 5.3.0, so a site below this
		// module's floor genuinely has the read and not the write. Unprobed, that is a
		// fatal in the middle of a change an operator has already approved.
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4', [], true, false );

		$this->api()->writeValue( 'subtitle', 12, 'A subtitle' );

		$this->assertSame( 0, $this->metaboxCallCount( 'write' ) );
	}

	/**
	 * THE FIRST HALF OF THE TRAP. A stored empty string HAS a row.
	 *
	 * `rwmb_meta()` answers `''` here, exactly as it does for a field with no row at
	 * all, so an implementation comparing the value against `''` answers false — and
	 * a snapshot built on that records "absent" for a field an operator deliberately
	 * emptied. The restore then deletes the row instead of writing `''` back.
	 */
	public function test_a_stored_empty_string_is_a_stored_row(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => '' ],
			true,
			true,
			[ '12:subtitle' ]
		);

		$api = $this->api();

		$this->assertSame( '', $api->readValue( 'subtitle', 12 ) );
		$this->assertTrue(
			$api->hasStoredRow( 'subtitle', 12 ),
			'A field storing the empty string has a row; only metadata_exists() can say so.'
		);
	}

	/**
	 * THE SECOND HALF OF THE TRAP. A field with no row answers false.
	 *
	 * Paired with the test above deliberately: either direction alone passes for an
	 * implementation comparing the value against `''`. Only the pair kills it.
	 */
	public function test_a_field_with_no_row_reads_the_same_empty_value_and_answers_false(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		$api = $this->api();

		$this->assertSame( '', $api->readValue( 'subtitle', 12 ) );
		$this->assertFalse( $api->hasStoredRow( 'subtitle', 12 ) );
	}

	public function test_the_row_probe_asks_core_post_meta_and_never_reads_the_value(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4', [], true, true, [ '12:subtitle' ] );

		$this->api()->hasStoredRow( 'subtitle', 12 );

		$this->assertSame( [ [ 'post', 12, 'subtitle' ] ], $this->metaboxCallArguments( 'row' ) );
		$this->assertSame(
			0,
			$this->metaboxCallCount( 'value' ),
			'Presence is a question about the meta row, never about the value.'
		);
	}

	public function test_the_row_probe_answers_false_on_a_process_without_the_core_function(): void {
		// The wrapper is loadable in a process with no WordPress at all — every unit
		// suite in this repository is such a process — so the probe is what makes this
		// a refusal rather than a fatal.
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.9.4', [], true, true, [ '12:subtitle' ], false );

		$this->assertFalse( $this->api()->hasStoredRow( 'subtitle', 12 ) );
	}

	/**
	 * The delete addresses the same core post meta row the probe asks about.
	 *
	 * Meta Box's own `rwmb_delete_meta()` exists only from 5.14.0, far above any
	 * defensible floor, and the row this must remove is the row hasStoredRow() reads
	 * — so both address the meta key through core.
	 */
	public function test_a_delete_removes_the_core_post_meta_row_under_the_field_id(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => 'A subtitle' ],
			true,
			true,
			[ '12:subtitle' ]
		);

		$api = $this->api();

		$api->deleteValue( 'subtitle', 12 );

		$this->assertSame( [ [ 12, 'subtitle' ] ], $this->metaboxCallArguments( 'delete' ) );
		$this->assertFalse( $api->hasStoredRow( 'subtitle', 12 ) );
	}

	public function test_a_delete_on_a_process_without_the_core_function_changes_nothing(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[],
			true,
			true,
			[ '12:subtitle' ],
			true,
			false
		);

		$this->api()->deleteValue( 'subtitle', 12 );

		$this->assertSame( 0, $this->metaboxCallCount( 'delete' ) );
		$this->assertTrue( $this->api()->hasStoredRow( 'subtitle', 12 ) );
	}

	/**
	 * The raw read answers the rows the site holds, not Meta Box's formatted answer.
	 *
	 * `rwmb_meta()` is the FORMATTED accessor: for an attachment field it answers an
	 * info array per id, carrying a server path the site never stored. A snapshot
	 * built on that records something that cannot be written back, so the recovery
	 * path reads the postmeta rows through core instead — all of them, which is what
	 * `$single = false` asks for and the only way a multi-row field is legible.
	 */
	public function test_a_raw_read_answers_every_stored_row_and_never_the_formatted_value(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'hero' => [ 9, 10 ] ],
			true,
			true,
			[ '12:hero' ],
			true,
			true,
			[],
			[ 'hero' ]
		);

		$this->assertSame( [ 9, 10 ], $this->api()->readRawRows( 'hero', 12 ) );
		$this->assertSame( [ [ 12, 'hero', false ] ], $this->metaboxCallArguments( 'rawRead' ) );
		$this->assertSame(
			0,
			$this->metaboxCallCount( 'value' ),
			'The raw read must not go near the formatted accessor.'
		);
	}

	public function test_a_raw_read_on_a_process_without_the_core_function_answers_no_rows(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => 'A subtitle' ],
			true,
			true,
			[ '12:subtitle' ],
			true,
			true,
			[],
			[],
			false
		);

		$this->assertSame( [], $this->api()->readRawRows( 'subtitle', 12 ) );
	}

	/**
	 * The raw write replaces the key's rows rather than adding to them.
	 *
	 * `add_post_meta()` appends, so writing three rows onto a key that already holds
	 * two leaves five. A restore is a replacement, not an addition, and the delete is
	 * what makes it one — which is why the two core functions are gated together.
	 */
	public function test_a_raw_write_clears_the_key_and_then_adds_one_row_per_element(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'hero' => [ 9 ] ],
			true,
			true,
			[ '12:hero' ],
			true,
			true,
			[],
			[ 'hero' ]
		);

		$api = $this->api();

		$api->writeRawRows( 'hero', 12, [ 4, 5 ] );

		$this->assertSame( [ [ 12, 'hero' ] ], $this->metaboxCallArguments( 'delete' ) );
		$this->assertSame(
			[ [ 12, 'hero', 4, false ], [ 12, 'hero', 5, false ] ],
			$this->metaboxCallArguments( 'rawWrite' )
		);
		$this->assertSame( [ 4, 5 ], $api->readRawRows( 'hero', 12 ) );
		$this->assertSame(
			0,
			$this->metaboxCallCount( 'write' ),
			'The recovery path restores through core and never through the plugin accessor.'
		);
	}

	public function test_a_raw_write_on_a_process_without_the_core_functions_changes_nothing(): void {
		$this->installMetabox(
			$this->metaboxRegistry( [] ),
			'5.9.4',
			[ 'subtitle' => 'Before' ],
			true,
			true,
			[ '12:subtitle' ],
			true,
			true,
			[],
			[],
			false
		);

		$this->api()->writeRawRows( 'subtitle', 12, [ 'After' ] );

		// THE DELETE MUST NOT HAPPEN EITHER. A guard that ran the delete and then
		// found it could not add the rows back would turn an unavailable restore into
		// a destroyed value.
		$this->assertSame( 0, $this->metaboxCallCount( 'delete' ) );
		$this->assertTrue( $this->api()->hasStoredRow( 'subtitle', 12 ) );
	}
}
