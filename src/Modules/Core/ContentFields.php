<?php
/**
 * The normalized WordPress content field map.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use stdClass;

/**
 * The one place that decides what "the state of a content item" means.
 *
 * Every consumer shares this definition: the change engine fingerprints this
 * map, the preview diffs it, verification compares against it, and the read
 * operation projects it into a client-facing record. Keeping it in one class is
 * what makes a fingerprint taken at preview comparable to one taken at apply.
 *
 * @package SiteHelm
 */
final class ContentFields {

	/**
	 * The option holding the administrator's permitted post-meta keys.
	 */
	public const META_ALLOWLIST_OPTION = 'sitehelm_meta_allowlist';

	/**
	 * The canonical field order. Field maps are built in this order and previews
	 * are rendered in this order, so output is deterministic rather than
	 * dependent on PHP array insertion order.
	 */
	public const FIELD_ORDER = [
		'post_type',
		'post_status',
		'post_title',
		'post_name',
		'post_content',
		'post_excerpt',
		'post_parent',
		'post_modified_gmt',
		'featured_media',
		'terms',
		'meta',
	];

	/**
	 * Statuses that keep content inside the draft workflow rather than making
	 * it live or otherwise visible outside it. Any status a write's input
	 * schema admits that is not listed here requires the post type's own
	 * publish capability, so a status added to a schema later fails closed by
	 * default instead of silently becoming writable by anyone who can write a
	 * draft.
	 *
	 * `private` is deliberately absent. WordPress requires publish_posts to set
	 * a private status, so treating it as draft-like would be a capability
	 * bypass rather than a convenience. Only draft and pending are below the
	 * line.
	 *
	 * This lives here rather than on one operation because it decides whether a
	 * capability is required, and more than one write needs the same answer. Two
	 * copies of a security-relevant split drift apart independently, and the
	 * copy that drifts is the one nobody is looking at.
	 */
	public const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];

	/**
	 * The target-key prefix for a post-shaped target.
	 */
	private const POST_PREFIX = 'post:';

	/**
	 * The maximum accepted post-meta key length.
	 */
	private const MAX_META_KEY_LENGTH = 255;

	/**
	 * The fields WordPress trims unconditionally when it stores them.
	 *
	 * Core registers `add_filter( 'title_save_pre', 'trim' )` in
	 * default-filters.php, OUTSIDE kses_init_filters(). That placement is the
	 * whole point: the trim applies on every branch, including a user holding
	 * unfiltered_html for whom kses never runs, and `wp_kses_data` does not trim.
	 *
	 * Verified against WordPress 7.0.2: a title sent as "  Padded title \n" is
	 * stored as "Padded title", while `content_save_pre` and `excerpt_save_pre`
	 * register no trim at all and stored the same padding byte-identically.
	 */
	private const TRIMMED_FIELDS = [ 'post_title' ];

	/**
	 * The stable target key for one existing post.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return string The target key, for example 'post:42'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function targetKey( int $postId ): string {
		return self::POST_PREFIX . $postId;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The target key used before a post exists, so a creation plan still binds
	 * to a concrete, stable target string.
	 *
	 * @return string The pending target key.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function pendingTargetKey(): string {
		return self::POST_PREFIX . 'new';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The post identifier a target key refers to.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int The post identifier, or 0 when the key names no existing post.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function postIdFromTargetKey( string $targetKey ): int {
		if ( ! str_starts_with( $targetKey, self::POST_PREFIX ) ) {
			return 0;
		}
		$suffix = substr( $targetKey, strlen( self::POST_PREFIX ) );

		return ctype_digit( $suffix ) ? (int) $suffix : 0;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One content field's value exactly as WordPress will store it.
	 *
	 * The promised after-state must model what WordPress actually persists,
	 * because that promise is what a human approves at preview and what
	 * verification compares the re-read item against. A transformation core
	 * applies that the promise does not model makes the preview show one value
	 * while the site stores another, which is precisely what the missing title
	 * trim did. Back when verification demanded byte-equality it also made that
	 * perfectly correct write report `verification_failed`; that failure mode
	 * has since been removed, and an unmodelled transformation now succeeds as
	 * `verified-with-adjustments` with the stored value disclosed. What is still
	 * at stake here is whether the preview tells the truth, which is reason
	 * enough. So this models core's save filters in core's own order: the
	 * unconditional trim first, then kses, matching the registration order of
	 * the two priority-10 callbacks on `title_save_pre`.
	 *
	 * A user holding unfiltered_html bypasses kses in WordPress, so the promise
	 * bypasses it too; otherwise every write by that user would be previewed
	 * wrongly and then reported as adjusted when nothing was adjusted. The trim
	 * is not part of that bypass, because core does not register it with the
	 * kses set.
	 *
	 * Both content writes that accept free text — create and update — call this.
	 * They previously held a byte-identical private copy each, and the trim was
	 * absent from both. The writes that set a single non-text field, such as
	 * status or featured media, have nothing for it to sanitize.
	 *
	 * The boundary is deliberate and bounded: this models only PURE, INPUT-ONLY
	 * transformations — deterministic functions of the requested value alone,
	 * being core's unconditional trim and the kses pass. It does NOT model
	 * transformations that depend on database state, other rows, the clock, or
	 * capability-gated filter members, because those cannot be known when the
	 * preview is generated. A slug is uniquified against whatever else exists at
	 * the moment of the insert; a publish becomes a future depending on the post's
	 * date; a featured media id is dropped if no such attachment exists. No pure
	 * function of the input can predict them, so attempting to would manufacture
	 * a guarantee that cannot be kept. WriteVerifier is what makes leaving them
	 * unmodelled safe: a value WordPress adjusts succeeds and is disclosed rather
	 * than reported as a failure.
	 *
	 * @param string $field  The normalized field name.
	 * @param string $value  The requested value.
	 * @param int    $userId The acting WordPress user.
	 *
	 * @return string The value as WordPress will store it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function sanitizeForSave( string $field, string $value, int $userId ): string {
		if ( in_array( $field, self::TRIMMED_FIELDS, true ) ) {
			$value = trim( $value );
		}

		if ( user_can( $userId, 'unfiltered_html' ) ) {
			return $value;
		}

		return match ( $field ) {
			'post_title' => (string) wp_kses_data( $value ),
			default      => (string) wp_kses_post( $value ),
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The administrator-permitted post-meta keys, validated and sorted.
	 *
	 * Keys beginning with an underscore are refused unconditionally: WordPress
	 * treats them as protected internal meta, and exposing them would leak
	 * builder payloads, edit locks, and attachment internals through an
	 * allowlist intended for ordinary custom fields.
	 *
	 * @return string[] The permitted meta keys in ascending order.
	 */
	public function allowlist(): array {
		$stored = get_option( self::META_ALLOWLIST_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$keys = [];
		foreach ( $stored as $key ) {
			if ( ! is_string( $key ) || '' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( strlen( $key ) > self::MAX_META_KEY_LENGTH ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				continue;
			}
			$keys[] = $key;
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Reads the normalized field map for one post.
	 *
	 * The get_post() call is duck-typed rather than checked against WP_Post, so
	 * the map stays unit-testable without loading WordPress.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return array<string, mixed>|null The field map, or null when absent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function read( int $postId ): ?array {
		if ( $postId <= 0 ) {
			return null;
		}
		$post = get_post( $postId );
		if ( ! is_object( $post ) || ! isset( $post->ID ) || (int) $post->ID !== $postId ) {
			return null;
		}

		$post_type = (string) $post->post_type;

		return [
			'post_type'         => $post_type,
			'post_status'       => (string) $post->post_status,
			'post_title'        => (string) $post->post_title,
			'post_name'         => (string) $post->post_name,
			'post_content'      => (string) $post->post_content,
			'post_excerpt'      => (string) $post->post_excerpt,
			'post_parent'       => (int) $post->post_parent,
			'post_modified_gmt' => (string) $post->post_modified_gmt,
			'featured_media'    => (int) get_post_thumbnail_id( $postId ),
			'terms'             => $this->terms( $postId, $post_type ),
			'meta'              => $this->meta( $postId ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Projects a field map into the client-facing record.
	 *
	 * Empty maps become objects so the JSON payload matches the advertised
	 * output schema, where `terms` and `meta` are objects rather than arrays.
	 *
	 * @param int                  $postId The post identifier.
	 * @param array<string, mixed> $fields The normalized field map.
	 *
	 * @return array<string, mixed> The client-facing record.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function publicRecord( int $postId, array $fields ): array {
		return [
			'id'            => $postId,
			'type'          => $fields['post_type'] ?? '',
			'status'        => $fields['post_status'] ?? '',
			'title'         => $fields['post_title'] ?? '',
			'slug'          => $fields['post_name'] ?? '',
			'content'       => $fields['post_content'] ?? '',
			'excerpt'       => $fields['post_excerpt'] ?? '',
			'parent'        => $fields['post_parent'] ?? 0,
			'modifiedGmt'   => $fields['post_modified_gmt'] ?? '',
			'featuredMedia' => $fields['featured_media'] ?? 0,
			'terms'         => [] === ( $fields['terms'] ?? [] ) ? new stdClass() : $fields['terms'],
			'meta'          => [] === ( $fields['meta'] ?? [] ) ? new stdClass() : $fields['meta'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The post's taxonomy terms as sorted identifier lists.
	 *
	 * Both taxonomies and identifiers are sorted so that re-saving the same
	 * terms in a different order does not change the state fingerprint.
	 *
	 * @param int    $post_id   The post identifier.
	 * @param string $post_type The post type.
	 *
	 * @return array<string, int[]> Taxonomy name to sorted term identifiers.
	 */
	private function terms( int $post_id, string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type );
		if ( ! is_array( $taxonomies ) ) {
			return [];
		}

		$map = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$ids = array_values( array_map( 'intval', $ids ) );
			sort( $ids, SORT_NUMERIC );
			$map[ $taxonomy ] = $ids;
		}
		ksort( $map, SORT_STRING );

		return $map;
	}

	/**
	 * The post's permitted meta values, as strings.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, string> Permitted meta key to value.
	 */
	private function meta( int $post_id ): array {
		$map = [];
		foreach ( $this->allowlist() as $key ) {
			$value       = get_post_meta( $post_id, $key, true );
			$map[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		ksort( $map, SORT_STRING );

		return $map;
	}
}
