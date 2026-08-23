<?php
/**
 * Rank Math's term metadata, read and written as term meta.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Rank Math stores a term's SEO metadata as term meta under the same keys it uses
 * for a post, and its robots directives as one array under `rank_math_robots`.
 *
 * THE DIRECTIVE ARRAY IS EDITED, NOT REPLACED: it can hold directives SiteHelm
 * does not address on a term (`noarchive`, `nosnippet`), and a write that set the
 * noindex state must leave them as they were. A noindex change removes both
 * `noindex` and `index` and adds the one requested; null adds neither; an array
 * left empty is deleted so a cleared term leaves no row.
 *
 * A snapshot is every owned key's raw rows, and a restore deletes then re-adds
 * each, for the reason SeoMetaProvider::restore() gives: a key the change added
 * has no row in the snapshot, and only delete-then-re-add removes it.
 *
 * @package SiteHelm
 */
final class RankMathTermProvider extends SeoTermProviderBase {

	private const KEY_ROBOTS = 'rank_math_robots';

	private const NOINDEX = 'noindex';

	private const INDEX = 'index';

	private const TEXT_KEYS = [
		SeoFields::FIELD_TITLE         => 'rank_math_title',
		SeoFields::FIELD_DESCRIPTION   => 'rank_math_description',
		SeoFields::FIELD_CANONICAL     => 'rank_math_canonical_url',
		SeoFields::FIELD_FOCUS_KEYWORD => 'rank_math_focus_keyword',
	];

	/**
	 * The provider's stable name.
	 *
	 * @return string Always 'rank-math'.
	 */
	public function name(): string {
		return 'rank-math';
	}

	/**
	 * Every term field's current value.
	 *
	 * @param string $taxonomy The taxonomy slug, unused: term meta is keyed by id alone.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value.
	 */
	public function values( string $taxonomy, int $term_id ): array {
		unset( $taxonomy );

		$values = [];

		foreach ( SeoTermFields::FIELD_ORDER as $field ) {
			if ( SeoFields::FIELD_NOINDEX === $field ) {
				$values[ $field ] = $this->noindex( $term_id );

				continue;
			}

			$values[ $field ] = self::clean( get_term_meta( $term_id, self::TEXT_KEYS[ $field ], true ) );
		}

		return $values;
	}

	/**
	 * Writes the named changes and reports whether they read back.
	 *
	 * @param string                          $taxonomy The taxonomy slug.
	 * @param int                             $term_id  The term identifier.
	 * @param array<string, string|bool|null> $changes  Field name => new value.
	 *
	 * @return bool True when every requested change is readable.
	 */
	public function apply( string $taxonomy, int $term_id, array $changes ): bool {
		foreach ( $changes as $field => $value ) {
			if ( SeoFields::FIELD_NOINDEX === $field ) {
				$this->write_noindex( $term_id, is_bool( $value ) ? $value : null );

				continue;
			}

			if ( ! isset( self::TEXT_KEYS[ $field ] ) ) {
				continue;
			}

			$clean = self::clean( $value );

			if ( null === $clean ) {
				delete_term_meta( $term_id, self::TEXT_KEYS[ $field ] );
			} else {
				update_term_meta( $term_id, self::TEXT_KEYS[ $field ], $clean );
			}
		}

		return $this->reads_back_as( $taxonomy, $term_id, $changes );
	}

	/**
	 * Every owned key's raw rows.
	 *
	 * @param string $taxonomy The taxonomy slug, unused.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( string $taxonomy, int $term_id ): array {
		unset( $taxonomy );

		return [
			'provider' => $this->name(),
			'meta'     => $this->raw_meta( $term_id ),
		];
	}

	/**
	 * Deletes every owned key and re-adds the captured rows.
	 *
	 * @param string               $taxonomy The taxonomy slug, unused.
	 * @param int                  $term_id  The term identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( string $taxonomy, int $term_id, array $snapshot ): bool {
		unset( $taxonomy );

		$meta = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : [];

		foreach ( $this->owned_keys() as $key ) {
			delete_term_meta( $term_id, $key );

			$rows = isset( $meta[ $key ] ) && is_array( $meta[ $key ] ) ? $meta[ $key ] : [];

			foreach ( $rows as $row ) {
				add_term_meta( $term_id, $key, $row );
			}
		}

		return $this->raw_meta( $term_id ) === $meta;
	}

	/**
	 * The keys this provider owns on a term.
	 *
	 * @return string[] Meta keys.
	 */
	private function owned_keys(): array {
		return array_merge( array_values( self::TEXT_KEYS ), [ self::KEY_ROBOTS ] );
	}

	/**
	 * Every owned key's raw rows, as the multi-value read returns them.
	 *
	 * @param int $term_id The term identifier.
	 *
	 * @return array<string, mixed[]> Meta key => raw rows.
	 */
	private function raw_meta( int $term_id ): array {
		$meta = [];

		foreach ( $this->owned_keys() as $key ) {
			$rows         = get_term_meta( $term_id, $key, false );
			$meta[ $key ] = is_array( $rows ) ? array_values( $rows ) : [];
		}

		return $meta;
	}

	/**
	 * The noindex state the directive array holds.
	 *
	 * @param int $term_id The term identifier.
	 *
	 * @return bool|null True for noindex, false for index, null for neither.
	 */
	private function noindex( int $term_id ): ?bool {
		$directives = $this->directives( $term_id );

		if ( in_array( self::NOINDEX, $directives, true ) ) {
			return true;
		}

		return in_array( self::INDEX, $directives, true ) ? false : null;
	}

	/**
	 * Sets the noindex state, leaving every other directive as it was.
	 *
	 * @param int       $term_id The term identifier.
	 * @param bool|null $value   True, false, or null to remove both words.
	 */
	private function write_noindex( int $term_id, ?bool $value ): void {
		$directives = array_values(
			array_filter(
				$this->directives( $term_id ),
				static fn( string $directive ): bool => ! in_array( $directive, [ self::NOINDEX, self::INDEX ], true )
			)
		);

		if ( true === $value ) {
			$directives[] = self::NOINDEX;
		} elseif ( false === $value ) {
			$directives[] = self::INDEX;
		}

		if ( [] === $directives ) {
			delete_term_meta( $term_id, self::KEY_ROBOTS );

			return;
		}

		update_term_meta( $term_id, self::KEY_ROBOTS, $directives );
	}

	/**
	 * The stored directive array, cleaned to a list of non-empty strings.
	 *
	 * @param int $term_id The term identifier.
	 *
	 * @return string[] The directives.
	 */
	private function directives( int $term_id ): array {
		$stored = get_term_meta( $term_id, self::KEY_ROBOTS, true );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$clean = [];

		foreach ( $stored as $directive ) {
			if ( is_string( $directive ) && '' !== trim( $directive ) ) {
				$clean[] = trim( $directive );
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
