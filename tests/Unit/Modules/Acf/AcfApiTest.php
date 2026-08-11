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
}
