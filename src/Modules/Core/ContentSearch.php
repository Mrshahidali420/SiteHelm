<?php
/**
 * Site-wide content search handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use WP_Query;

/**
 * REQ-0092 (free half): find every document on the site that mentions a phrase.
 *
 * The bulk change that rewrites what this finds is the Pro half. This operation
 * only reports, and reporting is the part that has to be right first: a bulk
 * rewrite is exactly as safe as the list it was handed.
 *
 * Two things about the result are deliberate.
 *
 * **It is filtered by capability, one document at a time.** The declared
 * capability is `edit_posts`, which is a primitive capability and therefore
 * legal to declare; whether the caller may see any particular document is a
 * `edit_post` question about that document, and it is asked here, per row,
 * before the row is counted. Without that, a search over `any` post status
 * would be a way to read every draft on the site through an account that may
 * not open one.
 *
 * **Elementor is reported at the document level, not the element level.** A
 * page built in Elementor keeps its text in the `_elementor_data` meta rather
 * than in `post_content`, so a search that reads only `post_content` misses it
 * entirely and reports a confident zero. This operation searches that meta and
 * says how many times the phrase occurs in it — then the caller asks
 * `elementor-element-search` for that one document to learn which elements.
 * Walking Elementor's tree here would put Elementor's storage format inside the
 * core module, where it would rot the first time Elementor changed it.
 *
 * That meta is stored as JSON, so a phrase containing a quote, a backslash or a
 * non-ASCII character may be escaped in storage and will not match literally.
 * The output says so through `elementorExact` rather than pretending otherwise.
 *
 * @package SiteHelm
 */
final class ContentSearch {

	/**
	 * The most documents examined before the answer is declared truncated.
	 *
	 * A phrase like "the" matches most of a site. Scanning all of it to build a
	 * report nobody reads is how a read operation becomes an outage, so the scan
	 * stops here and says that it stopped.
	 */
	public const MAX_SCANNED = 500;

	/**
	 * Characters of surrounding text kept either side of the first match.
	 */
	private const EXCERPT_RADIUS = 60;

