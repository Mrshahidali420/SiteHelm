<?php
/**
 * The normalized WordPress attachment field map.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

/**
 * The one place that decides what "the state of an attachment" means.
 *
 * Every media consumer shares this definition: the read operation projects it
 * into a client-facing record, the listing projects its seven-field summary,
 * and the writes verify against it. Keeping it in one class is what makes a
 * value read at preview comparable to one read at apply.
 *
 * @package SiteHelm
 */
final class MediaFields {

	/**
	 * The option holding the operator's permitted upload MIME types.
	 */
	public const MIME_ALLOWLIST_OPTION = 'sitehelm_media_mime_allowlist';

	/**
	 * The target-key prefix for an attachment-shaped target.
	 */
	public const ATTACHMENT_PREFIX = 'attachment:';

	/**
	 * The target key used before an attachment exists, so a creation plan still
	 * binds to a concrete, stable target string across preview and apply.
	 */
	public const PENDING_TARGET_KEY = 'attachment:new';

	/**
	 * The one post type this module reads or writes.
	 */
	public const ATTACHMENT_TYPE = 'attachment';

	/**
	 * The post meta key WordPress stores alternative text under.
	 */
	public const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * The four inert raster types permitted when no operator override is stored.
	 *
	 * This deliberately diverges from ContentFields::META_ALLOWLIST_OPTION's
	 * fail-closed empty default. A site with no configured meta allowlist still
	 * functions; an upload operation that permits nothing by default cannot
	 * satisfy REQ-0023 without configuration first. Four inert raster formats
	 * are still fail-closed in the sense that matters — nothing executable,
	 * nothing scriptable, nothing that renders attacker markup.
	 */
	public const DEFAULT_MIME_ALLOWLIST = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

	/**
	 * Never reachable, whatever the option says. SVG is a scripting vector.
	 */
	public const DENIED_MIME_TYPES = [ 'image/svg+xml' ];

