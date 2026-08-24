<?php
/**
 * The SEOPress store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the post meta SEOPress stores its per-post settings in.
 *
 * NOTHING HERE CALLS A PLUGIN FUNCTION OR NAMES A PLUGIN CLASS — every value is
 * a meta key, and the only plugin symbol the module touches is the version
 * constant, confined to SeoPresence.
 *
 * THE ROBOTS KEYS ARE NAMED FOR THE DIRECTIVE THEY SWITCH ON, NOT FOR ITS
 * MEANING: `_seopress_robots_index` holding 'yes' means NOINDEX, and
 * `_seopress_robots_follow` holding 'yes' means NOFOLLOW. There is no stored
 * "explicitly off" value — an unchecked box deletes the row — so neither flag
 * can hold an explicit false, and clearing is the only negative this store can
 * express. That is the Rank Math nofollow pattern applied to both flags, and it
 * is why storesExplicitNegative() answers false for each.
 *
 * @package SiteHelm
 */
final class SeoPressProvider extends SeoMetaProvider {

	/**
	 * The stored value meaning "this directive is on".
	 */
	private const ON = 'yes';

	/**
	 * The meta key whose 'yes' means noindex.
	 */
	private const KEY_NOINDEX = '_seopress_robots_index';

	/**
	 * The meta key whose 'yes' means nofollow.
	 */
	private const KEY_NOFOLLOW = '_seopress_robots_follow';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'seopress';
	}

	/**
	 * The meta key holding each writable text field.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function textKeys(): array {
		return [
			SeoFields::FIELD_TITLE               => '_seopress_titles_title',
			SeoFields::FIELD_DESCRIPTION         => '_seopress_titles_desc',
			SeoFields::FIELD_CANONICAL           => '_seopress_robots_canonical',
			SeoFields::FIELD_FOCUS_KEYWORD       => '_seopress_analysis_target_kw',
			SeoFields::FIELD_OG_TITLE            => '_seopress_social_fb_title',
			SeoFields::FIELD_OG_DESCRIPTION      => '_seopress_social_fb_desc',
			SeoFields::FIELD_TWITTER_TITLE       => '_seopress_social_twitter_title',
			SeoFields::FIELD_TWITTER_DESCRIPTION => '_seopress_social_twitter_desc',
		];
	}

	/**
	 * The meta key holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function imageKeys(): array {
		return [
			SeoFields::FIELD_OG_IMAGE      => '_seopress_social_fb_img',
			SeoFields::FIELD_TWITTER_IMAGE => '_seopress_social_twitter_img',
		];
	}

	/**
	 * SEOPress keeps its content analysis out of plain post meta, so no score is
	 * read: reporting a number the plugin's own screen does not show would be a
	 * guess, and null is the documented answer for "the plugin has no such score".
	 *
	 * @return array{seoScore: string|null, readabilityScore: string|null} Score name => meta key.
	 */
	protected function scoreKeys(): array {
		return [
			'seoScore'         => null,
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
				self::KEY_NOINDEX,
				self::KEY_NOFOLLOW,
			]
		);
	}

	/**
	 * Neither flag has a stored "explicitly off" value.
	 *
	 * @param string $flag The flag field name.
	 */
	protected function storesExplicitNegative( string $flag ): bool {
		unset( $flag );

		return false;
	}

	/**
	 * SEOPress's stored answer for the two robots flags.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, bool|null> Flag field name => true or null.
	 */
	protected function readFlags( int $post_id ): array {
		return [
			SeoFields::FIELD_NOINDEX  => $this->flag( $post_id, self::KEY_NOINDEX ),
			SeoFields::FIELD_NOFOLLOW => $this->flag( $post_id, self::KEY_NOFOLLOW ),
		];
	}

	/**
	 * Writes the named robots flags in SEOPress's encoding.
	 *
	 * True stores 'yes'; anything else deletes the row, because the store has no
	 * other value — false and "the plugin decides" are the same absent row here,
	 * which project() already folded into null before the write was planned.
	 *
	 * @param int                      $post_id The post identifier.
	 * @param array<string, bool|null> $flags   Flag field name => new value.
	 */
	protected function writeFlags( int $post_id, array $flags ): void {
		$keys = [
			SeoFields::FIELD_NOINDEX  => self::KEY_NOINDEX,
			SeoFields::FIELD_NOFOLLOW => self::KEY_NOFOLLOW,
		];

		foreach ( $flags as $field => $value ) {
			if ( ! isset( $keys[ $field ] ) ) {
				continue;
			}

			if ( true === $value ) {
				update_post_meta( $post_id, $keys[ $field ], self::ON );

				continue;
			}

			delete_post_meta( $post_id, $keys[ $field ] );
		}
	}

	/**
	 * One robots flag, projected from its stored word.
	 *
	 * Guarded on shape rather than cast: this is post meta, and a value written
	 * by an importer can be an array.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return bool|null True, or null for "the plugin decides".
	 */
	private function flag( int $post_id, string $key ): ?bool {
		$stored = get_post_meta( $post_id, $key, true );

		if ( ! is_string( $stored ) ) {
			return null;
		}

		return self::ON === trim( $stored ) ? true : null;
	}
}