	/**
	 * The meta key Elementor stores a document's element tree under.
	 */
	private const ELEMENTOR_META = '_elementor_data';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-search.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-search',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Find every post, page or custom post type on the site whose title, content, excerpt or Elementor data mentions a phrase. Results are filtered to the documents your WordPress user may edit, and report where in each document the phrase occurs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'phrase'        => [
						'type'        => 'string',
						'minLength'   => 2,
						'maxLength'   => 200,
						'description' => 'The phrase to find. Matched as a whole phrase, not as separate words. Two characters minimum: a one-character search matches almost every document and reports nothing useful.',
					],
					'postTypes'     => [
						'type'        => 'array',
						'items'       => [
							'type'      => 'string',
							'maxLength' => 32,
						],
						'maxItems'    => 20,
						'description' => 'Restrict the search to these post types. Omit to search every type the site exposes to search.',
					],
					'statuses'      => [
						'type'        => 'array',
						'items'       => [
							'type'      => 'string',
							'maxLength' => 32,
						],
						'maxItems'    => 10,
						'description' => 'Restrict the search to these post statuses. Omit to search published, draft, pending and private documents.',
					],
					'caseSensitive' => [
						'type'        => 'boolean',
						'description' => 'Count and locate matches with case taken into account. Which documents are found does not change: the database search is case-insensitive either way.',
					],
					'page'          => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Which page of matching documents to return.',
					],
					'perPage'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'How many matching documents to return per page.',
					],
				],
				'required'             => [ 'phrase' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'phrase'         => [ 'type' => 'string' ],
					'total'          => [ 'type' => 'integer' ],
					'page'           => [ 'type' => 'integer' ],
					'perPage'        => [ 'type' => 'integer' ],
					'pageCount'      => [ 'type' => 'integer' ],
					'scanned'        => [ 'type' => 'integer' ],
					'truncated'      => [ 'type' => 'boolean' ],
					'elementorExact' => [ 'type' => 'boolean' ],
					'matches'        => [ 'type' => 'array' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-search',
				'arguments' => [
					'phrase'  => 'old company name',
					'perPage' => 20,
				],
			],
		);
	}

	/**
	 * Returns the documents that mention the phrase.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'phrase'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The search report.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$phrase    = (string) ( $input['phrase'] ?? '' );
		$sensitive = ! empty( $input['caseSensitive'] );
		$page      = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page  = (int) ( $input['perPage'] ?? 20 );
		$per_page  = max( 1, min( 50, $per_page ) );

		$types    = $this->requestedTypes( $input );
		$statuses = $this->requestedStatuses( $input );

		$ids       = $this->candidateIds( $phrase, $types, $statuses );
		$scanned   = count( $ids );
		$truncated = $scanned >= self::MAX_SCANNED;

		$matches = [];

		foreach ( $ids as $id ) {
			// Asked per document rather than once for the request. `edit_posts`
			// says the caller edits posts in general; only `edit_post` says
			// whether they may have this one, and an unfiltered list is a
			// disclosure channel for every draft on the site.
			if ( ! user_can( $context->userId, 'edit_post', $id ) ) {
				continue;
			}

			$row = $this->describe( $id, $phrase, $sensitive );

			if ( null !== $row ) {
				$matches[] = $row;
			}
		}

		$total      = count( $matches );
		$page_count = (int) ceil( $total / $per_page );

		return [
			'phrase'         => $phrase,
			'total'          => $total,
			'page'           => $page,
			'perPage'        => $per_page,
			'pageCount'      => $page_count,
			'scanned'        => $scanned,
			'truncated'      => $truncated,
			'elementorExact' => $this->isJsonSafe( $phrase ),
			'matches'        => array_values( array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ) ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The post types to search.
	 *
	 * An unknown type is dropped rather than refused: a client that names five
	 * types and gets a refusal because one of them is not installed on this site
	 * learns nothing it can act on.
	 *
	 * @param array<string, mixed> $input The validated input.
	 *
	 * @return string[]|string The types, or 'any'.
	 */
	private function requestedTypes( array $input ) {
		$requested = $input['postTypes'] ?? null;

		if ( ! is_array( $requested ) || [] === $requested ) {
			return 'any';
		}

		$known = array_values( array_filter( array_map( 'strval', $requested ), 'post_type_exists' ) );

		return [] === $known ? 'any' : $known;
	}

	/**
	 * The post statuses to search.
	 *
	 * The default deliberately omits trash and auto-drafts. Someone searching
	 * for a phrase wants the documents that still say it, and a rewrite driven
	 * by this list should not resurrect a trashed page.
	 *
	 * @param array<string, mixed> $input The validated input.
	 *
	 * @return string[] The statuses.
	 */
	private function requestedStatuses( array $input ): array {
		$requested = $input['statuses'] ?? null;

		if ( ! is_array( $requested ) || [] === $requested ) {
			return [ 'publish', 'draft', 'pending', 'private' ];
		}

		$known = array_values(
			array_filter(
				array_map( 'strval', $requested ),
				static fn( string $status ): bool => null !== get_post_status_object( $status )
			)
		);

		return [] === $known ? [ 'publish', 'draft', 'pending', 'private' ] : $known;
	}

	/**
	 * The identifiers of every document that might contain the phrase.
	 *
	 * Two queries, because WordPress's own search reads the post table and has
	 * no opinion about post meta, and Elementor keeps a page's text in meta. The
	 * union is de-duplicated and capped; ordering is newest first so that a
	 * truncated answer is truncated at the oldest end, which is the end a person
	 * cleaning up a phrase cares about least.
	 *
	 * @param string          $phrase   The phrase.
	 * @param string[]|string $types    Post types, or 'any'.
	 * @param string[]        $statuses Post statuses.
	 *
	 * @return int[] Post identifiers.
	 */
	private function candidateIds( string $phrase, $types, array $statuses ): array {
		$common = [
			'post_type'              => $types,
			'post_status'            => $statuses,
			'posts_per_page'         => self::MAX_SCANNED,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		// 'sentence' keeps the phrase whole. Without it WordPress splits on
		// spaces and "old company name" starts matching every page with the
		// word "name" in it.
		$in_post = new WP_Query(
			$common + [
				's'        => $phrase,
				'sentence' => true,
			]
		);

		// The Elementor half needs a meta comparison and there is no other way
		// to ask it: the text is in meta, and a search that skips it reports a
		// confident zero for every page the site actually builds in Elementor.
		$in_meta = new WP_Query(
			$common + [
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => [
					[
						'key'     => self::ELEMENTOR_META,
						'value'   => $phrase,
						'compare' => 'LIKE',
					],
				],
			]
		);

		$ids = array_map( 'intval', array_merge( (array) $in_post->posts, (array) $in_meta->posts ) );

		return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_SCANNED );
	}

	/**
	 * Describes one matching document.
	 *
	 * Returns null when the phrase turns out not to occur in any field this
	 * reports. That happens for real: `LIKE` on the Elementor meta matches the
	 * raw JSON, so a document can survive the query and then hold no occurrence
	 * the caller would recognise. Reporting it anyway with every count at zero
	 * would be a row that says nothing.
	 *
	 * @param int    $id        The post identifier.
	 * @param string $phrase    The phrase.
	 * @param bool   $sensitive Whether to count with case taken into account.
	 *
	 * @return array<string, mixed>|null The match record, or null.
	 */
	private function describe( int $id, string $phrase, bool $sensitive ): ?array {
		$post = get_post( $id );

		if ( null === $post ) {
			return null;
		}

		$title     = (string) $post->post_title;
		$content   = (string) $post->post_content;
		$excerpt   = (string) $post->post_excerpt;
		$elementor = $this->elementorText( $id );

		$fields = [
			'title'     => $this->countIn( $title, $phrase, $sensitive ),
			'content'   => $this->countIn( $content, $phrase, $sensitive ),
			'excerpt'   => $this->countIn( $excerpt, $phrase, $sensitive ),
			'elementor' => $this->countIn( $elementor, $phrase, $sensitive ),
		];

		$total = array_sum( $fields );

		if ( 0 === $total ) {
			return null;
		}

		$permalink = get_permalink( $id );

		return [
			'id'         => $id,
			'type'       => (string) $post->post_type,
			'status'     => (string) $post->post_status,
			'title'      => $title,
			'url'        => is_string( $permalink ) ? $permalink : null,
			'matchCount' => $total,
			'fields'     => $fields,
			'excerpt'    => $this->firstOccurrence( $title, $content, $excerpt, $phrase, $sensitive ),
		];
	}

	/**
	 * The Elementor tree as searchable text, or an empty string.
	 *
	 * The stored value is JSON. It is not decoded, because the question here is
	 * only how often the phrase occurs; decoding it to walk elements is
	 * `elementor-element-search`'s job and Elementor's format to know.
	 *
	 * @param int $id The post identifier.
	 *
	 * @return string The stored tree, or an empty string.
	 */
	private function elementorText( int $id ): string {
		$stored = get_post_meta( $id, self::ELEMENTOR_META, true );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Counts occurrences of the phrase in a haystack.
	 *
	 * @param string $haystack  The text.
	 * @param string $phrase    The phrase.
	 * @param bool   $sensitive Whether case matters.
	 *
	 * @return int The number of occurrences.
	 */
	private function countIn( string $haystack, string $phrase, bool $sensitive ): int {
		if ( '' === $haystack || '' === $phrase ) {
			return 0;
		}

		return $sensitive
			? substr_count( $haystack, $phrase )
			: substr_count( mb_strtolower( $haystack ), mb_strtolower( $phrase ) );
	}

	/**
	 * A short plain-text excerpt around the phrase's first occurrence.
	 *
	 * Title, then content, then excerpt — the order a person would look. The
	 * Elementor tree is not quoted from: an excerpt of raw JSON reads as noise,
	 * and the caller has a named operation for looking inside it.
	 *
	 * @param string $title     The post title.
	 * @param string $content   The post content.
	 * @param string $excerpt   The post excerpt.
	 * @param string $phrase    The phrase.
	 * @param bool   $sensitive Whether case matters.
	 *
	 * @return string|null The excerpt, or null when the phrase occurs only in Elementor data.
	 */
	private function firstOccurrence( string $title, string $content, string $excerpt, string $phrase, bool $sensitive ): ?string {
		foreach ( [ $title, $content, $excerpt ] as $source ) {
			$text = trim( (string) wp_strip_all_tags( $source ) );

			if ( '' === $text ) {
				continue;
			}

			$at = $sensitive
				? mb_strpos( $text, $phrase )
				: mb_strpos( mb_strtolower( $text ), mb_strtolower( $phrase ) );

			if ( false === $at ) {
				continue;
			}

			$start  = max( 0, $at - self::EXCERPT_RADIUS );
			$length = mb_strlen( $phrase ) + ( self::EXCERPT_RADIUS * 2 );
			$window = mb_substr( $text, $start, $length );

			return ( $start > 0 ? '…' : '' )
				. $window
				. ( $start + $length < mb_strlen( $text ) ? '…' : '' );
		}

		return null;
	}

	/**
	 * Whether the phrase can be matched literally inside stored JSON.
	 *
	 * Elementor's meta is `wp_json_encode` output, so a quote becomes `\"`, a
	 * backslash becomes `\\`, and — depending on how it was encoded — a
	 * non-ASCII character may become a `\uXXXX` escape. A `LIKE` for the raw
	 * phrase then misses documents that do contain it. This does not attempt to
	 * paper over that; it tells the caller the Elementor half of this answer is
	 * not exhaustive, which is the only honest thing a search can say about a
	 * corpus it cannot reliably match.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return bool True when a literal match is trustworthy.
	 */
	private function isJsonSafe( string $phrase ): bool {
		return 1 === preg_match( '/^[\x20-\x21\x23-\x5B\x5D-\x7E]*$/', $phrase );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
