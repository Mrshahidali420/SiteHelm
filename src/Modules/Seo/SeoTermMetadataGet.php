<?php
/**
 * REQ-0098: read one taxonomy term's SEO metadata.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * Reports the search-engine metadata one term's archive carries, from whichever
 * SEO plugin holds it.
 *
 * The term counterpart of content-seo-get: the same provider name in the answer,
 * the same "null means the plugin decides" meaning, and the narrower field set
 * SeoTermFields explains.
 *
 * @package SiteHelm
 */
final class SeoTermMetadataGet {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-term-seo-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-term-seo-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Read one taxonomy term\'s search-engine metadata — title and description overrides, canonical URL, focus keyword and noindex — from whichever supported SEO plugin this site runs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => self::target_properties(),
				'required'             => [ 'taxonomy', 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => array_merge(
					[
						'taxonomy' => [
							'type'        => 'string',
							'description' => 'The taxonomy the term belongs to.',
						],
						'id'       => [
							'type'        => 'integer',
							'description' => 'The term identifier.',
						],
						'provider' => [
							'type'        => 'string',
							'description' => 'Which SEO plugin\'s store this answer came from.',
						],
					],
					self::value_properties()
				),
				'required'             => array_merge( [ 'taxonomy', 'id', 'provider' ], SeoTermFields::FIELD_ORDER ),
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ SeoTermFields::CAPABILITY ],
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
				'operation' => 'content-term-seo-get',
				'arguments' => [
					'taxonomy' => 'category',
					'id'       => 3,
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
	 * Reports one term's SEO metadata.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'taxonomy' and 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The target, the provider, and every term field.
	 */
	public function handle( array $input, OperationContext $context ): array {
		[ $taxonomy, $term_id, $provider ] = ( new SeoTermTarget( $this->presence ) )->resolve( $input, $context );

		return array_merge(
			[
				'taxonomy' => $taxonomy,
				'id'       => $term_id,
				'provider' => $provider->name(),
			],
			$provider->values( $taxonomy, $term_id )
		);
	}

	/**
	 * The input members that name a term.
	 *
	 * @return array<string, array<string, mixed>> The two properties.
	 */
	public static function target_properties(): array {
		return [
			'taxonomy' => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => SeoTermFields::TAXONOMY_MAX_LENGTH,
				'description' => 'The public taxonomy the term belongs to, for example category or post_tag.',
			],
			'id'       => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'The term identifier.',
			],
		];
	}

	/**
	 * The output members one term field each, in SeoTermFields order.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	public static function value_properties(): array {
		$descriptions = [
			SeoFields::FIELD_TITLE         => 'The archive\'s search-result title override, or null when the plugin builds it from its template.',
			SeoFields::FIELD_DESCRIPTION   => 'The archive\'s meta description, or null when none is set.',
			SeoFields::FIELD_CANONICAL     => 'The canonical URL override, or null when the plugin uses the archive\'s own URL.',
			SeoFields::FIELD_FOCUS_KEYWORD => 'The focus keyword, or null when none is set.',
			SeoFields::FIELD_NOINDEX       => 'True when the archive is kept out of search results, false when explicitly kept in, null when the site\'s setting decides.',
		];

		$properties = [];

		foreach ( SeoTermFields::FIELD_ORDER as $field ) {
			$properties[ $field ] = [
				'type'        => in_array( $field, SeoTermFields::FLAG_FIELDS, true ) ? [ 'boolean', 'null' ] : [ 'string', 'null' ],
				'description' => $descriptions[ $field ],
			];
		}

		return $properties;
	}
}
