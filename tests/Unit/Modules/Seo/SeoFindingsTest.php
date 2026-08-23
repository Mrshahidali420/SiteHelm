<?php
/**
 * Tests for SeoFindings.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFindings;
use SiteHelm\Tests\TestCase;

/**
 * Each rule is asserted in a state where only it fires, and the duplicate rule
 * on a set where "no override" and "the same override" are both present.
 */
final class SeoFindingsTest extends TestCase {

	/**
	 * A post whose metadata is in good standing, to isolate one rule at a time.
	 *
	 * @return array<string, mixed> Values.
	 */
	private function goodValues(): array {
		return [
			'title'        => 'Blue widgets',
			'description'  => str_repeat( 'a', 100 ),
			'focusKeyword' => 'widgets',
			'noindex'      => null,
		];
	}

	/**
	 * @return array<string, int|null> Scores above the floor.
	 */
	private function goodScores(): array {
		return [
			'seoScore'         => 80,
			'readabilityScore' => 90,
		];
	}

	public function test_a_healthy_post_carries_no_findings(): void {
		$this->assertSame( [], SeoFindings::for_post( $this->goodValues(), $this->goodScores(), 'Blue widgets', 'publish', 70 ) );
	}

	public function test_every_code_is_listed_once(): void {
		$codes = SeoFindings::codes();

		$this->assertCount( 11, $codes );
		$this->assertSame( $codes, array_values( array_unique( $codes ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function descriptionCases(): array {
		return [
			'absent'      => [ [ 'description' => null ], SeoFindings::MISSING_DESCRIPTION ],
			'blank'       => [ [ 'description' => '   ' ], SeoFindings::MISSING_DESCRIPTION ],
			'too short'   => [ [ 'description' => str_repeat( 'a', 69 ) ], SeoFindings::DESCRIPTION_TOO_SHORT ],
			'too long'    => [ [ 'description' => str_repeat( 'a', 161 ) ], SeoFindings::DESCRIPTION_TOO_LONG ],
			'title long'  => [ [ 'title' => str_repeat( 'widgets ', 8 ) ], SeoFindings::TITLE_TOO_LONG ],
			'no keyword'  => [ [ 'focusKeyword' => null ], SeoFindings::MISSING_FOCUS_KEYWORD ],
			'kw missing'  => [ [ 'focusKeyword' => 'gadgets' ], SeoFindings::FOCUS_KEYWORD_NOT_IN_TITLE ],
			'noindex'     => [ [ 'noindex' => true ], SeoFindings::NOINDEX ],
		];
	}

	/**
	 * @dataProvider descriptionCases
	 *
	 * @param array<string, mixed> $override The values that differ from the healthy post.
	 * @param string               $code     The one finding expected.
	 */
	public function test_each_metadata_rule_fires_alone( array $override, string $code ): void {
		$values = array_merge( $this->goodValues(), $override );

		$this->assertSame( [ $code ], SeoFindings::for_post( $values, $this->goodScores(), 'Blue widgets', 'publish', 70 ) );
	}

	public function test_the_bounds_are_inclusive(): void {
		$values = array_merge( $this->goodValues(), [ 'description' => str_repeat( 'a', 70 ), 'title' => str_repeat( 'w', 60 ) ] );
		$this->assertSame( [ SeoFindings::FOCUS_KEYWORD_NOT_IN_TITLE ], SeoFindings::for_post( $values, $this->goodScores(), 'x', 'publish', 70 ) );

		$values['description'] = str_repeat( 'a', 160 );
		$values['title']       = null;
		$this->assertSame( [], SeoFindings::for_post( $values, $this->goodScores(), 'Blue widgets', 'publish', 70 ) );
	}

	public function test_the_keyword_is_matched_against_the_post_title_when_no_override_is_set(): void {
		$values = array_merge( $this->goodValues(), [ 'title' => null ] );

		$this->assertSame( [], SeoFindings::for_post( $values, $this->goodScores(), 'Our WIDGETS page', 'publish', 70 ) );
		$this->assertSame( [ SeoFindings::FOCUS_KEYWORD_NOT_IN_TITLE ], SeoFindings::for_post( $values, $this->goodScores(), 'Our gadgets page', 'publish', 70 ) );
	}

	public function test_noindex_is_only_a_finding_on_a_published_post(): void {
		$values = array_merge( $this->goodValues(), [ 'noindex' => true ] );

		$this->assertSame( [], SeoFindings::for_post( $values, $this->goodScores(), 'Blue widgets', 'draft', 70 ) );
	}

	public function test_scores_under_the_floor_are_findings_and_unscored_is_not(): void {
		$low = [
			'seoScore'         => 69,
			'readabilityScore' => 10,
		];
		$this->assertSame(
			[ SeoFindings::LOW_SEO_SCORE, SeoFindings::LOW_READABILITY_SCORE ],
			SeoFindings::for_post( $this->goodValues(), $low, 'Blue widgets', 'publish', 70 )
		);
		$this->assertSame( [], SeoFindings::for_post( $this->goodValues(), $low, 'Blue widgets', 'publish', 10 ) );

		$unscored = [
			'seoScore'         => null,
			'readabilityScore' => null,
		];
		$this->assertSame( [], SeoFindings::for_post( $this->goodValues(), $unscored, 'Blue widgets', 'publish', 70 ) );
	}

	public function test_duplicates_flag_every_sharer_and_ignore_posts_with_no_override(): void {
		$by_post = [
			1 => [
				'title'       => 'Same Title',
				'description' => 'One',
			],
			2 => [
				'title'       => 'same title',
				'description' => 'Two',
			],
			3 => [
				'title'       => null,
				'description' => 'two',
			],
			4 => [
				'title'       => null,
				'description' => null,
			],
			5 => [
				'title'       => null,
				'description' => null,
			],
		];

		$this->assertSame(
			[
				1 => [ SeoFindings::DUPLICATE_TITLE ],
				2 => [ SeoFindings::DUPLICATE_TITLE, SeoFindings::DUPLICATE_DESCRIPTION ],
				3 => [ SeoFindings::DUPLICATE_DESCRIPTION ],
			],
			SeoFindings::duplicates( $by_post )
		);
	}
}
