<?php
/**
 * The Yoast SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the post meta Yoast SEO stores its per-post settings in.
 *
 * NOTHING HERE CALLS A PLUGIN FUNCTION OR NAMES A PLUGIN CLASS. Every value is a
 * meta key, which is a stored contract rather than a code contract: it survives
 * the plugin being deactivated, and it does not move when the plugin's internals
 * are rewritten. The only plugin symbol this module touches at all is the version
 * constant, and that is confined to SeoPresence.
 *
 * THE ROBOTS ENCODING IS THE PART WORTH READING. Yoast keeps two independent
 * numeric values rather than a directive list:
 *
 *   `_yoast_wpseo_meta-robots-noindex`  '1' noindex · '2' index · anything else,
 *                                       including absent, means "use the site's
 *                                       setting for this post type".
 *   `_yoast_wpseo_meta-robots-nofollow` '1' nofollow · '0' follow · absent means
 *                                       the same as '0' in Yoast's own rendering.
 *
 * Both therefore carry a real third state, which is why this module reports the
 * flags as true, false, or null rather than as booleans. Clearing a flag deletes
 * the row instead of writing the "default" number: an absent row and a stored '0'
 * render identically, and only the absent row is honest about never having been
 * set by anything.
 *
 * @package SiteHelm
 */
final class YoastProvider extends SeoMetaProvider {

	/**
	 * The stored value meaning "noindex", and separately "nofollow".
	 */
	private const ON = '1';

	/**
	 * The stored value meaning "index".
	 */
	private const INDEX = '2';

	/**
	 * The stored value meaning "follow".
	 */
	private const FOLLOW = '0';

	/**
	 * The meta key holding the noindex directive.
	 */
	private const KEY_NOINDEX = '_yoast_wpseo_meta-robots-noindex';

	/**
	 * The meta key holding the nofollow directive.
	 */
	private const KEY_NOFOLLOW = '_yoast_wpseo_meta-robots-nofollow';

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'yoast-seo';
	}

	/**
	 * The meta key holding each writable text field.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function textKeys(): array {
		return [
			SeoFields::FIELD_TITLE               => '_yoast_wpseo_title',
			SeoFields::FIELD_DESCRIPTION         => '_yoast_wpseo_metadesc',
			SeoFields::FIELD_CANONICAL           => '_yoast_wpseo_canonical',
			SeoFields::FIELD_FOCUS_KEYWORD       => '_yoast_wpseo_focuskw',
			SeoFields::FIELD_OG_TITLE            => '_yoast_wpseo_opengraph-title',
			SeoFields::FIELD_OG_DESCRIPTION      => '_yoast_wpseo_opengraph-description',
			SeoFields::FIELD_TWITTER_TITLE       => '_yoast_wpseo_twitter-title',
			SeoFields::FIELD_TWITTER_DESCRIPTION => '_yoast_wpseo_twitter-description',
		];
	}

	/**
	 * The meta key holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	protected function imageKeys(): array {
		return [
			SeoFields::FIELD_OG_IMAGE      => '_yoast_wpseo_opengraph-image',
			SeoFields::FIELD_TWITTER_IMAGE => '_yoast_wpseo_twitter-image',
		];
	}

	/**
	 * Every meta key this provider owns.
	 *
	 * The two `-image-id` keys are listed although no field projects them, because
	 * a snapshot has to be able to put back the pair the plugin renders from. A
	 * rollback that restored an image URL and left a stale id behind would leave a
	 * combination the plugin's own screen cannot produce.
	 *
	 * @return string[] Meta keys.
	 */
	protected function ownedKeys(): array {
		return array_merge(
			array_values( $this->textKeys() ),
			array_values( $this->imageKeys() ),
			[
				'_yoast_wpseo_opengraph-image-id',
				'_yoast_wpseo_twitter-image-id',
				self::KEY_NOINDEX,
				self::KEY_NOFOLLOW,
			]
		);
	}

	/**
	 * Yoast's stored answer for the two robots flags.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, bool|null> Flag field name => true, false, or null.
	 */
	protected function readFlags( int $post_id ): array {
		return [
			SeoFields::FIELD_NOINDEX  => $this->flag( $post_id, self::KEY_NOINDEX, self::INDEX ),
			SeoFields::FIELD_NOFOLLOW => $this->flag( $post_id, self::KEY_NOFOLLOW, self::FOLLOW ),
		];
	}

	/**
	 * Writes the named robots flags in Yoast's encoding.
	 *
	 * @param int                      $post_id The post identifier.
	 * @param array<string, bool|null> $flags   Flag field name => new value.
	 */
	protected function writeFlags( int $post_id, array $flags ): void {
		$keys = [
			SeoFields::FIELD_NOINDEX  => [ self::KEY_NOINDEX, self::INDEX ],
			SeoFields::FIELD_NOFOLLOW => [ self::KEY_NOFOLLOW, self::FOLLOW ],
		];

		foreach ( $flags as $field => $value ) {
			if ( ! isset( $keys[ $field ] ) ) {
				continue;
			}

			[ $key, $negative ] = $keys[ $field ];

			if ( null === $value ) {
				delete_post_meta( $post_id, $key );

				continue;
			}

			update_post_meta( $post_id, $key, $value ? self::ON : $negative );
		}
	}

	/**
	 * One robots flag, projected from its stored number.
	 *
	 * Guarded on shape rather than cast: this is post meta, and a value written by
	 * an importer can be an array.
	 *
	 * @param int    $post_id  The post identifier.
	 * @param string $key      The meta key.
	 * @param string $negative The stored value meaning "explicitly off".
	 *
	 * @return bool|null True, false, or null for "the plugin decides".
	 */
	private function flag( int $post_id, string $key, string $negative ): ?bool {
		$stored = get_post_meta( $post_id, $key, true );

		if ( ! is_string( $stored ) && ! is_numeric( $stored ) ) {
			return null;
		}

		$value = trim( (string) $stored );

		if ( self::ON === $value ) {
			return true;
		}

		return $negative === $value ? false : null;
	}
}
