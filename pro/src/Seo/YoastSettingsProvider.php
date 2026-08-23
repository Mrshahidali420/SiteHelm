<?php
/**
 * REQ-0098 (Pro): Yoast SEO's settings store.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

/**
 * Yoast SEO keeps its titles and templates in `wpseo_titles` and its social
 * defaults in `wpseo_social`.
 *
 * TWO PLACES YOAST DIFFERS, both declared before a write runs: the separator is
 * stored as a named code (`sc-dash`, `sc-pipe`, ...), so only the characters it
 * names can be set; and there is no per-type sitemap switch — a post type kept
 * in search results is in the sitemap — so `inSitemap` reads as `!noindex` and
 * is refused as a write with that explanation.
 */
final class YoastSettingsProvider extends SeoSettingsProviderBase {

	public const NAME = 'yoast-seo';

	public const OPTION_TITLES = 'wpseo_titles';

	public const OPTION_SOCIAL = 'wpseo_social';

	/** Yoast's separator codes and the characters they render. */
	public const SEPARATORS = [
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	];

	/**
	 * See the parent.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * See the parent.
	 *
	 * @param string $post_type See the parent.
	 */
	protected function owned_keys( ?string $post_type ): array {
		if ( null === $post_type ) {
			return [
				self::OPTION_TITLES => [ 'separator', 'company_name', 'company_logo', 'breadcrumbs-enable' ],
				self::OPTION_SOCIAL => [ 'og_default_image', 'og_default_image_id' ],
			];
		}

		return [
			self::OPTION_TITLES => [ "title-{$post_type}", "metadesc-{$post_type}", "noindex-{$post_type}" ],
		];
	}

	/**
	 * See the parent.
	 *
	 * @param string $post_type See the parent.
	 */
	public function values( ?string $post_type ): array {
		$titles = $this->option( self::OPTION_TITLES );

		if ( null === $post_type ) {
			$social = $this->option( self::OPTION_SOCIAL );
			$code   = $titles['separator'] ?? null;

			return [
				SeoSettingsFields::FIELD_SEPARATOR   => is_string( $code ) ? ( self::SEPARATORS[ $code ] ?? null ) : null,
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_NAME => $this->text( $titles, 'company_name' ),
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_LOGO => $this->text( $titles, 'company_logo' ),
				SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE => $this->text( $social, 'og_default_image' ),
				SeoSettingsFields::FIELD_BREADCRUMBS => ! empty( $titles['breadcrumbs-enable'] ),
			];
		}

		$noindex = ! empty( $titles[ "noindex-{$post_type}" ] );

		return [
			SeoSettingsFields::FIELD_TITLE_TEMPLATE       => $this->text( $titles, "title-{$post_type}" ),
			SeoSettingsFields::FIELD_DESCRIPTION_TEMPLATE => $this->text( $titles, "metadesc-{$post_type}" ),
			SeoSettingsFields::FIELD_NOINDEX              => $noindex,
			SeoSettingsFields::FIELD_IN_SITEMAP           => ! $noindex,
		];
	}

	/**
	 * See the parent.
	 *
	 * @param string               $post_type See the parent.
	 * @param array<string, mixed> $changes See the parent.
	 */
	public function refusal( ?string $post_type, array $changes ): ?string {
		if ( array_key_exists( SeoSettingsFields::FIELD_SEPARATOR, $changes ) ) {
			$wanted = $changes[ SeoSettingsFields::FIELD_SEPARATOR ];

			if ( null === $wanted || ! in_array( trim( (string) $wanted ), self::SEPARATORS, true ) ) {
				return 'Yoast SEO stores the separator as one of a fixed set of characters: ' . implode( ' ', self::SEPARATORS ) . '. Send one of those.';
			}
		}

		if ( null !== $post_type && array_key_exists( SeoSettingsFields::FIELD_IN_SITEMAP, $changes ) ) {
			return 'Yoast SEO has no separate sitemap switch per post type: a type left in search results is in the sitemap. Set noindex instead.';
		}

		return null;
	}

	/**
	 * See the parent.
	 *
	 * @param string               $post_type See the parent.
	 * @param array<string, mixed> $changes See the parent.
	 */
	public function project( ?string $post_type, array $changes ): array {
		$projected = [];

		foreach ( SeoSettingsFields::text_fields_for( $post_type ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$projected[ $field ] = $this->project_text( $changes[ $field ] );
			}
		}

		foreach ( SeoSettingsFields::flag_fields_for( $post_type ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$projected[ $field ] = (bool) $changes[ $field ];
			}
		}

		if ( null !== $post_type && array_key_exists( SeoSettingsFields::FIELD_NOINDEX, $projected ) ) {
			$projected[ SeoSettingsFields::FIELD_IN_SITEMAP ] = ! $projected[ SeoSettingsFields::FIELD_NOINDEX ];
		}

		return $projected;
	}

	/**
	 * See the parent.
	 *
	 * @param string               $post_type See the parent.
	 * @param array<string, mixed> $changes See the parent.
	 */
	public function apply( ?string $post_type, array $changes ): bool {
		$titles = $this->option( self::OPTION_TITLES );

		if ( null !== $post_type ) {
			$map = [
				SeoSettingsFields::FIELD_TITLE_TEMPLATE => "title-{$post_type}",
				SeoSettingsFields::FIELD_DESCRIPTION_TEMPLATE => "metadesc-{$post_type}",
			];

			foreach ( $map as $field => $key ) {
				if ( array_key_exists( $field, $changes ) ) {
					$titles[ $key ] = $this->project_text( $changes[ $field ] ) ?? '';
				}
			}

			if ( array_key_exists( SeoSettingsFields::FIELD_NOINDEX, $changes ) ) {
				$titles[ "noindex-{$post_type}" ] = (bool) $changes[ SeoSettingsFields::FIELD_NOINDEX ];
			}

			return $this->write_option( self::OPTION_TITLES, $titles );
		}

		if ( array_key_exists( SeoSettingsFields::FIELD_SEPARATOR, $changes ) ) {
			$code = array_search( trim( (string) $changes[ SeoSettingsFields::FIELD_SEPARATOR ] ), self::SEPARATORS, true );

			if ( false === $code ) {
				return false;
			}

			$titles['separator'] = $code;
		}

		$map = [
			SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_NAME => 'company_name',
			SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_LOGO => 'company_logo',
		];

		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$titles[ $key ] = $this->project_text( $changes[ $field ] ) ?? '';
			}
		}

		if ( array_key_exists( SeoSettingsFields::FIELD_BREADCRUMBS, $changes ) ) {
			$titles['breadcrumbs-enable'] = (bool) $changes[ SeoSettingsFields::FIELD_BREADCRUMBS ];
		}

		$ok = $this->write_option( self::OPTION_TITLES, $titles );

		if ( array_key_exists( SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE, $changes ) ) {
			$social = $this->option( self::OPTION_SOCIAL );

			$social['og_default_image']    = $this->project_text( $changes[ SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE ] ) ?? '';
			$social['og_default_image_id'] = '';

			$ok = $this->write_option( self::OPTION_SOCIAL, $social ) && $ok;
		}

		return $ok;
	}
}
