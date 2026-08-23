<?php
/**
 * The Rank Math store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the post meta Rank Math stores its per-post settings in.
 *
 * Like YoastProvider, nothing here calls a plugin function or names a plugin
 * class; the keys are the contract.
 *
 * THE ROBOTS ARRAY IS THE REASON THIS MODULE HAS A PROVIDER LAYER AT ALL. Rank
 * Math keeps every robots directive for a post in ONE meta value holding a list —
 * `[ 'noindex', 'nofollow', 'noarchive' ]` — where Yoast keeps two independent
 * numbers. Two consequences shape the code below:
 *
 * 1. A WRITE MUST MERGE, NEVER REPLACE. The list holds directives this module does
 *    not address: `noarchive`, `nosnippet`, `noimageindex`, `max-snippet` and
 *    others the plugin's advanced panel offers. Storing a freshly built two-member
 *    list would silently delete every one of them — a change to the noindex flag
 *    that also re-enables archiving, with nothing in the plan saying so.
 *
 * 2. THERE IS NO `follow` DIRECTIVE. `index` exists as the explicit opposite of
 *    `noindex`, so `noindex: false` is storable; nothing is the opposite of
 *    `nofollow`, so `nofollow: false` can only mean "remove it" and reads back as
 *    null. That is declared through storesExplicitNegative() so the plan says so
 *    before the write runs, rather than the verification discovering it after.
 *
 * @package SiteHelm
 */
final class RankMathProvider extends SeoMetaProvider {

	/**
	 * The meta key holding the robots directive list.
	 */
	private const KEY_ROBOTS = 'rank_math_robots';

	/**
	 * The directive meaning "do not index".
	 */
	private const NOINDEX = 'noindex';

	/**
	 * The directive meaning "index", the explicit opposite of NOINDEX.
	 */
	private const INDEX = 'index';

	/**
	 * The directive meaning "do not follow this page's links".
	 */
	private const NOFOLLOW = 'nofollow';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'rank-math';
	}

	/**
	 * The meta key holding each writable text field.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function textKeys(): array {
		return [
			SeoFields::FIELD_TITLE               => 'rank_math_title',
			SeoFields::FIELD_DESCRIPTION         => 'rank_math_description',
			SeoFields::FIELD_CANONICAL           => 'rank_math_canonical_url',
			SeoFields::FIELD_FOCUS_KEYWORD       => 'rank_math_focus_keyword',
			SeoFields::FIELD_OG_TITLE            => 'rank_math_facebook_title',
			SeoFields::FIELD_OG_DESCRIPTION      => 'rank_math_facebook_description',
			SeoFields::FIELD_TWITTER_TITLE       => 'rank_math_twitter_title',
			SeoFields::FIELD_TWITTER_DESCRIPTION => 'rank_math_twitter_description',
		];
	}

	/**
	 * The meta key holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function imageKeys(): array {
		return [
			SeoFields::FIELD_OG_IMAGE      => 'rank_math_facebook_image',
			SeoFields::FIELD_TWITTER_IMAGE => 'rank_math_twitter_image',
		];
	}

	/**
	 * Rank Math keeps one SEO score and folds readability into it.
	 *
	 * @return array{seoScore: string|null, readabilityScore: string|null} Score name => meta key.
	 */
	protected function scoreKeys(): array {
		return [
			'seoScore'         => 'rank_math_seo_score',
			'readabilityScore' => null,
		];
	}

	/**
	 * Every meta key this provider owns.
	 *
	 * @return string[] Meta keys.
	 */
	protected function ownedKeys(): array {
		return array_merge(
			array_values( $this->textKeys() ),
			array_values( $this->imageKeys() ),
			[
				'rank_math_facebook_image_id',
				'rank_math_twitter_image_id',
				self::KEY_ROBOTS,
			]
		);
	}

	/**
	 * Rank Math cannot store an explicit "follow".
	 *
	 * @param string $flag The flag field name.
	 *
	 * @return bool True when `false` is storable as a distinct value.
	 */
	protected function storesExplicitNegative( string $flag ): bool {
		return SeoFields::FIELD_NOFOLLOW !== $flag;
	}

	/**
	 * Rank Math's stored answer for the two robots flags.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, bool|null> Flag field name => true, false, or null.
	 */
	protected function readFlags( int $post_id ): array {
		$directives = $this->directives( $post_id );

		$noindex = null;
		if ( in_array( self::NOINDEX, $directives, true ) ) {
			$noindex = true;
		} elseif ( in_array( self::INDEX, $directives, true ) ) {
			$noindex = false;
		}

		return [
			SeoFields::FIELD_NOINDEX  => $noindex,
			SeoFields::FIELD_NOFOLLOW => in_array( self::NOFOLLOW, $directives, true ) ? true : null,
		];
	}

	/**
	 * Writes the named robots flags into the directive list.
	 *
	 * The list is read, edited, and written back, so directives this module does
	 * not address survive the change.
	 *
	 * @param int                      $post_id The post identifier.
	 * @param array<string, bool|null> $flags   Flag field name => new value.
	 */
	protected function writeFlags( int $post_id, array $flags ): void {
		$directives = $this->directives( $post_id );

		if ( array_key_exists( SeoFields::FIELD_NOINDEX, $flags ) ) {
			$directives = $this->without( $directives, [ self::NOINDEX, self::INDEX ] );
			$value      = $flags[ SeoFields::FIELD_NOINDEX ];

			if ( true === $value ) {
				$directives[] = self::NOINDEX;
			} elseif ( false === $value ) {
				$directives[] = self::INDEX;
			}
		}

		if ( array_key_exists( SeoFields::FIELD_NOFOLLOW, $flags ) ) {
			$directives = $this->without( $directives, [ self::NOFOLLOW ] );

			if ( true === $flags[ SeoFields::FIELD_NOFOLLOW ] ) {
				$directives[] = self::NOFOLLOW;
			}
		}

		if ( [] === $directives ) {
			delete_post_meta( $post_id, self::KEY_ROBOTS );

			return;
		}

		update_post_meta( $post_id, self::KEY_ROBOTS, array_values( $directives ) );
	}

	/**
	 * The stored directive list, cleaned.
	 *
	 * Rank Math stores an array, but this is post meta: an importer, a migration,
	 * or a hand-edited row can leave a string or a list with non-string members
	 * here. Anything that is not a list of strings is treated as no directives,
	 * which is the same answer an absent row gives — the safe direction, because
	 * the alternative is a write that builds its new list out of garbage.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string[] The directives.
	 */
	private function directives( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::KEY_ROBOTS, true );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$clean = [];

		foreach ( $stored as $directive ) {
			if ( is_string( $directive ) && '' !== trim( $directive ) ) {
				$clean[] = trim( $directive );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The directive list with the named directives removed.
	 *
	 * @param string[] $directives The current directives.
	 * @param string[] $remove     The directives to drop.
	 *
	 * @return string[] The remaining directives, re-indexed.
	 */
	private function without( array $directives, array $remove ): array {
		return array_values(
			array_filter(
				$directives,
				static fn( string $directive ): bool => ! in_array( $directive, $remove, true )
			)
		);
	}
}
