<?php
/**
 * REQ-0098 (Pro): list Rank Math's 404 log.
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
 * The URLs visitors asked for and did not find, as Rank Math's 404 monitor
 * recorded them — the list an owner turns into redirects.
 */
final class SeoNotFoundLogList extends RankMathTableList {

	public const ID = 'seo-404-log-list';

	public const TABLE = 'rank_math_404_logs';

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List the URLs visitors requested that returned 404, as Rank Math\'s 404 monitor recorded them, most recent first, with hit counts and referrers. SiteHelm Pro; Rank Math only.',
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
						'id'       => [
							'type'        => 'integer',
							'description' => 'The log row identifier.',
						],
						'uri'      => [
							'type'        => 'string',
							'description' => 'The requested path, relative to the site.',
						],
						'hits'     => [
							'type'        => 'integer',
							'description' => 'How many times the path was requested.',
						],
						'lastSeen' => [
							'type'        => [ 'string', 'null' ],
							'description' => 'When the path was last requested, ISO-8601 UTC.',
						],
						'referer'  => [
							'type'        => [ 'string', 'null' ],
							'description' => 'The referring URL of the last request, when one was sent.',
						],
					],
					'required'             => [ 'id', 'uri', 'hits', 'lastSeen', 'referer' ],
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
				'arguments' => [ 'limit' => 20 ],
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
		return 'accessed';
	}

	/**
	 * See the parent.
	 */
	protected function what(): string {
		return 'a 404 monitor log';
	}

	/**
	 * See the parent.
	 *
	 * @param array<string, mixed> $row See the parent.
	 */
	protected function project( array $row ): array {
		$referer = $row['referer'] ?? null;

		return [
			'id'       => (int) ( $row['id'] ?? 0 ),
			'uri'      => (string) ( $row['uri'] ?? '' ),
			'hits'     => (int) ( $row['times_accessed'] ?? 0 ),
			'lastSeen' => self::when( $row['accessed'] ?? null ),
			'referer'  => is_string( $referer ) && '' !== $referer ? $referer : null,
		];
	}
}
