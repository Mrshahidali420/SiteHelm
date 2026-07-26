<?php
/**
 * Tests for WriteVerifier.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteVerifier;
use SiteHelm\Tests\TestCase;

/**
 * Tests the three-way classification of a completed write.
 */
final class WriteVerifierTest extends TestCase {

	private WriteVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new WriteVerifier( new PayloadNormalizer() );
	}

	/**
	 * @param array<string, mixed> $fields The field map.
	 */
	private function state( array $fields ): TargetState {
		return new TargetState( 'post:42', true, $fields );
	}

	/**
	 * @param array<string, mixed> $afterFields The promised fields.
	 */
	private function plan( array $afterFields ): PlannedChange {
		return new PlannedChange( [], $afterFields, array_keys( $afterFields ) );
	}

	public function test_every_promised_field_stored_exactly_is_applied_with_no_adjustments(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_title' => 'Edited title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] ),
			$this->state( [ 'post_title' => 'Edited title' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * WordPress turning a requested value into a different one — publish becoming
	 * future on a scheduled post, a slug being uniquified — is the platform
	 * working correctly. The write landed, so this must not be a failure.
	 */
	public function test_a_value_wordpress_changed_is_applied_and_named_as_adjusted(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_status' => 'publish' ] ),
			$this->state( [ 'post_status' => 'draft' ] ),
			$this->state( [ 'post_status' => 'future' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [ 'post_status' ], $outcome->adjustedFields );
	}

	/**
	 * The stored value still being the prior value is the one case that means the
	 * write genuinely did not take.
	 */
	public function test_a_field_still_holding_its_prior_value_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_title' => 'Edited title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] )
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * One field stored and another reverted leaves the target in neither its prior
	 * nor its promised state, which is exactly when a recovery handle matters.
	 */
	public function test_a_partial_write_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Edited excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Original title',
					'post_excerpt' => 'Original excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Original excerpt',
				]
			)
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * Pins the branch ordering in the design. A field whose promised value equals
	 * its prior value — a no-op field inside a larger change — must classify as
	 * exact. Testing the prior value before the promised one would call it
	 * not-applied and fail the whole write.
	 */
	public function test_a_no_op_field_inside_a_larger_change_is_exact_not_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Original title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			)
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * Fields carry arrays as well as strings — terms and meta both do — so every
	 * comparison goes through the canonical fingerprint rather than ===.
	 */
	public function test_an_array_valued_field_is_compared_through_the_fingerprint(): void {
		$exact = $this->verifier->classify(
			$this->plan( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 1 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] )
		);

		$this->assertTrue( $exact->applied );
		$this->assertSame( [], $exact->adjustedFields );

		$adjusted = $this->verifier->classify(
			$this->plan( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 1 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 3, 5, 9 ] ] ] )
		);

		$this->assertTrue( $adjusted->applied );
		$this->assertSame( [ 'terms' ], $adjusted->adjustedFields );
	}

	/**
	 * A field the after-state does not carry at all normalizes to null, which is
	 * distinct from an empty string. Against a prior state that also lacks it —
	 * a creation — that makes the field not-applied rather than adjusted.
	 */
	public function test_a_field_absent_from_both_states_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'featured_media' => 999999 ] ),
			$this->state( [] ),
			$this->state( [] )
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * Pins a known weakness rather than a desirable behaviour, so nobody "fixes"
	 * it into something worse. A value WordPress silently DROPS — a featured media
	 * id naming no existing attachment, stored as '' against a prior state that
	 * had no such field at all — reads as adjusted rather than not-applied,
	 * because '' and null are different values. So it succeeds, disclosing the
	 * empty value in the response state.
	 *
	 * That is honest but weak, and it is exactly why interpretation I7 requires an
	 * operation accepting a reference to another object to validate that it
	 * resolves while PLANNING, returning invalid_input. The classifier is a
	 * backstop here, not the intended guard.
	 */
	public function test_a_dropped_value_reads_as_adjusted_not_as_a_failed_write(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'featured_media' => 999999 ] ),
			$this->state( [] ),
			$this->state( [ 'featured_media' => '' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [ 'featured_media' ], $outcome->adjustedFields );
	}
}
