<?php
/**
 * Tests for the Rank Math store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Seo\RankMathProvider;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The one meta value that made this module need a provider layer.
 *
 * RANK MATH KEEPS EVERY ROBOTS DIRECTIVE FOR A POST IN ONE LIST, where Yoast keeps
 * two independent numbers. Two consequences are what this file exists to hold:
 *
 * 1. A WRITE MERGES, NEVER REPLACES. The list carries directives this module does
 *    not address — `noarchive`, `nosnippet`, `noimageindex`, `max-snippet` — and a
 *    freshly built two-member list would silently delete all of them. That would be
 *    a change to the noindex flag that also re-enables archiving, with nothing in
 *    the plan saying so, which is the worst class of bug this codebase can ship:
 *    a write whose preview did not describe it.
 *
 * 2. THERE IS NO `follow` DIRECTIVE, so `nofollow: false` can only mean "remove it"
 *    and reads back as null. The provider DECLARES that through
 *    storesExplicitNegative() so the plan says so before the write runs, rather than
 *    the verification discovering it after — and the projection test below is what
 *    pins the declaration to the store's actual behaviour.
 */
final class RankMathProviderTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * The meta key holding the directive list.
	 */
	private const KEY_ROBOTS = 'rank_math_robots';

	/**
	 * The provider under test.
	 */
	private RankMathProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->provider = new RankMathProvider();
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'rank-math', $this->provider->name() );
	}

	/**
	 * THE ONLY PROVIDER THAT ANSWERS THIS WITH ANYTHING BUT TRUE, and it answers it
	 * from one option Rank Math writes itself. From roughly 1.0.200 a fresh install
	 * puts nothing on the page a visitor is served until an owner has been through
	 * its setup, so a site can store a perfectly good title and description through
	 * this provider and serve none of it.
	 */
	public function test_rank_math_reports_itself_configured_once_its_own_option_is_set(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				'rank_math_is_configured' === $key ? '1' : $fallback
		);

		$this->assertTrue( $this->provider->isConfigured() );
	}

	/**
	 * A missing option and a false one mean the same thing: setup never finished.
	 * The absent case is the one a fresh install is actually in, so both are pinned.
	 */
	public function test_rank_math_reports_itself_unconfigured_when_the_option_is_absent_or_false(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed => $fallback
		);

		$this->assertFalse( $this->provider->isConfigured() );

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				'rank_math_is_configured' === $key ? '0' : $fallback
		);

		$this->assertFalse( $this->provider->isConfigured() );
	}

	public function test_rank_math_reports_its_seo_score_and_no_readability_score(): void {
		$this->seedMeta( 42, 'rank_math_seo_score', '88' );

		$this->assertSame( [ 'seoScore' => 88, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_an_unscored_post_reports_both_scores_as_null(): void {
		$this->assertSame( [ 'seoScore' => null, 'readabilityScore' => null ], $this->provider->scores( 42 ) );
	}

	public function test_a_post_with_nothing_stored_reports_every_field_as_null(): void {
		$values = $this->provider->values( 42 );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $values ) );
		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $values );
	}

	public function test_stored_text_is_reported_under_the_neutral_field_name(): void {
		$this->seedMeta( 42, 'rank_math_title', 'A title' );
		$this->seedMeta( 42, 'rank_math_description', 'A description' );
		$this->seedMeta( 42, 'rank_math_facebook_title', 'Shared as' );

		$values = $this->provider->values( 42 );

		$this->assertSame( 'A title', $values[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'A description', $values[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertSame( 'Shared as', $values[ SeoFields::FIELD_OG_TITLE ] );
	}

	public function test_the_noindex_directive_is_read_from_the_list(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noindex' ] );

		$this->assertTrue( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * `index` is the explicit opposite of `noindex`, so `false` is a real stored answer.
	 */
	public function test_an_index_directive_reads_as_an_explicit_false(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'index' ] );

		$this->assertFalse( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_a_list_naming_neither_directive_means_the_plugin_decides(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noarchive' ] );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * Nothing is the opposite of `nofollow`, so its absence is the only way to say it.
	 */
	public function test_nofollow_is_true_or_null_and_never_an_explicit_false(): void {
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );

		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'nofollow' ] );

		$this->assertTrue( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * A list this module could not have written is treated as no directives.
	 *
	 * This is post meta: an importer, a migration or a hand-edited row can leave a
	 * string here. Reading garbage as "no directives" is the safe direction, because
	 * the alternative is a write that builds its new list out of it.
	 */
	public function test_a_robots_value_that_is_not_a_list_is_read_as_no_directives(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, 'noindex' );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	public function test_a_list_with_members_of_the_wrong_shape_keeps_only_the_usable_ones(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noindex', [ 'nested' ], 7, '', '  ' ] );

		$this->assertTrue( $this->provider->values( 42 )[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * THE MERGE. A directive this module does not address survives a flag write.
	 *
	 * This is the assertion the whole provider layer exists for.
	 */
	public function test_writing_a_flag_preserves_the_directives_this_module_does_not_address(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noarchive', 'nosnippet', 'noimageindex' ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => true ] ) );

		$stored = $this->rowsFor( 42, self::KEY_ROBOTS )[0];

		$this->assertContains( 'noarchive', $stored );
		$this->assertContains( 'nosnippet', $stored );
		$this->assertContains( 'noimageindex', $stored );
		$this->assertContains( 'noindex', $stored );
	}

	/**
	 * Setting noindex false replaces `noindex` with `index` rather than adding both.
	 *
	 * A list carrying both directives at once is a state the plugin's own screen
	 * cannot produce and its renderer does not resolve predictably.
	 */
	public function test_setting_noindex_false_swaps_the_directive_rather_than_adding_its_opposite(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noindex' ] );

		$this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => false ] );

		$this->assertSame( [ 'index' ], $this->rowsFor( 42, self::KEY_ROBOTS )[0] );
	}

	public function test_clearing_noindex_removes_both_directives_from_the_list(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'index', 'noarchive' ] );

		$this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] );

		$this->assertSame( [ 'noarchive' ], $this->rowsFor( 42, self::KEY_ROBOTS )[0] );
	}

	/**
	 * An emptied list deletes the row rather than storing an empty array.
	 */
	public function test_a_write_that_empties_the_list_deletes_the_row(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noindex' ] );

		$this->provider->apply( 42, [ SeoFields::FIELD_NOINDEX => null ] );

		$this->assertFalse( $this->hasMeta( 42, self::KEY_ROBOTS ) );
	}

	/**
	 * THE DECLARED LIMITATION. `nofollow: false` is promised as null, and reads back
	 * as null, so the plan and the verification agree.
	 *
	 * Asserted as projection AND read, because the point is not that the write fails
	 * — it succeeds — but that the PREVIEW already said what the caller would get.
	 */
	public function test_setting_nofollow_false_is_promised_as_null_and_reads_back_as_null(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'nofollow' ] );

		$this->assertNull( $this->provider->project( [ SeoFields::FIELD_NOFOLLOW => false ] )[ SeoFields::FIELD_NOFOLLOW ] );

		$this->assertTrue( $this->provider->apply( 42, [ SeoFields::FIELD_NOFOLLOW => false ] ) );

		$this->assertNull( $this->provider->values( 42 )[ SeoFields::FIELD_NOFOLLOW ] );
	}

	/**
	 * The noindex flag keeps its explicit negative; only nofollow loses one.
	 *
	 * Held next to the test above so a provider that declared the limitation for both
	 * flags — the easy over-correction — fails here.
	 */
	public function test_setting_noindex_false_is_still_promised_as_false(): void {
		$this->assertFalse( $this->provider->project( [ SeoFields::FIELD_NOINDEX => false ] )[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_the_projection_agrees_with_what_a_read_reports_afterwards(): void {
		$changes = [
			SeoFields::FIELD_TITLE    => '  Trimmed  ',
			SeoFields::FIELD_NOINDEX  => false,
			SeoFields::FIELD_NOFOLLOW => false,
		];

		$projected = $this->provider->project( $changes );

		$this->assertTrue( $this->provider->apply( 42, $changes ) );

		$values = $this->provider->values( 42 );

		foreach ( $projected as $field => $expected ) {
			$this->assertSame( $expected, $values[ $field ], "The projection for {$field} must match the read." );
		}
	}

	public function test_a_capture_records_which_plugin_took_it(): void {
		$this->assertSame( 'rank-math', $this->provider->capture( 42 )['provider'] );
	}

	/**
	 * A restore puts the directive list back exactly, garbage members and all.
	 *
	 * The snapshot is raw stored rows rather than the projected vocabulary, so a
	 * rollback returns the store to the state the plugin was actually in — including
	 * the parts this module cannot read.
	 */
	public function test_a_restore_puts_the_whole_directive_list_back(): void {
		$this->seedMeta( 42, self::KEY_ROBOTS, [ 'noarchive', 'index' ] );
		$snapshot = $this->provider->capture( 42 );

		$this->provider->apply(
			42,
			[
				SeoFields::FIELD_NOINDEX  => true,
				SeoFields::FIELD_NOFOLLOW => true,
			]
		);

		$this->assertTrue( $this->provider->restore( 42, $snapshot ) );
		$this->assertSame( [ 'noarchive', 'index' ], $this->rowsFor( 42, self::KEY_ROBOTS )[0] );
	}
}
