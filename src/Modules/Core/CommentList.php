<?php
/**
 * REQ-0060: list the comments a moderator needs to act on.
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
use WP_Comment;

/**
 * Lists comments by post, status, or search term, newest first.
 *
 * THE DEFAULT IS APPROVED PLUS PENDING, not the moderation queue alone. A queue
 * is one argument away, and making it the default would answer "what comments
 * are on post 42" with a partial list that looks like a complete one — the
 * failure a moderation tool can least afford, because the natural next step
 * after an empty answer is to conclude there is nothing to do.
 *
 * SPAM AND TRASH ARE EXCLUDED UNTIL ASKED FOR, matching what `all` means to
 * WordPress's own comment query. A spam folder holding thousands of rows would
 * otherwise crowd out the handful of comments a moderator has to make a decision
 * about.
 *
 * THERE IS NO PER-COMMENT PERMISSION RE-CHECK, and its absence is deliberate
 * rather than an omission. `content-list` re-checks every match with the
 * target-bound `edit_post` because `edit_posts` is a site-wide primitive that
 * does not imply rights over each item. `moderate_comments` has no target-bound
 * form: WordPress grants comment moderation over the whole site or not at all,
 * and its own moderation screen shows every comment on that basis. A per-item
 * check here would have to invent a rule WordPress does not have.
 *
 * The filter set is closed — post, status, search, limit and offset, ordered
 * newest first — for the reason `content-list` gives: fixing the ordering
 * removes an input rather than validating one.
 *
 * @package SiteHelm
 */
final class CommentList {

	/**
	 * The largest page a caller may request, matching content-list's clamp.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The comment query's own word for "approved or pending, nothing else".
	 */
	private const QUERY_STATUS_DEFAULT = 'all';

	/**
	 * The comment query's status argument for each reportable status.
	 *
	 * A third vocabulary again — `approve` and `hold` rather than approved and
	 * pending — kept here rather than reused from
	 * CommentFields::SET_ARGUMENT_BY_STATUS because that map is deliberately
	 * missing the two statuses a write may not set but a read may filter by.
	 * Sharing one map would mean either a read that cannot find trashed comments
	 * or a write that can create them.
	 *
	 * @var array<string, string>
	 */
	private const QUERY_STATUS_BY_STATUS = [
		CommentFields::STATUS_APPROVED     => 'approve',
		CommentFields::STATUS_PENDING      => 'hold',
		CommentFields::STATUS_SPAM         => 'spam',
		CommentFields::STATUS_TRASH        => 'trash',
		CommentFields::STATUS_POST_TRASHED => 'post-trashed',
	];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for comment-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'comment-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List comments by post, status, or search term, newest first, for moderation.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Return only comments on this content item.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => CommentFields::REPORTABLE_STATUSES,
						'description' => 'Return only comments in this status. Defaults to approved and pending together; spam and trash are returned only when asked for by name.',
					],
					'search' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only comments matching this search term in their author, email, site, or body.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100. Defaults to 20.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Comments to skip before the page begins.',
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
								'postId'      => [ 'type' => 'integer' ],
								'postTitle'   => [ 'type' => 'string' ],
								'parentId'    => [ 'type' => 'integer' ],
								'status'      => [ 'type' => 'string' ],
								'type'        => [ 'type' => 'string' ],
								'author'      => [ 'type' => 'string' ],
								'authorEmail' => [ 'type' => 'string' ],
								'authorUrl'   => [ 'type' => 'string' ],
								'content'     => [ 'type' => 'string' ],
								'dateGmt'     => [ 'type' => 'string' ],
							],
							'required'             => CommentFields::FIELD_ORDER,
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
			requiredCapabilities: [ CommentFields::CAPABILITY ],
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
				'operation' => 'comment-list',
				'arguments' => [
					'status' => CommentFields::STATUS_PENDING,
					'limit'  => 20,
				],
			],
		);
	}

	/**
	 * Lists the matching comments and the size of the full match set.
	 *
	 * `total` counts every comment the same filters match, not the page, so a
	 * caller can tell a queue of eight from a queue of eight hundred without
	 * paging through it. It is a second query rather than a count of the page,
	 * because a page that comes back full says nothing about what follows it.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The page, the total, and the paging echoed back.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                           WordPress user may not moderate comments.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, CommentFields::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not moderate comments on this site.',
				'Ask a site administrator to grant your WordPress user the comment moderation capability.'
			);
		}

		$limit   = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset  = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$filters = $this->filters( $input );

		$comments = get_comments(
			array_merge(
				$filters,
				[
					'number'  => $limit,
					'offset'  => $offset,
					'orderby' => 'comment_date_gmt',
					'order'   => 'DESC',
				]
			)
		);

		$total = get_comments( array_merge( $filters, [ 'count' => true ] ) );

		$items = [];

		foreach ( is_array( $comments ) ? $comments : [] as $comment ) {
			if ( $comment instanceof WP_Comment ) {
				$items[] = CommentFields::project( $comment );
			}
		}

		return [
			'items'  => $items,
			'total'  => (int) $total,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The query arguments the caller's filters translate to.
	 *
	 * Built once and reused by both the page query and the count query, so the
	 * two cannot disagree about what they are counting. A `total` computed under
	 * different filters than the page is worse than no total at all.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The shared query arguments.
	 */
	private function filters( array $input ): array {
		$status = (string) ( $input['status'] ?? '' );

		$filters = [
			'status' => self::QUERY_STATUS_BY_STATUS[ $status ] ?? self::QUERY_STATUS_DEFAULT,
		];

		if ( isset( $input['postId'] ) ) {
			$filters['post_id'] = (int) $input['postId'];
		}

		$search = trim( (string) ( $input['search'] ?? '' ) );

		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		return $filters;
	}
}
