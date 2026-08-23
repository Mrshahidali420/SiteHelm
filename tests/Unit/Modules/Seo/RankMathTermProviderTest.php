<?php
/**
 * Tests for RankMathTermProvider.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\RankMathTermProvider;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Rank Math's term store is term meta, with one directive array for robots.
 *
 * THE DIRECTIVE ARRAY IS EDITED, NOT REPLACED, and that is the substance here: a
 * term can carry `noarchive` or `nosnippet` set from the plugin's own screen, and
 * a noindex write that replaced the array would silently drop them. Every noindex
 * test below seeds an extra directive and asserts it survives.
 */
final class RankMathTermProviderTest extends TestCase {

	use SeoTermWordPressStubs;

	private RankMathTermProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoTermStubs();
		$this->provider = new RankMathTermProvider();
	}

	public function test_it_is_named_after_the_plugin(): void {
		$this->assertSame( 'rank-math', $this->provider->name() );
	}

	public function test_values_read_the_four_text_keys_and_the_directive_array(): void {
		$this->seedTermMeta( 3, 'rank_math_title', 'Guides' );
		$this->seedTermMeta( 3, 'rank_math_focus_keyword', 'guides' );
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'noindex', 'nosnippet' ] );

		$this->assertSame(
			[
				SeoFields::FIELD_TITLE         => 'Guides',
				SeoFields::FIELD_DESCRIPTION   => null,
				SeoFields::FIELD_CANONICAL     => null,
				SeoFields::FIELD_FOCUS_KEYWORD => 'guides',
				SeoFields::FIELD_NOINDEX       => true,
			],
			$this->provider->values( 'category', 3 )
		);
	}

	public function test_the_directive_array_maps_to_the_tri_state(): void {
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'index' ] );
		$this->seedTermMeta( 4, 'rank_math_robots', [ 'noarchive' ] );

		$this->assertFalse( $this->provider->values( 'category', 3 )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->values( 'category', 4 )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->values( 'category', 5 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_directive_row_that_is_not_an_array_reads_as_null(): void {
		$this->seedTermMeta( 3, 'rank_math_robots', 'noindex' );

		$this->assertNull( $this->provider->values( 'category', 3 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_apply_writes_text_keys_and_deletes_a_cleared_one(): void {
		$this->seedTermMeta( 3, 'rank_math_description', 'Old' );

		$ok = $this->provider->apply(
			'category',
			3,
			[
				SeoFields::FIELD_TITLE       => '  New title ',
				SeoFields::FIELD_DESCRIPTION => null,
			]
		);

		$this->assertTrue( $ok );
		$this->assertSame( [ 'New title' ], $this->termRowsFor( 3, 'rank_math_title' ) );
		$this->assertFalse( $this->hasTermMeta( 3, 'rank_math_description' ), 'A cleared field is a deleted row, not an empty one.' );
	}

	public function test_setting_noindex_keeps_the_other_directives(): void {
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'index', 'noarchive' ] );

		$this->assertTrue( $this->provider->apply( 'category', 3, [ SeoFields::FIELD_NOINDEX => true ] ) );
		$this->assertSame( [ [ 'noarchive', 'noindex' ] ], $this->termRowsFor( 3, 'rank_math_robots' ) );
	}

	public function test_clearing_noindex_removes_both_words_and_an_emptied_array(): void {
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'noindex' ] );
		$this->seedTermMeta( 4, 'rank_math_robots', [ 'noindex', 'nosnippet' ] );

		$this->assertTrue( $this->provider->apply( 'category', 3, [ SeoFields::FIELD_NOINDEX => null ] ) );
		$this->assertTrue( $this->provider->apply( 'category', 4, [ SeoFields::FIELD_NOINDEX => null ] ) );

		$this->assertFalse( $this->hasTermMeta( 3, 'rank_math_robots' ), 'An emptied array is deleted.' );
		$this->assertSame( [ [ 'nosnippet' ] ], $this->termRowsFor( 4, 'rank_math_robots' ) );
	}

	public function test_setting_index_on_a_term_with_no_array_creates_one(): void {
		$this->assertTrue( $this->provider->apply( 'category', 3, [ SeoFields::FIELD_NOINDEX => false ] ) );
		$this->assertSame( [ [ 'index' ] ], $this->termRowsFor( 3, 'rank_math_robots' ) );
	}

	public function test_apply_ignores_a_field_it_does_not_own(): void {
		$this->assertTrue( $this->provider->apply( 'category', 3, [ 'ogImage' => 'x' ] ) );
		$this->assertSame( [], $this->term_meta );
	}

	public function test_capture_is_every_owned_keys_raw_rows(): void {
		$this->seedTermMeta( 3, 'rank_math_title', 'Guides' );
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'noindex' ] );

		$this->assertSame(
			[
				'provider' => 'rank-math',
				'meta'     => [
					'rank_math_title'         => [ 'Guides' ],
					'rank_math_description'   => [],
					'rank_math_canonical_url' => [],
					'rank_math_focus_keyword' => [],
					'rank_math_robots'        => [ [ 'noindex' ] ],
				],
			],
			$this->provider->capture( 'category', 3 )
		);
	}

	/**
	 * Delete-then-re-add: a key the change ADDED has no row in the snapshot, and only
	 * deleting every owned key first removes it.
	 */
	public function test_restore_removes_what_the_change_added_and_puts_back_what_it_changed(): void {
		$this->seedTermMeta( 3, 'rank_math_title', 'Guides' );
		$this->seedTermMeta( 3, 'rank_math_other', 'kept' );
		$snapshot = $this->provider->capture( 'category', 3 );

		$this->provider->apply(
			'category',
			3,
			[
				SeoFields::FIELD_TITLE       => 'Changed',
				SeoFields::FIELD_DESCRIPTION => 'Added',
				SeoFields::FIELD_NOINDEX     => true,
			]
		);

		$this->assertTrue( $this->provider->restore( 'category', 3, $snapshot ) );
		$this->assertSame( [ 'Guides' ], $this->termRowsFor( 3, 'rank_math_title' ) );
		$this->assertFalse( $this->hasTermMeta( 3, 'rank_math_description' ) );
		$this->assertFalse( $this->hasTermMeta( 3, 'rank_math_robots' ) );
		$this->assertSame( [ 'kept' ], $this->termRowsFor( 3, 'rank_math_other' ), 'A key the provider does not own is untouched.' );
	}

	/**
	 * A snapshot whose `meta` is not an array restores nothing and says so: the store
	 * after (every owned key present with no rows) is not the snapshot (no keys), so
	 * the caller hears that the recorded state did not come back.
	 */
	public function test_restore_with_a_malformed_snapshot_reports_mismatch(): void {
		$this->seedTermMeta( 3, 'rank_math_title', 'Guides' );

		$this->assertFalse( $this->provider->restore( 'category', 3, [ 'provider' => 'rank-math', 'meta' => 'garbage' ] ) );
	}
}
