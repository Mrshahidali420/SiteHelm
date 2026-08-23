<?php
/**
 * REQ-0098 (Pro): list Rank Math's redirections.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoPresence;

/**
 * The redirections Rank Math's own module holds — distinct from SiteHelm's
 * redirect-list, which reads SiteHelm's own table.
 *
 * THE SOURCES COLUMN IS SERIALIZED PHP, and it is unserialized with classes
 * forbidden, so a row nothing but Rank Math should have written cannot hand
 * this operation an object.
 */
final class SeoRedirectionList extends RankMathTableList {

	public const ID = 'seo-redirection-list';

	public const TABLE = 'rank_math_redirections';

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List the redirections Rank Math\'s redirections module holds — source patterns, destination, status code, hit count, active or inactive — most recently changed first. SiteHelm Pro; Rank Math only.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => self::page_properties(),
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: self::output_schema(
				[
					'type'                 => 'object',
					'properties'           => [
						'id'           => [
							'type'        => 'integer',
							'description' => 'The redirection identifier.',
						],
						'sources'      => [
							'type'        => 'array',
							'items'       => [
								'type'                 => 'object',
								'properties'           => [
									'pattern'    => [
										'type'        => 'string',
										'description' => 'The source path or pattern.',
									],
									'comparison' => [
										'type'        => 'string',
										'description' => 'How the pattern is matched: exact, contains, start, end, or regex.',
									],
								],
								'required'             => [ 'pattern', 'comparison' ],
								'additionalProperties' => false,
							],
							'description' => 'The sources the redirection matches.',
						],
						'to'           => [
							'type'        => 'string',
							'description' => 'The destination URL.',
						],
						'code'         => [
							'type'        => 'integer',
							'description' => 'The HTTP status code: 301, 302, 307, 410 or 451.',
						],
						'hits'         => [
							'type'        => 'integer',
							'description' => 'How many times the redirection fired.',
						],
						'status'       => [
							'type'        => 'string',
							'description' => 'active, inactive or trashed.',
						],
						'lastAccessed' => [
							'type'        => [ 'string', 'null' ],
							'description' => 'When the redirection last fired, ISO-8601 UTC.',
						],
					],
					'required'             => [ 'id', 'sources', 'to', 'code', 'hits', 'status', 'lastAccessed' ],
					'additionalProperties' => false,
				]
			),
			schemaVersion: 1,
			requiredCapabilities: [ SeoSettingsFields::CAPABILITY ],
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
				'operation' => self::ID,
				'arguments' => [],
			],
		);
	}

	/**
	 * See the parent.
	 */
	protected function table_suffix(): string {
		return self::TABLE;
	}

	/**
	 * See the parent.
	 */
	protected function order_column(): string {
		return 'updated';
	}

	/**
	 * See the parent.
	 */
	protected function what(): string {
		return 'redirections';
	}

	/**
	 * See the parent.
	 *
	 * @param array<string, mixed> $row See the parent.
	 */
	protected function project( array $row ): array {
		return [
			'id'           => (int) ( $row['id'] ?? 0 ),
			'sources'      => self::sources( $row['sources'] ?? null ),
			'to'           => (string) ( $row['url_to'] ?? '' ),
			'code'         => (int) ( $row['header_code'] ?? 0 ),
			'hits'         => (int) ( $row['hits'] ?? 0 ),
			'status'       => (string) ( $row['status'] ?? '' ),
			'lastAccessed' => self::when( $row['last_accessed'] ?? null ),
		];
	}

	/**
	 * The stored sources, decoded with classes forbidden.
	 *
	 * @param mixed $stored The serialized column.
	 *
	 * @return array<int, array{pattern: string, comparison: string}> The sources.
	 */
	private static function sources( mixed $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return [];
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Rank Math's own column format; classes are forbidden, and a malformed row must read as empty.
		$decoded = @unserialize( $stored, [ 'allowed_classes' => false ] );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$sources = [];

		foreach ( $decoded as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$sources[] = [
				'pattern'    => (string) ( $source['pattern'] ?? '' ),
				'comparison' => (string) ( $source['comparison'] ?? 'exact' ),
			];
		}

		return $sources;
	}
}
