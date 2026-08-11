<?php
/**
 * Tests for what MetaboxFieldGet reports from a site that answers (REQ-0050).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0050: the values, their presence, and what is said about what could not be read.
 *
 * Split from MetaboxFieldGetTest, which covers the refusals and the declared shape.
 * Every test here reaches the reading, so every test here installs Meta Box — and
 * that is exactly why the two halves are separate files rather than one: installing
 * Meta Box is permanent within a process, and the refusal suite needs processes where
 * it was never installed.
 *
 * THE TWO FACTS THIS SUITE EXISTS TO KEEP APART ARE "no row" AND "a row holding an
 * empty string". `rwmb_meta()` answers `''` for both, so they are only distinguishable
 * through a separate probe of the meta table — and a suite testing one direction only
 * would pass against an implementation that reported every field absent. Both
 * directions are asserted, and so is the fact that the probe ran at all.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldGetValuesTest extends TestCase {

	use MetaboxFieldGetHarness;

	/**
	 * A structure whose innermost leaf sits exactly $levels below the top.
	 *
	 * @param int $levels How many arrays to wrap the leaf in.
	 *
	 * @return mixed The structure.
	 */
	private function nested( int $levels ): mixed {
		$value = 'leaf';

		for ( $i = 0; $i < $levels; $i++ ) {
			$value = [ 'child' => $value ];
		}

		return $value;
	}

	// ------------------------------------------------------------ the reading

	public function test_omitting_the_field_list_reports_every_field_that_applies(): void {
		$this->givenTwoFields( [ 'subtitle' => 'A subtitle' ] );

		$payload = $this->handle();

		$this->assertSame( [ 'subtitle', 'byline' ], array_column( $payload['fields'], 'id' ) );
	}

	/**
	 * THE ORDER IS THE CALLER'S ORDER AND NOT THE REGISTRY'S. A client that sent three
	 * ids and reads the response positionally is doing the obvious thing; answering in
	 * registration order would pair its labels with the wrong values.
	 */
	public function test_a_named_subset_is_reported_in_the_order_it_was_asked_for(): void {
		$this->givenTwoFields(
			[
				'subtitle' => 'A subtitle',
				'byline'   => 'A byline',
			]
		);

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'byline', 'subtitle' ],
			]
		);

		$this->assertSame( [ 'byline', 'subtitle' ], array_column( $payload['fields'], 'id' ) );
		$this->assertSame( [ 'A byline', 'A subtitle' ], array_column( $payload['fields'], 'value' ) );
	}

	/**
	 * A FIELD NAMED TWICE IS ONE FIELD, and it is read once. Two entries for one meta
	 * key would leave a client reconciling one stored value with itself, and the second
	 * read is a second pass through Meta Box's filter chain for no answer.
	 */
	public function test_a_field_named_twice_is_reported_once_and_read_once(): void {
		$this->givenTwoFields( [ 'subtitle' => 'A subtitle' ] );

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle', 'subtitle' ],
			]
		);

		$this->assertSame( [ 'subtitle' ], array_column( $payload['fields'], 'id' ) );
		$this->assertSame( 1, $this->metaboxCallCount( 'value' ) );
	}

	/**
	 * THE READ NAMES THE OBJECT TYPE AND THE POST, and the recorded argument list is the
	 * only place that is visible. `rwmb_meta()` called without an object id falls back
	 * to whatever post the current screen holds — a real value, read from the wrong
	 * post, which every assertion on the returned value would accept.
	 */
	public function test_the_read_names_the_object_type_and_the_post_it_reads_from(): void {
		$this->givenTwoFields(
			[ 'subtitle' => 'A subtitle' ],
			[ '42:subtitle' ]
		);

		$this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertSame(
			[ [ 'subtitle', [ 'object_type' => 'post' ], 42 ] ],
			$this->metaboxCallArguments( 'value' )
		);
	}

	// -------------------------------------------------- absent versus empty

	/**
	 * DIRECTION ONE: no row at all. The site answers `''` from `rwmb_meta()` because
	 * that is what Meta Box answers for a field it finds nothing for.
	 */
	public function test_a_field_with_no_stored_row_is_reported_as_not_present(): void {
		$this->givenTwoFields();

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertFalse( $payload['fields'][0]['present'] );
		$this->assertSame( '', $payload['fields'][0]['value'] );
	}

	/**
	 * DIRECTION TWO, AND THE ONE A SINGLE-DIRECTION SUITE MISSES. The site holds a row
	 * whose value is the empty string — a field an operator deliberately emptied — and
	 * `rwmb_meta()` answers exactly what it answered above. Only the meta-table probe
	 * separates them, so an implementation inferring presence from the value would pass
	 * the test above and fail this one.
	 */
	public function test_a_row_holding_an_empty_string_is_reported_as_present(): void {
		$this->givenTwoFields(
			[ 'subtitle' => '' ],
			[ '42:subtitle' ]
		);

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertTrue( $payload['fields'][0]['present'] );
		$this->assertSame( '', $payload['fields'][0]['value'] );
	}

	/**
	 * BOTH STATES IN ONE RESPONSE, which is the assertion neither single-direction test
	 * can make: two fields whose values are identical and whose presence is not. An
	 * implementation that answered a constant for `present` passes one of the two tests
	 * above and cannot pass this one.
	 */
	public function test_two_fields_with_the_same_empty_value_differ_in_their_presence(): void {
		$this->givenTwoFields(
			[
				'subtitle' => '',
				'byline'   => '',
			],
			[ '42:subtitle' ]
		);

		$payload = $this->handle();

		$this->assertSame( [ true, false ], array_column( $payload['fields'], 'present' ) );
		$this->assertSame( [ '', '' ], array_column( $payload['fields'], 'value' ) );
	}

	/**
	 * AND THE PROBE IS A PROBE OF THE META TABLE. Presence read off the value would
	 * still produce a plausible payload; the recorded call is what proves the question
	 * was asked of the storage, for this post and this meta key.
	 */
	public function test_presence_is_asked_of_the_meta_table_for_this_post_and_key(): void {
		$this->givenTwoFields(
			[ 'subtitle' => 'A subtitle' ],
			[ '42:subtitle' ]
		);

		$this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertSame(
			[ [ 'post', 42, 'subtitle' ] ],
			$this->metaboxCallArguments( 'row' )
		);
	}

	/**
	 * THE ROW PROBE RUNS FOR AN ORDINARY VALUE TOO, not only for one that looks empty.
	 * A conditional probe would make `present` true-by-assumption for every non-empty
	 * value — and `std`, a field's configured default, is the mechanism that makes that
	 * assumption wrong on a real site.
	 */
	public function test_a_configured_default_is_never_reported_as_a_stored_value(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'details',
						[
							'fields' => [
								$this->field( 'subtitle', 'Subtitle', 'text', [ 'std' => 'A default' ] ),
							],
						]
					),
				]
			)
		);

		$payload = $this->handle();

		$this->assertFalse( $payload['fields'][0]['present'] );
		$this->assertSame( '', $payload['fields'][0]['value'] );
		$this->assertStringNotContainsString( 'A default', (string) json_encode( $payload ) );
	}

	// ----------------------------------------------------------- the depth cap

	public function test_a_value_nested_to_the_cap_is_reported_whole_and_silently(): void {
		$this->givenTwoFields(
			[ 'subtitle' => $this->nested( MetaboxSchemaFormat::MAX_DEPTH ) ],
			[ '42:subtitle' ]
		);

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertSame( $this->nested( MetaboxSchemaFormat::MAX_DEPTH ), $payload['fields'][0]['value'] );
		$this->assertSame( [], $payload['fieldReadNotices'] );
	}

	/**
	 * ONE LEVEL FURTHER AND THE CUT IS VISIBLE. Truncating silently is the failure mode
	 * that matters: a client that snapshotted a truncated value and restored it later
	 * would write the truncation back over the site's own data, so the notice is what
	 * makes the value unusable as a restore source on purpose.
	 *
	 * THE NOTICE NAMES THE FIELD AND CARRIES NO PART OF THE VALUE (spec §8) — not the
	 * leaf, not the keys it sat under.
	 */
	public function test_a_value_past_the_cap_is_cut_and_announced_by_field_id(): void {
		$this->givenTwoFields(
			[ 'subtitle' => $this->nested( MetaboxSchemaFormat::MAX_DEPTH + 1 ) ],
			[ '42:subtitle' ]
		);

		$payload = $this->handle(
			[
				'post'   => 42,
				'fields' => [ 'subtitle' ],
			]
		);

		$this->assertNotSame( $this->nested( MetaboxSchemaFormat::MAX_DEPTH + 1 ), $payload['fields'][0]['value'] );
		$this->assertCount( 1, $payload['fieldReadNotices'] );
		$this->assertStringContainsString( 'subtitle', $payload['fieldReadNotices'][0] );
		$this->assertStringNotContainsString( 'leaf', $payload['fieldReadNotices'][0] );
	}

	// -------------------------------------------------------- the degraded read

	/**
	 * A GROUP WHOSE FIELD LIST CANNOT BE READ IS ANNOUNCED, NOT SKIPPED. Its fields
	 * are missing from the response either way; the difference is whether an operator
	 * reads that absence as "this post has no such field" and plans a write from it.
	 *
	 * The group id is named because the site registered it and an operator can go and
	 * look at it. No value is named, because none was read.
	 */
	public function test_a_group_whose_fields_cannot_be_read_is_announced_by_group_id(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group( 'details', [ 'fields' => [ $this->field( 'subtitle', 'Subtitle' ) ] ] ),
					$this->group( 'mangled', [ 'fields' => false ] ),
				]
			),
			'5.9.4',
			[ 'subtitle' => 'A subtitle' ],
			true,
			true,
			[ '42:subtitle' ]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'subtitle' ], array_column( $payload['fields'], 'id' ) );
		$this->assertCount( 1, $payload['fieldReadNotices'] );
		$this->assertStringContainsString( 'mangled', $payload['fieldReadNotices'][0] );
	}

	// ------------------------------------------------------------ the entries

	/**
	 * FIVE MEMBERS AND NO SIXTH. The output schema is closed, so a definition member
	 * that leaked into an entry — `std`, a field's configured default, being the one
	 * that would look most harmless — is a payload the boundary rejects, and a client
	 * reading it as a stored value would be reading something the post does not hold.
	 */
	public function test_every_reported_field_carries_exactly_the_declared_members(): void {
		$this->givenTwoFields(
			[ 'subtitle' => 'A subtitle' ],
			[ '42:subtitle' ]
		);

		$payload = $this->handle();

		foreach ( $payload['fields'] as $entry ) {
			$this->assertSame( [ 'id', 'name', 'type', 'present', 'value' ], array_keys( $entry ) );
		}
	}

	/**
	 * `id` IS THE META KEY AND `name` IS THE LABEL, which is inverted from the obvious
	 * reading of Meta Box's own vocabulary (spec §5). A response that swapped them
	 * would publish labels as the addresses every later write takes.
	 */
	public function test_the_entry_reports_the_meta_key_as_id_and_the_label_as_name(): void {
		$this->givenTwoFields( [ 'subtitle' => 'A subtitle' ] );

		$entry = $this->handle()['fields'][0];

		$this->assertSame( 'subtitle', $entry['id'] );
		$this->assertSame( 'Subtitle', $entry['name'] );
		$this->assertSame( 'text', $entry['type'] );
	}
}
