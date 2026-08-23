<?php
/**
 * REQ-0098 (Pro): the option mechanics every settings provider shares.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

/**
 * Reads, writes, captures and restores the option keys a provider owns.
 *
 * A SNAPSHOT IS ROWS, NOT VALUES: each owned key is captured as `[]` (absent) or
 * `[value]` (present), so a restore can tell "put the empty string back" from
 * "remove the key", and a key the change added is removed again. The comparison
 * after restore is against a fresh capture, so the answer is what the store
 * holds, not what the provider meant to store.
 *
 * A write is verified by re-reading: `update_option()` answers false both when
 * it failed and when the value was already there, so its answer alone would
 * call a no-op write a failure.
 */
abstract class SeoSettingsProviderBase implements SeoSettingsProvider {

	/**
	 * The option keys the scope owns, grouped by option name.
	 *
	 * @param string|null $post_type The scope.
	 *
	 * @return array<string, string[]> Option name => keys.
	 */
	abstract protected function owned_keys( ?string $post_type ): array;

	/**
	 * See the parent.
	 *
	 * @param string $post_type See the parent.
	 */
	public function capture( ?string $post_type ): array {
		$options = [];

		foreach ( $this->owned_keys( $post_type ) as $name => $keys ) {
			$stored = $this->option( $name );

			foreach ( $keys as $key ) {
				$options[ $name ][ $key ] = array_key_exists( $key, $stored ) ? [ $stored[ $key ] ] : [];
			}
		}

		return [
			'provider' => $this->name(),
			'options'  => $options,
		];
	}

	/**
	 * See the parent.
	 *
	 * @param string               $post_type See the parent.
	 * @param array<string, mixed> $snapshot See the parent.
	 */
	public function restore( ?string $post_type, array $snapshot ): bool {
		$wanted = $snapshot['options'] ?? null;

		if ( ! is_array( $wanted ) ) {
			return false;
		}

		foreach ( $this->owned_keys( $post_type ) as $name => $keys ) {
			$stored = $this->option( $name );

			foreach ( $keys as $key ) {
				$rows = $wanted[ $name ][ $key ] ?? [];

				if ( is_array( $rows ) && [] !== $rows ) {
					$stored[ $key ] = $rows[0];
				} else {
					unset( $stored[ $key ] );
				}
			}

			$this->write_option( $name, $stored );
		}

		return $this->capture( $post_type )['options'] === $wanted;
	}

	/**
	 * One option as an array; anything else reads as empty.
	 *
	 * @param string $name The option name.
	 *
	 * @return array<string, mixed> The option.
	 */
	protected function option( string $name ): array {
		$stored = get_option( $name, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Writes one option and answers whether it now reads back as written.
	 *
	 * @param string               $name  The option name.
	 * @param array<string, mixed> $value The value.
	 *
	 * @return bool True when the store holds the value.
	 */
	protected function write_option( string $name, array $value ): bool {
		if ( $this->option( $name ) !== $value ) {
			update_option( $name, $value );
		}

		return $this->option( $name ) === $value;
	}

	/**
	 * A stored text value: trimmed, or null when absent, empty, or not text.
	 *
	 * @param array<string, mixed> $stored The option.
	 * @param string               $key    The key.
	 *
	 * @return string|null The text.
	 */
	protected function text( array $stored, string $key ): ?string {
		$value = $stored[ $key ] ?? null;

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return '' === $value ? null : $value;
	}

	/**
	 * A requested text value as it will be stored: trimmed, null when cleared.
	 *
	 * @param mixed $value The requested value.
	 *
	 * @return string|null The projection.
	 */
	protected function project_text( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		return '' === $value ? null : $value;
	}
}
