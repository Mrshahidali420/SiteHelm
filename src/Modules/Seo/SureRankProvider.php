<?php
/**
 * The SureRank store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the grouped meta arrays SureRank stores its per-post settings in.
 *
 * THE STORE IS A FAMILY OF PREFIXED KEYS, one per settings group rather than
 * one blob: `surerank_settings_general` is an array holding the title,
 * description, canonical URL and focus keyword; `surerank_settings_social`
 * holds the per-card social text and images; and each robots directive is its
 * own scalar row (`surerank_settings_post_no_index`, `_post_no_follow`). The
 * general keys are the plugin's global vocabulary reused per post, which is why
 * the title sub-key is `page_title` rather than `title`.
 *
 * THE FLAG ROWS DEFAULT TO AN EMPTY STRING meaning "inherit the site rule",
 * and the plugin's own sanitizer accepts several spellings of true. This module
 * reads the true-spellings as true, emptiness as null, and any other stored
 * value as false; writing stores '1' — inside the sanitizer's true set — and
 * clearing deletes the row, because an explicit stored negative and the
 * inherited default are not reliably distinct in this store.
 *
 * @package SiteHelm
 */
final class SureRankProvider extends SeoArrayMetaProvider {

	/**
	 * The meta key holding the general settings group.
	 */
	private const KEY_GENERAL = 'surerank_settings_general';

	/**
	 * The meta key holding the social settings group.
	 */
	private const KEY_SOCIAL = 'surerank_settings_social';

	/**
	 * The meta key holding the noindex directive as a whole row.
	 */
	private const KEY_NOINDEX = 'surerank_settings_post_no_index';

	/**
	 * The meta key holding the nofollow directive as a whole row.
	 */
	private const KEY_NOFOLLOW = 'surerank_settings_post_no_follow';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'surerank';
	}

	/**
	 * The path holding each writable text field.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	protected function textPaths(): array {
		return [
			SeoFields::FIELD_TITLE               => [ self::KEY_GENERAL, 'page_title' ],
			SeoFields::FIELD_DESCRIPTION         => [ self::KEY_GENERAL, 'page_description' ],
			SeoFields::FIELD_CANONICAL           => [ self::KEY_GENERAL, 'canonical_url' ],
			SeoFields::FIELD_FOCUS_KEYWORD       => [ self::KEY_GENERAL, 'focus_keyword' ],
			SeoFields::FIELD_OG_TITLE            => [ self::KEY_SOCIAL, 'facebook_title' ],
			SeoFields::FIELD_OG_DESCRIPTION      => [ self::KEY_SOCIAL, 'facebook_description' ],
			SeoFields::FIELD_TWITTER_TITLE       => [ self::KEY_SOCIAL, 'twitter_title' ],
			SeoFields::FIELD_TWITTER_DESCRIPTION => [ self::KEY_SOCIAL, 'twitter_description' ],
		];
	}

	/**
	 * The path holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	protected function imagePaths(): array {
		return [
			SeoFields::FIELD_OG_IMAGE      => [ self::KEY_SOCIAL, 'facebook_image_url' ],
			SeoFields::FIELD_TWITTER_IMAGE => [ self::KEY_SOCIAL, 'twitter_image_url' ],
		];
	}

	/**
	 * Both robots directives are stored, each as its own scalar row.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Flag field name => [meta key, sub-key].
	 */
	protected function flagPaths(): array {
		return [
			SeoFields::FIELD_NOINDEX  => [ self::KEY_NOINDEX, null ],
			SeoFields::FIELD_NOFOLLOW => [ self::KEY_NOFOLLOW, null ],
		];
	}

	/**
	 * Every meta key this provider owns.
	 *
	 * @return string[] Meta keys.
	 */
	protected function ownedKeys(): array {
		return [
			self::KEY_GENERAL,
			self::KEY_SOCIAL,
			self::KEY_NOINDEX,
			self::KEY_NOFOLLOW,
		];
	}

	/**
	 * One directive's meaning, in the plugin's own boolean spellings.
	 *
	 * The sanitizer's true set reads as true; an empty row or an absent one is
	 * the inherited default, null; any other stored value is an explicit no.
	 *
	 * @param mixed $stored The raw stored value, or null when absent.
	 *
	 * @return bool|null True, false, or null for "the plugin decides".
	 */
	protected function flagFromStored( mixed $stored ): ?bool {
		if ( true === $stored || 1 === $stored || '1' === $stored || 'true' === $stored ) {
			return true;
		}

		if ( null === $stored || '' === $stored ) {
			return null;
		}

		return is_scalar( $stored ) ? false : null;
	}

	/**
	 * The stored spelling of "on", inside the plugin's accepted true set.
	 *
	 * @return mixed The stored representation of true.
	 */
	protected function storedFlag(): mixed {
		return '1';
	}
}
