<?php
/**
 * The Slim SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the single meta array Slim SEO stores its per-post settings in.
 *
 * ONE KEY HOLDS EVERYTHING: `slim_seo` is a serialized array whose sub-keys are
 * the plugin's whole per-post vocabulary — `title`, `description`, `noindex`,
 * and the two social images. The plugin is minimal by design, so most of
 * SiteHelm's fields are declined: no canonical override, no focus keyword, no
 * per-card social text, and no nofollow. A declined field reads as null and
 * projects to null, which is the honest answer for a plugin that has nowhere
 * to put the value.
 *
 * @package SiteHelm
 */
final class SlimSeoProvider extends SeoArrayMetaProvider {

	/**
	 * The one meta key the plugin stores its per-post array under.
	 */
	private const KEY = 'slim_seo';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'slim-seo';
	}

	/**
	 * The path holding each writable text field.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	protected function textPaths(): array {
		return [
			SeoFields::FIELD_TITLE       => [ self::KEY, 'title' ],
			SeoFields::FIELD_DESCRIPTION => [ self::KEY, 'description' ],
		];
	}

	/**
	 * The path holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	protected function imagePaths(): array {
		return [
			SeoFields::FIELD_OG_IMAGE      => [ self::KEY, 'facebook_image' ],
			SeoFields::FIELD_TWITTER_IMAGE => [ self::KEY, 'twitter_image' ],
		];
	}

	/**
	 * The plugin stores noindex only; nofollow is declined.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Flag field name => [meta key, sub-key].
	 */
	protected function flagPaths(): array {
		return [
			SeoFields::FIELD_NOINDEX => [ self::KEY, 'noindex' ],
		];
	}

	/**
	 * Every meta key this provider owns.
	 *
	 * @return string[] Meta keys.
	 */
	protected function ownedKeys(): array {
		return [ self::KEY ];
	}

	/**
	 * The plugin coerces the stored entry with a truthy check, so a boolean
	 * true and its common scalar spellings all mean "hidden from search".
	 * Anything else — absent included — leaves the plugin deciding.
	 *
	 * @param mixed $stored The raw stored value, or null when absent.
	 *
	 * @return bool|null True, or null for "the plugin decides".
	 */
	protected function flagFromStored( mixed $stored ): ?bool {
		if ( true === $stored || 1 === $stored || '1' === $stored ) {
			return true;
		}

		return null;
	}

	/**
	 * The sub-key survives serialization with its PHP type, so true is stored
	 * as the boolean the plugin's own reader defaults from.
	 *
	 * @return mixed The stored representation of true.
	 */
	protected function storedFlag(): mixed {
		return true;
	}
}
