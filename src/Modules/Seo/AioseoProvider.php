<?php
/**
 * The All in One SEO store.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * Addresses the custom table All in One SEO stores its per-post settings in.
 *
 * THIS IS THE ONE PROVIDER THAT DOES NOT READ POST META: since version 4 the
 * plugin keeps one row per post in `{prefix}aioseo_posts`, so every read is a
 * SELECT and every write an UPDATE (or an INSERT for a post the plugin has not
 * rowed yet). The snapshot is the whole row, and restore puts the whole row
 * back — column for column, id included — or removes it when none existed.
 *
 * THE ROBOTS COLUMNS ARE COUPLED THROUGH ONE SWITCH. `robots_default` = 1 means
 * "use the site rules" and every per-directive column is ignored; 0 means every
 * directive is explicit at once. The store therefore cannot hold "noindex is
 * explicit, nofollow inherits", and this module resolves that the way Rank
 * Math's missing negative is resolved — in project(), before the plan is
 * approved: CLEARING A FLAG HERE PROJECTS TO FALSE, not null, because the row
 * cannot return one directive to the default without returning both. A flag
 * write flips the row to explicit mode, and the untouched directive keeps the
 * value it was effectively rendering (its stored column, or false when the row
 * was still on the site rules).
 *
 * TWO FIELDS ARE DECLINED: the focus keyword, whose storage moved between a
 * JSON blob and a dedicated column across plugin versions, and both social
 * images, which the plugin composes from several columns. Reporting or writing
 * either would be a guess about which schema generation the site runs.
 *
 * @package SiteHelm
 */
final class AioseoProvider implements SeoProvider {

	/**
	 * The table column holding each writable text field.
	 */
	private const TEXT_COLUMNS = [
		SeoFields::FIELD_TITLE               => 'title',
		SeoFields::FIELD_DESCRIPTION         => 'description',
		SeoFields::FIELD_CANONICAL           => 'canonical_url',
		SeoFields::FIELD_OG_TITLE            => 'og_title',
		SeoFields::FIELD_OG_DESCRIPTION      => 'og_description',
		SeoFields::FIELD_TWITTER_TITLE       => 'twitter_title',
		SeoFields::FIELD_TWITTER_DESCRIPTION => 'twitter_description',
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's store IS a custom table; there is no API-shaped alternative that survives the plugin being inactive.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads must see the row the last write left, not a cache of it.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from $wpdb->prefix and a literal.

	/**
	 * The provider name a read reports.
	 */
	public function name(): string {
		return 'aioseo';
	}

	/**
	 * Every field's current value for one post.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, string|bool|null> Field name => value, every field present.
	 */
	public function values( int $post_id ): array {
		$row   = $this->row( $post_id );
		$flags = $this->robots( $row );

		$ordered = [];
		foreach ( SeoFields::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $flags ) ) {
				$ordered[ $field ] = $flags[ $field ];
				continue;
			}

			$ordered[ $field ] = isset( self::TEXT_COLUMNS[ $field ] )
				? $this->columnText( $row, self::TEXT_COLUMNS[ $field ] )
				: null;
		}

