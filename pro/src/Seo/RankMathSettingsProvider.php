<?php
/**
 * REQ-0098 (Pro): Rank Math's settings store.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

/**
 * Rank Math keeps its titles, templates and knowledge graph in
 * `rank-math-options-titles` and its sitemap switches in
 * `rank-math-options-sitemap`.
 *
 * THE ROBOTS SWITCH IS TWO KEYS: `pt_{type}_custom_robots` ('on'/'off') says
 * whether the type overrides the site default, and `pt_{type}_robots` lists the
 * directives. Setting `noindex` switches the override on and swaps `index` for
 * `noindex` (or back) in that list; the other directives in it are kept.
 * A type without an override reads as not noindex.
 */
final class RankMathSettingsProvider extends SeoSettingsProviderBase {

	public const NAME = 'rank-math';

	public const OPTION_TITLES = 'rank-math-options-titles';

	public const OPTION_SITEMAP = 'rank-math-options-sitemap';

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
				self::OPTION_TITLES => [ 'title_separator', 'knowledgegraph_name', 'knowledgegraph_logo', 'breadcrumbs', 'open_graph_image' ],
			];
		}

		return [
			self::OPTION_TITLES  => [ "pt_{$post_type}_title", "pt_{$post_type}_description", "pt_{$post_type}_custom_robots", "pt_{$post_type}_robots" ],
			self::OPTION_SITEMAP => [ "pt_{$post_type}_sitemap" ],
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
			return [
				SeoSettingsFields::FIELD_SEPARATOR   => $this->text( $titles, 'title_separator' ),
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_NAME => $this->text( $titles, 'knowledgegraph_name' ),
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_LOGO => $this->text( $titles, 'knowledgegraph_logo' ),
				SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE => $this->text( $titles, 'open_graph_image' ),
				SeoSettingsFields::FIELD_BREADCRUMBS => 'on' === ( $titles['breadcrumbs'] ?? null ),
			];
		}

		$sitemap = $this->option( self::OPTION_SITEMAP );

		return [
			SeoSettingsFields::FIELD_TITLE_TEMPLATE       => $this->text( $titles, "pt_{$post_type}_title" ),
			SeoSettingsFields::FIELD_DESCRIPTION_TEMPLATE => $this->text( $titles, "pt_{$post_type}_description" ),
			SeoSettingsFields::FIELD_NOINDEX              => $this->is_noindex( $titles, $post_type ),
			SeoSettingsFields::FIELD_IN_SITEMAP           => 'on' === ( $sitemap[ "pt_{$post_type}_sitemap" ] ?? null ),
		];
	}

	/**
	 * See the parent.
	 *
	 * @param string               $post_type See the parent.
	 * @param array<string, mixed> $changes See the parent.
	 */
	public function refusal( ?string $post_type, array $changes ): ?string {
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

		if ( null === $post_type ) {
			$map = [
				SeoSettingsFields::FIELD_SEPARATOR => 'title_separator',
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_NAME => 'knowledgegraph_name',
				SeoSettingsFields::FIELD_KNOWLEDGE_GRAPH_LOGO => 'knowledgegraph_logo',
				SeoSettingsFields::FIELD_DEFAULT_SOCIAL_IMAGE => 'open_graph_image',
			];

			foreach ( $map as $field => $key ) {
				if ( array_key_exists( $field, $changes ) ) {
					$titles[ $key ] = $this->project_text( $changes[ $field ] ) ?? '';
				}
			}

			if ( array_key_exists( SeoSettingsFields::FIELD_BREADCRUMBS, $changes ) ) {
				$titles['breadcrumbs'] = $changes[ SeoSettingsFields::FIELD_BREADCRUMBS ] ? 'on' : 'off';
			}

			return $this->write_option( self::OPTION_TITLES, $titles );
		}

		$map = [
			SeoSettingsFields::FIELD_TITLE_TEMPLATE       => "pt_{$post_type}_title",
			SeoSettingsFields::FIELD_DESCRIPTION_TEMPLATE => "pt_{$post_type}_description",
		];

		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$titles[ $key ] = $this->project_text( $changes[ $field ] ) ?? '';
			}
		}

		if ( array_key_exists( SeoSettingsFields::FIELD_NOINDEX, $changes ) ) {
			$titles = $this->with_noindex( $titles, $post_type, (bool) $changes[ SeoSettingsFields::FIELD_NOINDEX ] );
		}

		$ok = $this->write_option( self::OPTION_TITLES, $titles );

		if ( array_key_exists( SeoSettingsFields::FIELD_IN_SITEMAP, $changes ) ) {
			$sitemap = $this->option( self::OPTION_SITEMAP );

			$sitemap[ "pt_{$post_type}_sitemap" ] = $changes[ SeoSettingsFields::FIELD_IN_SITEMAP ] ? 'on' : 'off';

			$ok = $this->write_option( self::OPTION_SITEMAP, $sitemap ) && $ok;
		}

		return $ok;
	}

	/**
	 * Whether a post type's robots override says noindex.
	 *
	 * @param array<string, mixed> $titles    The titles option.
	 * @param string               $post_type The post type.
	 */
	private function is_noindex( array $titles, string $post_type ): bool {
		if ( 'on' !== ( $titles[ "pt_{$post_type}_custom_robots" ] ?? null ) ) {
			return false;
		}

		$robots = $titles[ "pt_{$post_type}_robots" ] ?? [];

		return is_array( $robots ) && in_array( 'noindex', $robots, true );
	}

	/**
	 * The titles option with a post type's robots override set to (not) noindex.
	 *
	 * @param array<string, mixed> $titles    The titles option.
	 * @param string               $post_type The post type.
	 * @param bool                 $noindex   The wanted state.
	 *
	 * @return array<string, mixed> The new option value.
	 */
	private function with_noindex( array $titles, string $post_type, bool $noindex ): array {
		$robots = $titles[ "pt_{$post_type}_robots" ] ?? [];
		$robots = is_array( $robots ) ? array_values( array_filter( $robots, 'is_string' ) ) : [];
		$robots = array_values( array_diff( $robots, [ 'index', 'noindex' ] ) );

		array_unshift( $robots, $noindex ? 'noindex' : 'index' );

		$titles[ "pt_{$post_type}_custom_robots" ] = 'on';
		$titles[ "pt_{$post_type}_robots" ]        = $robots;

		return $titles;
	}
}
