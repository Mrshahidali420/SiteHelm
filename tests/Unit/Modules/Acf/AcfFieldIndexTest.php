<?php
/**
 * Tests for AcfFieldIndex, the applicable-field index for one post.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The index every ACF operation that names a post is built on.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason AcfGroupListTest records:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and neither can be removed from a PHP process once installed.
 *
 * NULL AND [] ARE DIFFERENT ANSWERS, and holding them apart is the whole reason
 * this class returns what it returns. `null` means the applicable groups could not
 * be read at all; an index whose `fields` list is empty means they were read and
 * this post has no custom fields. A caller that coalesced the first into the
 * second would tell an operator a post carries no fields when nothing was
 * consulted, and an operator acts on that by creating fields that already exist.
 *
 * NOTHING IS SILENTLY LOST EITHER. A group whose field definitions could not be
 * read is skipped rather than failing the whole index — one unreadable group must
 * not blank the others — but its key comes back in `skippedGroups` so the
 * operation above can warn. A skip with no second channel is the same lie as
 * coalescing null, one group at a time.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldIndexTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Required by the shared double's contract. Nothing here calls a capability
	 * check; PHP 8.1 has no trait properties that would not collide, so the
	 * requirement is declared rather than inherited.
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
	 * The index, wired the way the module wires it.
	 *
	 * @return AcfFieldIndex The index under test.
	 */
	private function index(): AcfFieldIndex {
		$presence = new AcfPresence();

		return new AcfFieldIndex( new AcfApi( $presence ) );
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
	 * @param string               $key       The field key.
	 * @param string               $name      The field name.
	 * @param array<string, mixed> $overrides Members to add or replace.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function field( string $key, string $name, array $overrides = [] ): array {
		return array_merge(
			[
				'key'      => $key,
				'name'     => $name,
				'label'    => ucfirst( $name ),
				'type'     => 'text',
				'required' => 0,
			],
			$overrides
		);
	}

	// ---------------------------------------------------------------- happy path

	/**
	 * The order is the order ACF answered in, group by group and field by field.
	 *
	 * A caller reads this list to choose a field to write, so an order that varied
	 * between two calls on an unchanged site would make a plan built from the first
	 * call describe something else by the second.
	 *
	 * THE POST ID MUST REACH ACF. It is asserted here rather than inferred, because
	 * dropping it turns the question "which fields apply to THIS post" into "which
	 * fields does this site define" — an answer that is larger, plausible, and
	 * wrong, and one nothing downstream would notice.
	 */
	public function test_two_applicable_groups_yield_their_fields_in_group_order(): void {
		$this->installAcf(
			[
				$this->group( 'group_page', 'Page settings' ),
				$this->group( 'group_seo', 'SEO' ),
			],
			[
				'group_page' => [
					$this->field( 'field_subtitle', 'subtitle' ),
					$this->field( 'field_hero', 'hero', [ 'type' => 'image' ] ),
				],
				'group_seo'  => [
					$this->field( 'field_title', 'seo_title' ),
					$this->field( 'field_desc', 'seo_description', [ 'required' => 1 ] ),
				],
			]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'fields', 'skippedGroups' ], array_keys( $index ) );
		$this->assertSame( [], $index['skippedGroups'] );
		$this->assertCount( 4, $index['fields'] );

		$this->assertSame(
			[ 'field_subtitle', 'field_hero', 'field_title', 'field_desc' ],
			array_column( $index['fields'], 'key' )
		);

		$entry = $index['fields'][0];

		$this->assertSame(
			[ 'key', 'name', 'label', 'type', 'required', 'groupKey', 'groupTitle', 'definition' ],
			array_keys( $entry )
		);
		$this->assertSame( 'subtitle', $entry['name'] );
		$this->assertSame( 'Subtitle', $entry['label'] );
		$this->assertSame( 'text', $entry['type'] );
		$this->assertFalse( $entry['required'] );
		$this->assertSame( 'group_page', $entry['groupKey'] );
		$this->assertSame( 'Page settings', $entry['groupTitle'] );

		// ACF marks a field required with the integer 1; the index reports a
		// boolean, because a client branching on `1 === $required` and a client
		// branching on `true === $required` must not get different answers.
		$this->assertTrue( $index['fields'][3]['required'] );

		// The definition is carried whole and unprojected, because the sub-fields
		// of a repeater live inside it and Task 4's write validation reads them
		// from there.
		$this->assertSame( 'image', $index['fields'][1]['definition']['type'] );

		$this->assertSame(
			[ [ 'post_id' => 42 ] ],
			$this->acfCallArguments( 'groups' ),
			'ACF does the location matching, so the post id has to reach it.'
		);
	}

	// ------------------------------------------------- null and [] are different

	/**
	 * THE DECISION 5 GUARD, at the index level. `acf/load_field_groups` is a public
	 * filter, so a site really can answer with something that is not a list of
	 * groups; AcfApi turns that into null and the index must pass it through. An
	 * empty index here would report a post as carrying no custom fields when
	 * nothing was read at all.
	 */
	public function test_an_unreadable_group_list_answers_null_and_not_an_empty_index(): void {
		$this->installAcf( 'not a list of groups' );

		$this->assertNull( $this->index()->forPost( 42 ) );
	}

	/**
	 * The mirror, and the reason the test above can fail. A post no field group
	 * applies to is an ordinary post, and it has an index with nothing in it.
	 */
	public function test_a_post_no_group_applies_to_answers_an_empty_index_and_not_null(): void {
		$this->installAcf( [] );

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index, 'A post with no applicable groups was read successfully; that is not a failure.' );
		$this->assertSame( [], $index['fields'] );
		$this->assertSame( [], $index['skippedGroups'] );
	}

	// ------------------------------------------------------------ skipped groups

	/**
	 * ONE UNREADABLE GROUP MUST NOT BLANK THE OTHERS, AND MUST NOT VANISH EITHER.
	 *
	 * Returning null for the whole index would lose the fields that were read
	 * perfectly well; dropping the group silently would report a shorter field list
	 * as though it were complete, and an operator comparing it against the editor
	 * would conclude the fields had been deleted. The key comes back so the
	 * operation above can say which group is missing.
	 */
	public function test_a_group_whose_fields_cannot_be_read_is_skipped_and_reported(): void {
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

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'field_subtitle' ], array_column( $index['fields'], 'key' ) );
		$this->assertSame( [ 'group_broken' ], $index['skippedGroups'] );
	}

	/**
	 * A group that is not an array cannot even be asked for its fields — AcfApi
	 * takes an array — so it is skipped like an unreadable one. Its key is
	 * unknowable, and the empty string is how the index says so; the operation
	 * above counts it rather than naming it.
	 */
	public function test_a_group_that_is_not_an_array_is_skipped_with_no_name(): void {
		$this->installAcf(
			[
				'not a group',
				$this->group( 'group_page', 'Page settings' ),
			],
			[ 'group_page' => [ $this->field( 'field_subtitle', 'subtitle' ) ] ]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'field_subtitle' ], array_column( $index['fields'], 'key' ) );
		$this->assertSame( [ '' ], $index['skippedGroups'] );
	}

	// ------------------------------------------------------------------- dedup

	/**
	 * FIRST SEEN WINS, and the losing entry is not merged into the winner.
	 *
	 * ACF permits the same field key in two groups that both apply to one post, and
	 * `get_field()` resolves a key to exactly one definition. Reporting the key
	 * twice would offer an operator a choice ACF does not have; taking the last
	 * seen would make the index disagree with the group listing about which group
	 * a field belongs to.
	 */
	public function test_a_key_defined_in_two_applicable_groups_appears_once_first_seen_winning(): void {
		$this->installAcf(
			[
				$this->group( 'group_page', 'Page settings' ),
				$this->group( 'group_seo', 'SEO' ),
			],
			[
				'group_page' => [ $this->field( 'field_title', 'page_title' ) ],
				'group_seo'  => [ $this->field( 'field_title', 'seo_title' ) ],
			]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertCount( 1, $index['fields'] );
		$this->assertSame( 'page_title', $index['fields'][0]['name'] );
		$this->assertSame( 'group_page', $index['fields'][0]['groupKey'] );
	}

	// -------------------------------------------------------- unusable entries

	/**
	 * A field with no name is not addressable. `get_field()` and `update_field()`
	 * both need a key, and the response reports the name beside it; an entry
	 * carrying neither would be a row an operator cannot act on and a write cannot
	 * target.
	 */
	public function test_an_entry_with_no_name_is_dropped(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[
				'group_page' => [
					[
						'key'   => 'field_nameless',
						'label' => 'Nameless',
						'type'  => 'text',
					],
					$this->field( 'field_subtitle', 'subtitle' ),
				],
			]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'field_subtitle' ], array_column( $index['fields'], 'key' ) );
	}

	/**
	 * `acf/load_field` is public, so a key really can arrive as something that is
	 * not a string. It is DROPPED and never cast: `(string)` on an array is a fatal
	 * in the middle of a read, and a key coerced to `'Array'` would be a row every
	 * later operation would look up and never find.
	 */
	public function test_an_entry_whose_key_is_not_scalar_is_dropped(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[
				'group_page' => [
					[
						'key'  => [ 'field_broken' ],
						'name' => 'broken',
						'type' => 'text',
					],
					$this->field( 'field_subtitle', 'subtitle' ),
				],
			]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'field_subtitle' ], array_column( $index['fields'], 'key' ) );
	}

	/**
	 * A definition that is not an array at all is dropped rather than crashed on,
	 * for the reason the shape guards exist everywhere else in this module.
	 */
	public function test_a_definition_that_is_not_an_array_is_dropped(): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[
				'group_page' => [
					'not a field definition',
					$this->field( 'field_subtitle', 'subtitle' ),
				],
			]
		);

		$index = $this->index()->forPost( 42 );

		$this->assertNotNull( $index );
		$this->assertSame( [ 'field_subtitle' ], array_column( $index['fields'], 'key' ) );
	}

	// ------------------------------------------------------------------- find()

	/**
	 * KEY IS TRIED BEFORE NAME, and the fixture is built so that only that order
	 * can produce this answer: one field's NAME is another field's KEY. A key is
	 * assigned by ACF and unambiguous; a name is administrator-chosen text. Search
	 * by name first and a caller asking for `field_alias` — a real key — is handed
	 * the wrong field's definition, and a write built on it lands on the wrong
	 * field with no error anywhere.
	 */
	public function test_find_matches_a_key_before_it_matches_a_name(): void {
		$index = [
			[
				'key'  => 'field_wanted',
				'name' => 'field_alias',
			],
			[
				'key'  => 'field_alias',
				'name' => 'decoy',
			],
		];

		$found = $this->index()->find( $index, 'field_alias' );

		$this->assertNotNull( $found );
		$this->assertSame( 'decoy', $found['name'], 'The key match must win over the earlier name match.' );
	}

	public function test_find_matches_by_name_when_no_key_matches(): void {
		$index = [
			[
				'key'  => 'field_subtitle',
				'name' => 'subtitle',
			],
		];

		$found = $this->index()->find( $index, 'subtitle' );

		$this->assertNotNull( $found );
		$this->assertSame( 'field_subtitle', $found['key'] );
	}

	/**
	 * An unknown identifier answers null, so the operation above can refuse by
	 * name instead of writing to a field nobody asked for.
	 */
	public function test_find_answers_null_for_an_identifier_no_entry_carries(): void {
		$index = [
			[
				'key'  => 'field_subtitle',
				'name' => 'subtitle',
			],
		];

		$this->assertNull( $this->index()->find( $index, 'field_missing' ) );
		$this->assertNull( $this->index()->find( [], 'subtitle' ) );
	}
}
