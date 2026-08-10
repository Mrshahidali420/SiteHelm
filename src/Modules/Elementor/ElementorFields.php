<?php
/**
 * Shared Elementor schema fragments and projections.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * The one place that decides what "an Elementor document" means to a client.
 *
 * Every Elementor consumer shares this definition: the listing projects its rows
 * from it, and Phase 6a's two remaining reads declare their version ranges from
 * it. Keeping it in one class is what makes a value read by one operation
 * comparable to the same value read by another — the rule MenuFields and
 * MediaFields already settled for their modules.
 *
 * Nothing here names an `\Elementor\` symbol; the meta key comes from
 * ElementorDocument, which owns it, so the listing's selection criterion and the
 * document reader's detection criterion cannot drift apart into two spellings of
 * the same key.
 *
 * Every WordPress answer below is guarded on its SHAPE rather than cast. A post
 * row reaches this class from `WP_Query::$posts`, which the `the_posts` filter
 * lets any plugin rewrite, and stored meta is writable by anything that can call
 * `update_post_meta`. `(string)` on an array is a fatal and `(int)` on one is 1.
 *
 * @package SiteHelm
 */
final class ElementorFields {

	/**
	 * The post types an Elementor document can be.
	 *
	 * Pages and posts are the ordinary case; `elementor_library` is the post type
	 * Elementor stores saved templates, sections and popups in, and the spec's
	 * operations table puts it in scope for the listing alongside the other two.
	 * A site may of course enable Elementor on further custom post types — those
	 * are out of scope for V1 and their absence is a stated limit, not an
	 * oversight, because an unbounded post-type list turns the listing into a
	 * whole-site content query.
	 */
	public const POST_TYPES = [ 'page', 'post', 'elementor_library' ];

	/**
	 * The post type Elementor stores saved templates in.
	 */
	public const LIBRARY_POST_TYPE = 'elementor_library';

	/**
	 * The five members of one document summary, in their declared order.
	 *
	 * @var string[]
	 */
	public const SUMMARY_FIELDS = [ 'id', 'type', 'title', 'status', 'editMode' ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The dependency version ranges every Elementor definition declares.
	 *
	 * BOTH ranges, because `OperationDefinition` requires a plugin range in
	 * addition to the WordPress core range for every plugin-backed module, and
	 * without it the operation could not be version-blocked. Built from the two
	 * declared floors rather than from literals so that a definition's advertised
	 * range and the module's own health reporting cannot drift apart.
	 *
	 * @return array<string, string> The WordPress and Elementor ranges.
	 */
	public static function supportedVersions(): array {
		return [
			'wordpress' => '>=' . SITEHELM_MIN_WP,
			'elementor' => '>=' . ElementorPresence::MIN_VERSION,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The declared schema for one document summary.
	 *
	 * Declared here rather than inlined in the operation so that a second
	 * operation returning a summary cannot describe the same five fields
	 * differently — the split that only becomes visible once one copy drifts.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function documentSummarySchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'       => [
					'type'        => 'integer',
					'description' => 'The document\'s WordPress post identifier.',
				],
				'type'     => [
					'type'        => 'string',
					'description' => 'The WordPress post type: page, post, or elementor_library for a saved template.',
				],
				'title'    => [
					'type'        => 'string',
					'description' => 'The document\'s title.',
				],
				'status'   => [
					'type'        => 'string',
					'description' => 'The WordPress post status, such as publish or draft.',
				],
				'editMode' => [
					'type'        => 'string',
					'description' => 'The stored Elementor edit mode, normally builder. Empty when the stored value is unreadable.',
				],
			],
			'required'             => self::SUMMARY_FIELDS,
			'additionalProperties' => false,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The client-facing summary for one matched post row, or null.
	 *
	 * Null rather than a summary of zeroes and empty strings when the row is not
	 * an object, or carries no usable identifier. Neither is hypothetical:
	 * `the_posts` is a public filter, so a plugin appending a synthetic entry or
	 * a scalar is the common case — the same failure MenuFields::itemTree() drops
	 * rows for. Emitting one would put post 0 in a listing an operator then asks
	 * a later operation about.
	 *
	 * The parameter is `mixed` rather than `object` on purpose: a declared
	 * `object` would turn a filtered row into a TypeError, which is a crash
	 * rather than an omission, and the row-shape decision belongs in the one
	 * place that decides what a document summary is.
	 *
	 * The row's own columns are read rather than re-fetched with `get_post()`,
	 * because the query already loaded them and a second fetch per row is what
	 * turns a page of 100 into 100 extra reads.
	 *
	 * `editMode` is the only meta read, and it is read for the whole page from a
	 * cache the query primed. Its stored value is third-party writable, so a
	 * non-scalar answers the empty string rather than being cast.
	 *
	 * @param mixed $post One matched row from the query, of unverified shape.
	 *
	 * @return array<string, mixed>|null The summary, or null when the row is unusable.
	 */
	public function documentSummary( $post ): ?array {
		if ( ! is_object( $post ) ) {
			return null;
		}

		$id = is_scalar( $post->ID ?? null ) ? (int) $post->ID : 0;

		if ( $id <= 0 ) {
			return null;
		}

		return [
			'id'       => $id,
			'type'     => $this->column( $post, 'post_type' ),
			'title'    => $this->column( $post, 'post_title' ),
			'status'   => $this->column( $post, 'post_status' ),
			'editMode' => $this->edit_mode( $id ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One post column as a string, or '' when it is absent or not string-like.
	 *
	 * A cast is not enough on its own: `(string)` on an array is a fatal, and a
	 * row reaching here has passed through the `the_posts` filter.
	 *
	 * @param object $post   The matched row.
	 * @param string $column The column name.
	 *
	 * @return string The column's value, or ''.
	 */
	private function column( object $post, string $column ): string {
		$value = $post->$column ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The stored Elementor edit mode for one document.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @return string The stored mode, or '' when it is absent or unreadable.
	 */
	private function edit_mode( int $post_id ): string {
		$mode = get_post_meta( $post_id, ElementorDocument::META_EDIT_MODE, true );

		return is_scalar( $mode ) ? (string) $mode : '';
	}
}
