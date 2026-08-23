<?php
/**
 * REQ-0098: the SEO findings one post, or a page of posts, can carry.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Turns stored metadata and scores into a short list of named findings.
 *
 * A FINDING IS A CODE, NOT A SENTENCE. A client acting on a site-wide audit wants
 * to group and count, and a fixed vocabulary of kebab-case codes is what makes
 * `summary` in content-seo-audit addable across sites and runs. Every code is
 * listed by codes(), so a client can learn the vocabulary without triggering each
 * state.
 *
 * THE RULES ARE THE ONES BOTH PLUGINS AGREE ON and nothing more: a description
 * that is missing, too short or too long; a title override that is too long; no
 * focus keyword, or one that the title does not contain; a published post kept out
 * of search; and a stored score under the caller's floor. Neither plugin's own
 * analysis is re-run here — the scores are READ from what the plugin stored — so
 * the findings agree with what the editor shows rather than disagreeing with it
 * on a detail.
 *
 * THE BOUNDS ARE THE SHARED CONVENTION, not one plugin's: 70–160 characters for a
 * description and 60 for a title is where both plugins' traffic lights turn green,
 * and where search engines truncate in practice.
 *
 * @package SiteHelm
 */
final class SeoFindings {

	/** The score floor content-seo-audit and content-seo-score-get apply by default. */
	public const DEFAULT_MIN_SCORE = 70;

	/** A description shorter than this is too short. */
	public const DESCRIPTION_MIN = 70;

	/** A description longer than this is truncated in results. */
	public const DESCRIPTION_MAX = 160;

	/** A title override longer than this is truncated in results. */
	public const TITLE_MAX = 60;

	public const MISSING_DESCRIPTION        = 'missing-description';
	public const DESCRIPTION_TOO_SHORT      = 'description-too-short';
	public const DESCRIPTION_TOO_LONG       = 'description-too-long';
	public const TITLE_TOO_LONG             = 'title-too-long';
	public const MISSING_FOCUS_KEYWORD      = 'missing-focus-keyword';
	public const FOCUS_KEYWORD_NOT_IN_TITLE = 'focus-keyword-not-in-title';
	public const NOINDEX                    = 'noindex';
	public const LOW_SEO_SCORE              = 'low-seo-score';
	public const LOW_READABILITY_SCORE      = 'low-readability-score';
	public const DUPLICATE_TITLE            = 'duplicate-title';
	public const DUPLICATE_DESCRIPTION      = 'duplicate-description';

	/**
	 * Every finding code, in the order findings are reported.
	 *
	 * @return string[] The vocabulary.
	 */
	public static function codes(): array {
		return [
			self::MISSING_DESCRIPTION,
			self::DESCRIPTION_TOO_SHORT,
			self::DESCRIPTION_TOO_LONG,
			self::TITLE_TOO_LONG,
			self::MISSING_FOCUS_KEYWORD,
			self::FOCUS_KEYWORD_NOT_IN_TITLE,
			self::NOINDEX,
			self::LOW_SEO_SCORE,
			self::LOW_READABILITY_SCORE,
			self::DUPLICATE_TITLE,
			self::DUPLICATE_DESCRIPTION,
		];
	}

	/**
	 * The findings one post carries on its own.
	 *
	 * Duplicates are not decided here — they need the other posts — see duplicates().
	 *
	 * @param array<string, mixed> $values      The provider's values() for the post.
	 * @param array<string, mixed> $scores      The provider's scores() for the post.
	 * @param string               $post_title  The post's own title, used when no override is set.
	 * @param string               $post_status The post status; noindex only matters when published.
	 * @param int                  $min_score   The score floor.
	 *
	 * @return string[] Finding codes, in codes() order.
	 */
	public static function for_post( array $values, array $scores, string $post_title, string $post_status, int $min_score ): array {
		$findings    = [];
		$description = self::text( $values['description'] ?? null );
		$title       = self::text( $values['title'] ?? null );
		$keyword     = self::text( $values['focusKeyword'] ?? null );

		if ( null === $description ) {
			$findings[] = self::MISSING_DESCRIPTION;
		} elseif ( mb_strlen( $description ) < self::DESCRIPTION_MIN ) {
			$findings[] = self::DESCRIPTION_TOO_SHORT;
		} elseif ( mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			$findings[] = self::DESCRIPTION_TOO_LONG;
		}

		if ( null !== $title && mb_strlen( $title ) > self::TITLE_MAX ) {
			$findings[] = self::TITLE_TOO_LONG;
		}

		if ( null === $keyword ) {
			$findings[] = self::MISSING_FOCUS_KEYWORD;
		} elseif ( ! self::contains( $title ?? $post_title, $keyword ) ) {
			$findings[] = self::FOCUS_KEYWORD_NOT_IN_TITLE;
		}

		if ( true === ( $values['noindex'] ?? null ) && 'publish' === $post_status ) {
			$findings[] = self::NOINDEX;
		}

		if ( self::is_low( $scores['seoScore'] ?? null, $min_score ) ) {
			$findings[] = self::LOW_SEO_SCORE;
		}

		if ( self::is_low( $scores['readabilityScore'] ?? null, $min_score ) ) {
			$findings[] = self::LOW_READABILITY_SCORE;
		}

		return $findings;
	}

	/**
	 * The duplicate findings across a set of posts.
	 *
	 * Two posts sharing a title override or a description are each flagged; a post
	 * with no override is never a duplicate of another with none, because "the
	 * plugin decides" is not a value.
	 *
	 * @param array<int, array<string, mixed>> $values_by_post Post identifier => the provider's values().
	 *
	 * @return array<int, string[]> Post identifier => duplicate finding codes.
	 */
	public static function duplicates( array $values_by_post ): array {
		$out = [];

		$fields = [
			'title'       => self::DUPLICATE_TITLE,
			'description' => self::DUPLICATE_DESCRIPTION,
		];

		foreach ( $fields as $field => $code ) {
			$seen = [];

			foreach ( $values_by_post as $post_id => $values ) {
				$text = self::text( $values[ $field ] ?? null );

				if ( null !== $text ) {
					$seen[ mb_strtolower( $text ) ][] = (int) $post_id;
				}
			}

			foreach ( $seen as $ids ) {
				if ( count( $ids ) < 2 ) {
					continue;
				}

				foreach ( $ids as $id ) {
					$out[ $id ][] = $code;
				}
			}
		}

		return $out;
	}

	/**
	 * Trims a stored text to a value or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The trimmed text, or null when absent or blank.
	 */
	private static function text( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );

		return '' === $trimmed ? null : $trimmed;
	}

	/**
	 * Case-insensitive containment.
	 *
	 * @param string $haystack The text searched.
	 * @param string $needle   The text looked for.
	 *
	 * @return bool True when the needle appears.
	 */
	private static function contains( string $haystack, string $needle ): bool {
		return false !== mb_stripos( $haystack, $needle );
	}

	/**
	 * Whether a stored score exists and sits under the floor.
	 *
	 * @param mixed $score     The stored score, or null.
	 * @param int   $min_score The floor.
	 *
	 * @return bool True when scored and low.
	 */
	private static function is_low( mixed $score, int $min_score ): bool {
		return is_int( $score ) && $score < $min_score;
	}
}
