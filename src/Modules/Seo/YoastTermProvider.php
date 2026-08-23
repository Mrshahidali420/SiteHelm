<?php
/**
 * Yoast SEO's term metadata, read and written through its one taxonomy option.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Yoast keeps every term's SEO metadata in the `wpseo_taxonomy_meta` option,
 * keyed by taxonomy and then by term id.
 *
 * ONE OPTION, NOT TERM META, which is why this provider does not extend the
 * post one: a read is an option read and an array walk, a write rewrites the
 * option with one term's array changed, and a snapshot is that term's raw array.
 * Nothing outside the one term's array is touched on a write or a restore, so a
 * rollback cannot disturb another term or another taxonomy, and the option's
 * other members — Yoast stores keys SiteHelm does not address — survive both.
 *
 * Yoast stores "keep this archive out of search" as the word `noindex`, the
 * explicit opposite as `index`, and "the plugin decides" as `default` or an
 * absent key; the last two are one null here.
 *
 * @package SiteHelm
 */
final class YoastTermProvider extends SeoTermProviderBase {

	/** The option Yoast keeps every term's metadata in. */
	public const OPTION = 'wpseo_taxonomy_meta';

	private const KEY_NOINDEX = 'wpseo_noindex';

	private const NOINDEX = 'noindex';

	private const INDEX = 'index';

	private const TEXT_KEYS = [
		SeoFields::FIELD_TITLE         => 'wpseo_title',
		SeoFields::FIELD_DESCRIPTION   => 'wpseo_desc',
		SeoFields::FIELD_CANONICAL     => 'wpseo_canonical',
		SeoFields::FIELD_FOCUS_KEYWORD => 'wpseo_focuskw',
	];

	/**
	 * The provider's stable name.
	 *
	 * @return string Always 'yoast-seo'.
	 */
	public function name(): string {
		return 'yoast-seo';
	}

	/**
	 * Every term field's current value.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value.
	 */
	public function values( string $taxonomy, int $term_id ): array {
		$raw    = $this->term_array( $taxonomy, $term_id );
		$values = [];

		foreach ( SeoTermFields::FIELD_ORDER as $field ) {
			if ( SeoFields::FIELD_NOINDEX === $field ) {
				$values[ $field ] = $this->flag( $raw[ self::KEY_NOINDEX ] ?? null );

				continue;
			}

			$values[ $field ] = self::clean( $raw[ self::TEXT_KEYS[ $field ] ] ?? null );
		}

		return $values;
	}

	/**
	 * Writes the named changes into the term's array and reports whether they read back.
	 *
	 * @param string                          $taxonomy The taxonomy slug.
	 * @param int                             $term_id  The term identifier.
	 * @param array<string, string|bool|null> $changes  Field name => new value.
	 *
	 * @return bool True when every requested change is readable.
	 */
	public function apply( string $taxonomy, int $term_id, array $changes ): bool {
		$raw = $this->term_array( $taxonomy, $term_id );

		foreach ( $changes as $field => $value ) {
			if ( SeoFields::FIELD_NOINDEX === $field ) {
				if ( ! is_bool( $value ) ) {
					unset( $raw[ self::KEY_NOINDEX ] );
				} else {
					$raw[ self::KEY_NOINDEX ] = $value ? self::NOINDEX : self::INDEX;
				}

				continue;
			}

			if ( ! isset( self::TEXT_KEYS[ $field ] ) ) {
				continue;
			}

			$clean = self::clean( $value );

			if ( null === $clean ) {
				unset( $raw[ self::TEXT_KEYS[ $field ] ] );
			} else {
				$raw[ self::TEXT_KEYS[ $field ] ] = $clean;
			}
		}

		$this->store_term_array( $taxonomy, $term_id, $raw );

		return $this->reads_back_as( $taxonomy, $term_id, $changes );
	}

	/**
	 * The term's raw array, exactly as the option holds it, or null when it has none.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( string $taxonomy, int $term_id ): array {
		$option = $this->option();

		return [
			'provider' => $this->name(),
			'term'     => $option[ $taxonomy ][ $term_id ] ?? null,
		];
	}

	/**
	 * Puts the captured array back in place of whatever the term now carries.
	 *
	 * @param string               $taxonomy The taxonomy slug.
	 * @param int                  $term_id  The term identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( string $taxonomy, int $term_id, array $snapshot ): bool {
		$term = isset( $snapshot['term'] ) && is_array( $snapshot['term'] ) ? $snapshot['term'] : [];

		$this->store_term_array( $taxonomy, $term_id, $term );

		$after = $this->option();

		return ( $after[ $taxonomy ][ $term_id ] ?? null ) === ( [] === $term ? null : $term );
	}

	/**
	 * Reads Yoast's tri-state noindex word as a flag.
	 *
	 * @param mixed $stored The stored word.
	 *
	 * @return bool|null True for noindex, false for index, null otherwise.
	 */
	private function flag( mixed $stored ): ?bool {
		$word = self::clean( $stored );

		if ( self::NOINDEX === $word ) {
			return true;
		}

		return self::INDEX === $word ? false : null;
	}

	/**
	 * The whole option as an array, whatever shape the site holds it in.
	 *
	 * @return array<string, mixed> The option, `[]` when absent or malformed.
	 */
	private function option(): array {
		$option = get_option( self::OPTION, [] );

		return is_array( $option ) ? $option : [];
	}

	/**
	 * One term's array within the option.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, mixed> The term's array, `[]` when it has none.
	 */
	private function term_array( string $taxonomy, int $term_id ): array {
		$option = $this->option();
		$term   = $option[ $taxonomy ][ $term_id ] ?? null;

		return is_array( $term ) ? $term : [];
	}

	/**
	 * Rewrites the option with one term's array replaced, removed when empty.
	 *
	 * An empty term array is removed rather than stored, and an emptied taxonomy
	 * with it, so a term whose every override was cleared leaves no trace — the
	 * same state as a term nobody ever set.
	 *
	 * @param string               $taxonomy The taxonomy slug.
	 * @param int                  $term_id  The term identifier.
	 * @param array<string, mixed> $term     The term's new array.
	 */
	private function store_term_array( string $taxonomy, int $term_id, array $term ): void {
		$option = $this->option();

		if ( [] === $term ) {
			unset( $option[ $taxonomy ][ $term_id ] );

			if ( isset( $option[ $taxonomy ] ) && [] === $option[ $taxonomy ] ) {
				unset( $option[ $taxonomy ] );
			}
		} else {
			if ( ! isset( $option[ $taxonomy ] ) || ! is_array( $option[ $taxonomy ] ) ) {
				$option[ $taxonomy ] = [];
			}

			$option[ $taxonomy ][ $term_id ] = $term;
		}

		update_option( self::OPTION, $option );
	}
}
