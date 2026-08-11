<?php
/**
 * Covers the caller-facing validation every ACF write runs through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfFieldUpdateInput;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Tests\TestCase;

/**
 * The request half of an ACF write.
 *
 * AcfWriteTarget has already established that the site, the caller and the post
 * are usable by the time anything here runs; every refusal in this file is
 * therefore InvalidInput and blames the request, never the site.
 *
 * NO ACF DOUBLE IS INSTALLED AND NO PROCESS IS ISOLATED. The only collaborator is
 * AcfFieldIndex, and the only method reached on it is find(), which walks a list
 * the test hands over and calls nothing. A test that installed ACF here would be
 * asserting against a plugin this class never speaks to.
 *
 * @package SiteHelm
 */
final class AcfFieldUpdateInputTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var AcfFieldUpdateInput
	 */
	private AcfFieldUpdateInput $input;

	/**
	 * Builds the subject over a real index.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->input = new AcfFieldUpdateInput( new AcfFieldIndex( new AcfApi( new AcfPresence() ) ) );
	}

	/**
	 * One index entry, shaped exactly as AcfFieldIndex::forPost() reports it.
	 *
	 * @param string               $key        The field key.
	 * @param string               $name       The field name.
	 * @param string               $type       The field type.
	 * @param array<string, mixed> $definition Extra definition members, merged over the defaults.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( string $key, string $name, string $type = 'text', array $definition = [] ): array {
		return [
			'key'        => $key,
			'name'       => $name,
			'label'      => ucfirst( $name ),
			'type'       => $type,
			'required'   => false,
			'groupKey'   => 'group_1',
			'groupTitle' => 'Details',
			'definition' => array_merge(
				[
					'key'  => $key,
					'name' => $name,
					'type' => $type,
				],
				$definition
			),
		];
	}

	/**
	 * A two-field index: one plain text field and one flexible-content field.
	 *
	 * @return array[] The index's `fields` list.
	 */
	private function index(): array {
		return [
			$this->entry( 'field_sub', 'subtitle' ),
			$this->entry(
				'field_flex',
				'sections',
				'flexible_content',
				[
					'layouts' => [
						'layout_a' => [
							'key'  => 'layout_a',
							'name' => 'hero',
						],
						'layout_b' => [
							'key'  => 'layout_b',
							'name' => 'quote',
						],
					],
				]
			),
		];
	}

	/**
	 * One well-formed request member.
	 *
	 * @param string $field The field name or key.
	 * @param mixed  $value The value to write.
	 *
	 * @return array<string, mixed> The member.
	 */
	private function member( string $field, mixed $value ): array {
		return [
			'field' => $field,
			'value' => $value,
		];
	}

	/**
	 * Runs validate() and hands back the refusal it threw.
	 *
	 * Asserting the exception is PRESENT comes first and separately: a try/catch
	 * whose assertions live in the catch block passes silently when nothing is
	 * thrown, which is this suite's most frequent defect.
	 *
	 * @param array<string, mixed> $input The request.
	 * @param array[]              $index The index to resolve against.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $input, array $index ): OperationException {
		$thrown = null;

		try {
			$this->input->validate( $input, $index );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'The request was accepted where a refusal was required.'
		);

		$this->assertSame(
			ErrorCode::InvalidInput,
			$thrown->errorCode,
			'A request this operation could not use must be blamed on the request.'
		);

		return $thrown;
	}

	// -- The happy path -----------------------------------------------------

	/**
	 * @test
	 */
	public function test_a_request_naming_two_fields_validates_to_one_entry_each(): void {
		$validated = $this->input->validate(
			[
				'fields' => [
					$this->member( 'subtitle', 'A subtitle' ),
					$this->member( 'sections', [] ),
				],
			],
			$this->index()
		);

		$this->assertCount( 2, $validated, 'Every named field must produce exactly one write.' );
		$this->assertSame( 'field_sub', $validated[0]['key'] );
		$this->assertSame( 'field_flex', $validated[1]['key'] );
	}

	/**
	 * @test
	 */
	public function test_a_validated_entry_carries_the_key_the_name_the_type_the_value_and_the_definition(): void {
		$index = $this->index();

		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'subtitle', 'A subtitle' ) ] ],
			$index
		);

		$this->assertSame(
			[ 'key', 'name', 'type', 'value', 'definition' ],
			array_keys( $validated[0] ),
			'The validated shape is what the operation and the snapshot both read.'
		);
		$this->assertSame( 'field_sub', $validated[0]['key'] );
		$this->assertSame( 'subtitle', $validated[0]['name'] );
		$this->assertSame( 'text', $validated[0]['type'] );
		$this->assertSame( 'A subtitle', $validated[0]['value'] );
		$this->assertSame(
			$index[0]['definition'],
			$validated[0]['definition'],
			'The definition is carried whole, so the writer never asks ACF a second time.'
		);
	}

	/**
	 * The key/name split is AcfFieldIndex's rule, and this proves it is not respelled.
	 *
	 * @test
	 */
	public function test_a_field_named_by_key_resolves_to_the_same_entry_as_one_named_by_name(): void {
		$by_key = $this->input->validate(
			[ 'fields' => [ $this->member( 'field_sub', 'x' ) ] ],
			$this->index()
		);

		$by_name = $this->input->validate(
			[ 'fields' => [ $this->member( 'subtitle', 'x' ) ] ],
			$this->index()
		);

		$this->assertSame( $by_key, $by_name, 'A key and its name address one field.' );
	}

	/**
	 * @test
	 */
	public function test_a_null_value_is_carried_through_rather_than_dropped(): void {
		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'subtitle', null ) ] ],
			$this->index()
		);

		$this->assertCount( 1, $validated, 'A null is a value to write, not an absent member.' );
		$this->assertArrayHasKey( 'value', $validated[0] );
		$this->assertNull( $validated[0]['value'] );
	}

	// -- The list itself ----------------------------------------------------

	/**
	 * @test
	 */
	public function test_an_empty_field_list_is_refused(): void {
		$refusal = $this->refusal( [ 'fields' => [] ], $this->index() );

		$this->assertNotSame( '', $refusal->getMessage() );
	}

	/**
	 * @test
	 */
	public function test_a_missing_field_list_is_refused(): void {
		$this->refusal( [], $this->index() );
	}

	/**
	 * @test
	 */
	public function test_a_field_list_that_is_not_a_list_is_refused(): void {
		$this->refusal( [ 'fields' => 'subtitle' ], $this->index() );
	}

	/**
	 * @test
	 */
	public function test_more_fields_than_the_limit_are_refused(): void {
		$this->refusal(
			[ 'fields' => $this->repeated( AcfFieldUpdateInput::MAX_FIELDS + 1 ) ],
			$this->index()
		);
	}

	/**
	 * The limit is a maximum and not a strict one; this is the off-by-one proof.
	 *
	 * @test
	 */
	public function test_exactly_the_limit_is_accepted(): void {
		$index = [];
		$fields = [];

		for ( $position = 0; $position < AcfFieldUpdateInput::MAX_FIELDS; $position++ ) {
			$index[]  = $this->entry( 'field_' . $position, 'name_' . $position );
			$fields[] = $this->member( 'name_' . $position, $position );
		}

		$validated = $this->input->validate( [ 'fields' => $fields ], $index );

		$this->assertCount( AcfFieldUpdateInput::MAX_FIELDS, $validated );
	}

	/**
	 * A list of malformed members, long enough to break whichever limit is asked for.
	 *
	 * @param int $count How many members to produce.
	 *
	 * @return array[] The members.
	 */
	private function repeated( int $count ): array {
		$fields = [];

		for ( $position = 0; $position < $count; $position++ ) {
			$fields[] = $this->member( 'subtitle', $position );
		}

		return $fields;
	}

	// -- One member ---------------------------------------------------------

	/**
	 * @test
	 */
	public function test_a_member_that_is_not_an_object_is_refused(): void {
		$this->refusal( [ 'fields' => [ 'subtitle' ] ], $this->index() );
	}

	/**
	 * @test
	 */
	public function test_a_member_with_no_field_member_is_refused(): void {
		$this->refusal( [ 'fields' => [ [ 'value' => 'x' ] ] ], $this->index() );
	}

	/**
	 * @test
	 */
	public function test_a_member_with_no_value_member_is_refused(): void {
		$this->refusal( [ 'fields' => [ [ 'field' => 'subtitle' ] ] ], $this->index() );
	}

	/**
	 * @test
	 */
	public function test_a_member_carrying_an_extra_key_is_refused(): void {
		$this->refusal(
			[
				'fields' => [
					[
						'field' => 'subtitle',
						'value' => 'x',
						'force' => true,
					],
				],
			],
			$this->index()
		);
	}

	/**
	 * Two members, neither of them `field` — the count rule alone cannot see this.
	 *
	 * @test
	 */
	public function test_a_member_that_swaps_field_for_another_key_is_refused(): void {
		$this->refusal(
			[
				'fields' => [
					[
						'value'  => 'x',
						'append' => true,
					],
				],
			],
			$this->index()
		);
	}

	/**
	 * @test
	 */
	public function test_a_field_identifier_that_is_not_a_string_is_refused(): void {
		$this->refusal( [ 'fields' => [ $this->member( '', 'x' ) ] ], $this->index() );
		$this->refusal( [ 'fields' => [ [ 'field' => 42, 'value' => 'x' ] ] ], $this->index() );
	}

	// -- Resolution ---------------------------------------------------------

	/**
	 * @test
	 */
	public function test_an_unknown_field_is_refused_naming_the_string_the_caller_sent(): void {
		$refusal = $this->refusal(
			[ 'fields' => [ $this->member( 'not_a_field', 'x' ) ] ],
			$this->index()
		);

		$this->assertStringContainsString(
			'not_a_field',
			$refusal->getMessage(),
			'An operator who mistyped a field must be told which string did not match.'
		);
	}

	/**
	 * A refusal names the unmatched string and nothing else about the site.
	 *
	 * @test
	 */
	public function test_a_refusal_for_an_unknown_field_leaks_no_other_field(): void {
		$refusal = $this->refusal(
			[ 'fields' => [ $this->member( 'not_a_field', 'x' ) ] ],
			$this->index()
		);

		$this->assertStringNotContainsString( 'subtitle', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'field_sub', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'sections', $refusal->getMessage() );
	}

	/**
	 * @test
	 */
	public function test_two_members_resolving_to_one_key_are_refused(): void {
		$this->refusal(
			[
				'fields' => [
					$this->member( 'subtitle', 'first' ),
					$this->member( 'subtitle', 'second' ),
				],
			],
			$this->index()
		);
	}

	/**
	 * The dedup is on the RESOLVED key, so the two spellings of one field collide.
	 *
	 * @test
	 */
	public function test_a_field_named_once_by_key_and_once_by_name_is_refused(): void {
		$this->refusal(
			[
				'fields' => [
					$this->member( 'field_sub', 'first' ),
					$this->member( 'subtitle', 'second' ),
				],
			],
			$this->index()
		);
	}

	/**
	 * A refusal never carries a value, and both spellings here are values.
	 *
	 * @test
	 */
	public function test_a_duplicate_refusal_carries_neither_value(): void {
		$refusal = $this->refusal(
			[
				'fields' => [
					$this->member( 'subtitle', 'a-secret-draft' ),
					$this->member( 'subtitle', 'another-secret-draft' ),
				],
			],
			$this->index()
		);

		$this->assertStringNotContainsString( 'a-secret-draft', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'another-secret-draft', $refusal->getMessage() );
	}

	// -- Flexible content ---------------------------------------------------

	/**
	 * @test
	 */
	public function test_a_flexible_content_request_naming_declared_layouts_is_accepted(): void {
		$validated = $this->input->validate(
			[
				'fields' => [
					$this->member(
						'sections',
						[
							[
								'acf_fc_layout' => 'hero',
								'heading'       => 'Welcome',
							],
							[ 'acf_fc_layout' => 'quote' ],
						]
					),
				],
			],
			$this->index()
		);

		$this->assertCount( 1, $validated );
		$this->assertCount(
			2,
			$validated[0]['value'],
			'A validated flexible-content value is passed on unchanged, rows and all.'
		);
	}

	/**
	 * @test
	 */
	public function test_a_flexible_content_value_with_no_rows_is_accepted(): void {
		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'sections', [] ) ] ],
			$this->index()
		);

		$this->assertSame( [], $validated[0]['value'], 'An empty list clears the field.' );
	}

	/**
	 * @test
	 */
	public function test_a_flexible_content_value_that_is_not_a_list_of_rows_is_refused(): void {
		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', 'hero' ) ] ],
			$this->index()
		);
	}

	/**
	 * @test
	 */
	public function test_a_flexible_content_row_that_is_not_an_object_is_refused(): void {
		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ 'hero' ] ) ] ],
			$this->index()
		);
	}

	/**
	 * @test
	 */
	public function test_a_flexible_content_row_with_no_layout_is_refused(): void {
		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ [ 'heading' => 'Welcome' ] ] ) ] ],
			$this->index()
		);
	}

	/**
	 * @test
	 */
	public function test_a_flexible_content_row_naming_an_undeclared_layout_is_refused(): void {
		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ [ 'acf_fc_layout' => 'banner' ] ] ) ] ],
			$this->index()
		);
	}

	/**
	 * The layout a caller sent is part of the VALUE, so a refusal may not repeat it.
	 *
	 * @test
	 */
	public function test_a_layout_refusal_names_the_field_but_never_the_layout(): void {
		$refusal = $this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ [ 'acf_fc_layout' => 'banner' ] ] ) ] ],
			$this->index()
		);

		$this->assertStringContainsString(
			'sections',
			$refusal->getMessage(),
			'Naming the field is what makes the refusal actionable.'
		);
		$this->assertStringNotContainsString( 'banner', $refusal->getMessage() );
	}

	/**
	 * A later row is checked as strictly as the first.
	 *
	 * @test
	 */
	public function test_a_bad_row_after_a_good_one_still_refuses_the_whole_request(): void {
		$this->refusal(
			[
				'fields' => [
					$this->member(
						'sections',
						[
							[ 'acf_fc_layout' => 'hero' ],
							[ 'acf_fc_layout' => 'banner' ],
						]
					),
				],
			],
			$this->index()
		);
	}

	/**
	 * A field whose layouts cannot be read declares none, and none is not "any".
	 *
	 * @test
	 */
	public function test_a_field_whose_layouts_cannot_be_read_refuses_every_row(): void {
		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ [ 'acf_fc_layout' => 'hero' ] ] ) ] ],
			[
				$this->entry(
					'field_flex',
					'sections',
					'flexible_content',
					[ 'layouts' => 'not a list of layouts' ]
				),
			]
		);
	}

	/**
	 * An unreadable layout entry is skipped rather than cast into a name.
	 *
	 * @test
	 */
	public function test_a_layout_entry_with_no_readable_name_declares_nothing(): void {
		$index = [
			$this->entry(
				'field_flex',
				'sections',
				'flexible_content',
				[
					'layouts' => [
						'broken',
						[ 'label' => 'No name at all' ],
						[ 'name' => '' ],
						[ 'name' => 'hero' ],
					],
				]
			),
		];

		$this->refusal(
			[ 'fields' => [ $this->member( 'sections', [ [ 'acf_fc_layout' => 'quote' ] ] ) ] ],
			$index
		);

		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'sections', [ [ 'acf_fc_layout' => 'hero' ] ] ) ] ],
			$index
		);

		$this->assertCount(
			1,
			$validated,
			'The one readable layout beside the broken ones is still declared.'
		);
	}

	// -- What is deliberately NOT validated ---------------------------------

	/**
	 * @test
	 */
	public function test_a_repeater_array_is_passed_through_untouched(): void {
		$rows = [
			[ 'title' => 'One' ],
			[ 'title' => 'Two' ],
			'not even a row',
		];

		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'items', $rows ) ] ],
			[ $this->entry( 'field_items', 'items', 'repeater' ) ]
		);

		$this->assertSame(
			$rows,
			$validated[0]['value'],
			'ACF owns a repeater shape; validating it here would refuse writes ACF accepts.'
		);
	}

	/**
	 * @test
	 */
	public function test_a_relationship_array_is_passed_through_untouched(): void {
		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'related', [ 12, 'thirteen' ] ) ] ],
			[ $this->entry( 'field_rel', 'related', 'relationship' ) ]
		);

		$this->assertSame( [ 12, 'thirteen' ], $validated[0]['value'] );
	}

	/**
	 * The Pro types are accepted because being in the index IS the licence proof.
	 *
	 * @test
	 */
	public function test_a_gallery_field_in_the_index_is_accepted(): void {
		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'shots', [ 4, 5 ] ) ] ],
			[ $this->entry( 'field_gallery', 'shots', 'gallery' ) ]
		);

		$this->assertCount(
			1,
			$validated,
			'A Pro gate here would refuse a field ACF itself registered.'
		);
	}

	/**
	 * @test
	 */
	public function test_a_clone_field_in_the_index_is_accepted(): void {
		$validated = $this->input->validate(
			[ 'fields' => [ $this->member( 'shared', 'x' ) ] ],
			[ $this->entry( 'field_clone', 'shared', 'clone' ) ]
		);

		$this->assertCount( 1, $validated );
	}

	// -- Crossing proofs ----------------------------------------------------

	/**
	 * Both the limit and a malformed member hold; only the order decides the message.
	 *
	 * @test
	 */
	public function test_the_length_limit_is_checked_before_any_member_is_read(): void {
		$fields   = $this->repeated( AcfFieldUpdateInput::MAX_FIELDS );
		$fields[] = 'not an object at all';

		$refusal = $this->refusal( [ 'fields' => $fields ], $this->index() );

		$this->assertStringContainsString(
			(string) AcfFieldUpdateInput::MAX_FIELDS,
			$refusal->getMessage(),
			'A caller who sent too many fields must be told the limit, not the shape of the last one.'
		);
	}

	/**
	 * An unknown field and a duplicate hold at once; the first member decides.
	 *
	 * @test
	 */
	public function test_members_are_refused_in_the_order_the_caller_sent_them(): void {
		$refusal = $this->refusal(
			[
				'fields' => [
					$this->member( 'not_a_field', 'x' ),
					$this->member( 'subtitle', 'y' ),
					$this->member( 'subtitle', 'z' ),
				],
			],
			$this->index()
		);

		$this->assertStringContainsString( 'not_a_field', $refusal->getMessage() );
	}

	/**
	 * A refusal anywhere refuses everything — nothing is validated partially.
	 *
	 * @test
	 */
	public function test_a_request_whose_last_member_is_bad_yields_no_entries_at_all(): void {
		$thrown = null;

		try {
			$this->input->validate(
				[
					'fields' => [
						$this->member( 'subtitle', 'fine' ),
						$this->member( 'not_a_field', 'x' ),
					],
				],
				$this->index()
			);
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'A partial write is not an acceptable outcome; the whole request refuses.'
		);
	}
}
