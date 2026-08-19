<?php
/**
 * Tests for AuditRedactor.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Tests\TestCase;

/**
 * Tests that audit summaries carry identifiers and sizes, never values.
 */
final class AuditRedactorTest extends TestCase {

	private AuditRedactor $redactor;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->redactor = new AuditRedactor();
	}

	public function test_no_field_value_reaches_the_summary(): void {
		$summary = $this->redactor->summarize(
			[ 'post_title' => 'Confidential launch name' ],
			[ 'post_title' => 'Even more confidential name' ]
		);

		$this->assertStringNotContainsString( 'Confidential launch name', $summary );
		$this->assertStringNotContainsString( 'Even more confidential name', $summary );
	}

	public function test_changed_names_are_listed_and_sorted(): void {
		$summary = $this->redactor->summarize(
			[
				'post_title'   => 'a',
				'post_content' => 'b',
			],
			[
				'post_title'   => 'z',
				'post_content' => 'y',
			]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( [ 'post_content', 'post_title' ], $decoded['changed'] );
	}

	public function test_metrics_report_before_and_after_sizes(): void {
		$summary = $this->redactor->summarize(
			[ 'post_content' => str_repeat( 'x', 10 ) ],
			[ 'post_content' => str_repeat( 'y', 25 ) ]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( 10, $decoded['metrics']['post_content']['before'] );
		$this->assertSame( 25, $decoded['metrics']['post_content']['after'] );
	}

	public function test_unchanged_fields_are_omitted(): void {
		$summary = $this->redactor->summarize(
			[ 'post_title' => 'same' ],
			[ 'post_title' => 'same' ]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( [], $decoded['changed'] );
	}

	public function test_absent_before_value_measures_as_zero(): void {
		$summary = $this->redactor->summarize( [], [ 'post_title' => 'four' ] );
		$decoded = json_decode( $summary, true );

		$this->assertSame( 0, $decoded['metrics']['post_title']['before'] );
		$this->assertSame( 4, $decoded['metrics']['post_title']['after'] );
	}

	public function test_array_values_measure_as_item_counts(): void {
		$summary = $this->redactor->summarize(
			[ 'terms' => [ 'category' => [ 1 ] ] ],
			[
				'terms' => [
					'category' => [ 1, 2 ],
					'post_tag' => [ 9 ],
				],
			]
		);
		$decoded = json_decode( $summary, true );

		$this->assertSame( 1, $decoded['metrics']['terms']['before'] );
		$this->assertSame( 2, $decoded['metrics']['terms']['after'] );
	}

	public function test_empty_metrics_encode_as_a_json_object(): void {
		$summary = $this->redactor->summarize( [ 'a' => 1 ], [ 'a' => 1 ] );

		$this->assertStringContainsString( '"metrics":{}', $summary );
	}

	/**
	 * A value can carry a sensitive payload several levels below the field the
	 * redactor inspects (e.g. a 'meta' field holding a nested API key). Because
	 * measure() only ever counts array items rather than serializing them, no
	 * key or value from inside a nested structure can reach the summary, no
	 * matter how deep it sits or how innocuous the top-level field name is.
	 */
	public function test_nested_values_never_reach_the_summary_regardless_of_depth(): void {
		$summary = $this->redactor->summarize(
			[
				'meta' => [
					'billing' => [ 'stripe_secret_key' => 'sk_live_before_0000000000' ],
				],
			],
			[
				'meta' => [
					'billing' => [ 'stripe_secret_key' => 'sk_live_after_1111111111' ],
				],
			]
		);

		$this->assertStringNotContainsString( 'sk_live_before_0000000000', $summary );
		$this->assertStringNotContainsString( 'sk_live_after_1111111111', $summary );
		$this->assertStringNotContainsString( 'stripe_secret_key', $summary );
		$this->assertStringNotContainsString( 'billing', $summary );
	}

	/**
	 * Guards the opposite failure mode: a redactor that is too aggressive and
	 * blanks out the non-sensitive metadata the audit trail exists to keep.
	 * Field names are not secret and must survive intact even when several
	 * fields change at once and one of them carries a large, sensitive value.
	 */
	public function test_nonsensitive_field_names_survive_alongside_a_sensitive_field(): void {
		$summary = $this->redactor->summarize(
			[
				'post_title' => 'Draft title',
				'api_secret' => 'old-secret-value',
			],
			[
				'post_title' => 'Published title',
				'api_secret' => 'new-secret-value',
			]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( [ 'api_secret', 'post_title' ], $decoded['changed'] );
		$this->assertSame( 11, $decoded['metrics']['post_title']['before'] );
		$this->assertSame( 15, $decoded['metrics']['post_title']['after'] );
		$this->assertStringNotContainsString( 'old-secret-value', $summary );
		$this->assertStringNotContainsString( 'new-secret-value', $summary );
	}
	/**
	 * A field is changed only when it is not identical, not merely unequal.
	 *
	 * WordPress hands back column values as strings while a payload carries
	 * scalars, so '0' against 0 is realistic. A review found relaxing the
	 * comparison to `==` passed the whole suite, which would drop a real change
	 * out of the audit summary — the record would say a field was untouched
	 * when the write altered it.
	 */
	public function test_a_loosely_equal_value_is_still_recorded_as_changed(): void {
		$decoded = json_decode( $this->redactor->summarize( [ 'post_title' => '0' ], [ 'post_title' => 0 ] ), true );

		$this->assertSame( [ 'post_title' ], $decoded['changed'] );
	}

	/**
	 * Field names are recorded exactly as given.
	 *
	 * A review found lower-casing the recorded name left the suite green.
	 * An audit summary naming a field that does not exist is evidence an
	 * investigator cannot match against the schema.
	 */
	public function test_a_field_name_is_recorded_with_its_original_case(): void {
		$decoded = json_decode( $this->redactor->summarize( [], [ 'postTitle_X' => 'value' ] ), true );

		$this->assertSame( [ 'postTitle_X' ], $decoded['changed'] );
		$this->assertArrayHasKey( 'postTitle_X', $decoded['metrics'] );
	}

	/**
	 * Metrics are ordered so two identical changes summarize byte-identically.
	 *
	 * A review found removing the sort left the suite green, because no test
	 * supplied fields out of order. Unstable ordering makes two audit rows for
	 * the same change compare as different, which defeats diffing the trail.
	 */
	public function test_metrics_are_ordered_independently_of_input_order(): void {
		$one = $this->redactor->summarize( [], [ 'zeta' => 'a', 'alpha' => 'bb' ] );
		$two = $this->redactor->summarize( [], [ 'alpha' => 'bb', 'zeta' => 'a' ] );

		$this->assertSame( $one, $two );
		$this->assertSame( '{"changed":["alpha","zeta"],"metrics":{"alpha":{"before":0,"after":2},"zeta":{"before":0,"after":1}}}', $one );
	}

	/**
	 * Before and after are not interchangeable.
	 *
	 * A review found swapping them left the suite green, because the fixtures
	 * happened to be symmetric in size. An audit row that reports a field
	 * shrinking when it grew is misleading evidence.
	 */
	public function test_before_and_after_sizes_are_not_swapped(): void {
		$decoded = json_decode(
			$this->redactor->summarize( [ 'post_title' => 'ab' ], [ 'post_title' => 'abcdefgh' ] ),
			true
		);

		$this->assertSame( 2, $decoded['metrics']['post_title']['before'] );
		$this->assertSame( 8, $decoded['metrics']['post_title']['after'] );
	}
	/**
	 * `false` MEASURES AS ONE, not as nothing. A deletion sweep found this
	 * branch unpinned, and it is not the harmless half of the pair: delete it
	 * and `true` still measures 1 — `(string) true` is `'1'` — while `false`
	 * falls to `mb_strlen( '' )` and measures 0.
	 *
	 * Zero is the signature of an absent or emptied field, which is what the
	 * before/after sizes exist to distinguish. An audit record is read after
	 * the fact by someone asking what a change did; a setting switched OFF must
	 * not leave the same trace as a field whose content was deleted.
	 */
	public function test_a_boolean_measures_as_one_whichever_way_it_points(): void {
		$decoded = json_decode(
			$this->redactor->summarize(
				[ 'ping_status' => true ],
				[ 'ping_status' => false ]
			),
			true
		);

		$this->assertSame( [ 'ping_status' ], $decoded['changed'] );
		$this->assertSame( 1, $decoded['metrics']['ping_status']['before'] );
		$this->assertSame( 1, $decoded['metrics']['ping_status']['after'] );
	}
}
