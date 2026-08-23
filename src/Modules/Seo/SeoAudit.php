<?php
/**
 * REQ-0098: the site-wide SEO audit.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

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
 * Walks a page of posts and reports each one's scores and findings, with a
 * count of every finding across the page.
 *
 * ONE PAGE AT A TIME, never the whole site in one answer: each post costs a
 * handful of meta reads, and a site with ten thousand posts would otherwise turn
 * one call into a minute of work and a megabyte of answer. The page is the same
 * shape as content-list's — `limit`, `offset`, `total` — so a client that already
 * walks content-list walks this the same way.
 *
 * A POST THE CALLER MAY NOT EDIT IS SKIPPED, NOT REPORTED, and counted in
 * `skipped`. The list capability (`edit_posts`) admits the caller to the audit;
 * the per-post capability (`edit_post`) decides what they see in it, so an author
 * with a contributor's rights sees their own posts' SEO state and a count of the
 * ones they were not shown. The number keeps the answer honest without leaking
 * the posts behind it.
 *
 * DUPLICATES ARE DECIDED WITHIN THE PAGE. A duplicate title across pages is not
 * seen, and the schema says so; the trade is accepted because the alternative is
 * reading every post's meta to audit any of them.
 *
 * @package SiteHelm
 */
final class SeoAudit {

	/** The largest page a caller can ask for. */
	public const MAX_LIMIT = 100;

	/** The page size when none is asked for. */
	public const DEFAULT_LIMIT = 50;

	/** The capability that admits a caller to the audit at all. */
	public const CAPABILITY = 'edit_posts';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-seo-audit.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-seo-audit',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Audit a page of posts for SEO problems: the SEO plugin\'s stored scores, missing or over-long descriptions, missing focus keywords, noindexed published posts, and titles or descriptions duplicated within the page.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'     => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'The public post type to audit, for example post or page. Defaults to post.',
					],
					'status'   => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
						'description' => 'The post status to audit. Defaults to publish.',
					],
					'limit'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Posts per page. Defaults to ' . self::DEFAULT_LIMIT . '.',
					],
					'offset'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Posts to skip, for paging. Defaults to 0.',
					],
					'minScore' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => 'A stored score under this floor is reported as a low-score finding. Defaults to ' . SeoFindings::DEFAULT_MIN_SCORE . '.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'provider' => [
						'type'        => 'string',
						'description' => 'Which SEO plugin\'s store this answer came from.',
					],
					'items'    => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'id'               => [ 'type' => 'integer' ],
								'title'            => [ 'type' => 'string' ],
								'status'           => [ 'type' => 'string' ],
								'seoScore'         => [ 'type' => [ 'integer', 'null' ] ],
								'readabilityScore' => [ 'type' => [ 'integer', 'null' ] ],
								'findings'         => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
										'enum' => SeoFindings::codes(),
									],
								],
							],
							'required'             => [ 'id', 'title', 'status', 'seoScore', 'readabilityScore', 'findings' ],
							'additionalProperties' => false,
						],
						'description' => 'One entry per post the caller may edit, most recently modified first.',
					],
					'total'    => [
						'type'        => 'integer',
						'description' => 'How many posts match before paging.',
					],
					'limit'    => [ 'type' => 'integer' ],
					'offset'   => [ 'type' => 'integer' ],
					'skipped'  => [
						'type'        => 'integer',
						'description' => 'Posts on this page the caller may not edit, and so were not reported.',
					],
					'summary'  => [
						'type'                 => 'object',
						'additionalProperties' => [ 'type' => 'integer' ],
						'description'          => 'Finding code => how many reported posts carry it. Duplicates are detected within this page only.',
					],
				],
				'required'             => [ 'provider', 'items', 'total', 'limit', 'offset', 'skipped', 'summary' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ self::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Seo,
			supportedVersions: SeoPresence::supportedVersions(),
			example: [
				'operation' => 'content-seo-audit',
				'arguments' => [
					'type'     => 'post',
					'status'   => 'publish',
					'limit'    => 50,
					'minScore' => 70,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct( private readonly SeoPresence $presence ) {
	}

	/**
	 * Audits one page of posts.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The page, its total, and the summary.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the caller may not
	 *                           edit posts, IntegrationUnavailable when no supported
	 *                           SEO plugin is usable, or InvalidInput when the post
	 *                           type is not a public one.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit posts on this site.',
				'Ask a site administrator to grant your WordPress user permission to edit posts.'
			);
		}

		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so there are no SEO scores to audit.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		$type      = (string) ( $input['type'] ?? 'post' );
		$status    = (string) ( $input['status'] ?? 'publish' );
		$limit     = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset    = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$min_score = (int) ( $input['minScore'] ?? SeoFindings::DEFAULT_MIN_SCORE );

		$this->assert_public_type( $type );

		$query = new WP_Query(
			[
				'post_type'              => $type,
				'post_status'            => $status,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			]
		);

		$items   = [];
		$values  = [];
		$skipped = 0;

		foreach ( $query->posts as $post ) {
			$post_id = (int) $post->ID;

			if ( ! user_can( $context->userId, SeoFields::CAPABILITY, $post_id ) ) {
				++$skipped;
				continue;
			}

			$post_values        = $provider->values( $post_id );
			$scores             = $provider->scores( $post_id );
			$values[ $post_id ] = $post_values;
			$items[ $post_id ]  = [
				'id'               => $post_id,
				'title'            => (string) ( $post->post_title ?? '' ),
				'status'           => (string) ( $post->post_status ?? '' ),
				'seoScore'         => $scores['seoScore'],
				'readabilityScore' => $scores['readabilityScore'],
				'findings'         => SeoFindings::for_post(
					$post_values,
					$scores,
					(string) ( $post->post_title ?? '' ),
					(string) ( $post->post_status ?? '' ),
					$min_score
				),
			];
		}

		foreach ( SeoFindings::duplicates( $values ) as $post_id => $codes ) {
			$items[ $post_id ]['findings'] = array_merge( $items[ $post_id ]['findings'], $codes );
		}

		$summary = [];

		foreach ( $items as $item ) {
			foreach ( $item['findings'] as $code ) {
				$summary[ $code ] = ( $summary[ $code ] ?? 0 ) + 1;
			}
		}

		return [
			'provider' => $provider->name(),
			'items'    => array_values( $items ),
			'total'    => (int) $query->found_posts,
			'limit'    => $limit,
			'offset'   => $offset,
			'skipped'  => $skipped,
			'summary'  => $summary,
		];
	}

	/**
	 * Refuses a post type that is not registered or not public.
	 *
	 * @param string $type The requested post type.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_public_type( string $type ): void {
		$object = get_post_type_object( $type );

		if ( null !== $object && true === ( $object->public ?? false ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'The requested content type is not available for auditing on this site.',
			'Choose a public content type this site registers, for example post or page.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
