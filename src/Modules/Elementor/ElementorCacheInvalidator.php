<?php
/**
 * The verified CSS-cache invalidation step of the Elementor write path.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * REQ-0043: discards BOTH halves of one document's Elementor CSS cache, and
 * reports only what it could confirm gone afterwards.
 *
 * THERE ARE TWO HALVES AND BOTH MUST GO. Elementor keeps a `_elementor_css`
 * post meta row describing the generated stylesheet, and the stylesheet itself
 * at `elementor/css/post-{id}.css` under the uploads directory. Invalidating
 * one and leaving the other is worse than invalidating neither: the meta row
 * points at a file that no longer matches, or the file survives a meta row that
 * would have caused it to be rebuilt, and either way the page keeps serving
 * CSS that describes a layout the document no longer has.
 *
 * ELEMENTOR'S OWN FLUSH IS ATTEMPTED FIRST, through `ElementorApi`, because it
 * is the only thing that also clears whatever that release caches internally.
 * The two manual deletes below it are not a duplicate of it — they are what
 * happens on a site where Elementor could not be reached at all, which is the
 * exact site state the fallback write that calls this class is already in.
 *
 * NOTHING HERE NAMES AN `\Elementor\` SYMBOL (spec Decision 1). The flush goes
 * through `ElementorApi`; the meta key and the generated file's path are a WordPress
 * meta key and a path under WordPress's own uploads directory, which is why
 * they can be named here.
 *
 * THE ANSWER IS WHAT WAS VERIFIED, NEVER WHAT WAS ATTEMPTED, and the two are
 * genuinely different. `delete_post_meta()` answers false for a row that was
 * not there and true for one it removed, and neither answer says anything
 * about the row's state afterwards — a persistent object cache another plugin
 * owns can serve the old value back on the next read. So both halves are
 * re-read, and both re-reads are reported separately: a caller that received
 * one boolean could not tell a half-invalidated cache from a whole one.
 *
 * ABSENCE IS SUCCESS. A page that has never been rendered has no generated file
 * and no meta row, and reporting that as a failure would make every first write
 * to a new page look broken.
 *
 * IT IS NOT AN OPERATION, and cannot become one: it changes no state a caller
 * can promise, so it can form no `PlannedChange`. It is also deliberately not
 * added to `ElementorModule::cacheCleanup()`, which returns WordPress
 * object-cache GROUP names — a meta key and a file path are neither, and
 * putting them in that list would make one list mean two things.
 *
 * NO PATH AND NO MESSAGE LEAVES THIS CLASS. The answer is two booleans, so
 * there is nowhere for a filesystem path to appear in an envelope.
 *
 * @package SiteHelm
 */
final class ElementorCacheInvalidator {

	/**
	 * The post meta key Elementor stores one document's CSS state under.
	 */
	public const META_CSS = '_elementor_css';

	/**
	 * The uploads-relative directory Elementor generates per-post CSS into.
	 */
	public const CSS_DIRECTORY = 'elementor/css';

	/**
	 * The generated stylesheet's file name, before the post identifier.
	 */
	public const CSS_FILE_PREFIX = 'post-';

	/**
	 * Constructs the invalidator.
	 *
	 * The accessor is injected rather than constructed here for the same reason
	 * every Elementor class takes its collaborator: one object answers "can
	 * Elementor be reached" for a whole request, so a write and the invalidation
	 * inside it cannot disagree about it half way through.
	 *
	 * @param ElementorApi $api The accessor for Elementor's own write API.
	 */
	public function __construct(
		private readonly ElementorApi $api,
	) {
	}

	/**
	 * Discards one document's cached CSS and reports what it confirmed gone.
	 *
	 * The identifier is tested BEFORE anything is deleted. A non-positive id
	 * names no post, and `delete_post_meta( 0, ... )` reaches the meta of
	 * whatever `$post` happens to be global on some configurations — deleting one
	 * page's cache state while the caller asked about another. It answers "I
	 * confirmed nothing" rather than refusing, because this is a step inside a
	 * write whose own guards have already refused by the time it could matter.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array{meta:bool,file:bool} Each true only when a re-read confirmed
	 *                                    that half gone.
	 */
	public function invalidate( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [
				'meta' => false,
				'file' => false,
			];
		}

		// Elementor's own flush first; its answer is deliberately not read. True
		// would mean the documented call ran, which is not evidence about either
		// half's state, and the re-reads below are the evidence.
		$this->api->flushDocumentCss( $post_id );

		delete_post_meta( $post_id, self::META_CSS );

		$path = $this->css_path( $post_id );

		if ( null !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		$meta = get_post_meta( $post_id, self::META_CSS, true );

		// PHP caches stat results per request, so a file deleted a microsecond ago
		// can still answer file_exists() true. Verifying against a stale cache
		// would report the one state this class exists to disprove. The clear sits
		// IMMEDIATELY BEFORE the confirming re-read, with nothing between them that
		// could stat the path again and re-populate what was just cleared — a clear
		// followed by other work is a clear that proves nothing.
		clearstatcache( true, $path ?? '' );

		return [
			'meta' => '' === $meta || [] === $meta || null === $meta || false === $meta,
			// An unresolvable uploads directory makes the file's state UNKNOWN,
			// and unknown is not confirmed.
			'file' => null !== $path && ! file_exists( $path ),
		];
	}

	/**
	 * The generated stylesheet's absolute path, or null when it cannot be
	 * resolved.
	 *
	 * `wp_upload_dir()` reports its own failure in an `error` member rather than
	 * by returning nothing, and on a failure the `basedir` it also returns is not
	 * trustworthy — so the error is tested first and a path is never built from a
	 * directory WordPress just said it could not resolve.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string|null The absolute path, or null.
	 */
	private function css_path( int $post_id ): ?string {
		$uploads = wp_upload_dir();

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return null;
		}

		$basedir = $uploads['basedir'] ?? '';

		if ( ! is_string( $basedir ) || '' === trim( $basedir ) ) {
			return null;
		}

		return rtrim( $basedir, '/\\' ) . '/' . self::CSS_DIRECTORY . '/' . self::CSS_FILE_PREFIX . $post_id . '.css';
	}
}
