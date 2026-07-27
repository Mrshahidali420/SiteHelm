<?php
/**
 * Content listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use WP_Query;

/**
 * REQ-0010: content listing. An agency operator finds the client content worth
 * acting on before reading or revising any single item.
 *
 * The declared capability `edit_posts` gates the operation, and it is a
 * site-wide primitive: holding it does not mean the caller may edit every item
 * a query happens to match. So every match is re-checked with the target-bound
 * `edit_post` and omitted when that fails. Omitting rather than listing the
 * item and refusing it later is deliberate — naming an item is already a
 * disclosure of content the caller has no rights to. The primitive gates the
 * operation, the per-item check gates its contents.
 *
 * The filter set is closed on purpose: type, status, search, parent, limit and
 * offset, with the ordering fixed to most-recently-modified first. There is no
 * date range, author filter, taxonomy filter, client-chosen ordering, or meta
 * query, because REQ-0010 requires none of them and a meta query in particular
 * is a caller-shaped query surface pointed straight at the database. Fixing the
 * ordering removes an input rather than validating one.
 *
 * @package SiteHelm
 */
final class ContentList {

	/**
	 * The largest page a caller may request, matching audit-list's clamp.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The content type listed when the caller names none.
	 */
	private const DEFAULT_TYPE = 'post';

	/**
	 * The statuses queried when the caller names none: exactly the statuses
	 * ContentCreate's schema admits, so a default listing shows everything this
	 * plugin can put on a site rather than published items alone.
	 *
	 * Trash is absent deliberately. A default listing that surfaced trashed
	 * items would be surprising, and REQ-0019 will make the trash a destination
	 * a client chooses on purpose, so it is listed only when `status` asks.
	 */
	private const DEFAULT_STATUSES = [ 'draft', 'pending', 'private', 'publish' ];

	/**
	 * Returns one page of content summaries the caller may edit, newest
	 * modification first.
	 *
	 * @param array<string, mixed> $input   Validated filters and pagination.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Items, the unpaginated total, and the page.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the requested
	 *                            content type is not listable on this site.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$type = (string) ( $input['type'] ?? self::DEFAULT_TYPE );
		$this->assert_public_type( $type );

		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $type, $input, $limit, $offset );

		return [
			'items'  => $this->editable_summaries( $query->posts, $context ),
			'total'  => (int) $query->found_posts,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * Refuses a content type this operation will not list.
	 *
	 * A type the site does not register and a type registered as non-public are
	 * refused identically, and the message names neither the requested type nor
	 * the types that do exist: a listing operation must not become a way to
	 * enumerate a site's internal post types by guessing.
	 *
	 * @param string $type The requested content type.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_public_type( string $type ): void {
		$object = '' === $type ? null : get_post_type_object( $type );

		if ( is_object( $object ) && isset( $object->public ) && true === $object->public ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'The requested content type is not available for listing on this site.',
			'Choose a public content type this site registers, for example post or page.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Runs the one query this operation makes.
	 *
	 * WP_Query is the supported path, so no SQL is assembled here and the
	 * unpaginated match count comes from the query's own found_posts rather than
	 * a second counting query.
	 *
	 * The two cache-priming queries WP_Query runs by default are switched off:
	 * the summary carries neither meta nor terms, and map_meta_cap( 'edit_post' )
	 * reads the post row alone, so priming them would cost two extra queries per
	 * call for values nothing reads.
	 *
	 * @param string               $type   The resolved content type.
	 * @param array<string, mixed> $input  Validated filters.
	 * @param int                  $limit  The clamped page size.
	 * @param int                  $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 */
	private function run_query( string $type, array $input, int $limit, int $offset ): WP_Query {
		$status = (string) ( $input['status'] ?? '' );
		$search = (string) ( $input['search'] ?? '' );

		$args = [
			'post_type'              => $type,
			'post_status'            => '' === $status ? self::DEFAULT_STATUSES : $status,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		return new WP_Query( $args );
	}

	/**
	 * Projects the matches the caller may edit into client-facing summaries.
	 *
	 * The projection is exactly seven fields, sharing ContentFields'
	 * publicRecord() names for the same values so a client sees one vocabulary.
	 * Body, excerpt, terms, and meta are absent on purpose: a page of full
	 * records is bulk the caller discards, and content-get already returns one
	 * item in full.
	 *
	 * @param object[]         $posts   The matched post-shaped rows.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<int, array<string, mixed>> The permitted summaries.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function editable_summaries( array $posts, OperationContext $context ): array {
		$summaries = [];

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;

			if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
				continue;
			}

			$summaries[] = [
				'id'          => $post_id,
				'type'        => (string) $post->post_type,
				'status'      => (string) $post->post_status,
				'title'       => (string) $post->post_title,
				'slug'        => (string) $post->post_name,
				'modifiedGmt' => (string) $post->post_modified_gmt,
				'parent'      => (int) $post->post_parent,
			];
		}

		return $summaries;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
