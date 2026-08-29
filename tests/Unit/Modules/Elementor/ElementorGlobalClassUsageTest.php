<?php
/**
 * Tests for ElementorGlobalClassUsage.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorGlobalClassUsage;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;

/**
 * The scan that tells an operator how far a deletion reaches.
 *
 * THE NUMBER IS A LOWER BOUND AND THE FLAG SAYS WHEN. A scan that hit its bound
 * reports `complete: false`, and the delete operation words its warning
 * differently because of it. If that flag were wrong in the optimistic
 * direction, an operator would read "used by 200 documents" as a total on a site
 * where the real figure is thousands.
 */
final class ElementorGlobalClassUsageTest extends TestCase {

	private ElementorGlobalClassUsage $usage;

	protected function setUp(): void {
		parent::setUp();

		$this->usage = new ElementorGlobalClassUsage();
	}

	/**
	 * Queues one scan result.
	 *
	 * @param int $documents How many documents the query reports.
	 *
	 * @return void
	 */
	private function documents( int $documents ): void {
		FakeWpQuery::$rows = array_fill( 0, $documents, (object) [] );
	}

	public function test_a_class_no_document_wears_counts_zero_and_is_a_total(): void {
		$this->documents( 0 );

		$this->assertSame(
			[
				'count'    => 0,
				'complete' => true,
			],
			$this->usage->documentsWearing( 'g-card' )
		);
	}

	public function test_a_partial_scan_says_so(): void {
		$this->documents( ElementorGlobalClassUsage::MAX_SCAN );

		$result = $this->usage->documentsWearing( 'g-card' );

		$this->assertSame( ElementorGlobalClassUsage::MAX_SCAN, $result['count'] );
		$this->assertFalse( $result['complete'], 'A scan that filled its bound has not seen the whole site.' );
	}

	public function test_a_scan_below_the_bound_is_a_total(): void {
		$this->documents( ElementorGlobalClassUsage::MAX_SCAN - 1 );

		$this->assertTrue( $this->usage->documentsWearing( 'g-card' )['complete'] );
	}

	/**
	 * The scan is bounded, asks only for ids, and warms no caches.
	 */
	public function test_the_scan_is_bounded_and_reads_as_little_as_it_can(): void {
		$this->documents( 1 );

		$this->usage->documentsWearing( 'g-card' );
		$args = FakeWpQuery::$calls[0];

		$this->assertSame( ElementorGlobalClassUsage::MAX_SCAN, $args['posts_per_page'] );
		$this->assertSame( 'ids', $args['fields'] );
		$this->assertTrue( $args['no_found_rows'] );
		$this->assertFalse( $args['update_post_meta_cache'] );
		$this->assertFalse( $args['update_post_term_cache'] );
	}

	/**
	 * Drafts and non-page post types wear global classes too.
	 */
	public function test_the_scan_looks_at_every_post_type_and_status(): void {
		$this->documents( 1 );

		$this->usage->documentsWearing( 'g-card' );
		$args = FakeWpQuery::$calls[0];

		$this->assertSame( 'any', $args['post_type'] );
		$this->assertSame( 'any', $args['post_status'] );
	}

	public function test_the_identifier_is_matched_inside_the_stored_element_tree(): void {
		$this->documents( 1 );

		$this->usage->documentsWearing( 'g-card' );

		$this->assertSame(
			[
				[
					'key'     => '_elementor_data',
					'value'   => 'g-card',
					'compare' => 'LIKE',
				],
			],
			FakeWpQuery::$calls[0]['meta_query']
		);
	}
}
