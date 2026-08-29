<?php
/**
 * Counts the documents wearing one Elementor global class.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use WP_Query;

/**
 * The question a global-class delete has to be able to answer before it is safe.
 *
 * Deleting a shared class is the one global-class change that cannot be judged
 * from the class itself. The class definition says what styles it carries; it
 * says nothing about the forty pages wearing it, which is exactly what an
 * operator needs to know before approving its removal. Without this, "delete the
 * class called Card" is a change whose blast radius is invisible until it lands.
 *
 * A LOWER BOUND, HONESTLY LABELLED. The scan stops at `MAX_SCAN` documents and
 * reports whether it stopped, because an unbounded scan of every Elementor
 * document on a large site is a preview that never returns, and a bound that
 * pretended to be a total would understate the damage on exactly the sites where
 * understating it matters most. A caller is told "at least 100, and there are
 * more" rather than "100".
 *
 * WHY A META SEARCH AND NOT A PARSE. A class reference lives inside the
 * `_elementor_data` JSON of every document that wears it, at a depth that varies
 * by element type and Elementor version. Parsing every document to walk for it
 * would be correct and would also mean loading every document's whole tree into
 * memory. The identifier is long, prefixed, and minted to be unique, so a
 * substring test over the stored JSON finds every document that references it.
 * It can in principle over-count — an identifier could appear inside an unrelated
 * string — which is why this is used to WARN and never to refuse: over-warning
 * before a destructive change is the safe direction to be wrong in.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassUsage {

	/**
	 * The greatest number of documents one scan will look at.
	 */
	public const MAX_SCAN = 200;

	/**
	 * The meta key holding a document's element tree.
	 */
	private const META_DATA = '_elementor_data';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase.
	/**
	 * How many documents wear one class, and whether the scan saw them all.
	 *
	 * @param string $id The class identifier.
	 *
	 * @return array{count: int, complete: bool} The count and whether it is a total.
	 *
	 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The clause is owned by this operation, carries no caller-shaped structure, and is bounded by MAX_SCAN. The alternative is loading and parsing every Elementor document on the site.
	 */
	public function documentsWearing( string $id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => self::MAX_SCAN,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => self::META_DATA,
						'value'   => $id,
						'compare' => 'LIKE',
					],
				],
			]
		);

		$count = count( $query->posts );

		return [
			'count'    => $count,
			'complete' => $count < self::MAX_SCAN,
		];
	}
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
