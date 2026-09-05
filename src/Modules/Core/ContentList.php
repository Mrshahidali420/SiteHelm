<?php
/**
 * Content listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
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
 * LISTABLE IS NOT THE SAME AS PUBLIC. This gate used to ask whether a type was
 * registered `public`, which is a question about visitors, and the answer turned
 * away every form submission, order record and log entry a site keeps — content
 * an administrator reads in wp-admin every day and a management tool has every
 * reason to see. The question it asks now is whether the type has an editing
 * surface at all (`public` or `show_ui`) and whether this account holds the
 * type's own edit capability. Types with no surface — revisions, menu items, the
 * scaffolding other plugins hide — stay out, and so does a type this account
 * cannot edit, with the same refusal either way.
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
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List summaries of content items matching a type, status, search term, or parent, most recently modified first, limited to the items the caller may edit.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'   => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A content type this site registers and this account can edit, for example post or page. Types with no editing screen, such as revisions, are not listable. Defaults to post.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish', 'trash', 'any' ],
						'description' => 'Return only items in this status. Use any for every status this site registers, including statuses added by other plugins, but not the trash. Defaults to draft, pending, private and publish.',
					],
					'search' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items matching this search term.',
					],
					'parent' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only children of this content item; 0 returns top-level items.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset' => [
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
								'type'        => [ 'type' => 'string' ],
								'status'      => [ 'type' => 'string' ],
								'title'       => [ 'type' => 'string' ],
								'slug'        => [ 'type' => 'string' ],
								'modifiedGmt' => [ 'type' => 'string' ],
								'parent'      => [ 'type' => 'integer' ],
							],
							'required'             => [ 'id', 'type', 'status', 'title', 'slug', 'modifiedGmt', 'parent' ],
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
				'operation' => 'content-list',
				'arguments' => [
					'type'  => 'post',
					'limit' => 20,
				],
			],
		);
	}

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
	 * items would be surprising, and `content-trash` makes the trash a
	 * destination a client chooses on purpose, so it is listed only when
	 * `status` asks. That operation shipping does not widen this list: a
	 * trashed item stays out of the default page, which is what keeps a trash
	 * and the listing that follows it consistent with each other.
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
		$this->assert_listable_type( $type, $context );

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
	 * THREE FAILURES SHARE ONE MESSAGE, and that is the point. A type the site
	 * does not register, a type with no editing screen, and a type this account
	 * may not edit are refused identically, and the message names neither the
	 * requested type nor the types that do exist: a listing operation must not
	 * become a way to enumerate a site's internal post types by guessing, and it
	 * must not become a way to learn which of them an account is short of.
	 *
	 * The capability asked for is the type's own `edit_posts`, not the literal
	 * string: a type registered with its own capability set answers with that
	 * set, and asking the generic capability would admit an account the type
	 * itself keeps out.
	 *
	 * @param string           $type    The requested content type.
	 * @param OperationContext $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function assert_listable_type( string $type, OperationContext $context ): void {
		$object = '' === $type ? null : get_post_type_object( $type );

		$has_screen = is_object( $object )
			&& ( true === ( $object->public ?? false ) || true === ( $object->show_ui ?? false ) );

		if ( $has_screen && user_can( $context->userId, self::edit_capability_for( $object ) ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'The requested content type is not available for listing on this site.',
			'Choose a content type this site registers and this account can edit, for example post or page.'
		);
	}

	/**
	 * The capability that governs editing items of one content type.
	 *
	 * A type registered without a capability set is governed by `edit_posts`,
	 * which is also what WordPress itself falls back to.
	 *
	 * @param object $type_object The registered post type object.
	 *
	 * @return string The capability to ask for.
	 */
	private static function edit_capability_for( object $type_object ): string {
		$caps = $type_object->cap ?? null;

		if ( is_object( $caps ) && isset( $caps->edit_posts ) && is_string( $caps->edit_posts ) && '' !== $caps->edit_posts ) {
			return $caps->edit_posts;
		}

		return 'edit_posts';
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
