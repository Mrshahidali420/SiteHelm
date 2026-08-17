<?php
/**
 * REQ-0059: read one post's SEO metadata.
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
 * Reports the search-engine metadata one post carries, whichever SEO plugin holds it.
 *
 * THE ANSWER NAMES ITS SOURCE. `provider` is part of the output rather than a
 * diagnostic, because without it a null description is ambiguous between "no
 * description is set" and "the description is set in the other SEO plugin this
 * site also has installed" — and a client that guessed wrong would write into a
 * store nothing renders from.
 *
 * THE DOMAIN IS CONTENT, NOT SEO, and that is not a naming compromise: a
 * dispatcher name is DERIVED from domain and mode by
 * OperationDefinition::dispatcherName(), and the eleven dispatchers are frozen.
 * A post's SEO metadata is part of that post, so `content-read` is also where a
 * client already looking for per-post reads will find it.
 *
 * EVERY FIELD IS ALWAYS PRESENT in the answer, each one a value or null, so a
 * caller never has to distinguish "this plugin does not support the field" from
 * "the field is not set". Null means "the plugin decides" throughout — see
 * SeoProvider::values() for why an absent row and a stored empty string are the
 * same answer.
 *
 * @package SiteHelm
 */
final class SeoMetadataGet {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-seo-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-seo-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Read one post\'s search-engine metadata from whichever supported SEO plugin this site runs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the post whose SEO metadata is being read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'                 => [
						'type'        => 'integer',
						'description' => 'The post identifier.',
					],
					'provider'           => [
						'type'        => 'string',
						'description' => 'Which SEO plugin\'s store this answer came from.',
					],
					'title'              => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The search-result title override, or null when the plugin decides.',
					],
					'description'        => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The meta description, or null when the plugin decides.',
					],
					'canonical'          => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The canonical URL override, or null when the plugin decides.',
					],
					'focusKeyword'       => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The focus keyword the plugin scores its analysis against.',
					],
					'noindex'            => [
						'type'        => [ 'boolean', 'null' ],
						'description' => 'True to keep this post out of search results, false to keep it in, null to use the site\'s setting.',
					],
					'nofollow'           => [
						'type'        => [ 'boolean', 'null' ],
						'description' => 'True to tell search engines not to follow this post\'s links, false to follow them, null to use the site\'s setting.',
					],
					'ogTitle'            => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Open Graph title override.',
					],
					'ogDescription'      => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Open Graph description override.',
					],
					'ogImage'            => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Open Graph image URL. Reported only; content-seo-set will not write it.',
					],
					'twitterTitle'       => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Twitter card title override.',
					],
					'twitterDescription' => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Twitter card description override.',
					],
					'twitterImage'       => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The Twitter card image URL. Reported only; content-seo-set will not write it.',
					],
				],
				'required'             => [
					'id',
					'provider',
					'title',
					'description',
					'canonical',
					'focusKeyword',
					'noindex',
					'nofollow',
					'ogTitle',
					'ogDescription',
					'ogImage',
					'twitterTitle',
					'twitterDescription',
					'twitterImage',
				],
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
				'operation' => 'content-seo-get',
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
	 * Reports one post's SEO metadata.
	 *
	 * The guard order is the module convention, and each step is asked for a
	 * reason: capability FIRST, so a caller with no rights over this post causes no
	 * database read and learns nothing about the site from the refusal; presence
	 * SECOND, so the answer for a site with no SEO plugin is one clear refusal
	 * rather than twelve nulls that look like an unconfigured post; existence LAST,
	 * because it is the only step that needs a query.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The post identifier, the provider, and every field.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                           WordPress user may not edit the post,
	 *                           ErrorCode::IntegrationUnavailable when no supported
	 *                           SEO plugin is usable, or ErrorCode::TargetNotFound
	 *                           when no post carries the identifier.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

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
				'No supported SEO plugin is active on this site, so this post carries no SEO metadata to read.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		return array_merge(
			[
				'id'       => $post_id,
				'provider' => $provider->name(),
			],
			$provider->values( $post_id )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
