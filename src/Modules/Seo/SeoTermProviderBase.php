<?php
/**
 * The projection and verification both term providers share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * What a term change reads back as, and whether it did — independent of the store.
 *
 * BOTH TERM STORES HOLD AN EXPLICIT "INDEX", so unlike the post providers there
 * is no flag whose false collapses to null: Yoast writes the word `index` and
 * Rank Math the `index` directive. The projection is therefore the same for both:
 * text is trimmed and an empty text clears, a flag is true, false or null.
 *
 * @package SiteHelm
 */
abstract class SeoTermProviderBase implements SeoTermProvider {

	/**
	 * What the named changes will read back as once written.
	 *
	 * @param array<string, string|bool|null> $changes Field name => requested value.
	 *
	 * @return array<string, string|bool|null> Field name => the value that will be readable.
	 */
	public function project( array $changes ): array {
		$projected = [];

		foreach ( $changes as $field => $value ) {
			if ( ! in_array( $field, SeoTermFields::FIELD_ORDER, true ) ) {
				continue;
			}

			if ( in_array( $field, SeoTermFields::FLAG_FIELDS, true ) ) {
				$projected[ $field ] = is_bool( $value ) ? $value : null;

				continue;
			}

			$projected[ $field ] = self::clean( $value );
		}

		return $projected;
	}

	/**
	 * Whether every requested change is the value that now comes back.
	 *
	 * @param string                          $taxonomy The taxonomy slug.
	 * @param int                             $term_id  The term identifier.
	 * @param array<string, string|bool|null> $changes  Field name => requested value.
	 *
	 * @return bool True when the store agrees with the projected request.
	 */
	protected function reads_back_as( string $taxonomy, int $term_id, array $changes ): bool {
		$current = $this->values( $taxonomy, $term_id );

		foreach ( $this->project( $changes ) as $field => $expected ) {
			if ( ! array_key_exists( $field, $current ) || $current[ $field ] !== $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A stored or requested text, projected: trimmed, and null when absent or blank.
	 *
	 * Guarded on shape because both stores can hold an array where a string is
	 * expected — an import, another plugin — and `trim()` on an array is a fatal.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The projected text.
	 */
	protected static function clean( mixed $value ): ?string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}

		$trimmed = trim( (string) $value );

		return '' === $trimmed ? null : $trimmed;
	}
}
