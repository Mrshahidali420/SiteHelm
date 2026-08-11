<?php
/**
 * Tests for AcfApi, the module's one wrapper around ACF's read functions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The wrapper's two jobs: ask ACF the right question, and never guess an answer.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM AcfGroupListTest. The operation exercises
 * the wrapper's default path and nothing else, so two of the wrapper's branches
 * shipped with no test at all: the post-id filter, and the separate probe for
 * acf_get_fields(). Both are the kind of defect that does not announce itself —
 * a wrong filter key makes ACF answer with every group or with none, which reads
 * downstream as a site with an unexpected configuration rather than as a bug, and
 * an unprobed missing function is a fatal rather than a refusal. Task 2 is the
 * first caller to pass a post id, so the shape is pinned here before it is used.
 *
 * NULL AND [] ARE THE WRAPPER'S WHOLE CONTRACT. `null` is "I could not read
 * this"; `[]` is "I read it and it holds nothing". Every assertion below that
 * uses assertNull or assertSame([]) is holding those apart, because a wrapper
 * that coalesced them would make the distinction unrecoverable one layer up.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfApiTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Unused by the wrapper, which asks no capability question, but required by
	 * the shared trait's contract.
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
	 * @return AcfApi The wrapper under test, over a real presence gate.
	 */
	private function api(): AcfApi {
		return new AcfApi( new AcfPresence() );
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

	// -------------------------------------------------------------- ACF is absent

	public function test_groups_answers_null_on_a_site_without_acf(): void {
		$this->assertNull( $this->api()->groups() );
	}

	public function test_fields_answers_null_on_a_site_without_acf(): void {
		$this->assertNull( $this->api()->fields( $this->group( 'group_page' ) ) );
	}

	/**
	 * The presence gate refuses before either function is reached, which is what
	 * makes an absent plugin a refusal rather than a fatal.
	 */
	public function test_a_half_loaded_acf_is_not_read_from(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], null );

		$this->assertNull( $this->api()->groups() );
		$this->assertNull( $this->api()->fields( $this->group( 'group_page' ) ) );
		$this->assertSame( 0, $this->acfCallCount() );
	}

	// ------------------------------------------------------------- the groups read

	public function test_groups_answers_what_acf_answered(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ] );

		$groups = $this->api()->groups();

		$this->assertIsArray( $groups );
		$this->assertSame( 'group_page', $groups[0]['key'] );
	}

	/**
	 * A site that registers no field groups is a normal site, and the empty list
	 * it answers with must not become the null that means "unreadable".
	 */
	public function test_groups_answers_an_empty_list_rather_than_null_when_acf_has_none(): void {
		$this->installAcf( [] );

		$this->assertSame( [], $this->api()->groups() );
	}

	/**
	 * `acf/load_field_groups` is a public filter, so a non-array answer is an
	 * ordinary outcome rather than a theoretical one.
	 */
	public function test_groups_answers_null_when_acf_answers_something_that_is_not_an_array(): void {
		$this->installAcf( 'not a list of groups' );

		$this->assertNull( $this->api()->groups() );
	}

	/**
	 * No id means NO ARGUMENT, which is a different call from an empty filter.
	 * The double is variadic so the two stay distinguishable; a call with no
	 * argument records null, and `acf_get_field_groups( [] )` would record `[]`.
	 * ACF treats a filter array as a location query, so handing it an empty one is
	 * not obviously harmless and is not what this branch is meant to do.
	 */
	public function test_groups_asks_for_every_group_by_passing_no_argument_at_all(): void {
		$this->installAcf( [] );

		$this->api()->groups();

		$this->assertSame( [ null ], $this->acfCallArguments( 'groups' ) );
	}

	/**
	 * THE ARGUMENT SHAPE TASK 2 DEPENDS ON. ACF does the location matching itself
	 * when handed `post_id`, and a wrong key here would not raise anything: ACF
	 * would ignore the filter and answer with every group on the site, which reads
	 * as a configuration surprise rather than as a bug.
	 */
	public function test_groups_narrows_to_a_post_by_passing_acfs_post_id_filter(): void {
		$this->installAcf( [] );

		$this->api()->groups( 41 );

		$this->assertSame( [ [ 'post_id' => 41 ] ], $this->acfCallArguments( 'groups' ) );
	}

	// ------------------------------------------------------------- the fields read

	public function test_fields_answers_the_groups_definitions(): void {
		$this->installAcf(
			[ $this->group( 'group_page' ) ],
			[
				'group_page' => [
					[
						'key'  => 'field_subtitle',
						'name' => 'subtitle',
					],
				],
			]
		);

		$fields = $this->api()->fields( $this->group( 'group_page' ) );

		$this->assertIsArray( $fields );
		$this->assertSame( 'field_subtitle', $fields[0]['key'] );
	}

	public function test_fields_answers_an_empty_list_for_a_group_with_no_fields(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ] );

		$this->assertSame( [], $this->api()->fields( $this->group( 'group_page' ) ) );
	}

	public function test_fields_answers_null_when_acf_answers_something_that_is_not_an_array(): void {
		$this->installAcf(
			[ $this->group( 'group_page' ) ],
			[ 'group_page' => 'not a list of fields' ]
		);

		$this->assertNull( $this->api()->fields( $this->group( 'group_page' ) ) );
	}

	/**
	 * THE SEPARATE PROBE, AND THE ONLY TEST THAT CAN SEE IT. The presence gate
	 * proves acf_get_field_groups() exists, which is a claim about the plugin and
	 * not about this symbol; a fork or a disturbed load order satisfies the gate
	 * without defining acf_get_fields(). Delete the function_exists() check from
	 * AcfApi::fields() and this test stops being a refusal and becomes a fatal.
	 */
	public function test_fields_answers_null_when_acf_does_not_define_the_fields_function(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], '6.2.7', false );

		$this->assertFalse( function_exists( 'acf_get_fields' ), 'The double must not have installed the function this test is about.' );
		$this->assertNull( $this->api()->fields( $this->group( 'group_page' ) ) );
		$this->assertSame( 0, $this->acfCallCount( 'fields' ) );
	}

	// -------------------------------------------------------------- the value read

	/**
	 * WHY THE VALUE READ IS TESTED HERE AND NOT ONLY THROUGH acf-field-get. The
	 * operation gates on presence before it reaches the wrapper, so neither of the
	 * wrapper's own refusals is reachable from that direction — they are live code
	 * with no caller able to exercise them, which is exactly the shape that reads
	 * as dead code to whoever runs the coverage pass later and has none of this
	 * context. The wrapper's contract is its own, and it is asserted directly.
	 */
	public function test_read_value_answers_null_on_a_site_without_acf(): void {
		$this->assertNull( $this->api()->readValue( 'field_subtitle', 42, true ) );
	}

	public function test_read_value_is_not_attempted_on_a_half_loaded_acf(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], null, true, [ 'field_subtitle' => 'A subtitle' ] );

		$this->assertNull( $this->api()->readValue( 'field_subtitle', 42, true ) );
		$this->assertSame( 0, $this->acfCallCount( 'value' ), 'The presence gate refuses before the function is reached.' );
	}

	/**
	 * THE SECOND SEPARATE PROBE, AND THE ONLY TEST THAT CAN SEE IT. The presence
	 * gate proves acf_get_field_groups() exists, which says nothing about
	 * get_field() — and get_field() is the one ACF symbol with an unqualified,
	 * extremely common name, so a theme defining its own is a real configuration
	 * rather than a hypothetical. Delete the function_exists() check from
	 * AcfApi::readValue() and this stops being a refusal and becomes a fatal in the
	 * middle of a read.
	 */
	public function test_read_value_answers_null_when_acf_does_not_define_the_value_function(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], '6.2.7', true, [], false );

		$this->assertFalse( function_exists( 'get_field' ), 'The double must not have installed the function this test is about.' );
		$this->assertNull( $this->api()->readValue( 'field_subtitle', 42, true ) );
		$this->assertSame( 0, $this->acfCallCount( 'value' ) );
	}

	public function test_read_value_answers_what_acf_answered(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], '6.2.7', true, [ 'field_subtitle' => 'A subtitle' ] );

		$this->assertSame( 'A subtitle', $this->api()->readValue( 'field_subtitle', 42, true ) );
	}

	/**
	 * THE WRAPPER PASSES ITS THREE ARGUMENTS THROUGH AND INVENTS NOTHING. The
	 * formatting flag is the one that matters: it is the caller's decision, and a
	 * wrapper that hardcoded either value would silently overrule the read side or
	 * the write side depending on which it picked.
	 */
	public function test_read_value_passes_the_key_the_post_and_the_formatting_flag_through(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ] );

		$this->api()->readValue( 'field_subtitle', 42, false );

		$this->assertSame( [ [ 'field_subtitle', 42, false ] ], $this->acfCallArguments( 'value' ) );
	}

	/**
	 * A FIELD ACF HOLDS NOTHING FOR ANSWERS null, AND SO DOES A FIELD IT CANNOT
	 * READ. This is the one place the wrapper's null/[] contract does NOT hold, and
	 * it is a property of get_field() rather than a choice made here: ACF answers
	 * null for an empty field and null for an unknown one, and nothing at this
	 * layer can separate them. The index is what tells the operation whether a
	 * field exists, which is why acf-field-get resolves the field FIRST and only
	 * then reads its value — a null from here means "empty", because the field was
	 * already known to apply.
	 */
	public function test_read_value_answers_null_for_a_field_acf_holds_nothing_for(): void {
		$this->installAcf( [ $this->group( 'group_page' ) ], [], '6.2.7', true, [ 'field_subtitle' => 'A subtitle' ] );

		$this->assertNull( $this->api()->readValue( 'field_absent', 42, true ) );
		$this->assertSame( 1, $this->acfCallCount( 'value' ), 'The wrapper asks ACF rather than guessing from a key it does not recognise.' );
	}

	// ------------------------------------------------------------- the stored row

	/**
	 * THE ASYMMETRY THIS SUITE EXISTS TO PIN. `metadata_exists()` asks about a
	 * postmeta ROW, and ACF stores that row under the field's NAME, while every
	 * write below goes by the field's KEY. Both spellings appear in AcfApi and
	 * nowhere else, and passing a key here answers false for every field on a site
	 * that stores them all — which reads downstream as "this field was never set"
	 * and turns a restore into a delete.
	 */
	public function test_has_stored_row_asks_about_the_post_meta_row_under_the_field_name(): void {
		$this->installAcf( [], [], '6.2.7', true, [], true, true, true, [ '42:subtitle' ] );

		$this->assertTrue( $this->api()->hasStoredRow( 'subtitle', 42 ) );
		$this->assertSame( [ [ 'post', 42, 'subtitle' ] ], $this->acfCallArguments( 'row' ) );
	}

	public function test_has_stored_row_is_false_for_a_field_this_post_has_no_row_for(): void {
		$this->installAcf( [], [], '6.2.7', true, [], true, true, true, [ '42:subtitle' ] );

		$this->assertFalse( $this->api()->hasStoredRow( 'subtitle', 41 ) );
	}

	public function test_has_stored_row_is_false_on_a_site_without_acf(): void {
		$this->assertFalse( $this->api()->hasStoredRow( 'subtitle', 42 ) );
	}

	public function test_has_stored_row_is_not_attempted_on_a_half_loaded_acf(): void {
		$this->installAcf( [], [], null, true, [], true, true, true, [ '42:subtitle' ] );

		$this->assertFalse( $this->api()->hasStoredRow( 'subtitle', 42 ) );
		$this->assertSame( 0, $this->acfCallCount( 'row' ), 'The presence gate refuses before the function is reached.' );
	}

	/**
	 * `metadata_exists()` is core WordPress rather than ACF, but the wrapper probes
	 * for it all the same: this file is loadable in a process that has no WordPress
	 * at all, and an unguarded call there is a fatal in the middle of a snapshot.
	 */
	public function test_has_stored_row_is_false_when_the_metadata_function_is_not_defined(): void {
		$this->installAcf( [], [], '6.2.7', true, [], true, true, true, [ '42:subtitle' ], false );

		$this->assertFalse( function_exists( 'metadata_exists' ), 'The double must not have installed the function this test is about.' );
		$this->assertFalse( $this->api()->hasStoredRow( 'subtitle', 42 ) );
	}

	// ------------------------------------------------------------------ the write

	/**
	 * THE WRITE GOES BY KEY AND THE RECORDED ARGUMENT IS THE PROOF (spec Decision
	 * 8). `update_field()` handed a NAME does not fail — it silently writes nothing
	 * when the target has no stored row yet, because it cannot resolve the name
	 * without one. Nothing downstream would notice; the recorded first argument is
	 * the only place that mutation is visible.
	 */
	public function test_write_value_passes_the_key_the_value_and_the_post_through(): void {
		$this->installAcf( [] );

		$this->api()->writeValue( 'field_subtitle', 'A subtitle', 42 );

		$this->assertSame( [ [ 'field_subtitle', 'A subtitle', 42 ] ], $this->acfCallArguments( 'update' ) );
	}

	public function test_write_value_is_not_attempted_on_a_site_without_acf(): void {
		$this->api()->writeValue( 'field_subtitle', 'A subtitle', 42 );

		$this->assertSame( 0, $this->acfCallCount( 'update' ) );
	}

	public function test_write_value_is_not_attempted_on_a_half_loaded_acf(): void {
		$this->installAcf( [], [], null );

		$this->api()->writeValue( 'field_subtitle', 'A subtitle', 42 );

		$this->assertSame( 0, $this->acfCallCount( 'update' ), 'The presence gate refuses before the function is reached.' );
	}

	/**
	 * The third separate probe. A site whose ACF is loaded but whose `update_field`
	 * is missing — a fork, a disturbed load order — must refuse rather than fatal
	 * halfway through a change the operator already approved.
	 */
	public function test_write_value_is_not_attempted_when_acf_does_not_define_the_update_function(): void {
		$this->installAcf( [], [], '6.2.7', true, [], true, false );

		$this->assertFalse( function_exists( 'update_field' ), 'The double must not have installed the function this test is about.' );

		$this->api()->writeValue( 'field_subtitle', 'A subtitle', 42 );

		$this->assertSame( 0, $this->acfCallCount( 'update' ) );
	}

	/**
	 * A null is a value an operator can write — it is how a field is cleared — and
	 * it must reach ACF rather than being read as "nothing to do" by a wrapper
	 * that guessed.
	 */
	public function test_write_value_passes_a_null_through_rather_than_skipping_the_write(): void {
		$this->installAcf( [] );

		$this->api()->writeValue( 'field_subtitle', null, 42 );

		$this->assertSame( [ [ 'field_subtitle', null, 42 ] ], $this->acfCallArguments( 'update' ) );
	}

	// ----------------------------------------------------------------- the delete

	public function test_delete_value_passes_the_key_and_the_post_through(): void {
		$this->installAcf( [] );

		$this->api()->deleteValue( 'field_subtitle', 42 );

		$this->assertSame( [ [ 'field_subtitle', 42 ] ], $this->acfCallArguments( 'delete' ) );
	}

	public function test_delete_value_is_not_attempted_on_a_site_without_acf(): void {
		$this->api()->deleteValue( 'field_subtitle', 42 );

		$this->assertSame( 0, $this->acfCallCount( 'delete' ) );
	}

	public function test_delete_value_is_not_attempted_when_acf_does_not_define_the_delete_function(): void {
		$this->installAcf( [], [], '6.2.7', true, [], true, true, false );

		$this->assertFalse( function_exists( 'delete_field' ), 'The double must not have installed the function this test is about.' );

		$this->api()->deleteValue( 'field_subtitle', 42 );

		$this->assertSame( 0, $this->acfCallCount( 'delete' ) );
	}
}
