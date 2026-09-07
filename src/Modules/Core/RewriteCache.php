<?php
/**
 * The one place this plugin touches WordPress's cached address rules.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * WordPress's cached rewrite rules, thrown away rather than rebuilt.
 *
 * THE CACHE IS DELETED, NEVER FLUSHED, AND THAT IS THE WHOLE POINT OF THIS
 * CLASS. `flush_rewrite_rules()` does not ask WordPress to work out what the
 * site's addresses should be; it writes back whatever the global `$wp_rewrite`
 * is holding right now. Every operation in this plugin arrives over a REST
 * request, and by the time a route callback runs, `init` and `wp_loaded` have
 * both fired: `$wp_rewrite` was built at the top of the request, from the
 * permalink structure and the plugin list the request booted with. So a flush
 * made after changing either one persists the rules for the state we just left,
 * and does it while reporting success.
 *
 * Deleting the option has no such window. `wp_rewrite_rules()` rebuilds only
 * when the stored rules are empty, so the rebuild happens on the next request —
 * one that boots with the new permalink structure and the new plugin list, and
 * with every other plugin's `init` handler registering its own rules again.
 *
 * The cost is that the repair lands on the next visit rather than this one, and
 * every operation that clears the cache says so in a warning before the
 * operator approves it.
 *
 * NOTHING HERE TOUCHES .htaccess. The hard variant of a flush rewrites that
 * file, which needs filesystem credentials this plugin refuses to hold. Only
 * the rules WordPress stores in the database are this plugin's business.
 *
 * @package SiteHelm
 */
final class RewriteCache {

	/**
	 * The option WordPress caches its generated rewrite rules in.
	 */
	public const OPTION = 'rewrite_rules';

	/**
	 * How many rules this site currently has cached.
	 *
	 * A site on plain permalinks caches none, and that is a fact rather than a
	 * fault: the count is what the operator sees go to zero, not a precondition.
	 *
	 * @return int The cached rule count, zero when nothing is cached.
	 */
	public static function count(): int {
		$rules = get_option( self::OPTION );

		return is_array( $rules ) ? count( $rules ) : 0;
	}

	/**
	 * Throws the cached rules away so the next request rebuilds them.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}
}
