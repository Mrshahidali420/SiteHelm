<?php
/**
 * REQ-0098 (Pro): read the SEO plugin's site or post-type settings.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Licence\Licence;

/**
 * Reports the allowlisted settings of one scope — the site, or one public post
 * type — as the SEO plugin stores them, in SiteHelm's vocabulary.
 */
final class SeoSettingsGet {

	public const ID = 'seo-settings-get';

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Read the SEO plugin\'s site-wide settings (title separator, knowledge graph, default social image, breadcrumbs) or one post type\'s settings (title and description templates, noindex, sitemap inclusion). SiteHelm Pro.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => self::scope_properties(),
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'provider' => [
						'type'        => 'string',
						'description' => 'Which SEO plugin\'s store this answer came from.',
					],
					'postType' => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The post type the settings belong to, or null for the site-wide settings.',
					],
					'settings' => [
						'type'                 => 'object',
						'properties'           => self::settings_properties(),
						'additionalProperties' => false,
						'description'          => 'The scope\'s settings. Site scope: separator, knowledgeGraphName, knowledgeGraphLogo, defaultSocialImage, breadcrumbs. Post-type scope: titleTemplate, descriptionTemplate, noindex, inSitemap.',
					],
				],
				'required'             => [ 'provider', 'postType', 'settings' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ SeoSettingsFields::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Seo,
			supportedVersions: SeoPresence::supportedVersions(),
			example: [
				'operation' => self::ID,
				'arguments' => [ 'postType' => 'post' ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param Licence     $licence  The site's Pro licence.
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct(
		private readonly Licence $licence,
		private readonly SeoPresence $presence
	) {
	}

	/**
	 * Reports one scope's settings.
	 *
	 * @param array<string, mixed> $input   Validated arguments, optionally carrying 'postType'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Provider, scope and settings.
	 */
	public function handle( array $input, OperationContext $context ): array {
		[ $post_type, $provider ] = ( new SeoSettingsTarget( $this->licence, $this->presence ) )->resolve( $input, $context );

		return [
			'provider' => $provider->name(),
			'postType' => $post_type,
			'settings' => $provider->values( $post_type ),
		];
	}

	/**
	 * The optional scope member both settings operations accept.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	public static function scope_properties(): array {
		return [
			'postType' => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 20,
				'pattern'     => '^[a-z0-9_-]+$',
				'description' => 'A public post type slug to work with that type\'s settings. Omit for the site-wide settings.',
			],
		];
	}

	/**
	 * Every settings member of both scopes, typed.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	public static function settings_properties(): array {
		$properties = [];

		foreach ( array_merge( SeoSettingsFields::SITE_TEXT_FIELDS, SeoSettingsFields::TYPE_TEXT_FIELDS ) as $field ) {
			$properties[ $field ] = [
				'type'        => [ 'string', 'null' ],
				'maxLength'   => SeoSettingsFields::MAX_LENGTH[ $field ],
				'description' => self::DESCRIPTIONS[ $field ],
			];
		}

		foreach ( array_merge( SeoSettingsFields::SITE_FLAG_FIELDS, SeoSettingsFields::TYPE_FLAG_FIELDS ) as $field ) {
			$properties[ $field ] = [
				'type'        => 'boolean',
				'description' => self::DESCRIPTIONS[ $field ],
			];
		}

		return $properties;
	}

	/** What each setting means, shared by the read and the write schemas. */
	public const DESCRIPTIONS = [
		SeoSettingsFields::FIELD_SEPARATOR            => 'The character placed between a page title and the site name.',
		SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_NAME => 'The organisation or person name the plugin puts in its knowledge-graph markup.',
		SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_LOGO => 'The logo URL for the same markup.',
		SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE => 'The image URL shared when a page sets none of its own.',
		SeoSettingsFields::FIELD_BREADCRUMBS          => 'Whether the plugin\'s breadcrumb trail is switched on.',
		SeoSettingsFields::FIELD_TITLE_TEMPLATE       => 'The post type\'s title template, in the plugin\'s own variable syntax.',
		SeoSettingsFields::FIELD_DESCRIPTION_TEMPLATE => 'The post type\'s meta description template.',
		SeoSettingsFields::FIELD_NOINDEX              => 'Whether the post type is kept out of search results.',
		SeoSettingsFields::FIELD_IN_SITEMAP           => 'Whether the post type is listed in the XML sitemap.',
	];
}
