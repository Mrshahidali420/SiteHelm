<?php
/**
 * The contract one SEO plugin's stored TERM metadata is addressed through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Translates between SiteHelm's term field vocabulary and one plugin's term store.
 *
 * A SEPARATE CONTRACT FROM SeoProvider because the two plugins keep term metadata
 * in stores of different SHAPE, not just under different keys: Rank Math writes
 * term meta rows exactly as it writes post meta, while Yoast keeps every term's
 * metadata of every taxonomy inside one serialised option. A post-shaped
 * provider cannot describe the second, so the term operations ask for values in
 * SiteHelm's vocabulary and leave each implementation to decide how its own
 * store produces them. The methods mirror SeoProvider's and carry the same
 * meanings: null is "the plugin decides", project() is what a change will read
 * back as, apply() measures rather than assumes, and a snapshot is the raw store.
 *
 * @package SiteHelm
 */
interface SeoTermProvider {

	/**
	 * The provider's stable name, the same one the post provider reports.
	 *
	 * @return string The provider name, lowercase and hyphenated.
	 */
	public function name(): string;

	/**
	 * Every term field's current value, every key in SeoTermFields::FIELD_ORDER present.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value.
	 */
	public function values( string $taxonomy, int $term_id ): array;

	/**
	 * What the named changes will read back as once written.
	 *
	 * @param array<string, string|bool|null> $changes Field name => requested value.
	 *
	 * @return array<string, string|bool|null> Field name => the value that will be readable.
	 */
	public function project( array $changes ): array;

	/**
	 * Writes the named changes and reports whether they are all readable afterwards.
	 *
	 * @param string                          $taxonomy The taxonomy slug.
	 * @param int                             $term_id  The term identifier.
	 * @param array<string, string|bool|null> $changes  Field name => new value; null clears.
	 *
	 * @return bool True when every requested change is readable.
	 */
	public function apply( string $taxonomy, int $term_id, array $changes ): bool;

	/**
	 * Captures this provider's raw stored state for one term, provider name included.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( string $taxonomy, int $term_id ): array;

	/**
	 * Puts a captured snapshot back, and reports whether the store now matches it.
	 *
	 * @param string               $taxonomy The taxonomy slug.
	 * @param int                  $term_id  The term identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( string $taxonomy, int $term_id, array $snapshot ): bool;
}