		return $ordered;
	}

	/**
	 * The plugin's stored analysis score, clamped to the 0-100 band.
	 *
	 * The plugin keeps one combined content score and no separate readability
	 * number, so the second answer is always null — the same shape Rank Math
	 * reports.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array{seoScore: int|null, readabilityScore: int|null} The scores.
	 */
	public function scores( int $post_id ): array {
		$row = $this->row( $post_id );
		$raw = null !== $row && array_key_exists( 'seo_score', $row ) ? $row['seo_score'] : null;

		$score = null;
		if ( ( is_string( $raw ) || is_int( $raw ) || is_float( $raw ) ) && is_numeric( $raw ) ) {
			$score = max( 0, min( 100, (int) round( (float) $raw ) ) );
		}

		return [
			'seoScore'         => $score,
			'readabilityScore' => null,
		];
	}

	/**
	 * What the named changes will read back as once written.
	 *
	 * A FLAG NEVER PROJECTS TO NULL: the coupled robots switch means a written
	 * row cannot hold "the site decides" for one directive alone, so both false
	 * and null requests promise false — the row goes explicit and the directive
	 * is off. That is stated here, in the plan, rather than discovered as a
	 * verification mismatch.
	 *
	 * @param array<string, string|bool|null> $changes Field name => requested value.
	 *
	 * @return array<string, string|bool|null> Field name => the value that will be readable.
	 */
	public function project( array $changes ): array {
		$projected = [];

		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, SeoFields::FLAG_FIELDS, true ) ) {
				$projected[ $field ] = ( true === $value );

				continue;
			}

			if ( ! is_string( $value ) || ! isset( self::TEXT_COLUMNS[ $field ] ) ) {
				$projected[ $field ] = null;

				continue;
			}

			$trimmed             = trim( $value );
			$projected[ $field ] = '' === $trimmed ? null : $trimmed;
		}

		return $projected;
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
		$row       = $this->row( $post_id );
		$projected = $this->project( $changes );
		$updates   = [];

		$touches_flags = false;
		foreach ( $changes as $field => $value ) {
			unset( $value );

			if ( in_array( $field, SeoFields::FLAG_FIELDS, true ) ) {
				$touches_flags = true;
				continue;
			}

			if ( isset( self::TEXT_COLUMNS[ $field ] ) ) {
				$updates[ self::TEXT_COLUMNS[ $field ] ] = $projected[ $field ];
			}
		}

		if ( $touches_flags ) {
			$current = $this->robots( $row );

			$noindex = array_key_exists( SeoFields::FIELD_NOINDEX, $projected )
				? ( true === $projected[ SeoFields::FIELD_NOINDEX ] )
				: ( true === $current[ SeoFields::FIELD_NOINDEX ] );

			$nofollow = array_key_exists( SeoFields::FIELD_NOFOLLOW, $projected )
				? ( true === $projected[ SeoFields::FIELD_NOFOLLOW ] )
				: ( true === $current[ SeoFields::FIELD_NOFOLLOW ] );

			$updates['robots_default']  = 0;
			$updates['robots_noindex']  = $noindex ? 1 : 0;
			$updates['robots_nofollow'] = $nofollow ? 1 : 0;
		}

		if ( [] !== $updates ) {
			global $wpdb;

			if ( null === $row ) {
				$now = gmdate( 'Y-m-d H:i:s' );
				$wpdb->insert(
					$this->table(),
					array_merge(
						[
							'post_id' => $post_id,
							'created' => $now,
							'updated' => $now,
						],
						$updates
					)
				);
			} else {
				$wpdb->update( $this->table(), $updates, [ 'post_id' => $post_id ] );
			}
		}

		return $this->readsBackAs( $post_id, $changes );
	}

	/**
	 * Captures this provider's raw stored state for one post.
	 *
	 * The snapshot is the whole table row — id and timestamps included — or a
	 * null row for a post the plugin has never rowed. The projection is lossy by
	 * design, so putting back anything less than the row would quietly rewrite
	 * columns the change never touched.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, mixed> The opaque snapshot.
	 */
	public function capture( int $post_id ): array {
		return [
			'provider' => $this->name(),
			'row'      => $this->row( $post_id ),
		];
	}

	/**
	 * Puts a captured snapshot back, and reports whether the store now matches it.
	 *
	 * The existing row is deleted first and the snapshot re-inserted whole, so a
	 * row the change created — absent at capture time — is removed rather than
	 * left behind by an update-only restore.
	 *
	 * @param int                  $post_id  The post identifier.
	 * @param array<string, mixed> $snapshot A snapshot this provider captured.
	 *
	 * @return bool True when the store matches the snapshot afterwards.
	 */
	public function restore( int $post_id, array $snapshot ): bool {
		$expected = isset( $snapshot['row'] ) && is_array( $snapshot['row'] ) ? $snapshot['row'] : null;

		global $wpdb;

		$table = $this->table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );

		if ( null !== $expected ) {
			$wpdb->insert( $table, $expected );
		}

		return $this->row( $post_id ) === $expected;
	}

	/**
	 * The plugin's row for one post, or null when it has none.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function row( int $post_id ): ?array {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The plugin's table name on this site.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aioseo_posts';
	}

	/**
	 * One text column, projected: absent, empty, and non-text all read as null.
	 *
	 * @param array<string, mixed>|null $row    The post's row, or null.
	 * @param string                    $column The column name.
	 *
	 * @return string|null The value, or null.
	 */
	private function columnText( ?array $row, string $column ): ?string {
		$stored = null !== $row && array_key_exists( $column, $row ) ? $row[ $column ] : null;

		if ( ! is_string( $stored ) && ! is_numeric( $stored ) ) {
			return null;
		}

		$value = trim( (string) $stored );

		return '' === $value ? null : $value;
	}

	/**
	 * The two robots flags a row currently renders.
	 *
	 * A missing row and a `robots_default` of 1 both mean the site rules apply —
	 * null for both flags. A non-numeric switch value is treated the same way,
	 * because guessing "explicit" from a corrupt column would report directives
	 * the plugin is not rendering.
	 *
	 * @param array<string, mixed>|null $row The post's row, or null.
	 *
	 * @return array<string, bool|null> Flag field name => true, false, or null.
	 */
	private function robots( ?array $row ): array {
		$inherit = [
			SeoFields::FIELD_NOINDEX  => null,
			SeoFields::FIELD_NOFOLLOW => null,
		];

		if ( null === $row ) {
			return $inherit;
		}

		$switch = $row['robots_default'] ?? null;

		if ( ! is_numeric( $switch ) || 0 !== (int) $switch ) {
			return $inherit;
		}

		return [
			SeoFields::FIELD_NOINDEX  => is_numeric( $row['robots_noindex'] ?? null ) && 1 === (int) $row['robots_noindex'],
			SeoFields::FIELD_NOFOLLOW => is_numeric( $row['robots_nofollow'] ?? null ) && 1 === (int) $row['robots_nofollow'],
		];
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

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
