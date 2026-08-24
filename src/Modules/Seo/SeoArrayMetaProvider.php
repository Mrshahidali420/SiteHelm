<?php
/**
 * The shared mechanics for SEO plugins that store fields inside meta arrays.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * A SeoProvider over post meta whose values live INSIDE stored arrays.
 *
 * SeoMetaProvider assumes one meta key per field, and two of the supported
 * plugins break that assumption the same way: they keep several fields together
 * in one serialized array under one key (Slim SEO's single `slim_seo` array,
 * SureRank's `surerank_settings_*` group arrays). This base speaks in PATHS
 * instead of keys — a field is addressed as `[meta key, sub-key]`, or as
 * `[meta key, null]` when the plugin stores the value as the whole row — and
 * every write is a read-modify-write of the owning array so sub-keys this
 * module does not address survive untouched.
 *
 * The vocabulary rules are SeoMetaProvider's, restated over paths: absent and
 * empty both read as null, null writes remove the sub-key (and delete the row
 * once the array holds nothing), a field with no path is declined and projects
 * to null, and NEITHER PLUGIN STORES AN EXPLICIT NEGATIVE FLAG — an unchecked
 * box removes the entry — so a false flag projects to null the way Rank Math's
 * nofollow does.
 *
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- the
 * provider vocabulary is camelCase by module convention.
 *
 * @package SiteHelm
 */
abstract class SeoArrayMetaProvider implements SeoProvider {

	/**
	 * The path holding each writable text field.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	abstract protected function textPaths(): array;

	/**
	 * The path holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Field name => [meta key, sub-key].
	 */
	abstract protected function imagePaths(): array;

	/**
	 * The path holding each robots flag this plugin stores.
	 *
	 * A flag absent from the map is declined: it reads as null and a write
	 * projects to null.
	 *
	 * @return array<string, array{0: string, 1: string|null}> Flag field name => [meta key, sub-key].
	 */
	abstract protected function flagPaths(): array;

	/**
	 * Every meta key this provider owns, for capture and restore.
	 *
	 * @return string[] Meta keys.
	 */
	abstract protected function ownedKeys(): array;

	/**
	 * One flag's meaning, projected from whatever the store holds at its path.
	 *
	 * @param mixed $stored The raw stored value, or null when absent.
	 *
	 * @return bool|null True, false, or null for "the plugin decides".
	 */
	abstract protected function flagFromStored( mixed $stored ): ?bool;

	/**
	 * The value written at a flag's path to switch the directive on.
	 *
	 * @return mixed The stored representation of true.
	 */
	abstract protected function storedFlag(): mixed;

	/**
	 * Neither array-storing plugin persists an analysis score.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array{seoScore: int|null, readabilityScore: int|null} The scores.
	 */
	public function scores( int $post_id ): array {
		unset( $post_id );

		return [
			'seoScore'         => null,
			'readabilityScore' => null,
		];
	}

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
			if ( in_array( $field, SeoFields::FLAG_FIELDS, true ) ) {
				// True is the only value these stores can hold: an unchecked box
				// removes the entry, so false and "clear" both read back as null.
				$projected[ $field ] = ( true === $value && isset( $this->flagPaths()[ $field ] ) )
					? true
					: null;

				continue;
			}

			// A text field with no mapped path is one this plugin does not store,
			// so the honest promise is null: apply() will skip it, and the plan
			// must say so rather than promise a value verification cannot find.
			if ( ! is_string( $value ) || ! isset( $this->textPaths()[ $field ] ) ) {
				$projected[ $field ] = null;

				continue;
			}

