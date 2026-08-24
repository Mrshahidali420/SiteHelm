<?php
/**
 * The SEO Framework store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the post meta The SEO Framework stores its per-post settings in.
 *
 * THE KEY NAMES SAY GENESIS AND MEAN THE SEO FRAMEWORK: the plugin adopted the
 * Genesis theme's meta keys for compatibility and has kept them ever since, so
 * `_genesis_title` here is The SEO Framework's title override, not a theme's.
 * The open graph and twitter keys are the plugin's own, without the prefix.
 *
 * TWO FIELDS ARE DECLINED because the plugin does not store them: the focus
 * keyword (its analysis is not persisted as a field), and a separate twitter
 * image (one `_social_image_url` serves both cards, reported as ogImage).
 * Declined fields read as null and project to null, so a plan says "this
 * plugin cannot hold that" instead of promising a value verification would
 * never find.
 *
 * THE FLAGS ARE A CHECKBOX, NOT A TRI-STATE: `_genesis_noindex` and
 * `_genesis_nofollow` store 1 when checked and 0 (or nothing) when not, and
 * the plugin's default meta writes the 0 itself. A stored 0 and an absent row
 * both mean "the plugin decides", so both read as null, and clearing deletes
 * the row rather than writing a number.
 *
 * @package SiteHelm
 */
final class SeoFrameworkProvider extends SeoMetaProvider {

	/**
	 * The stored value meaning "this directive is on".
	 */
	private const ON = '1';

	/**
	 * The meta key holding the noindex checkbox.
	 */
	private const KEY_NOINDEX = '_genesis_noindex';

	/**
	 * The meta key holding the nofollow checkbox.
	 */
	private const KEY_NOFOLLOW = '_genesis_nofollow';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'seo-framework';
	}

	/**
	 * The meta key holding each writable text field.
	 *
	 * The focus keyword is absent deliberately: the plugin stores no such field,
	 * and an unmapped field is how a SeoMetaProvider declines one.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function textKeys(): array {
		return [
			SeoFields::FIELD_TITLE               => '_genesis_title',
			SeoFields::FIELD_DESCRIPTION         => '_genesis_description',
			SeoFields::FIELD_CANONICAL           => '_genesis_canonical_uri',
			SeoFields::FIELD_OG_TITLE            => '_open_graph_title',
			SeoFields::FIELD_OG_DESCRIPTION      => '_open_graph_description',
			SeoFields::FIELD_TWITTER_TITLE       => '_twitter_title',
			SeoFields::FIELD_TWITTER_DESCRIPTION => '_twitter_description',
		];
	}

	/**
	 * The meta key holding each reported-but-not-writable image URL.
	 *
	 * One stored image serves both cards; it is reported as ogImage and the
	 * twitterImage field is declined rather than reported twice.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function imageKeys(): array {
		return [
			SeoFields::FIELD_OG_IMAGE => '_social_image_url',
		];
	}

	/**
	 * The plugin persists no analysis score, so both answers are null.
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
	 * `_social_image_id` is listed although no field projects it, because a
	 * snapshot has to be able to put back the pair the plugin renders from: a
	 * rollback that restored the image URL and left a stale id behind would
	 * leave a combination the plugin's own screen cannot produce.
	 *
	 * @return string[] Meta keys.
	 */
	protected function ownedKeys(): array {
		return array_merge(
			array_values( $this->textKeys() ),
			array_values( $this->imageKeys() ),
			[
				'_social_image_id',
				self::KEY_NOINDEX,
				self::KEY_NOFOLLOW,
			]
		);
	}

	/**
	 * A checkbox has no stored "explicitly off" distinct from its default.
	 *
	 * @param string $flag The flag field name.
	 */
	protected function storesExplicitNegative( string $flag ): bool {
		unset( $flag );

		return false;
	}

	/**
	 * The plugin's stored answer for the two robots flags.
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
	 * Writes the named robots flags in the plugin's encoding.
	 *
	 * True stores '1'; anything else deletes the row. The plugin's own default
	 * meta writes 0 for unchecked, but 0 and absent render identically and only
	 * the absent row is honest about never having been set by anything.
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
	 * One robots flag, projected from its stored number.
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

		if ( ! is_string( $stored ) && ! is_int( $stored ) ) {
			return null;
		}

		return self::ON === trim( (string) $stored ) ? true : null;
	}
}
