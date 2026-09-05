<?php
/**
 * The contract one SEO plugin's stored metadata is addressed through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Translates between SiteHelm's field vocabulary and one plugin's stored meta.
 *
 * ONE IMPLEMENTATION PER SEO PLUGIN, and the operations above know nothing about
 * which one they hold. A third SEO plugin is a new class implementing this
 * interface plus one row in SeoPresence's precedence list — not a new operation,
 * and not a branch inside the read.
 *
 * THE TWO PLUGINS DISAGREE ABOUT ROBOTS DIRECTIVES, and that disagreement is the
 * reason this interface exists at value level rather than as a flat key map. Yoast
 * SEO stores noindex and nofollow as two independent numeric meta values; Rank
 * Math stores one array holding every directive it manages, including several
 * SiteHelm does not address. A key map could describe the first and not the
 * second, so each implementation is asked for VALUES in SiteHelm's vocabulary and
 * is left to decide how its own store produces them.
 *
 * @package SiteHelm
 */
interface SeoProvider {

	/**
	 * The provider's stable name, as a read reports it.
	 *
	 * Reported to the caller so an answer says which plugin's store it came from.
	 * Without it a null description is ambiguous between "no description is set"
	 * and "the description is set in the other SEO plugin this site also has
	 * installed".
	 *
	 * @return string The provider name, lowercase and hyphenated.
	 */
	public function name(): string;

	/**
	 * Whether this plugin is doing anything with what it stores.
	 *
	 * THE THIRD STATE THE HEALTH MODEL WAS MISSING. A plugin can be installed, in
	 * range, activated and loaded, and still emit nothing at all, because several
	 * SEO plugins park themselves behind their own setup and stay inert until an
	 * owner walks through it. Everything SiteHelm does keeps working in that
	 * state: the meta rows are written, and read back exactly as written. What
	 * does not happen is the part a visitor would see.
	 *
	 * The damage that produced this method was silent and real. A theme that
	 * emits its own social tags and stands down when an SEO plugin is present did
	 * exactly that, the SEO plugin emitted nothing in its place, and the site
	 * served no social cards from anyone while every SiteHelm answer said the
	 * module was active.
	 *
	 * THE DEFAULT IS TRUE, and it is stated in each base class rather than
	 * assumed, because "I have no evidence this plugin is dormant" and "this
	 * plugin is working" are the same answer here and only one of them is worth
	 * reporting. A provider overrides this only when its plugin has a gate that
	 * can be read from an option, and the override names the option.
	 *
	 * @return bool True when the plugin is acting on what it stores.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isConfigured(): bool;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Every field's current value for one post.
	 *
	 * EVERY KEY IN SeoFields::FIELD_ORDER IS PRESENT in the returned array, so a
	 * caller reading the answer never has to distinguish "this provider does not
	 * support the field" from "the field is not set". Text fields are a string or
	 * null; the two flags are true, false, or null.
	 *
	 * NULL MEANS "THE PLUGIN DECIDES", not "empty". An absent meta row and a
	 * stored empty string are both reported as null, because an empty override is
	 * not an override: both leave the plugin rendering its own template. Reporting
	 * them differently would invite a client to write '' expecting a different
	 * result from clearing.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value, every field present.
	 */
	public function values( int $post_id ): array;

	/**
	 * The plugin's own analysis scores for one post.
	 *
	 * BOTH KEYS ARE ALWAYS PRESENT: `seoScore` and `readabilityScore`, each an
	 * integer from 0 to 100 or null. Null means the plugin has not scored the post
	 * (it has not been saved in the editor since the plugin was installed, or no
	 * focus keyword was set) OR the plugin has no such score at all — Rank Math
	 * keeps one SEO score and no separate readability score. A caller that needs
	 * the distinction has the provider name.
	 *
	 * The score is read, never computed: SiteHelm reports what the plugin's own
	 * analysis stored, so the number agrees with the one the editor shows.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array{seoScore: int|null, readabilityScore: int|null} The scores.
	 */
	public function scores( int $post_id ): array;

	/**
	 * What the named changes will read back as once written.
	 *
	 * THIS EXISTS BECAUSE A STORE CANNOT ALWAYS HOLD WHAT WAS ASKED FOR, and the
	 * honest place to say so is the preview rather than the verification. Two
	 * cases arise: a text value is trimmed, and an empty one clears the field, so
	 * `'  '` reads back as null; and Rank Math's robots array has no directive
	 * meaning "follow", so `nofollow: false` there means "remove the nofollow
	 * directive" and reads back as null rather than false.
	 *
	 * The write builds its promise through this method, so the plan a caller
	 * approves already states the outcome and the read-back agrees with it. The
	 * alternative — promising the request and discovering the difference at
	 * verification — turns a documented store limitation into a failed write.
	 *
	 * @param array<string, string|bool|null> $changes Field name => requested value.
	 *
	 * @return array<string, string|bool|null> Field name => the value that will be readable.
	 */
	public function project( array $changes ): array;

	/**
	 * Writes the named changes and reports whether they are all readable afterwards.
	 *
	 * Only the fields present in `$changes` are touched. A null value CLEARS the
	 * field, which means deleting the underlying meta rather than storing an empty
	 * string: an empty string is a value some plugin code paths treat as an
	 * override of nothing, and a cleared field must be indistinguishable from one
	 * that was never set.
	 *
	 * The return value is measured, not assumed: the implementation re-reads and
	 * reports whether every requested change is now the value that comes back.
	 * `update_post_meta()` returning false is ambiguous — it is also what an
	 * unchanged value returns — so it cannot be the evidence.
	 *
	 * @param int                             $post_id The post identifier.
	 * @param array<string, string|bool|null> $changes Field name => new value.
	 *
	 * @return bool True when every requested change is readable.
	 */
	public function apply( int $post_id, array $changes ): bool;

	/**
	 * Captures this provider's raw stored state for one post.
	 *
	 * RAW VENDOR VALUES, not the projected vocabulary. A snapshot exists to put
	 * the store back exactly as it was, and the projection is lossy by design: it
	 * folds an absent row and a stored empty string into the same null, and it
	 * ignores the robots directives SiteHelm does not address. Restoring from the
	 * projection would quietly rewrite values the change never touched.
	 *
	 * The provider name is carried inside the snapshot so a restore can refuse when
	 * the site's SEO plugin changed between apply and rollback.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( int $post_id ): array;

	/**
	 * Puts a captured snapshot back, and reports whether the store now matches it.
	 *
	 * @param int                  $post_id  The post identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( int $post_id, array $snapshot ): bool;
}
