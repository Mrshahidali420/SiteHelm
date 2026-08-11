<?php
/**
 * Covers the one value shape AcfFieldUpdateInput validates.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Tests\TestCase;

/**
 * Flexible-content rows, the only value shape the validator checks.
 *
 * A repeater's rows and a relationship's identifiers pass through untouched
 * because ACF owns their shapes. Flexible content is the exception because a row
 * naming an undeclared layout is not a write ACF refuses — it is one ACF
 * silently drops, which reaches the operator as a successful call that changed
 * nothing.
 *
 * Split out of AcfFieldUpdateInputTest at the flexible-content seam when that
 * file reached the 800-line limit. The subject and its fixtures come from
 * AcfFieldUpdateRequests, so both halves test one object built one way.
 *
 * @package SiteHelm
 */
final class AcfFieldUpdateLayoutsTest extends TestCase {

	use AcfFieldUpdateRequests;

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
			'"sections"',
			$refusal->getMessage(),
			'Naming the field is what makes the refusal actionable, and the echoed name is DELIMITED: undelimited, a name ending in a full stop reads as a continuation of our own sentence.'
		);
		$this->assertStringNotContainsString( 'banner', $refusal->getMessage() );
		$this->assertSame(
			'Call acf-group-list for this post to see the layouts the field declares.',
			$refusal->remediation,
			'The remediation must name an operation that can actually answer: acf-field-list reports values, and it is acf-group-list that reports the layouts a field declares.'
		);
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
}
