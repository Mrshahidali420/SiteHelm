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
	 * What values() would answer if the store held the rows a snapshot recorded.
	 *
	 * THE SNAPSHOT AND THE READ SPEAK DIFFERENT LANGUAGES, and a rollback has to
	 * promise the read's. capture() records the raw store; a read-back answers
	 * projected field names. A rollback that promised the snapshot would be
	 * non-empty, would pass the plan check, and would verify nothing while the
	 * site was wrong — so the translation lives beside the store that knows how to
	 * read itself.
	 *
	 * The taxonomy and term are not asked for: a snapshot carries everything the
	 * projection needs, and re-reading the live store here would answer the state
	 * a rollback is about to replace.
	 *
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return array<string, string|bool|null> Field name => value, every field present.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function valuesFromSnapshot( array $snapshot ): array;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

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
