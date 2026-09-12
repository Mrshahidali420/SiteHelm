<?php
/**
 * A restore must survive the key sorting the snapshot store applies.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Modules\Seo\RankMathProvider;
use SiteHelm\Modules\Seo\RankMathTermProvider;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\YoastProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The snapshot a rollback reads back is NOT the array the capture handed over.
 *
 * A recorded state is stored as canonical JSON, and canonical JSON key-sorts
 * every associative array so that identical states always hash identically. A
 * provider builds its live reading in its own key order instead, and PHP's
 * `===` on two arrays wants the same keys in the same order. Every SEO restore
 * compared the two with `===`, so on any provider whose owned keys are not in
 * alphabetical order the comparison failed even when every value agreed. The
 * rollback had already put the values back correctly; it then reported itself
 * failed, and the engine's compensation undid the correct restore.
 *
 * Found on a staging site on 2026-09-12: an undo of `content-seo-set` against
 * Rank Math answered execution_failed and left the change in place.
 *
 * A unit test could not see it, because a test hands the snapshot straight back
 * to restore() in capture order. So these tests put the snapshot through the
 * same normalizer the store uses before handing it over.
 */
final class SeoSnapshotKeyOrderTest extends TestCase {

	use SeoTermWordPressStubs;
	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->installSeoTermStubs();
	}

	/**
	 * The round trip a snapshot actually makes: into canonical JSON and back.
	 *
	 * @param array<string, mixed> $snapshot The captured snapshot.
	 *
	 * @return array<string, mixed> The snapshot as a rollback receives it.
	 */
	private function asStored( array $snapshot ): array {
		$normalizer = new PayloadNormalizer();
		$decoded    = json_decode( $normalizer->canonicalJson( $snapshot ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Rank Math's owned keys are not alphabetical, so this is the case that broke.
	 */
	public function test_a_rank_math_post_restore_survives_the_stores_key_sorting(): void {
		$provider = new RankMathProvider();

		$provider->apply( 7, [ SeoFields::FIELD_TITLE => 'Before' ] );
		$snapshot = $this->asStored( $provider->capture( 7 ) );

		$provider->apply( 7, [ SeoFields::FIELD_DESCRIPTION => 'Added by the change' ] );

		$this->assertTrue(
			$provider->restore( 7, $snapshot ),
			'The values were put back, so the restore must report success.'
		);
		$this->assertSame( 'Before', $provider->values( 7 )[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $provider->values( 7 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * The same trip on the other post-meta provider shape.
	 */
	public function test_a_yoast_post_restore_survives_the_stores_key_sorting(): void {
		$provider = new YoastProvider();

		$provider->apply( 9, [ SeoFields::FIELD_TITLE => 'Before' ] );
		$snapshot = $this->asStored( $provider->capture( 9 ) );

		$provider->apply( 9, [ SeoFields::FIELD_DESCRIPTION => 'Added by the change' ] );

		$this->assertTrue( $provider->restore( 9, $snapshot ) );
		$this->assertSame( 'Before', $provider->values( 9 )[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $provider->values( 9 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * Terms take the same trip through the same store.
	 */
	public function test_a_rank_math_term_restore_survives_the_stores_key_sorting(): void {
		$provider = new RankMathTermProvider();

		$provider->apply( 'category', 4, [ SeoFields::FIELD_TITLE => 'Before' ] );
		$snapshot = $this->asStored( $provider->capture( 'category', 4 ) );

		$provider->apply( 'category', 4, [ SeoFields::FIELD_DESCRIPTION => 'Added by the change' ] );

		$this->assertTrue( $provider->restore( 'category', 4, $snapshot ) );
		$this->assertSame( 'Before', $provider->values( 'category', 4 )[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $provider->values( 'category', 4 )[ SeoFields::FIELD_DESCRIPTION ] );
	}

	/**
	 * Ignoring key order must not turn into ignoring a real difference.
	 *
	 * The comparison is asked directly here, because a restore writes the
	 * snapshot and then reads it back, so it can never disagree with itself.
	 *
	 * @dataProvider mismatchProvider
	 *
	 * @param array<string, mixed> $current  What the store holds.
	 * @param array<string, mixed> $recorded What the snapshot recorded.
	 * @param string               $why      What the difference is.
	 */
	public function test_a_real_difference_is_still_a_mismatch( array $current, array $recorded, string $why ): void {
		$this->assertFalse( $this->comparison()->matches( $current, $recorded ), $why );
	}

	/**
	 * Differences that must not be forgiven.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, string}> The cases.
	 */
	public static function mismatchProvider(): array {
		return [
			'a different value'    => [ [ 'a' => [ 'one' ] ], [ 'a' => [ 'two' ] ], 'The values differ.' ],
			'a different type'     => [ [ 'a' => [ '0' ] ], [ 'a' => [ 0 ] ], 'A string and a number are not the same stored value.' ],
			'a key only the store has' => [ [ 'a' => [], 'b' => [] ], [ 'a' => [] ], 'The store carries a key the snapshot did not.' ],
			'a key only the snapshot has' => [ [ 'a' => [] ], [ 'a' => [], 'b' => [] ], 'The snapshot carries a key the store did not.' ],
			'rows in another order' => [ [ 'a' => [ 'one', 'two' ] ], [ 'a' => [ 'two', 'one' ] ], 'A list of rows keeps its order.' ],
		];
	}

	/**
	 * Key order alone is forgiven.
	 */
	public function test_key_order_alone_is_not_a_mismatch(): void {
		$this->assertTrue(
			$this->comparison()->matches(
				[ 'title' => [ 'A' ], 'canonical' => [ 'B' ] ],
				[ 'canonical' => [ 'B' ], 'title' => [ 'A' ] ]
			)
		);
	}

	/**
	 * The trait under test, reachable from outside.
	 *
	 * @return object An object exposing the comparison.
	 */
	private function comparison(): object {
		return new class() {
			use \SiteHelm\Modules\Seo\SeoStoreComparison;

			/**
			 * Asks the comparison.
			 *
			 * @param mixed $current  What the store holds.
			 * @param mixed $recorded What the snapshot recorded.
			 *
			 * @return bool True when the two say the same thing.
			 */
			public function matches( mixed $current, mixed $recorded ): bool {
				return $this->storeMatches( $current, $recorded );
			}
		};
	}
}
