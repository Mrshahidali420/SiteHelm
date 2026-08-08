<?php
/**
 * Media library listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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
 * REQ-0020: media listing. An agency operator finds the client library assets
 * that already exist before uploading a duplicate.
 *
 * The declared capability `upload_files` gates the operation, and it is a
 * site-wide primitive: holding it does not mean the caller may edit every
 * attachment a query happens to match. So every match is re-checked with the
 * target-bound `edit_post` and omitted when that fails, exactly as ContentList
 * omits. Omitting rather than listing the item and refusing it later is
 * deliberate — naming an asset is already a disclosure of content the caller
 * has no rights to.
 *
 * This inherits ContentList's known asymmetry: `total` counts what the query
 * found, not what the caller may see, so a filtered page can return fewer items
 * than `limit` while `total` suggests more remain. Recorded as inherited debt,
 * because fixing it means changing ContentList too.
 *
 * The filter set is closed on purpose: search, MIME type, parent, limit and
 * offset, with the ordering fixed to most-recently-uploaded first. There is no
 * date range, author filter, or meta query, because REQ-0020 requires none of
 * them and a meta query in particular is a caller-shaped query surface pointed
 * straight at the database. Fixing the ordering removes an input rather than
 * validating one.
 *
 * @package SiteHelm
 */
final class MediaList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for media-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-list',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'List summaries of media library items matching a search term, MIME type, or parent, most recently uploaded first, limited to the items the caller may edit.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'search'   => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items matching this search term.',
					],
					'mimeType' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items of this MIME type, either a full type such as image/png or a bare top-level type such as image.',
					],
					'parent'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only items attached to this content item; 0 returns unattached items.',
					],
					'limit'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Items to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'items'  => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'          => [ 'type' => 'integer' ],
								'title'       => [ 'type' => 'string' ],
								'filename'    => [ 'type' => 'string' ],
								'mimeType'    => [ 'type' => 'string' ],
								'url'         => [ 'type' => 'string' ],
								'parent'      => [ 'type' => 'integer' ],
								'uploadedGmt' => [ 'type' => 'string' ],
							],
							'required'             => [ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
							'additionalProperties' => false,
						],
					],
					'total'  => [ 'type' => 'integer' ],
					'limit'  => [ 'type' => 'integer' ],
					'offset' => [ 'type' => 'integer' ],
				],
				'required'             => [ 'items', 'total', 'limit', 'offset' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-list',
				'arguments' => [
					'mimeType' => 'image',
					'limit'    => 20,
				],
			],
		);
	}

	/**
	 * The largest page a caller may request, matching content-list's clamp.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none, matching content-list.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The only post status an attachment carries.
	 */
	private const ATTACHMENT_STATUS = 'inherit';

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns one page of media summaries the caller may edit, newest upload
	 * first.
	 *
	 * @param array<string, mixed> $input   Validated filters and pagination.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Items, the unpaginated total, and the page.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $input, $limit, $offset );

		return [
			'items'  => $this->editable_summaries( $query->posts, $context ),
			'total'  => (int) $query->found_posts,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Runs the one query this operation makes.
	 *
	 * WP_Query is the supported path, so no SQL is assembled here and the
	 * unpaginated match count comes from the query's own found_posts rather than
	 * a second counting query.
	 *
	 * The post-meta cache IS primed, which is the deliberate opposite of
	 * ContentList. Every field of a media summary — the filename, the URL, and
	 * the attachment metadata behind them — is post meta, so leaving the cache
	 * cold would cost several uncached meta reads per row instead of one primed
	 * read per page. The term cache stays cold: no media summary field is a term.
	 *
	 * @param array<string, mixed> $input  Validated filters.
	 * @param int                  $limit  The clamped page size.
	 * @param int                  $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 */
	private function run_query( array $input, int $limit, int $offset ): WP_Query {
		$search    = (string) ( $input['search'] ?? '' );
		$mime_type = (string) ( $input['mimeType'] ?? '' );

		$args = [
			'post_type'              => MediaFields::ATTACHMENT_TYPE,
			'post_status'            => self::ATTACHMENT_STATUS,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		];

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $mime_type ) {
			$args['post_mime_type'] = $mime_type;
		}

		// isset() rather than a truthiness test: 0 is the documented way to ask
		// for unattached assets, and a falsy check would discard it.
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		return new WP_Query( $args );
	}

	/**
	 * Projects the matches the caller may edit into client-facing summaries.
	 *
	 * The projection is MediaFields::summary(), shared with nothing else so that
	 * a listing and a read cannot disagree about what a filename is. A match
	 * that is no longer a readable attachment — deleted between the query and
	 * the projection, or never an attachment at all — is omitted rather than
	 * emitted as a summary of empty strings.
	 *
	 * @param object[]         $posts   The matched attachment-shaped rows.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<int, array<string, mixed>> The permitted summaries.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function editable_summaries( array $posts, OperationContext $context ): array {
		$summaries = [];

		foreach ( $posts as $post ) {
			$media_id = (int) $post->ID;

			if ( ! user_can( $context->userId, 'edit_post', $media_id ) ) {
				continue;
			}

			$summary = $this->fields->summary( $media_id );

			if ( null === $summary ) {
				continue;
			}

			$summaries[] = $summary;
		}

		return $summaries;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
