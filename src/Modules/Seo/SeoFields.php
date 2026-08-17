<?php
/**
 * The vendor-neutral vocabulary the SEO operations read and write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * The field names, bounds, and target addressing shared by the SEO read and the
 * SEO write.
 *
 * THIS VOCABULARY IS SITEHELM'S OWN, not either plugin's. A caller asks for
 * `description` and gets the meta description whether the site runs Yoast SEO or
 * Rank Math; the provider classes are the only place a vendor's own key names
 * appear. That containment is the whole point of the module: an agent that had to
 * know which SEO plugin a site runs before it could write a meta description
 * would need a different call per plugin, and the second plugin would arrive as a
 * new operation rather than as a new provider.
 *
 * NAMES ARE FLAT rather than nested under `openGraph` and `twitter` objects. The
 * write accepts exactly the names the read reports, so a client can read a post,
 * change one member, and send it back; a nested read paired with a flat write is
 * the shape that produces "I sent what you gave me and you refused it".
 *
 * @package SiteHelm
 */
final class SeoFields {

	/**
	 * The search-result title override.
	 */
	public const FIELD_TITLE = 'title';

	/**
	 * The meta description.
	 */
	public const FIELD_DESCRIPTION = 'description';

	/**
	 * The canonical URL override.
	 */
	public const FIELD_CANONICAL = 'canonical';

	/**
	 * The focus keyword the plugin scores its analysis against.
	 */
	public const FIELD_FOCUS_KEYWORD = 'focusKeyword';

	/**
	 * Whether search engines are told not to index this post.
	 */
	public const FIELD_NOINDEX = 'noindex';

	/**
	 * Whether search engines are told not to follow this post's links.
	 */
	public const FIELD_NOFOLLOW = 'nofollow';

	/**
	 * The Open Graph (Facebook, LinkedIn, Slack) title override.
	 */
	public const FIELD_OG_TITLE = 'ogTitle';

	/**
	 * The Open Graph description override.
	 */
	public const FIELD_OG_DESCRIPTION = 'ogDescription';

	/**
	 * The Open Graph image URL, reported but not writable.
	 */
	public const FIELD_OG_IMAGE = 'ogImage';

	/**
	 * The Twitter (X) card title override.
	 */
	public const FIELD_TWITTER_TITLE = 'twitterTitle';

	/**
	 * The Twitter card description override.
	 */
	public const FIELD_TWITTER_DESCRIPTION = 'twitterDescription';

	/**
	 * The Twitter card image URL, reported but not writable.
	 */
	public const FIELD_TWITTER_IMAGE = 'twitterImage';

	/**
	 * Every field, in the order a read reports them.
	 *
	 * Title and description first because they are what appears in a search
	 * result, then the two directives that decide whether it appears at all, then
	 * the social overrides that only matter once the page is shareable.
	 */
	public const FIELD_ORDER = [
		self::FIELD_TITLE,
		self::FIELD_DESCRIPTION,
		self::FIELD_CANONICAL,
		self::FIELD_FOCUS_KEYWORD,
		self::FIELD_NOINDEX,
		self::FIELD_NOFOLLOW,
		self::FIELD_OG_TITLE,
		self::FIELD_OG_DESCRIPTION,
		self::FIELD_OG_IMAGE,
		self::FIELD_TWITTER_TITLE,
		self::FIELD_TWITTER_DESCRIPTION,
		self::FIELD_TWITTER_IMAGE,
	];

	/**
	 * The fields a write may set, and whose values are strings.
	 */
	public const TEXT_FIELDS = [
		self::FIELD_TITLE,
		self::FIELD_DESCRIPTION,
		self::FIELD_CANONICAL,
		self::FIELD_FOCUS_KEYWORD,
		self::FIELD_OG_TITLE,
		self::FIELD_OG_DESCRIPTION,
		self::FIELD_TWITTER_TITLE,
		self::FIELD_TWITTER_DESCRIPTION,
	];

	/**
	 * The fields a write may set, and whose values are booleans or null.
	 */
	public const FLAG_FIELDS = [
		self::FIELD_NOINDEX,
		self::FIELD_NOFOLLOW,
	];

	/**
	 * The fields a read reports and a write refuses.
	 *
	 * BOTH PLUGINS STORE A SOCIAL IMAGE AS A PAIR — the URL and the attachment id
	 * that produced it — and the pair is what their front-end renderers read. A
	 * write that set the URL alone would leave the id pointing at the previous
	 * image, which is a state neither plugin's own interface can produce and
	 * neither's renderer resolves predictably. The images are therefore reported,
	 * so an agent can see what a page shares as, and refused, so it cannot create
	 * that state. Setting one is a media choice made in the plugin's own screen.
	 */
	public const READ_ONLY_FIELDS = [
		self::FIELD_OG_IMAGE,
		self::FIELD_TWITTER_IMAGE,
	];

	/**
	 * The longest text value a write accepts, in characters.
	 *
	 * Generous rather than tight, and deliberately so: an SEO title or description
	 * may legitimately carry a plugin's own template variables — `%%title%% %%sep%%
	 * %%sitename%%` in Yoast, `%title% %sep% %sitename%` in Rank Math — which are
	 * longer stored than rendered. A bound tuned to the ~60-character search
	 * snippet would refuse the templated values both plugins write by default.
	 * What the bound is actually for is refusing a value that is a document rather
	 * than a description.
	 */
	public const TEXT_MAX_LENGTH = 500;

	/**
	 * The longest canonical URL a write accepts, in characters.
	 *
	 * Separate from TEXT_MAX_LENGTH because a URL is a different kind of value
	 * with a different natural ceiling, and 500 is short enough to refuse
	 * legitimate URLs carrying long path segments and query strings.
	 */
	public const CANONICAL_MAX_LENGTH = 2000;

	/**
	 * The prefix every SEO target key carries.
	 *
	 * The subject is the post's SEO metadata rather than the post, so the key is
	 * distinct from the content module's post targets: two write plans addressing
	 * `post:42` and `post-seo:42` change different things, and a shared key would
	 * make them look like the same target to anything reading a plan.
	 */
	public const TARGET_PREFIX = 'post-seo:';

	/**
	 * The capability both operations require.
	 *
	 * A post's SEO metadata is part of that post, so the capability is the one
	 * that governs editing it — not `manage_options`. An editor who may rewrite a
	 * page's copy may rewrite the description that copy appears under.
	 */
	public const CAPABILITY = 'edit_post';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * Builds the target key for a post.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}

	/**
	 * Reads the post identifier back out of a target key.
	 *
	 * Returns null rather than 0 for anything that is not a target key this class
	 * built, because 0 is a post identifier WordPress treats as "the current
	 * global post" — a value that would silently address whatever post happened to
	 * be in scope instead of refusing.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The post identifier, or null when the key is not one of ours.
	 */
	public static function postIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$digits = substr( $key, strlen( self::TARGET_PREFIX ) );

		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $digits ) ) {
			return null;
		}

		return (int) $digits;
	}

	/**
	 * The longest value the named field accepts.
	 *
	 * @param string $field The field name.
	 *
	 * @return int The bound in characters.
	 */
	public static function maxLengthFor( string $field ): int {
		return self::FIELD_CANONICAL === $field ? self::CANONICAL_MAX_LENGTH : self::TEXT_MAX_LENGTH;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