	/**
	 * Never reachable, whatever the option says: anything executable or
	 * markup-bearing.
	 */
	public const DENIED_EXTENSIONS = [ 'svg', 'svgz', 'php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'js' ];

	/**
	 * A positive decimal integer with no leading zero, which is the only suffix
	 * an attachment target key may carry.
	 */
	private const ID_SUFFIX_PATTERN = '/^[1-9][0-9]*$/';

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The stable target key for one existing attachment.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return string The target key, for example 'attachment:42'.
	 */
	public function targetKey( int $attachmentId ): string {
		return self::ATTACHMENT_PREFIX . $attachmentId;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The target key used before an attachment exists.
	 *
	 * @return string The pending target key.
	 */
	public function pendingTargetKey(): string {
		return self::PENDING_TARGET_KEY;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The attachment identifier a target key names, or null when it names none.
	 *
	 * The pending key returns null rather than 0 on purpose: 0 is an int a
	 * caller could pass on to get_post(), which resolves it to whatever post is
	 * in the loop. Null cannot be mistaken for an identifier.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int|null The attachment identifier, or null.
	 */
	public function attachmentIdFromKey( string $targetKey ): ?int {
		if ( ! str_starts_with( $targetKey, self::ATTACHMENT_PREFIX ) ) {
			return null;
		}

		$suffix = substr( $targetKey, strlen( self::ATTACHMENT_PREFIX ) );

		if ( 1 !== preg_match( self::ID_SUFFIX_PATTERN, $suffix ) ) {
			return null;
		}

		return (int) $suffix;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * The full normalized record for one attachment.
	 *
	 * The identity of the returned row is asserted rather than assumed. The
	 * `<= 0` guard above already stops the `get_post( 0 )` case, where core
	 * returns `$GLOBALS['post']` instead of null; the
	 * `(int) $media->ID === $attachmentId` comparison covers the remaining
	 * case, where a filter on `get_post` hands back a row other than the one
	 * asked for. Without it that row would be reported as the answer.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return array<string, mixed>|null The record, or null when the identifier
	 *                                   is not a readable attachment.
	 */
	public function read( int $attachmentId ): ?array {
		if ( $attachmentId <= 0 ) {
			return null;
		}

		$media = get_post( $attachmentId );

		if ( ! is_object( $media ) || ! isset( $media->ID ) || (int) $media->ID !== $attachmentId ) {
			return null;
		}

		if ( self::ATTACHMENT_TYPE !== (string) $media->post_type ) {
			return null;
		}

		$url      = (string) wp_get_attachment_url( $attachmentId );
		$file     = (string) get_attached_file( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		$metadata = is_array( $metadata ) ? $metadata : [];

		return [
			'id'          => $attachmentId,
			'title'       => (string) $media->post_title,
			'filename'    => '' === $file ? '' : (string) wp_basename( $file ),
			'mimeType'    => (string) $media->post_mime_type,
			'url'         => $url,
			'alt'         => (string) get_post_meta( $attachmentId, self::ALT_META_KEY, true ),
			'caption'     => (string) $media->post_excerpt,
			'description' => (string) $media->post_content,
			'parent'      => (int) $media->post_parent,
			'uploadedGmt' => (string) $media->post_date_gmt,
			'width'       => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'      => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'filesize'    => $this->filesize( $metadata, $file ),
			'sizes'       => $this->renditions( $metadata, $url ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	/**
	 * The seven-member listing summary for one attachment.
	 *
	 * Derived from read() rather than restated, so the two projections cannot
	 * disagree about what a filename or an upload time is.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return array<string, mixed>|null The summary, or null when the identifier
	 *                                   is not a readable attachment.
	 */
	public function summary( int $attachmentId ): ?array {
		$record = $this->read( $attachmentId );

		if ( null === $record ) {
			return null;
		}

		return [
			'id'          => $record['id'],
			'title'       => $record['title'],
			'filename'    => $record['filename'],
			'mimeType'    => $record['mimeType'],
			'url'         => $record['url'],
			'parent'      => $record['parent'],
			'uploadedGmt' => $record['uploadedGmt'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The image sizes this site's theme and plugins register.
	 *
	 * Registration order is whatever sequence the site's plugins happened to
	 * boot in, so it is sorted by name for the same reason TaxonomyList sorts
	 * taxonomies: the same site state must produce the same response.
	 *
	 * `crop` may be declared as a boolean or as a positional array such as
	 * `[ 'center', 'top' ]`. Both mean cropped, so it is cast rather than
	 * compared, and a client reads one boolean either way.
	 *
	 * @return array<int, array<string, mixed>> Registered sizes, sorted by name.
	 */
	public function registeredSizes(): array {
		$registered = wp_get_registered_image_subsizes();

		if ( ! is_array( $registered ) ) {
			return [];
		}

		ksort( $registered, SORT_STRING );

		$sizes = [];

		foreach ( $registered as $name => $size ) {
			$sizes[] = [
				'name'   => (string) $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => (bool) ( $size['crop'] ?? false ),
			];
		}

		return $sizes;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The effective upload MIME allowlist for this site.
	 *
	 * Three inputs, applied in this order, and the order is the contract:
	 *
	 * 1. The built-in default — four inert raster types — used when the operator
	 *    option is absent, empty, or not an array. This deliberately diverges
	 *    from ContentFields::allowlist(), which defaults to `[]`. A meta
	 *    allowlist that permits nothing still leaves a working site; an upload
	 *    operation that permits nothing cannot demonstrate REQ-0023 without
	 *    configuration first. Four raster types is still fail-closed in the
	 *    sense that matters: nothing executable, nothing scriptable, nothing
	 *    that renders attacker markup.
	 * 2. The operator option, which REPLACES the default when it is non-empty.
	 * 3. The deny lists and the site's own upload permissions, both SUBTRACTED
	 *    last so that neither the operator nor a plugin can add its way past
	 *    them.
	 *
	 * `sitehelm_media_mime_allowlist` sits between step 2 and step 3, so a site
	 * or an add-on can ADD a type — the Pro add-on appends `application/zip` so a
	 * theme or plugin package can reach the library — but cannot add its way past
	 * anything: the filtered list still runs the whole of step 3, so a filter that
	 * returns `image/svg+xml`, `text/html` or a PHP type gets it subtracted again
	 * immediately, and a type the site itself does not permit is dropped too.
	 *
	 * The deny list is subtracted on two independent axes because neither alone
	 * is sufficient. DENIED_MIME_TYPES catches a denied type registered under an
	 * extension the deny list does not name; DENIED_EXTENSIONS catches a type
	 * the deny list does not name — text/html — that core registers under an
	 * extension it does. Each axis has a test that fails when it alone is
	 * removed.
	 *
	 * @return string[] The permitted MIME types, lowercase, in effective order.
	 */
	public function mimeAllowlist(): array {
		$configured = get_option( self::MIME_ALLOWLIST_OPTION, [] );
		$requested  = is_array( $configured ) ? $this->normalize_types( $configured ) : [];
		$effective  = [] === $requested ? self::DEFAULT_MIME_ALLOWLIST : $requested;

		/**
		 * Filters the MIME types SiteHelm will accept into the media library.
		 *
		 * Additive only in effect: whatever this returns is still subtracted
		 * against the deny lists and the site's own `get_allowed_mime_types()`
		 * below, so a filter cannot re-admit a denied type.
		 *
		 * @param string[] $effective The requested or default allowlist.
		 */
		$filtered  = apply_filters( 'sitehelm_media_mime_allowlist', $effective );
		$effective = is_array( $filtered ) ? $this->normalize_types( $filtered ) : $effective;

		$permitted = get_allowed_mime_types();
		$permitted = is_array( $permitted ) ? $permitted : [];
		$types     = array_map( 'strtolower', array_values( $permitted ) );

		$allowed = [];
		foreach ( $effective as $type ) {
			if ( in_array( $type, self::DENIED_MIME_TYPES, true ) ) {
				continue;
			}
			if ( ! in_array( $type, $types, true ) ) {
				continue;
			}
			if ( $this->has_denied_extension( $type, $permitted ) ) {
				continue;
			}
			$allowed[] = $type;
		}

		return array_values( array_unique( $allowed ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Lowercases, trims, and drops empty members of a stored type list.
	 *
	 * @param mixed[] $types The stored option value.
	 *
	 * @return string[] The normalized types.
	 */
	private function normalize_types( array $types ): array {
		$normalized = [];

		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}

			$type = strtolower( trim( $type ) );

			if ( '' !== $type ) {
				$normalized[] = $type;
			}
		}

		return $normalized;
	}

	/**
	 * Whether the site registers this MIME type under any denied extension.
	 *
	 * The keys of get_allowed_mime_types() are pipe-separated extension patterns,
	 * so `htm|html => text/html` must be tested member by member rather than as a
	 * single string.
	 *
	 * @param string                $type      The candidate MIME type, lowercase.
	 * @param array<string, string> $permitted The site's upload permission table.
	 *
	 * @return bool True when any registered extension for the type is denied.
	 */
	private function has_denied_extension( string $type, array $permitted ): bool {
		foreach ( $permitted as $pattern => $mime ) {
			if ( strtolower( (string) $mime ) !== $type ) {
				continue;
			}

			foreach ( explode( '|', strtolower( (string) $pattern ) ) as $extension ) {
				if ( in_array( $extension, self::DENIED_EXTENSIONS, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The attachment's byte count, or null when it cannot be established.
	 *
	 * WordPress 6.0 and later record `filesize` in the attachment metadata, so
	 * that is preferred. Otherwise the file on disk is measured, and a file that
	 * is missing — a real and common state on a migrated site — measures 0.
	 * Zero is a plausible filesize, so it is reported as null rather than
	 * passed through as a number a client would believe.
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @param string               $file     The absolute path, or '' when absent.
	 *
	 * @return int|null The byte count, or null.
	 */
	private function filesize( array $metadata, string $file ): ?int {
		if ( isset( $metadata['filesize'] ) ) {
			return (int) $metadata['filesize'];
		}

		if ( '' === $file ) {
			return null;
		}

		$bytes = (int) wp_filesize( $file );

		return 0 === $bytes ? null : $bytes;
	}

	/**
	 * The renditions that actually exist for this attachment, sorted by name.
	 *
	 * The URL is derived from the full-size URL's directory rather than by
	 * calling wp_get_attachment_image_src() per size, which would cost one
	 * function call and one metadata read per rendition for a value already in
	 * hand. An attachment whose full-size URL could not be resolved yields an
	 * empty rendition URL rather than a bare filename, which a client would
	 * otherwise resolve relative to its own host.
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @param string               $url      The full-size URL, or ''.
	 *
	 * @return array<int, array<string, mixed>> The renditions.
	 */
	private function renditions( array $metadata, string $url ): array {
		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return [];
		}

		$slash = strrpos( $url, '/' );
		$base  = false === $slash ? '' : substr( $url, 0, $slash + 1 );

		$stored = $metadata['sizes'];
		ksort( $stored, SORT_STRING );

		$renditions = [];

		foreach ( $stored as $name => $size ) {
			$file = (string) ( $size['file'] ?? '' );

			$renditions[] = [
				'name'   => (string) $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'url'    => ( '' === $base || '' === $file ) ? '' : $base . $file,
			];
		}

		return $renditions;
	}
}