			$trimmed             = trim( $value );
			$projected[ $field ] = '' === $trimmed ? null : $trimmed;
		}

		return $projected;
	}

	/**
	 * Every field's current value for one post.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value, every field present.
	 */
	public function values( int $post_id ): array {
		$values = [];

		foreach ( $this->textPaths() as $field => $path ) {
			$values[ $field ] = $this->readText( $post_id, $path );
		}

		foreach ( $this->imagePaths() as $field => $path ) {
			$values[ $field ] = $this->readText( $post_id, $path );
		}

		foreach ( $this->flagPaths() as $field => $path ) {
			$values[ $field ] = $this->flagFromStored( $this->readRaw( $post_id, $path ) );
		}

		$ordered = [];
		foreach ( SeoFields::FIELD_ORDER as $field ) {
			$ordered[ $field ] = $values[ $field ] ?? null;
		}

		return $ordered;
	}

	/**
	 * Writes the named changes and reports whether they are all readable afterwards.
	 *
	 * @param int                             $post_id The post identifier.
	 * @param array<string, string|bool|null> $changes Field name => new value.
	 *
	 * @return bool True when every requested change is readable.
	 */
	public function apply( int $post_id, array $changes ): bool {
		$texts = $this->textPaths();
		$flags = $this->flagPaths();

		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, SeoFields::FLAG_FIELDS, true ) ) {
				if ( ! isset( $flags[ $field ] ) ) {
					continue;
				}

				$this->writeRaw( $post_id, $flags[ $field ], true === $value ? $this->storedFlag() : null );

				continue;
			}

			if ( ! isset( $texts[ $field ] ) ) {
				continue;
			}

			$clean = is_string( $value ) ? trim( $value ) : '';
			$this->writeRaw( $post_id, $texts[ $field ], '' === $clean ? null : $clean );
		}

		return $this->readsBackAs( $post_id, $changes );
	}

	/**
	 * Captures this provider's raw stored state for one post.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( int $post_id ): array {
		return [
			'provider' => $this->name(),
			'meta'     => $this->rawMeta( $post_id ),
		];
	}

	/**
	 * Puts a captured snapshot back, and reports whether the store now matches it.
	 *
	 * Every owned key is deleted first and then re-added from the snapshot, so a
	 * key the change added — absent at capture time — is removed rather than
	 * left behind by an update-only restore.
	 *
	 * @param int                  $post_id  The post identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( int $post_id, array $snapshot ): bool {
		$meta = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : [];

		foreach ( $this->ownedKeys() as $key ) {
			delete_post_meta( $post_id, $key );

			$rows = isset( $meta[ $key ] ) && is_array( $meta[ $key ] ) ? $meta[ $key ] : [];

			foreach ( $rows as $row ) {
				add_post_meta( $post_id, $key, $row );
			}
		}

		return $this->rawMeta( $post_id ) === $meta;
	}

	/**
	 * One text value at a path, projected.
	 *
	 * @param int                              $post_id The post identifier.
	 * @param array{0: string, 1: string|null} $path  The [meta key, sub-key] path.
	 *
	 * @return string|null The value, or null when it is unset or empty.
	 */
	private function readText( int $post_id, array $path ): ?string {
		$stored = $this->readRaw( $post_id, $path );

		if ( ! is_string( $stored ) && ! is_numeric( $stored ) ) {
			return null;
		}

		$value = trim( (string) $stored );

		return '' === $value ? null : $value;
	}

	/**
	 * The raw stored value at a path, or null when absent.
	 *
	 * The whole-row read is guarded on shape before a sub-key is followed,
	 * because post meta is a store any plugin or import can put anything into.
	 *
	 * @param int                              $post_id The post identifier.
	 * @param array{0: string, 1: string|null} $path  The [meta key, sub-key] path.
	 *
	 * @return mixed The stored value, or null.
	 */
	private function readRaw( int $post_id, array $path ) {
		[ $key, $sub ] = $path;

		$stored = get_post_meta( $post_id, $key, true );

		if ( null === $sub ) {
			return '' === $stored ? null : $stored;
		}

		if ( ! is_array( $stored ) || ! array_key_exists( $sub, $stored ) ) {
			return null;
		}

		return $stored[ $sub ];
	}

	/**
	 * Stores or clears one value at a path, leaving the rest of its array alone.
	 *
	 * A null value removes the sub-key, and the whole row is deleted once the
	 * array holds nothing: an empty array and an absent row render identically,
	 * and only the absent row is honest about never having been set.
	 *
	 * @param int                              $post_id The post identifier.
	 * @param array{0: string, 1: string|null} $path  The [meta key, sub-key] path.
	 * @param mixed                            $value  The value, or null to clear.
	 */
	private function writeRaw( int $post_id, array $path, $value ): void {
		[ $key, $sub ] = $path;

		if ( null === $sub ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );

				return;
			}

			update_post_meta( $post_id, $key, $value );

			return;
		}

		$stored = get_post_meta( $post_id, $key, true );
		$row    = is_array( $stored ) ? $stored : [];

		if ( null === $value ) {
			unset( $row[ $sub ] );
		} else {
			$row[ $sub ] = $value;
		}

		if ( [] === $row ) {
			delete_post_meta( $post_id, $key );

			return;
		}

		update_post_meta( $post_id, $key, $row );
	}

	/**
	 * Every owned key's raw rows, exactly as the store holds them.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, mixed[]> Meta key => raw rows.
	 */
	private function rawMeta( int $post_id ): array {
		$meta = [];

		foreach ( $this->ownedKeys() as $key ) {
			$rows         = get_post_meta( $post_id, $key, false );
			$meta[ $key ] = is_array( $rows ) ? array_values( $rows ) : [];
		}

		return $meta;
	}

	/**
	 * Whether every requested change is the value that now comes back.
	 *
	 * Compared against project() rather than against the raw request, so this
	 * agrees by construction with the promise the plan carried.
	 *
	 * @param int                             $post_id The post identifier.
	 * @param array<string, string|bool|null> $changes Field name => requested value.
	 *
	 * @return bool True when the store agrees with the projected request.
	 */
	private function readsBackAs( int $post_id, array $changes ): bool {
		$current = $this->values( $post_id );

		foreach ( $this->project( $changes ) as $field => $expected ) {
			if ( ! array_key_exists( $field, $current ) || $current[ $field ] !== $expected ) {
				return false;
			}
		}

		return true;
	}
}
