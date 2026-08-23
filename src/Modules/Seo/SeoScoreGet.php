<?php
/**
 * REQ-0098: read one post's SEO scores and findings.
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

/**
 * Reports the SEO plugin's own scores for one post, with the findings SiteHelm
 * derives from the stored metadata.
 *
 * THE SCORES ARE READ, NOT COMPUTED. Both plugins analyse a post in the editor and
 * store the result; this operation reports that stored number so it agrees with
 * what the editor shows. A post never saved since the plugin was installed has no
 * score, and the answer says null rather than inventing one. Rank Math has no
 * separate readability score, so that member is null on a Rank Math site; the
 * provider name in the answer tells the two cases apart.
 *
 * The guard order is the SEO module's: capability, presence, existence — see
 * SeoMetadataGet::handle() for why each comes where it does.
 *
 * @package SiteHelm
 */
final class SeoScoreGet {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-seo-score-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-seo-score-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Read one post\'s SEO and readability scores as the site\'s SEO plugin stored them, plus the findings SiteHelm derives from its metadata.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the post whose scores are being read.',
					],
					'minScore' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100,
						'default'     => SeoFindings::DEFAULT_MIN_SCORE,
						'description' => 'A stored score under this floor is reported as a low-score finding.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'               => [
						'type'        => 'integer',
						'description' => 'The post identifier.',
					],
					'provider'         => [
						'type'        => 'string',
						'description' => 'Which SEO plugin\'s store this answer came from.',
					],
					'seoScore'         => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'The plugin\'s stored SEO analysis score, 0-100, or null when the post has not been scored.',
					],
					'readabilityScore' => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'The plugin\'s stored readability score, 0-100, or null when unscored or when the plugin keeps no separate readability score.',
					],
					'focusKeyword'     => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The focus keyword the scores were computed against.',
					],
					'findings'         => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => SeoFindings::codes(),
						],
						'description' => 'Finding codes derived from the stored metadata and scores.',
					],
				],
				'required'             => [ 'id', 'provider', 'seoScore', 'readabilityScore', 'focusKeyword', 'findings' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ SeoFields::CAPABILITY ],
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
				'operation' => 'content-seo-score-get',
				'arguments' => [ 'id' => 42 ],
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
	 * Reports one post's scores and findings.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'id' and optionally 'minScore'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The identifier, provider, scores, keyword and findings.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable or
	 *                           TargetNotFound, in the SEO module's guard order.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id   = (int) ( $input['id'] ?? 0 );
		$min_score = (int) ( $input['minScore'] ?? SeoFindings::DEFAULT_MIN_SCORE );

		if ( ! user_can( $context->userId, SeoFields::CAPABILITY, $post_id ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit the requested post.',
				'Ask a site administrator to grant your WordPress user permission to edit that post.'
			);
		}

		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so this post carries no SEO score to read.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		$post = get_post( $post_id );

		if ( null === $post ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		$values = $provider->values( $post_id );
		$scores = $provider->scores( $post_id );

		return [
			'id'               => $post_id,
			'provider'         => $provider->name(),
			'seoScore'         => $scores['seoScore'],
			'readabilityScore' => $scores['readabilityScore'],
			'focusKeyword'     => $values['focusKeyword'],
			'findings'         => SeoFindings::for_post(
				$values,
				$scores,
				(string) ( $post->post_title ?? '' ),
				(string) ( $post->post_status ?? '' ),
				$min_score
			),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
