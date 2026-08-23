<?php
/**
 * Tests for YoastTermProvider.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\YoastTermProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Yoast's term store is one option keyed by taxonomy then term id.
 *
 * THE OTHER MEMBERS OF THE OPTION ARE THE THING WORTH PINNING: a write and a
 * restore each rewrite the whole option, so the test that matters is the one
 * asserting that another taxonomy, another term, and an unaddressed key on the same
 * term all survive both. Losing them would be a silent, site-wide data loss caused
 * by editing one category.
 */
final class YoastTermProviderTest extends TestCase {

	use SeoTermWordPressStubs;

	private YoastTermProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoTermStubs();
		$this->provider = new YoastTermProvider();
	}

	/**
	 * Seeds the option with two taxonomies and a key SiteHelm does not address.
	 */
	private function seedOption(): void {
		$this->options[ YoastTermProvider::OPTION ] = [
			'category' => [
				3 => [
					'wpseo_title'    => 'Guides',
					'wpseo_desc'     => 'Every guide.',
					'wpseo_noindex'  => 'noindex',
					'wpseo_linkdex'  => '70',
				],
				4 => [ 'wpseo_title' => 'News' ],
			],
			'post_tag' => [
				9 => [ 'wpseo_focuskw' => 'tags' ],
			],
		];
	}

	public function test_it_is_named_after_the_plugin(): void {
		$this->assertSame( 'yoast-seo', $this->provider->name() );
	}

	public function test_values_read_the_term_array_and_map_the_noindex_word(): void {
		$this->seedOption();

		$this->assertSame(
			[
				SeoFields::FIELD_TITLE         => 'Guides',
				SeoFields::FIELD_DESCRIPTION   => 'Every guide.',
				SeoFields::FIELD_CANONICAL     => null,
				SeoFields::FIELD_FOCUS_KEYWORD => null,
				SeoFields::FIELD_NOINDEX       => true,
			],
			$this->provider->values( 'category', 3 )
		);
	}

	public function test_values_on_a_term_with_no_array_are_all_null(): void {
		$this->assertSame(
			[ null, null, null, null, null ],
			array_values( $this->provider->values( 'category', 3 ) )
		);
	}

	/**
	 * `index` is false, `default` and an absent key are null — three stored states, two
	 * meanings, and the tri-state is what the read reports.
	 */
	public function test_the_noindex_word_maps_to_the_tri_state(): void {
		$this->options[ YoastTermProvider::OPTION ] = [
			'category' => [
				3 => [ 'wpseo_noindex' => 'index' ],
				4 => [ 'wpseo_noindex' => 'default' ],
			],
		];

		$this->assertFalse( $this->provider->values( 'category', 3 )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->values( 'category', 4 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_malformed_option_reads_as_empty_rather_than_fataling(): void {
		$this->options[ YoastTermProvider::OPTION ] = 'not an array';

		$this->assertNull( $this->provider->values( 'category', 3 )[ SeoFields::FIELD_TITLE ] );
	}

	public function test_apply_writes_the_term_array_and_leaves_every_other_member_alone(): void {
		$this->seedOption();

		$ok = $this->provider->apply(
			'category',
			3,
			[
				SeoFields::FIELD_TITLE   => '  Better guides  ',
				SeoFields::FIELD_NOINDEX => false,
				SeoFields::FIELD_DESCRIPTION => null,
			]
		);

		$this->assertTrue( $ok );

		$option = $this->options[ YoastTermProvider::OPTION ];

		$this->assertSame( 'Better guides', $option['category'][3]['wpseo_title'] );
		$this->assertSame( 'index', $option['category'][3]['wpseo_noindex'] );
		$this->assertArrayNotHasKey( 'wpseo_desc', $option['category'][3] );
		$this->assertSame( '70', $option['category'][3]['wpseo_linkdex'], 'An unaddressed key on the same term survives.' );
		$this->assertSame( [ 'wpseo_title' => 'News' ], $option['category'][4], 'Another term survives.' );
		$this->assertSame( [ 9 => [ 'wpseo_focuskw' => 'tags' ] ], $option['post_tag'], 'Another taxonomy survives.' );
	}

	public function test_apply_on_a_term_with_no_array_creates_it(): void {
		$this->assertTrue( $this->provider->apply( 'post_tag', 9, [ SeoFields::FIELD_CANONICAL => 'https://example.com/t' ] ) );
		$this->assertSame( 'https://example.com/t', $this->options[ YoastTermProvider::OPTION ]['post_tag'][9]['wpseo_canonical'] );
	}

	/**
	 * Clearing the last key removes the term array, and the emptied taxonomy with it,
	 * so a term nobody has set and a term everyone has cleared are the same state.
	 */
	public function test_clearing_the_last_key_removes_the_term_and_an_emptied_taxonomy(): void {
		$this->options[ YoastTermProvider::OPTION ] = [
			'category' => [ 3 => [ 'wpseo_title' => 'Guides' ] ],
			'post_tag' => [ 9 => [ 'wpseo_title' => 'Tags' ] ],
		];

		$this->assertTrue( $this->provider->apply( 'category', 3, [ SeoFields::FIELD_TITLE => null ] ) );
		$this->assertSame( [ 'post_tag' => [ 9 => [ 'wpseo_title' => 'Tags' ] ] ], $this->options[ YoastTermProvider::OPTION ] );
	}

	public function test_a_null_noindex_removes_the_word(): void {
		$this->seedOption();

		$this->assertTrue( $this->provider->apply( 'category', 3, [ SeoFields::FIELD_NOINDEX => null ] ) );
		$this->assertArrayNotHasKey( 'wpseo_noindex', $this->options[ YoastTermProvider::OPTION ]['category'][3] );
	}

	public function test_apply_ignores_a_field_it_does_not_own(): void {
		$this->seedOption();

		$this->assertTrue( $this->provider->apply( 'category', 3, [ 'ogImage' => 'x' ] ) );
		$this->assertArrayNotHasKey( 'ogImage', $this->options[ YoastTermProvider::OPTION ]['category'][3] );
	}

	public function test_capture_is_the_raw_term_array_and_null_when_there_is_none(): void {
		$this->seedOption();

		$this->assertSame(
			[
				'provider' => 'yoast-seo',
				'term'     => [
					'wpseo_title'   => 'Guides',
					'wpseo_desc'    => 'Every guide.',
					'wpseo_noindex' => 'noindex',
					'wpseo_linkdex' => '70',
				],
			],
			$this->provider->capture( 'category', 3 )
		);
		$this->assertNull( $this->provider->capture( 'category', 99 )['term'] );
	}

	public function test_restore_puts_the_captured_array_back_and_leaves_the_rest_alone(): void {
		$this->seedOption();
		$snapshot = $this->provider->capture( 'category', 3 );

		$this->provider->apply( 'category', 3, [ SeoFields::FIELD_TITLE => 'Changed', SeoFields::FIELD_NOINDEX => null ] );
		$this->provider->apply( 'post_tag', 9, [ SeoFields::FIELD_TITLE => 'Tag title' ] );

		$this->assertTrue( $this->provider->restore( 'category', 3, $snapshot ) );

		$option = $this->options[ YoastTermProvider::OPTION ];

		$this->assertSame( $snapshot['term'], $option['category'][3] );
		$this->assertSame( 'Tag title', $option['post_tag'][9]['wpseo_title'], 'A later change on another term is not rolled back.' );
	}

	/**
	 * A snapshot of "no array" restores to "no array", which is how a term that was
	 * first given metadata by the change comes back clean.
	 */
	public function test_restoring_an_absent_snapshot_removes_what_the_change_added(): void {
		$snapshot = $this->provider->capture( 'category', 3 );

		$this->provider->apply( 'category', 3, [ SeoFields::FIELD_TITLE => 'New' ] );

		$this->assertTrue( $this->provider->restore( 'category', 3, $snapshot ) );
		$this->assertSame( [], $this->options[ YoastTermProvider::OPTION ] );
	}
}
