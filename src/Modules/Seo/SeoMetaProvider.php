<?php
/**
 * The post-meta mechanics both SEO providers share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Reads, writes, snapshots and restores post meta on behalf of one SEO plugin.
 *
 * BOTH SUPPORTED PLUGINS STORE EVERYTHING THIS MODULE ADDRESSES AS ORDINARY POST
 * META, which is why neither provider calls a plugin function. That is not a
 * shortcut around their APIs — it is the reason this module can be trusted on a
 * site whose SEO plugin is a version nobody tested against. A meta key is a
 * stored contract; a function signature is not. It also means the read works on a
 * site where the plugin is installed but not loaded on this request.
 *
 * The mechanics live here so they are written and tested once: the projection
 * rules (absent and empty both read as null), the clearing rule (null deletes the
 * row rather than storing an empty string), the measured write, and the exact
 * snapshot. What is left to each subclass is the part that genuinely differs —
 * which keys hold which field, and how that plugin encodes robots directives.
 *
 * @package SiteHelm
 */
abstract class SeoMetaProvider implements SeoProvider {

	/**
	 * The meta key holding each writable text field.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	abstract protected function textKeys(): array;

	/**
	 * The meta key holding each reported-but-not-writable image URL.
	 *
	 * @return array<string, string> Field name => meta key.
	 */
	abstract protected function imageKeys(): array;

	/**
	 * Every meta key this provider owns, including ones no field projects.
	 *
	 * Snapshot and restore walk THIS list rather than the field map, because a
	 * plugin stores values beside the ones SiteHelm projects — an attachment id
	 * beside each social image URL, robots directives this module does not address
	 * — and a rollback that put back only the projected keys would leave the store
	 * in a combination the plugin's own interface cannot produce.
	 *
	 * @return string[] Meta keys.
	 */
	abstract protected function ownedKeys(): array;

	/**
	 * The meta key holding each stored analysis score, or null when the plugin has none.
	 *
	 * @return array{seoScore: string|null, readabilityScore: string|null} Score name => meta key.
	 */
	abstract protected function scoreKeys(): array;

	/**
	 * This plugin's stored answer for the two robots flags.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, bool|null> Flag field name => true, false, or null for "the plugin decides".
	 */
	abstract protected function readFlags( int $post_id ): array;

	/**
	 * Writes the named robots flags in this plugin's own encoding.
	 *
	 * Only the flags present in `$flags` are touched.
	 *
	 * @param int                      $post_id The post identifier.
	 * @param array<string, bool|null> $flags   Flag field name => new value.
	 */
	abstract protected function writeFlags( int $post_id, array $flags ): void;

	/**
	 * Whether this plugin's store can hold an explicit negative for the named flag.
	 *
	 * Yoast SEO can for both: `_yoast_wpseo_meta-robots-noindex` distinguishes
	 * "index" from "use the site default", and the nofollow key distinguishes
	 * "follow" from unset. Rank Math can for noindex — its robots array carries an
	 * `index` directive — but not for nofollow, because the array has no `follow`
	 * member and the absence of `nofollow` is the only way to say it.
	 *
	 * Defaults to true, so a provider only declares what it cannot do.
	 *
	 * @param string $flag The flag field name.
	 *
	 * @return bool True when `false` is storable as a distinct value.
	 */
	protected function storesExplicitNegative( string $flag ): bool {
		unset( $flag );

		return true;
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
				$flag = is_bool( $value ) ? $value : null;

				$projected[ $field ] = ( false === $flag && ! $this->storesExplicitNegative( $field ) )
					? null
					: $flag;

				continue;
			}

			// A text field with no mapped key is one this plugin does not store,
			// so the honest promise is null: apply() will skip it, and the plan
			// must say so rather than promise a value verification cannot find.
			if ( ! is_string( $value ) || ! isset( $this->textKeys()[ $field ] ) ) {
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

		foreach ( $this->textKeys() as $field => $key ) {
			$values[ $field ] = $this->readText( $post_id, $key );
		}

		foreach ( $this->imageKeys() as $field => $key ) {
			$values[ $field ] = $this->readText( $post_id, $key );
		}

		$flags = $this->readFlags( $post_id );

		$ordered = [];
		foreach ( SeoFields::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $flags ) ) {
				$ordered[ $field ] = $flags[ $field ];
				continue;
			}

			$ordered[ $field ] = $values[ $field ] ?? null;
		}

		return $ordered;
	}

	/**
	 * The plugin's stored analysis scores, both keys always present.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array{seoScore: int|null, readabilityScore: int|null} The scores.
	 */
	public function scores( int $post_id ): array {
		$scores = [];

		foreach ( $this->scoreKeys() as $name => $key ) {
			$scores[ $name ] = null === $key ? null : $this->readScore( $post_id, $key );
		}

		return $scores;
	}

	/**
	 * Reads one stored score as an integer clamped to the 0-100 band.
	 *
	 * Both plugins store the number as a string; a non-numeric or absent value is
	 * "not scored", which is null rather than zero because zero is a score.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return int|null The score, or null when the post has none.
	 */
	private function readScore( int $post_id, string $key ): ?int {
		$raw = $this->readText( $post_id, $key );

		if ( null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		return max( 0, min( 100, (int) round( (float) $raw ) ) );
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
		$keys  = $this->textKeys();
		$flags = [];

		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, SeoFields::FLAG_FIELDS, true ) ) {
				$flags[ $field ] = is_bool( $value ) ? $value : null;
				continue;
			}

			if ( ! isset( $keys[ $field ] ) ) {
				continue;
			}

			$this->writeText( $post_id, $keys[ $field ], is_string( $value ) ? $value : null );
		}

		if ( [] !== $flags ) {
			$this->writeFlags( $post_id, $flags );
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
	 * Every owned key is DELETED FIRST and then re-added from the snapshot, rather
	 * than updated in place. A key the change added did not exist at capture time,
	 * so the snapshot holds no row for it and an update-only restore would leave
	 * the added value behind — a rollback that removes some of a change and not
	 * all of it.
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
	 * One text value, projected.
	 *
	 * The single read is guarded on shape before it is trimmed, because post meta
	 * is a store any plugin or import can put an array or an object into, and
	 * `trim()` on an array is a fatal rather than a wrong answer.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return string|null The value, or null when it is unset or empty.
	 */
	protected function readText( int $post_id, string $key ): ?string {
		$stored = get_post_meta( $post_id, $key, true );

		if ( ! is_string( $stored ) && ! is_numeric( $stored ) ) {
			return null;
		}

		$value = trim( (string) $stored );

		return '' === $value ? null : $value;
	}

	/**
	 * Stores or clears one text value.
	 *
	 * @param int         $post_id The post identifier.
	 * @param string      $key     The meta key.
	 * @param string|null $value   The value, or null to clear.
	 */
	protected function writeText( int $post_id, string $key, ?string $value ): void {
		$clean = null === $value ? '' : trim( $value );

		if ( '' === $clean ) {
			delete_post_meta( $post_id, $key );

			return;
		}

		update_post_meta( $post_id, $key, $clean );
	}

	/**
	 * Every owned key's raw rows, exactly as the store holds them.
	 *
	 * The multi-value read is used rather than the single read so the answer keeps
	 * the distinction the single read erases: a key with no rows and a key with one
	 * empty row are `[]` and `['']` here, and only the first is "never set".
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
	 * agrees by construction with the promise the plan carried. Two different
	 * answers to "what should be there now" — one in the preview and one in the
	 * verification — is how a documented store limitation becomes an intermittent
	 * failed write.
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
