<?php
/**
 * REQ-0098 (Pro): the allowlisted SEO settings vocabulary.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

/**
 * The settings SiteHelm Pro will read and write in an SEO plugin, and nothing else.
 *
 * THE LIST IS THE GUARD. Both plugins keep hundreds of keys in their options, some of
 * which (licence keys, API tokens, import/export state, module switches) must never
 * be reachable from an agent. An allowlist of named, typed settings is the only
 * shape that stays safe as the plugins grow; a "set any option key" operation would
 * be a remote shell wearing an SEO hat.
 *
 * Two scopes: SITE settings (one set per site) and POST-TYPE settings (one set per
 * public post type). A call names its scope with the optional `postType` argument.
 */
final class SeoSettingsFields {

	public const CAPABILITY = 'manage_options';

	public const TARGET_PREFIX = 'seo-settings:';

	public const SCOPE_SITE = 'site';

	public const SCOPE_TYPE = 'type';

	/** The character placed between a title and the site name. */
	public const FIELD_SEPARATOR = 'separator';

	/** The organisation name the plugin puts in its knowledge-graph markup. */
	public const FIELD_KNOWLEDGE_GRAPH_NAME = 'knowledgeGraphName';

	/** The organisation logo URL for the same markup. */
	public const FIELD_KNOWLEDGE_GRAPH_LOGO = 'knowledgeGraphLogo';

	/** The image shared when a page has none of its own. */
	public const FIELD_DEFAULT_SOCIAL_IMAGE = 'defaultSocialImage';

	/** Whether the plugin's breadcrumb trail is switched on. */
	public const FIELD_BREADCRUMBS = 'breadcrumbs';

	/** The title template for a post type, in the plugin's own variable syntax. */
	public const FIELD_TITLE_TEMPLATE = 'titleTemplate';

	/** The description template for a post type. */
	public const FIELD_DESCRIPTION_TEMPLATE = 'descriptionTemplate';

	/** Whether the post type is kept out of search results. */
	public const FIELD_NOINDEX = 'noindex';

	/** Whether the post type is listed in the XML sitemap. */
	public const FIELD_IN_SITEMAP = 'inSitemap';

	public const SITE_TEXT_FIELDS = [
		self::FIELD_SEPARATOR,
		self::FIELD_KNOWLEDGE_GRAPH_NAME,
		self::FIELD_KNOWLEDGE_GRAPH_LOGO,
		self::FIELD_DEFAULT_SOCIAL_IMAGE,
	];

	public const SITE_FLAG_FIELDS = [ self::FIELD_BREADCRUMBS ];

	public const TYPE_TEXT_FIELDS = [
		self::FIELD_TITLE_TEMPLATE,
		self::FIELD_DESCRIPTION_TEMPLATE,
	];

	public const TYPE_FLAG_FIELDS = [
		self::FIELD_NOINDEX,
		self::FIELD_IN_SITEMAP,
	];

	public const SITE_FIELDS = [
		self::FIELD_SEPARATOR,
		self::FIELD_KNOWLEDGE_GRAPH_NAME,
		self::FIELD_KNOWLEDGE_GRAPH_LOGO,
		self::FIELD_DEFAULT_SOCIAL_IMAGE,
		self::FIELD_BREADCRUMBS,
	];

	public const TYPE_FIELDS = [
		self::FIELD_TITLE_TEMPLATE,
		self::FIELD_DESCRIPTION_TEMPLATE,
		self::FIELD_NOINDEX,
		self::FIELD_IN_SITEMAP,
	];

	public const MAX_LENGTH = [
		self::FIELD_SEPARATOR            => 10,
		self::FIELD_KNOWLEDGE_GRAPH_NAME => 200,
		self::FIELD_KNOWLEDGE_GRAPH_LOGO => 2000,
		self::FIELD_DEFAULT_SOCIAL_IMAGE => 2000,
		self::FIELD_TITLE_TEMPLATE       => 500,
		self::FIELD_DESCRIPTION_TEMPLATE => 500,
	];

	/**
	 * The fields a scope carries, in output order.
	 *
	 * @param string|null $post_type The post type, or null for the site scope.
	 *
	 * @return string[] Field names.
	 */
	public static function fields_for( ?string $post_type ): array {
		return null === $post_type ? self::SITE_FIELDS : self::TYPE_FIELDS;
	}

	/**
	 * The text fields a scope carries.
	 *
	 * @param string|null $post_type The post type, or null for the site scope.
	 *
	 * @return string[] Field names.
	 */
	public static function text_fields_for( ?string $post_type ): array {
		return null === $post_type ? self::SITE_TEXT_FIELDS : self::TYPE_TEXT_FIELDS;
	}

	/**
	 * The flag fields a scope carries.
	 *
	 * @param string|null $post_type The post type, or null for the site scope.
	 *
	 * @return string[] Field names.
	 */
	public static function flag_fields_for( ?string $post_type ): array {
		return null === $post_type ? self::SITE_FLAG_FIELDS : self::TYPE_FLAG_FIELDS;
	}

	/**
	 * The target key for a scope.
	 *
	 * @param string|null $post_type The post type, or null for the site scope.
	 *
	 * @return string The key.
	 */
	public static function target_key( ?string $post_type ): string {
		return null === $post_type
			? self::TARGET_PREFIX . self::SCOPE_SITE
			: self::TARGET_PREFIX . self::SCOPE_TYPE . ':' . $post_type;
	}

	/**
	 * The scope a target key names.
	 *
	 * @param string $key The key.
	 *
	 * @return array{0: bool, 1: string|null} Whether the key is one of ours, and the post type (null = site).
	 */
	public static function from_key( string $key ): array {
		if ( self::TARGET_PREFIX . self::SCOPE_SITE === $key ) {
			return [ true, null ];
		}

		$type_prefix = self::TARGET_PREFIX . self::SCOPE_TYPE . ':';

		if ( str_starts_with( $key, $type_prefix ) && strlen( $key ) > strlen( $type_prefix ) ) {
			return [ true, substr( $key, strlen( $type_prefix ) ) ];
		}

		return [ false, null ];
	}
}
